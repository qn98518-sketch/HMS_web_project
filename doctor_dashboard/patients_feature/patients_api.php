<?php
/*==============================================================
  patients_api.php - API Bệnh nhân cho Doctor
  
  Các action:
  - GET  ?action=list        : Danh sách bệnh nhân đã có lịch Confirmed/Completed với bác sĩ này
  - GET  ?action=detail&id=  : Chi tiết bệnh nhân (patient_id) - chỉ bệnh nhân trong phạm vi
  - POST action=update_history : Cập nhật medical_history của bệnh nhân trong phạm vi điều trị

  Giới hạn:
  - Chỉ thấy bệnh nhân đã có lịch Confirmed hoặc Completed với chính mình
  - Khi lịch chỉ Pending: trả thông tin cơ bản (tên, giờ hẹn, lý do) để đánh giá
  - Không liệt kê toàn bộ bệnh nhân bệnh viện
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

    $myDoctorId = requireDoctor($conn);

    $pdo = new PDO(
        "mysql:host=localhost;dbname=hospital_management_system;charset=utf8mb4",
        "root", "",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => false]
    );

    $action = $_POST["action"] ?? $_GET["action"] ?? "";

    // ===================== LIST =====================
    if ($action === "list") {
        // Bệnh nhân đã có lịch Confirmed hoặc Completed với bác sĩ này
        $stmt = $pdo->prepare(
            "SELECT DISTINCT p.patient_id, u.full_name, u.username, u.email, u.phone_number,
                    p.dob, p.gender, p.address, p.medical_history, p.created_at,
                    COUNT(a.appointment_id) AS appointment_count,
                    MAX(a.appointment_datetime) AS last_appointment
             FROM patients p
             LEFT JOIN users u ON u.user_id = p.user_id
             JOIN appointments a ON a.patient_id = p.patient_id
             WHERE a.doctor_id = ? AND a.appointment_status IN ('confirmed', 'completed')
             GROUP BY p.patient_id, u.full_name, u.username, u.email, u.phone_number,
                      p.dob, p.gender, p.address, p.medical_history, p.created_at
             ORDER BY last_appointment DESC");
        $stmt->execute([$myDoctorId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $patients = array_map(fn($r) => [
            "patient_id"        => (string)($r["patient_id"] ?? ""),
            "full_name"         => (string)($r["full_name"] ?? ""),
            "username"          => (string)($r["username"] ?? ""),
            "email"             => (string)($r["email"] ?? ""),
            "phone"             => (string)($r["phone_number"] ?? ""),
            "dob"               => (string)($r["dob"] ?? ""),
            "gender"            => (string)($r["gender"] ?? ""),
            "address"           => (string)($r["address"] ?? ""),
            "medical_history"   => (string)($r["medical_history"] ?? ""),
            "appointment_count" => (int)($r["appointment_count"] ?? 0),
            "last_appointment"  => (string)($r["last_appointment"] ?? ""),
        ], $rows);

        respond(200, ["success" => true, "data" => $patients]);
    }

    // ===================== DETAIL =====================
    if ($action === "detail") {
        $patientId = trim((string)($_GET["id"] ?? ""));
        if ($patientId === "") {
            respond(400, ["success" => false, "message" => "Missing patient id."]);
        }

        // Chỉ được xem chi tiết bệnh nhân đã có lịch Confirmed/Completed với mình
        $stmtCheck = $pdo->prepare(
            "SELECT 1 FROM appointments
             WHERE doctor_id = ? AND patient_id = ?
               AND appointment_status IN ('confirmed', 'completed') LIMIT 1");
        $stmtCheck->execute([$myDoctorId, $patientId]);
        if (!$stmtCheck->fetchColumn()) {
            respond(403, ["success" => false,
                "message" => "You can only view patients who have confirmed or completed appointments with you."]);
        }

        // Lấy thông tin bệnh nhân
        $stmt = $pdo->prepare(
            "SELECT p.patient_id, u.full_name, u.username, u.email, u.phone_number,
                    p.dob, p.gender, p.address, p.medical_history, p.created_at
             FROM patients p
             LEFT JOIN users u ON u.user_id = p.user_id
             WHERE p.patient_id = ? LIMIT 1");
        $stmt->execute([$patientId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$p) {
            respond(404, ["success" => false, "message" => "Patient not found."]);
        }

        // Lịch sử lịch hẹn của bệnh nhân này với bác sĩ này
        $stmtAppt = $pdo->prepare(
            "SELECT appointment_id, appointment_datetime, visit_reason,
                    appointment_status, doctor_response
             FROM appointments
             WHERE doctor_id = ? AND patient_id = ?
             ORDER BY appointment_datetime DESC");
        $stmtAppt->execute([$myDoctorId, $patientId]);
        $appts = $stmtAppt->fetchAll(PDO::FETCH_ASSOC);

        respond(200, [
            "success" => true,
            "data"    => [
                "patient_id"      => (string)($p["patient_id"] ?? ""),
                "full_name"       => (string)($p["full_name"] ?? ""),
                "username"        => (string)($p["username"] ?? ""),
                "email"           => (string)($p["email"] ?? ""),
                "phone"           => (string)($p["phone_number"] ?? ""),
                "dob"             => (string)($p["dob"] ?? ""),
                "gender"          => (string)($p["gender"] ?? ""),
                "address"         => (string)($p["address"] ?? ""),
                "medical_history" => (string)($p["medical_history"] ?? ""),
            ],
            "appointments" => $appts,
        ]);
    }

    // Các action POST
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        respond(405, ["success" => false, "message" => "Method not allowed."]);
    }

    // ===================== UPDATE MEDICAL HISTORY =====================
    if ($action === "update_history") {
        $patientId      = trim((string)($_POST["patient_id"] ?? ""));
        $medicalHistory = trim((string)($_POST["medical_history"] ?? ""));

        if ($patientId === "") {
            respond(400, ["success" => false, "message" => "Missing patient id."]);
        }

        // Chỉ cập nhật medical_history của bệnh nhân đã có lịch Confirmed/Completed với mình
        $stmtCheck = $pdo->prepare(
            "SELECT 1 FROM appointments
             WHERE doctor_id = ? AND patient_id = ?
               AND appointment_status IN ('confirmed', 'completed') LIMIT 1");
        $stmtCheck->execute([$myDoctorId, $patientId]);
        if (!$stmtCheck->fetchColumn()) {
            respond(403, ["success" => false,
                "message" => "You can only update medical history for patients with confirmed or completed appointments."]);
        }

        $pdo->prepare("UPDATE patients SET medical_history = ? WHERE patient_id = ?")
            ->execute([$medicalHistory === "" ? null : $medicalHistory, $patientId]);

        respond(200, ["success" => true, "message" => "Medical history updated."]);
    }

    respond(400, ["success" => false, "message" => "Unknown action."]);

} catch (PDOException $e) {
    error_log("Doctor patients_api PDO error: " . $e->getMessage());
    respond(500, ["success" => false, "message" => "Database error."]);
} catch (Throwable $e) {
    error_log("Doctor patients_api error: " . $e->getMessage());
    respond(500, ["success" => false, "message" => "Server error."]);
}
