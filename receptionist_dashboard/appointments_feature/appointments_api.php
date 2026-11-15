<?php
/*==============================================================
  appointments_api.php - Receptionist Appointments Management API
  Actions:
    list | availability | create | update | cancel |
    approve | override_approve | reject | mark_no_show
==============================================================*/
session_start();
header("Content-Type: application/json; charset=UTF-8");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set("display_errors", "0");

require_once __DIR__ . "/../php/auth_guard.php";

if (!defined("PK")) define("PK", "user_id");

const OPEN_STATUSES = ["pending", "confirmed"];

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

function baseSelect(): string {
    return "SELECT a.appointment_id, a.patient_id, a.doctor_id, a.appointment_datetime,
                   a.visit_reason, a.appointment_status, a.doctor_response,
                   a.doctor_reject_reason, a.doctor_responded_at,
                   a.reviewed_by, a.reviewed_at, a.review_note, a.created_at,
                   pu.full_name AS patient_name, pu.username AS patient_username,
                   pu.phone_number AS patient_phone, pu.is_deleted AS patient_deleted,
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
    $isOverridden = ($r["doctor_response"] === "rejected" && in_array($r["appointment_status"], ["confirmed", "completed", "no_show"], true));
    return [
        "id"                   => $s("appointment_id"),
        "patient_id"           => $s("patient_id"),
        "patient_name"         => $s("patient_name"),
        "patient_username"     => $s("patient_username"),
        "patient_phone"        => $s("patient_phone"),
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
        "is_overridden"        => $isOverridden,
    ];
}

function doctorAssignable(PDO $pdo, string $doctorId): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM doctors d JOIN users u ON u.user_id = d.user_id
         WHERE d.doctor_id = ? AND u.role = 'doctor'
           AND d.status = 1 AND u.user_status = 1 AND u.is_deleted = 0");
    $stmt->execute([$doctorId]);
    return (bool)$stmt->fetchColumn();
}

function patientBookable(PDO $pdo, string $patientId): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM patients p LEFT JOIN users u ON u.user_id = p.user_id
         WHERE p.patient_id = ? AND (p.user_id IS NULL OR
               (u.role = 'patient' AND u.is_deleted = 0 AND u.user_status = 1))");
    $stmt->execute([$patientId]);
    return (bool)$stmt->fetchColumn();
}

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
    requireReceptionist($conn);
    mysqli_close($conn);

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

        $patients = [];
        $q = $pdo->query(
            "SELECT p.patient_id, u.full_name, u.username, u.phone_number
             FROM patients p LEFT JOIN users u ON u.user_id = p.user_id
             WHERE p.user_id IS NULL OR
                   (u.role = 'patient' AND u.is_deleted = 0 AND u.user_status = 1)
             ORDER BY u.full_name, p.created_at");
        foreach ($q as $r) {
            $patients[] = [
                "id"       => (string)$r["patient_id"],
                "name"     => (string)($r["full_name"] ?? ""),
                "username" => (string)($r["username"] ?? ""),
                "phone"    => (string)($r["phone_number"] ?? ""),
            ];
        }

        respond(200, [
            "success"  => true,
            "data"     => $rows,
            "doctors"  => $doctors,
            "patients" => $patients,
            "now"      => (string)$pdo->query("SELECT NOW()")->fetchColumn()
        ]);
    }

    // ---------- AVAILABILITY ----------
    if ($action === "availability") {
        $doctorId = trim((string)($_GET["doctor_id"] ?? ""));
        $date     = trim((string)($_GET["date"] ?? ""));

        if ($doctorId === "" || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            respond(400, ["success" => false, "message" => "Please specify doctor_id and valid date (YYYY-MM-DD)."]);
        }

        $stmt = $pdo->prepare(
            "SELECT appointment_datetime
             FROM appointments
             WHERE doctor_id = ? AND DATE(appointment_datetime) = ?
               AND appointment_status IN ('pending', 'confirmed')
             ORDER BY appointment_datetime"
        );
        $stmt->execute([$doctorId, $date]);
        $busySlots = $stmt->fetchAll(PDO::FETCH_COLUMN);

        respond(200, [
            "success"    => true,
            "doctor_id"  => $doctorId,
            "date"       => $date,
            "busy_slots" => $busySlots
        ]);
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
        $now = (string)$pdo->query("SELECT NOW()")->fetchColumn();
        if ($dt <= $now) {
            respond(400, ["success" => false, "message" => "The date and time must be in the future."]);
        }
        if (slotTaken($pdo, $doctorId, $dt)) {
            respond(409, ["success" => false, "message" => "This doctor already has an appointment at that time."]);
        }

        $pdo->prepare(
            "INSERT INTO appointments (appointment_id, patient_id, doctor_id, appointment_datetime, visit_reason, appointment_status, doctor_response)
             VALUES (?, ?, ?, ?, ?, 'pending', 'pending')"
        )->execute([uuidv4(), $patientId, $doctorId, $dt, $reason === "" ? null : $reason]);

        respond(200, ["success" => true, "message" => "Walk-in appointment created successfully."]);
    }

    // ---------- APPROVE (Standard review: doctor accepted -> confirmed) ----------
    if ($action === "approve") {
        if ($id === "") respond(400, ["success" => false, "message" => "Missing appointment id."]);
        $reviewNote = trim((string)($_POST["review_note"] ?? ""));

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM appointments WHERE appointment_id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $cur = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cur) {
            $pdo->rollBack();
            respond(404, ["success" => false, "message" => "Appointment not found."]);
        }
        if ($cur["appointment_status"] !== "pending") {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "Only pending appointments can be approved."]);
        }
        if ($cur["doctor_response"] !== "accepted") {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "Doctor has not accepted this appointment yet. If doctor rejected, use Override."]);
        }

        $now = (string)$pdo->query("SELECT NOW()")->fetchColumn();
        if ($cur["appointment_datetime"] <= $now) {
            $pdo->rollBack();
            respond(400, ["success" => false, "message" => "Cannot confirm an appointment whose scheduled time has already passed."]);
        }
        if (!doctorAssignable($pdo, (string)$cur["doctor_id"])) {
            $pdo->rollBack();
            respond(400, ["success" => false, "message" => "Doctor is no longer active."]);
        }
        if (!patientBookable($pdo, (string)$cur["patient_id"])) {
            $pdo->rollBack();
            respond(400, ["success" => false, "message" => "Patient is no longer active."]);
        }

        $reviewerId = (string)($_SESSION["user_id"] ?? "");
        $pdo->prepare(
            "UPDATE appointments
             SET appointment_status = 'confirmed',
                 reviewed_by = ?,
                 reviewed_at = NOW(),
                 review_note = ?
             WHERE appointment_id = ?"
        )->execute([$reviewerId, $reviewNote === "" ? null : $reviewNote, $id]);

        $pdo->commit();
        respond(200, ["success" => true, "message" => "Appointment confirmed successfully."]);
    }

    // ---------- OVERRIDE APPROVE (Exception: doctor rejected -> confirmed with justification) ----------
    if ($action === "override_approve") {
        if ($id === "") respond(400, ["success" => false, "message" => "Missing appointment id."]);
        $reviewNote = trim((string)($_POST["review_note"] ?? ""));

        if ($reviewNote === "") {
            respond(400, ["success" => false, "message" => "Review note is mandatory when overriding a doctor's rejection."]);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM appointments WHERE appointment_id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $cur = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cur) {
            $pdo->rollBack();
            respond(404, ["success" => false, "message" => "Appointment not found."]);
        }
        if ($cur["appointment_status"] !== "pending") {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "Only pending appointments can be approved."]);
        }
        if ($cur["doctor_response"] !== "rejected") {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "Override is only applicable when doctor rejected."]);
        }

        $now = (string)$pdo->query("SELECT NOW()")->fetchColumn();
        if ($cur["appointment_datetime"] <= $now) {
            $pdo->rollBack();
            respond(400, ["success" => false, "message" => "Cannot confirm an appointment in the past."]);
        }

        $reviewerId = (string)($_SESSION["user_id"] ?? "");
        // Do NOT change doctor_response! Keep 'rejected' so the override is recognized
        $pdo->prepare(
            "UPDATE appointments
             SET appointment_status = 'confirmed',
                 reviewed_by = ?,
                 reviewed_at = NOW(),
                 review_note = ?
             WHERE appointment_id = ?"
        )->execute([$reviewerId, $reviewNote, $id]);

        $pdo->commit();
        respond(200, ["success" => true, "message" => "Appointment overridden and confirmed with reception note."]);
    }

    // ---------- REJECT (Receptionist rejects the appointment request) ----------
    if ($action === "reject") {
        if ($id === "") respond(400, ["success" => false, "message" => "Missing appointment id."]);
        $reviewNote = trim((string)($_POST["review_note"] ?? ""));

        if ($reviewNote === "") {
            respond(400, ["success" => false, "message" => "Please enter a rejection reason."]);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM appointments WHERE appointment_id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $cur = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cur) {
            $pdo->rollBack();
            respond(404, ["success" => false, "message" => "Appointment not found."]);
        }
        if ($cur["appointment_status"] !== "pending") {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "Only pending appointments can be rejected."]);
        }

        $reviewerId = (string)($_SESSION["user_id"] ?? "");
        $pdo->prepare(
            "UPDATE appointments
             SET appointment_status = 'rejected',
                 reviewed_by = ?,
                 reviewed_at = NOW(),
                 review_note = ?
             WHERE appointment_id = ?"
        )->execute([$reviewerId, $reviewNote, $id]);

        $pdo->commit();
        respond(200, ["success" => true, "message" => "Appointment rejected and slot released."]);
    }

    // ---------- MARK NO SHOW (Patient did not show up for confirmed appointment) ----------
    if ($action === "mark_no_show") {
        if ($id === "") respond(400, ["success" => false, "message" => "Missing appointment id."]);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM appointments WHERE appointment_id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $cur = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cur) {
            $pdo->rollBack();
            respond(404, ["success" => false, "message" => "Appointment not found."]);
        }
        if ($cur["appointment_status"] !== "confirmed") {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "Only confirmed appointments can be marked as No-show."]);
        }

        $now = (string)$pdo->query("SELECT NOW()")->fetchColumn();
        if ($cur["appointment_datetime"] > $now) {
            $pdo->rollBack();
            respond(400, ["success" => false, "message" => "Cannot mark No-show before scheduled appointment time."]);
        }

        $pdo->prepare(
            "UPDATE appointments SET appointment_status = 'no_show' WHERE appointment_id = ?"
        )->execute([$id]);

        $pdo->commit();
        respond(200, ["success" => true, "message" => "Appointment marked as No-Show."]);
    }

    // ---------- UPDATE (Reschedule appointment) ----------
    if ($action === "update" || $action === "edit") {
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

        // Reset response and review info when changing doctor or time
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
        respond(200, [
            "success"         => true,
            "message"         => "Appointment updated successfully." . ($backToPending ? " Appointment was returned to Pending for review." : ""),
            "reset"           => $reset,
            "back_to_pending" => $backToPending
        ]);
    }

    // ---------- CANCEL ----------
    if ($action === "cancel") {
        if ($id === "") respond(400, ["success" => false, "message" => "Missing appointment id."]);

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
    if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        respond(409, ["success" => false, "message" => "This doctor already has an appointment at that time."]);
    }
    error_log($e->getMessage());
    respond(500, ["success" => false, "message" => "Database error."]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log($e->getMessage());
    respond(500, ["success" => false, "message" => "Server error."]);
}
