<?php
/*==============================================================
  treatment_api.php - API Bệnh án, Đơn thuốc, Xét nghiệm cho Doctor
  
  Các action:
  - GET  ?action=list              : Danh sách bệnh án của bác sĩ này
  - GET  ?action=detail&id=        : Chi tiết bệnh án (record_id)
  - POST action=create_record      : Tạo bệnh án mới
  - POST action=update_record      : Cập nhật bệnh án
  - POST action=add_prescription   : Thêm đơn thuốc cho bệnh án
  - POST action=add_test           : Thêm kết quả xét nghiệm
  - POST action=update_test_status : Cập nhật trạng thái xét nghiệm

  Giới hạn:
  - doctor_id lấy từ session - KHÔNG nhận từ form
  - Chỉ thao tác bệnh án của chính mình
  - Chỉ tạo bệnh án cho lịch Confirmed thuộc bác sĩ này
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

function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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

    // ===================== LIST Medical Records =====================
    if ($action === "list") {
        $stmt = $pdo->prepare(
            "SELECT mr.record_id, mr.patient_id, mr.appointment_id,
                    mr.diagnosis, mr.symptoms, mr.treatment, mr.notes, mr.record_date,
                    u.full_name AS patient_name,
                    a.appointment_datetime,
                    (SELECT COUNT(*) FROM prescriptions px WHERE px.record_id = mr.record_id) AS rx_count,
                    (SELECT COUNT(*) FROM test_results tr WHERE tr.record_id = mr.record_id) AS test_count
             FROM medical_records mr
             LEFT JOIN patients p ON p.patient_id = mr.patient_id
             LEFT JOIN users    u ON u.user_id = p.user_id
             LEFT JOIN appointments a ON a.appointment_id = mr.appointment_id
             WHERE mr.doctor_id = ?
             ORDER BY mr.record_date DESC");
        $stmt->execute([$myDoctorId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Danh sách bệnh nhân đã có lịch Confirmed/Completed để tạo bệnh án mới
        $stmtPat = $pdo->prepare(
            "SELECT DISTINCT p.patient_id, u.full_name, a.appointment_id, a.appointment_datetime
             FROM appointments a
             JOIN patients p ON p.patient_id = a.patient_id
             LEFT JOIN users u ON u.user_id = p.user_id
             WHERE a.doctor_id = ? AND a.appointment_status = 'confirmed'
             ORDER BY a.appointment_datetime DESC");
        $stmtPat->execute([$myDoctorId]);
        $confirmedAppts = $stmtPat->fetchAll(PDO::FETCH_ASSOC);

        respond(200, [
            "success"          => true,
            "data"             => $rows,
            "confirmed_appts"  => $confirmedAppts,
        ]);
    }

    // ===================== DETAIL =====================
    if ($action === "detail") {
        $recordId = trim((string)($_GET["id"] ?? ""));
        if ($recordId === "") {
            respond(400, ["success" => false, "message" => "Missing record id."]);
        }

        // Chỉ xem bệnh án của chính mình
        $stmt = $pdo->prepare(
            "SELECT mr.*, u.full_name AS patient_name, a.appointment_datetime
             FROM medical_records mr
             LEFT JOIN patients p ON p.patient_id = mr.patient_id
             LEFT JOIN users    u ON u.user_id = p.user_id
             LEFT JOIN appointments a ON a.appointment_id = mr.appointment_id
             WHERE mr.record_id = ? AND mr.doctor_id = ? LIMIT 1");
        $stmt->execute([$recordId, $myDoctorId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            respond(404, ["success" => false, "message" => "Medical record not found."]);
        }

        // Đơn thuốc
        $stmtRx = $pdo->prepare(
            "SELECT px.prescription_id, px.PrescriptionDate, px.Notes,
                    pd.prescription_detailID, pd.medicine_name, pd.dosage,
                    pd.frequency, pd.duration, pd.quantity, pd.instructions
             FROM prescriptions px
             JOIN prescription_details pd ON pd.prescription_id = px.prescription_id
             WHERE px.record_id = ? AND px.doctor_id = ?
             ORDER BY px.PrescriptionDate DESC");
        $stmtRx->execute([$recordId, $myDoctorId]);
        $prescriptions = $stmtRx->fetchAll(PDO::FETCH_ASSOC);

        // Xét nghiệm
        $stmtTest = $pdo->prepare(
            "SELECT test_result_id, test_name, test_date, result, reference_range, Status, notes
             FROM test_results
             WHERE record_id = ? AND doctor_id = ?
             ORDER BY test_date DESC");
        $stmtTest->execute([$recordId, $myDoctorId]);
        $tests = $stmtTest->fetchAll(PDO::FETCH_ASSOC);

        respond(200, [
            "success"       => true,
            "data"          => $record,
            "prescriptions" => $prescriptions,
            "tests"         => $tests,
        ]);
    }

    // POST actions
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        respond(405, ["success" => false, "message" => "Method not allowed."]);
    }

    // ===================== CREATE MEDICAL RECORD =====================
    if ($action === "create_record") {
        $appointmentId = trim((string)($_POST["appointment_id"] ?? ""));
        $diagnosis     = trim((string)($_POST["diagnosis"] ?? ""));
        $symptoms      = trim((string)($_POST["symptoms"] ?? ""));
        $treatment     = trim((string)($_POST["treatment"] ?? ""));
        $notes         = trim((string)($_POST["notes"] ?? ""));

        if ($appointmentId === "") {
            respond(400, ["success" => false, "message" => "Please select an appointment."]);
        }
        if ($diagnosis === "") {
            respond(400, ["success" => false, "message" => "Diagnosis is required."]);
        }

        // Kiểm tra lịch hẹn thuộc bác sĩ này và đang Confirmed
        $stmtAppt = $pdo->prepare(
            "SELECT appointment_id, patient_id, appointment_status
             FROM appointments
             WHERE appointment_id = ? AND doctor_id = ? LIMIT 1");
        $stmtAppt->execute([$appointmentId, $myDoctorId]);
        $appt = $stmtAppt->fetch(PDO::FETCH_ASSOC);

        if (!$appt) {
            respond(404, ["success" => false, "message" => "Appointment not found."]);
        }
        if ($appt["appointment_status"] !== "confirmed") {
            respond(409, ["success" => false,
                "message" => "Medical records can only be created for confirmed appointments."]);
        }

        // Kiểm tra bệnh án đã tồn tại chưa
        $stmtCheck = $pdo->prepare(
            "SELECT 1 FROM medical_records WHERE appointment_id = ? AND doctor_id = ? LIMIT 1");
        $stmtCheck->execute([$appointmentId, $myDoctorId]);
        if ($stmtCheck->fetchColumn()) {
            respond(409, ["success" => false,
                "message" => "A medical record already exists for this appointment."]);
        }

        $recordId = uuidv4();
        $pdo->prepare(
            "INSERT INTO medical_records (record_id, patient_id, doctor_id, appointment_id,
                                          diagnosis, symptoms, treatment, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $recordId,
            $appt["patient_id"],
            $myDoctorId,
            $appointmentId,
            $diagnosis,
            $symptoms === "" ? null : $symptoms,
            $treatment === "" ? null : $treatment,
            $notes === "" ? null : $notes,
        ]);

        respond(200, ["success" => true, "message" => "Medical record created.", "record_id" => $recordId]);
    }

    // ===================== UPDATE MEDICAL RECORD =====================
    if ($action === "update_record") {
        $recordId  = trim((string)($_POST["record_id"] ?? ""));
        $diagnosis = trim((string)($_POST["diagnosis"] ?? ""));
        $symptoms  = trim((string)($_POST["symptoms"] ?? ""));
        $treatment = trim((string)($_POST["treatment"] ?? ""));
        $notes     = trim((string)($_POST["notes"] ?? ""));

        if ($recordId === "" || $diagnosis === "") {
            respond(400, ["success" => false, "message" => "Record ID and diagnosis are required."]);
        }

        // Chỉ sửa bệnh án của chính mình
        $stmtCheck = $pdo->prepare(
            "SELECT 1 FROM medical_records WHERE record_id = ? AND doctor_id = ? LIMIT 1");
        $stmtCheck->execute([$recordId, $myDoctorId]);
        if (!$stmtCheck->fetchColumn()) {
            respond(404, ["success" => false, "message" => "Medical record not found."]);
        }

        $pdo->prepare(
            "UPDATE medical_records SET diagnosis = ?, symptoms = ?, treatment = ?, notes = ?
             WHERE record_id = ? AND doctor_id = ?"
        )->execute([
            $diagnosis,
            $symptoms === "" ? null : $symptoms,
            $treatment === "" ? null : $treatment,
            $notes === "" ? null : $notes,
            $recordId,
            $myDoctorId,
        ]);

        respond(200, ["success" => true, "message" => "Medical record updated."]);
    }

    // ===================== ADD PRESCRIPTION =====================
    if ($action === "add_prescription") {
        $recordId     = trim((string)($_POST["record_id"] ?? ""));
        $medicineName = trim((string)($_POST["medicine_name"] ?? ""));
        $dosage       = trim((string)($_POST["dosage"] ?? ""));
        $frequency    = trim((string)($_POST["frequency"] ?? ""));
        $duration     = trim((string)($_POST["duration"] ?? ""));
        $quantity     = (int)($_POST["quantity"] ?? 0);
        $instructions = trim((string)($_POST["instructions"] ?? ""));
        $rxNotes      = trim((string)($_POST["rx_notes"] ?? ""));

        if ($recordId === "" || $medicineName === "") {
            respond(400, ["success" => false, "message" => "Record ID and medicine name are required."]);
        }
        if ($quantity <= 0) {
            respond(400, ["success" => false, "message" => "Quantity must be greater than 0."]);
        }

        // Kiểm tra bệnh án thuộc bác sĩ này
        $stmtCheck = $pdo->prepare(
            "SELECT patient_id FROM medical_records WHERE record_id = ? AND doctor_id = ? LIMIT 1");
        $stmtCheck->execute([$recordId, $myDoctorId]);
        $rec = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$rec) {
            respond(404, ["success" => false, "message" => "Medical record not found."]);
        }

        $pdo->beginTransaction();

        // Tạo prescription header
        $rxId = uuidv4();
        $pdo->prepare(
            "INSERT INTO prescriptions (prescription_id, record_id, patient_id, doctor_id, Notes)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$rxId, $recordId, $rec["patient_id"], $myDoctorId,
                    $rxNotes === "" ? null : $rxNotes]);

        // Tạo prescription detail
        $pdo->prepare(
            "INSERT INTO prescription_details
                (prescription_detailID, prescription_id, medicine_name, dosage,
                 frequency, duration, quantity, instructions)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            uuidv4(), $rxId, $medicineName,
            $dosage === "" ? null : $dosage,
            $frequency === "" ? null : $frequency,
            $duration === "" ? null : $duration,
            $quantity,
            $instructions === "" ? null : $instructions,
        ]);

        $pdo->commit();
        respond(200, ["success" => true, "message" => "Prescription added."]);
    }

    // ===================== ADD TEST RESULT =====================
    if ($action === "add_test") {
        $recordId       = trim((string)($_POST["record_id"] ?? ""));
        $testName       = trim((string)($_POST["test_name"] ?? ""));
        $result         = trim((string)($_POST["result"] ?? ""));
        $referenceRange = trim((string)($_POST["reference_range"] ?? ""));
        $testNotes      = trim((string)($_POST["test_notes"] ?? ""));

        if ($recordId === "" || $testName === "") {
            respond(400, ["success" => false, "message" => "Record ID and test name are required."]);
        }

        // Kiểm tra bệnh án thuộc bác sĩ này
        $stmtCheck = $pdo->prepare(
            "SELECT patient_id FROM medical_records WHERE record_id = ? AND doctor_id = ? LIMIT 1");
        $stmtCheck->execute([$recordId, $myDoctorId]);
        $rec = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$rec) {
            respond(404, ["success" => false, "message" => "Medical record not found."]);
        }

        $status = $result === "" ? "Pending" : "Completed";

        $pdo->prepare(
            "INSERT INTO test_results (test_result_id, patient_id, doctor_id, record_id,
                                       test_name, result, reference_range, Status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            uuidv4(),
            $rec["patient_id"],
            $myDoctorId,
            $recordId,
            $testName,
            $result === "" ? null : $result,
            $referenceRange === "" ? null : $referenceRange,
            $status,
            $testNotes === "" ? null : $testNotes,
        ]);

        respond(200, ["success" => true, "message" => "Test result added."]);
    }

    // ===================== UPDATE TEST STATUS =====================
    if ($action === "update_test_status") {
        $testId = trim((string)($_POST["test_id"] ?? ""));
        $status = trim((string)($_POST["status"] ?? ""));

        if ($testId === "") {
            respond(400, ["success" => false, "message" => "Missing test id."]);
        }
        if (!in_array($status, ["Pending", "Completed", "Cancelled"], true)) {
            respond(400, ["success" => false, "message" => "Invalid status."]);
        }

        // Chỉ sửa xét nghiệm của chính mình
        $stmtCheck = $pdo->prepare(
            "SELECT 1 FROM test_results WHERE test_result_id = ? AND doctor_id = ? LIMIT 1");
        $stmtCheck->execute([$testId, $myDoctorId]);
        if (!$stmtCheck->fetchColumn()) {
            respond(404, ["success" => false, "message" => "Test result not found."]);
        }

        $pdo->prepare(
            "UPDATE test_results SET Status = ? WHERE test_result_id = ? AND doctor_id = ?"
        )->execute([$status, $testId, $myDoctorId]);

        respond(200, ["success" => true, "message" => "Test status updated."]);
    }

    respond(400, ["success" => false, "message" => "Unknown action."]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Doctor treatment_api PDO error: " . $e->getMessage());
    respond(500, ["success" => false, "message" => "Database error."]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Doctor treatment_api error: " . $e->getMessage());
    respond(500, ["success" => false, "message" => "Server error."]);
}
