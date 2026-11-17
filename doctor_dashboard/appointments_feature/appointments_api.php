<?php
/*==============================================================
  appointments_api.php - API Lịch hẹn cho Doctor
  
  Các action:
  - GET  ?action=list        : Danh sách lịch của bác sĩ đang đăng nhập
  - GET  ?action=detail&id=  : Chi tiết một lịch (phải thuộc bác sĩ này)
  - POST action=respond      : Phản hồi lịch (accepted/rejected) - CHỈ đổi doctor_response
  - POST action=complete     : Hoàn tất lịch (confirmed + đã qua giờ + có medical record)

  Quy tắc bảo mật:
  - doctor_id lấy từ session qua requireDoctor(), KHÔNG nhận từ form
  - Doctor chỉ xem/thao tác lịch CÓ doctor_id của mình
  - respond: chỉ đổi doctor_response, KHÔNG đổi appointment_status
  - complete: đổi appointment_status = completed, phải có medical_record hợp lệ
==============================================================*/
session_start();
header("Content-Type: application/json; charset=UTF-8");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set("display_errors", "0");

if (!defined("PK")) define("PK", "user_id");

function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . "/../php/doctor_guard.php";

try {
    $conn = mysqli_connect("localhost", "root", "", "hospital_management_system");
    if (!$conn) {
        respond(500, ["success" => false, "message" => "Database connection failed."]);
    }
    mysqli_set_charset($conn, "utf8mb4");

    // Lấy doctor_id của bác sĩ đang đăng nhập (buộc phải hợp lệ)
    $myDoctorId = requireDoctor($conn);

    // Dùng PDO cho các truy vấn phức tạp
    $pdo = new PDO(
        "mysql:host=localhost;dbname=hospital_management_system;charset=utf8mb4",
        "root", "",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => false]
    );

    $action = $_POST["action"] ?? $_GET["action"] ?? "";

    // ===================== LIST =====================
    if ($action === "list") {
        // Lấy tất cả lịch của bác sĩ này (mọi trạng thái)
        $sql = "SELECT a.appointment_id, a.patient_id, a.doctor_id,
                       a.appointment_datetime, a.visit_reason,
                       a.appointment_status, a.doctor_response,
                       a.doctor_reject_reason, a.doctor_responded_at,
                       a.reviewed_by, a.reviewed_at, a.review_note,
                       a.created_at,
                       pu.full_name AS patient_name,
                       pu.username  AS patient_username,
                       ru.full_name AS reviewer_name
                FROM appointments a
                LEFT JOIN patients p  ON p.patient_id = a.patient_id
                LEFT JOIN users    pu ON pu.user_id   = p.user_id
                LEFT JOIN users    ru ON ru.user_id   = a.reviewed_by
                WHERE a.doctor_id = ?
                ORDER BY a.appointment_datetime DESC, a.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$myDoctorId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $r) {
            $s = fn($k) => (string)($r[$k] ?? "");
            $items[] = [
                "id"                   => $s("appointment_id"),
                "patient_id"           => $s("patient_id"),
                "patient_name"         => $s("patient_name"),
                "patient_username"     => $s("patient_username"),
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

        // Đếm medical records của bác sĩ này
        $stmtRec = $pdo->prepare("SELECT COUNT(*) FROM medical_records WHERE doctor_id = ?");
        $stmtRec->execute([$myDoctorId]);
        $recordsCount = (int)$stmtRec->fetchColumn();

        respond(200, [
            "success"       => true,
            "data"          => $items,
            "records_count" => $recordsCount
        ]);
    }

    // ===================== DETAIL =====================
    if ($action === "detail") {
        $id = trim((string)($_GET["id"] ?? ""));
        if ($id === "") {
            respond(400, ["success" => false, "message" => "Missing appointment id."]);
        }

        $stmt = $pdo->prepare(
            "SELECT a.*, pu.full_name AS patient_name, pu.username AS patient_username,
                    ru.full_name AS reviewer_name
             FROM appointments a
             LEFT JOIN patients p  ON p.patient_id = a.patient_id
             LEFT JOIN users    pu ON pu.user_id   = p.user_id
             LEFT JOIN users    ru ON ru.user_id   = a.reviewed_by
             WHERE a.appointment_id = ? AND a.doctor_id = ? LIMIT 1");
        $stmt->execute([$id, $myDoctorId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$r) {
            respond(404, ["success" => false, "message" => "Appointment not found."]);
        }

        // Kiểm tra có medical record chưa
        $stmtRec = $pdo->prepare(
            "SELECT record_id, diagnosis, symptoms, treatment, notes, record_date
             FROM medical_records
             WHERE appointment_id = ? AND doctor_id = ? LIMIT 1");
        $stmtRec->execute([$id, $myDoctorId]);
        $record = $stmtRec->fetch(PDO::FETCH_ASSOC);

        $s = fn($k) => (string)($r[$k] ?? "");
        respond(200, [
            "success" => true,
            "data"    => [
                "id"                   => $s("appointment_id"),
                "patient_id"           => $s("patient_id"),
                "patient_name"         => $s("patient_name"),
                "patient_username"     => $s("patient_username"),
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
                "medical_record"       => $record ?: null,
            ]
        ]);
    }

    // Các action còn lại yêu cầu POST
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        respond(405, ["success" => false, "message" => "Method not allowed."]);
    }

    $id = trim((string)($_POST["id"] ?? ""));

    // ===================== RESPOND =====================
    // Bác sĩ chấp nhận hoặc từ chối lịch hẹn
    if ($action === "respond") {
        $decision     = trim((string)($_POST["decision"] ?? ""));      // "accepted" hoặc "rejected"
        $rejectReason = trim((string)($_POST["reject_reason"] ?? ""));

        if ($id === "") {
            respond(400, ["success" => false, "message" => "Missing appointment id."]);
        }
        if (!in_array($decision, ["accepted", "rejected"], true)) {
            respond(400, ["success" => false, "message" => "Invalid decision. Must be 'accepted' or 'rejected'."]);
        }
        if ($decision === "rejected" && $rejectReason === "") {
            respond(400, ["success" => false, "message" => "Please provide a reason for rejecting."]);
        }

        $pdo->beginTransaction();

        // Khóa dòng để tránh race condition
        $stmt = $pdo->prepare(
            "SELECT appointment_id, doctor_id, appointment_status, doctor_response, appointment_datetime
             FROM appointments WHERE appointment_id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $cur = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cur) {
            $pdo->rollBack();
            respond(404, ["success" => false, "message" => "Appointment not found."]);
        }
        // Chỉ được phản hồi lịch của chính mình
        if ($cur["doctor_id"] !== $myDoctorId) {
            $pdo->rollBack();
            respond(403, ["success" => false, "message" => "This appointment does not belong to you."]);
        }
        // Chỉ phản hồi khi lịch đang Pending
        if ($cur["appointment_status"] !== "pending") {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "You can only respond to pending appointments."]);
        }
        // Chỉ phản hồi khi chưa có phản hồi
        if ($cur["doctor_response"] !== "pending") {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "You have already responded to this appointment."]);
        }
        // Phải còn trong tương lai
        $now = (string)$pdo->query("SELECT NOW()")->fetchColumn();
        if ($cur["appointment_datetime"] <= $now) {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "The appointment time has already passed."]);
        }

        // Chỉ cập nhật doctor_response - KHÔNG đổi appointment_status (lễ tân mới quyết định)
        if ($decision === "accepted") {
            $pdo->prepare(
                "UPDATE appointments
                 SET doctor_response = 'accepted',
                     doctor_reject_reason = NULL,
                     doctor_responded_at = NOW()
                 WHERE appointment_id = ?"
            )->execute([$id]);
            $msg = "Appointment accepted. Waiting for reception to confirm.";
        } else {
            $pdo->prepare(
                "UPDATE appointments
                 SET doctor_response = 'rejected',
                     doctor_reject_reason = ?,
                     doctor_responded_at = NOW()
                 WHERE appointment_id = ?"
            )->execute([$rejectReason, $id]);
            $msg = "Appointment rejected. The appointment is still pending reception decision.";
        }

        $pdo->commit();
        respond(200, ["success" => true, "message" => $msg]);
    }

    // ===================== COMPLETE =====================
    // Hoàn tất lịch khám (chỉ khi confirmed và đã đến giờ)
    if ($action === "complete") {
        if ($id === "") {
            respond(400, ["success" => false, "message" => "Missing appointment id."]);
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT appointment_id, doctor_id, patient_id, appointment_status, appointment_datetime
             FROM appointments WHERE appointment_id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $cur = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cur) {
            $pdo->rollBack();
            respond(404, ["success" => false, "message" => "Appointment not found."]);
        }
        if ($cur["doctor_id"] !== $myDoctorId) {
            $pdo->rollBack();
            respond(403, ["success" => false, "message" => "This appointment does not belong to you."]);
        }
        if ($cur["appointment_status"] !== "confirmed") {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "Only confirmed appointments can be marked as completed."]);
        }

        // Phải đã đến giờ hẹn
        $now = (string)$pdo->query("SELECT NOW()")->fetchColumn();
        if ($cur["appointment_datetime"] > $now) {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "The appointment time has not arrived yet."]);
        }

        // Phải có medical record hợp lệ gắn với lịch này
        $stmtRec = $pdo->prepare(
            "SELECT record_id, diagnosis FROM medical_records
             WHERE appointment_id = ? AND doctor_id = ? AND patient_id = ? LIMIT 1");
        $stmtRec->execute([$id, $myDoctorId, $cur["patient_id"]]);
        $record = $stmtRec->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            $pdo->rollBack();
            respond(409, ["success" => false,
                "message" => "Please create a medical record for this appointment before completing it."]);
        }
        if (trim((string)($record["diagnosis"] ?? "")) === "") {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "Medical record must have a diagnosis."]);
        }

        // Hoàn tất lịch
        $pdo->prepare(
            "UPDATE appointments SET appointment_status = 'completed' WHERE appointment_id = ?"
        )->execute([$id]);

        $pdo->commit();
        respond(200, ["success" => true, "message" => "Appointment marked as completed."]);
    }

    respond(400, ["success" => false, "message" => "Unknown action."]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Doctor appointments_api PDO error: " . $e->getMessage());
    respond(500, ["success" => false, "message" => "Database error."]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Doctor appointments_api error: " . $e->getMessage());
    respond(500, ["success" => false, "message" => "Server error."]);
}
