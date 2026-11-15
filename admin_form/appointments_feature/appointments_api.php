<?php
/*==============================================================
  appointments_api.php - API cho module Appointments Management (admin)
  action: list | create | update | cancel

  Chỉ dùng đúng các cột của bảng appointments:
    appointment_id, patient_id, doctor_id, appointment_datetime, visit_reason,
    appointment_status, doctor_response, doctor_reject_reason, doctor_responded_at,
    reviewed_by, reviewed_at, review_note, created_at, slot_key (cột tự sinh)

  Quyền của admin ở đây:
    - xem tất cả (kể cả lịch của tài khoản đã bị ẩn)
    - tạo lịch mới (trạng thái pending, chờ bác sĩ + lễ tân)
    - sửa bác sĩ / giờ / lý do khám khi lịch còn pending hoặc confirmed
    - hủy lịch pending / confirmed
  Admin KHÔNG sửa phản hồi của bác sĩ, KHÔNG duyệt thay lễ tân, KHÔNG xóa lịch.
==============================================================*/
session_start();
header("Content-Type: application/json; charset=UTF-8");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set("display_errors", "0");

if (!defined("PK")) define("PK", "user_id");          // auth_guard.php dùng hằng số này

const OPEN_STATUSES = ["pending", "confirmed"];       // còn "mở": được sửa / hủy

function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function textLength(string $s): int {
    return function_exists("mb_strlen") ? mb_strlen($s, "UTF-8") : strlen($s);
}

// Nhận "2026-12-01T09:30" / "2026-12-01 09:30" / "2026-12-01 09:30:00" -> "Y-m-d H:i:s"
function parseDateTime(string $s): ?string {
    $s = trim(str_replace("T", " ", $s));
    foreach (["Y-m-d H:i:s", "Y-m-d H:i"] as $fmt) {
        $d = DateTime::createFromFormat("!" . $fmt, $s);
        if ($d && $d->format($fmt) === $s && (int)$d->format("Y") >= 2000 && (int)$d->format("Y") <= 2100) {
            return $d->format("Y-m-d H:i:s");
        }
    }
    return null;
}

// Lịch hẹn + tên bệnh nhân / bác sĩ / người duyệt. LEFT JOIN và KHÔNG lọc is_deleted:
// lịch cũ của tài khoản đã ẩn vẫn phải hiện đủ thông tin.
function baseSelect(): string {
    return "SELECT a.appointment_id, a.patient_id, a.doctor_id, a.appointment_datetime,
                   a.visit_reason, a.appointment_status, a.doctor_response,
                   a.doctor_reject_reason, a.doctor_responded_at,
                   a.reviewed_by, a.reviewed_at, a.review_note, a.created_at,
                   pu.full_name AS patient_name, pu.username AS patient_username,
                   pu.is_deleted AS patient_deleted,
                   du.full_name AS doctor_name, du.is_deleted AS doctor_deleted,
                   d.specialization,
                   ru.full_name AS reviewer_name
            FROM appointments a
            LEFT JOIN patients p  ON p.patient_id = a.patient_id
            LEFT JOIN users    pu ON pu.user_id   = p.user_id
            LEFT JOIN doctors  d  ON d.doctor_id  = a.doctor_id
            LEFT JOIN users    du ON du.user_id   = d.user_id
            LEFT JOIN users    ru ON ru.user_id   = a.reviewed_by";
}

function shapeRow(array $r): array {
    $s = fn($k) => (string)($r[$k] ?? "");
    return [
        "id"                   => $s("appointment_id"),
        "patient_id"           => $s("patient_id"),
        "patient_name"         => $s("patient_name"),
        "patient_username"     => $s("patient_username"),
        "patient_deleted"      => (int)($r["patient_deleted"] ?? 0) === 1,
        "doctor_id"            => $s("doctor_id"),
        "doctor_name"          => $s("doctor_name"),
        "doctor_deleted"       => (int)($r["doctor_deleted"] ?? 0) === 1,
        "specialization"       => $s("specialization"),
        "datetime"             => $s("appointment_datetime"),
        "visit_reason"         => $s("visit_reason"),
        "status"               => $s("appointment_status"),
        "doctor_response"      => $s("doctor_response"),
        "doctor_reject_reason" => $s("doctor_reject_reason"),
        "doctor_responded_at"  => $s("doctor_responded_at"),
        "reviewer_name"        => $s("reviewer_name"),
        "reviewed_at"          => $s("reviewed_at"),
        "review_note"          => $s("review_note"),
        "created_at"           => $s("created_at"),
    ];
}

// Bác sĩ có thể nhận lịch mới: hồ sơ đang bật, tài khoản đang hoạt động và chưa bị ẩn
function doctorAssignable(PDO $pdo, string $doctorId): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM doctors d JOIN users u ON u.user_id = d.user_id
         WHERE d.doctor_id = ? AND u.role = 'doctor'
           AND d.status = 1 AND u.user_status = 1 AND u.is_deleted = 0");
    $stmt->execute([$doctorId]);
    return (bool)$stmt->fetchColumn();
}

// Bệnh nhân có thể đặt lịch: không có tài khoản, hoặc tài khoản hiện có role patient,
// đang hoạt động và chưa bị ẩn. Hồ sơ cũ sau khi đổi role không được dùng đặt lịch mới.
function patientBookable(PDO $pdo, string $patientId): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM patients p LEFT JOIN users u ON u.user_id = p.user_id
         WHERE p.patient_id = ? AND (p.user_id IS NULL OR
               (u.role = 'patient' AND u.is_deleted = 0 AND u.user_status = 1))");
    $stmt->execute([$patientId]);
    return (bool)$stmt->fetchColumn();
}

// Một tài khoản có thể còn cả hai hồ sơ sau khi đổi role, nhưng không được tự hẹn khám.
function isSelfAppointment(PDO $pdo, string $patientId, string $doctorId): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM patients p JOIN doctors d ON d.user_id = p.user_id
         WHERE p.patient_id = ? AND d.doctor_id = ? LIMIT 1");
    $stmt->execute([$patientId, $doctorId]);
    return (bool)$stmt->fetchColumn();
}

function slotTaken(PDO $pdo, string $doctorId, string $dt, string $exceptId = ""): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM appointments
         WHERE doctor_id = ? AND appointment_datetime = ?
           AND appointment_status IN ('pending','confirmed') AND appointment_id <> ? LIMIT 1");
    $stmt->execute([$doctorId, $dt, $exceptId]);
    return (bool)$stmt->fetchColumn();
}

require_once __DIR__ . "/../php/auth_guard.php";

try {
    $conn = mysqli_connect("localhost", "root", "", "hospital_management_system");
    mysqli_set_charset($conn, "utf8mb4");
    requireAdmin($conn);                              // chỉ admin còn hiệu lực mới được gọi

    $pdo = new PDO(
        "mysql:host=localhost;dbname=hospital_management_system;charset=utf8mb4",
        "root", "",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => false]
    );

    $action = $_POST["action"] ?? $_GET["action"] ?? "";

    // ---------- LIST ----------
    if ($action === "list") {
        $rows = array_map("shapeRow", $pdo->query(
            baseSelect() . " ORDER BY a.appointment_datetime DESC, a.created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC));

        // Bác sĩ có thể chọn khi tạo / sửa lịch
        $doctors = [];
        $q = $pdo->query(
            "SELECT d.doctor_id, u.full_name, d.specialization
             FROM doctors d JOIN users u ON u.user_id = d.user_id
             WHERE u.role = 'doctor' AND d.status = 1 AND u.user_status = 1 AND u.is_deleted = 0
             ORDER BY u.full_name");
        foreach ($q as $r) {
            $doctors[] = [
                "id"             => (string)$r["doctor_id"],
                "name"           => $r["full_name"],
                "specialization" => (string)($r["specialization"] ?? ""),
            ];
        }

        // Bệnh nhân có thể chọn khi tạo lịch
        $patients = [];
        $q = $pdo->query(
            "SELECT p.patient_id, u.full_name, u.username
             FROM patients p LEFT JOIN users u ON u.user_id = p.user_id
             WHERE p.user_id IS NULL OR
                   (u.role = 'patient' AND u.is_deleted = 0 AND u.user_status = 1)
             ORDER BY u.full_name, p.created_at");
        foreach ($q as $r) {
            $patients[] = [
                "id"       => (string)$r["patient_id"],
                "name"     => (string)($r["full_name"] ?? ""),
                "username" => (string)($r["username"] ?? ""),
            ];
        }

        respond(200, ["success" => true, "data" => $rows, "doctors" => $doctors, "patients" => $patients]);
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        respond(405, ["success" => false, "message" => "Method not allowed."]);
    }

    $id = trim((string)($_POST["id"] ?? ""));

    // ---------- CREATE ----------
    if ($action === "create") {
        $patientId = trim((string)($_POST["patient_id"] ?? ""));
        $doctorId  = trim((string)($_POST["doctor_id"] ?? ""));
        $dt        = parseDateTime((string)($_POST["appointment_datetime"] ?? ""));
        $reason    = trim((string)($_POST["visit_reason"] ?? ""));

        if ($patientId === "" || $doctorId === "") {
            respond(400, ["success" => false, "message" => "Please choose a patient and a doctor."]);
        }
        if ($dt === null) {
            respond(400, ["success" => false, "message" => "Invalid date and time."]);
        }
        if (textLength($reason) > 255) {
            respond(400, ["success" => false, "message" => "Visit reason is too long (max 255 characters)."]);
        }
        if (!patientBookable($pdo, $patientId)) {
            respond(400, ["success" => false, "message" => "Invalid or inactive patient."]);
        }
        if (!doctorAssignable($pdo, $doctorId)) {
            respond(400, ["success" => false, "message" => "Invalid or inactive doctor."]);
        }
        if (isSelfAppointment($pdo, $patientId, $doctorId)) {
            respond(400, ["success" => false, "message" => "A patient cannot book an appointment with themselves."]);
        }
        $now = (string)$pdo->query("SELECT NOW()")->fetchColumn();   // so với giờ của DB cho nhất quán
        if ($dt <= $now) {
            respond(400, ["success" => false, "message" => "The date and time must be in the future."]);
        }
        if (slotTaken($pdo, $doctorId, $dt)) {
            respond(409, ["success" => false, "message" => "This doctor already has an appointment at that time."]);
        }

        // appointment_status = 'pending', doctor_response = 'pending' là giá trị mặc định của bảng
        $pdo->prepare(
            "INSERT INTO appointments (appointment_id, patient_id, doctor_id, appointment_datetime, visit_reason)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([uuidv4(), $patientId, $doctorId, $dt, $reason === "" ? null : $reason]);

        respond(200, ["success" => true, "message" => "Appointment created."]);
    }

    // ---------- UPDATE ----------
    if ($action === "update") {
        $newDoctorId = trim((string)($_POST["doctor_id"] ?? ""));
        $newDt       = parseDateTime((string)($_POST["appointment_datetime"] ?? ""));
        $newReason   = trim((string)($_POST["visit_reason"] ?? ""));

        if ($id === "" || $newDoctorId === "") {
            respond(400, ["success" => false, "message" => "Missing required fields."]);
        }
        if ($newDt === null) {
            respond(400, ["success" => false, "message" => "Invalid date and time."]);
        }
        if (textLength($newReason) > 255) {
            respond(400, ["success" => false, "message" => "Visit reason is too long (max 255 characters)."]);
        }
        $newReason = $newReason === "" ? null : $newReason;

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM appointments WHERE appointment_id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $cur = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            $pdo->rollBack();
            respond(404, ["success" => false, "message" => "Appointment not found."]);
        }
        if (!in_array($cur["appointment_status"], OPEN_STATUSES, true)) {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "Only pending or confirmed appointments can be edited."]);
        }

        $doctorChanged = $newDoctorId !== (string)$cur["doctor_id"];
        $timeChanged   = $newDt !== (string)$cur["appointment_datetime"];
        $reasonChanged = $newReason !== ($cur["visit_reason"] === null ? null : (string)$cur["visit_reason"]);

        if (!$doctorChanged && !$timeChanged && !$reasonChanged) {
            $pdo->rollBack();
            respond(200, ["success" => true, "message" => "No changes."]);
        }

        if (isSelfAppointment($pdo, (string)$cur["patient_id"], $newDoctorId)) {
            $pdo->rollBack();
            respond(400, ["success" => false, "message" => "A patient cannot book an appointment with themselves."]);
        }

        if ($doctorChanged && !doctorAssignable($pdo, $newDoctorId)) {
            $pdo->rollBack();
            respond(400, ["success" => false, "message" => "Invalid or inactive doctor."]);
        }

        if ($timeChanged) {
            $now = (string)$pdo->query("SELECT NOW()")->fetchColumn();
            if ($newDt <= $now) {
                $pdo->rollBack();
                respond(400, ["success" => false, "message" => "The new date and time must be in the future."]);
            }
        }

        if (($doctorChanged || $timeChanged) && slotTaken($pdo, $newDoctorId, $newDt, $id)) {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "This doctor already has an appointment at that time."]);
        }

        // Đổi bác sĩ hoặc giờ => phản hồi cũ của bác sĩ không còn đúng nữa => đặt lại.
        // Lịch đã confirmed mà bị đổi => quay về pending để lễ tân duyệt lại.
        $reset         = $doctorChanged || $timeChanged;
        $backToPending = $reset && $cur["appointment_status"] === "confirmed";

        $sets   = ["doctor_id = ?", "appointment_datetime = ?", "visit_reason = ?"];
        $params = [$newDoctorId, $newDt, $newReason];
        if ($reset) {
            $sets[] = "doctor_response = 'pending'";
            $sets[] = "doctor_reject_reason = NULL";
            $sets[] = "doctor_responded_at = NULL";
        }
        if ($backToPending) {
            $sets[] = "appointment_status = 'pending'";
            $sets[] = "reviewed_by = NULL";
            $sets[] = "reviewed_at = NULL";
            $sets[] = "review_note = NULL";
        }
        $params[] = $id;
        $pdo->prepare("UPDATE appointments SET " . implode(", ", $sets) . " WHERE appointment_id = ?")
            ->execute($params);

        $pdo->commit();
        respond(200, ["success" => true, "message" => "Appointment updated.",
                      "reset" => $reset, "back_to_pending" => $backToPending]);
    }

    // ---------- CANCEL ----------
    if ($action === "cancel") {
        if ($id === "") {
            respond(400, ["success" => false, "message" => "Missing appointment id."]);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT appointment_status FROM appointments WHERE appointment_id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $status = $stmt->fetchColumn();
        if ($status === false) {
            $pdo->rollBack();
            respond(404, ["success" => false, "message" => "Appointment not found."]);
        }
        if (!in_array($status, OPEN_STATUSES, true)) {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "Only pending or confirmed appointments can be cancelled."]);
        }

        $pdo->prepare("UPDATE appointments SET appointment_status = 'cancelled' WHERE appointment_id = ?")
            ->execute([$id]);
        $pdo->commit();

        respond(200, ["success" => true, "message" => "Appointment cancelled."]);
    }

    respond(400, ["success" => false, "message" => "Unknown action."]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ((int)($e->errorInfo[1] ?? 0) === 1062) {      // trùng giờ bác sĩ (khóa uq_doctor_slot)
        respond(409, ["success" => false, "message" => "This doctor already has an appointment at that time."]);
    }
    error_log($e->getMessage());
    respond(500, ["success" => false, "message" => "Database error."]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log($e->getMessage());
    respond(500, ["success" => false, "message" => "Server error."]);
}
