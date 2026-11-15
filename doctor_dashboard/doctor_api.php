<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set("display_errors", "0");

function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$uid = (string)($_SESSION["user_id"] ?? "");
$role = (string)($_SESSION["role"] ?? "");

if ($uid === "" || $role !== "doctor") {
    respond(401, ["success" => false, "message" => "Unauthorized access. Please log in as a Doctor."]);
}

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=hospital_management_system;charset=utf8mb4",
        "root", "",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );

    // Get doctor_id
    $stmt = $pdo->prepare("SELECT doctor_id, specialization FROM doctors WHERE user_id = ? AND status = 1");
    $stmt->execute([$uid]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        respond(403, ["success" => false, "message" => "Doctor profile not found."]);
    }

    $doctorId = $doc["doctor_id"];
    $action = $_POST["action"] ?? $_GET["action"] ?? "";

    // ---------- LIST DOCTOR'S APPOINTMENTS ----------
    if ($action === "list") {
        $stmt = $pdo->prepare(
            "SELECT a.appointment_id, a.appointment_datetime, a.visit_reason, a.appointment_status,
                    a.doctor_response, a.doctor_reject_reason, a.doctor_responded_at,
                    a.reviewed_by, a.reviewed_at, a.review_note,
                    pu.full_name AS patient_name, pu.phone_number AS patient_phone
             FROM appointments a
             JOIN patients p ON p.patient_id = a.patient_id
             JOIN users pu ON pu.user_id = p.user_id
             WHERE a.doctor_id = ?
             ORDER BY a.appointment_datetime ASC"
        );
        $stmt->execute([$doctorId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        respond(200, [
            "success"  => true,
            "data"     => $rows,
            "doctor"   => [
                "name"           => $_SESSION["username"] ?? "Doctor",
                "specialization" => $doc["specialization"]
            ]
        ]);
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        respond(405, ["success" => false, "message" => "Method not allowed."]);
    }

    // ---------- RESPOND (ACCEPT / REJECT) ----------
    if ($action === "respond") {
        $appId = trim((string)($_POST["id"] ?? ""));
        $decision = trim((string)($_POST["decision"] ?? ""));
        $rejectReason = trim((string)($_POST["reject_reason"] ?? ""));

        if (!in_array($decision, ["accepted", "rejected"], true)) {
            respond(400, ["success" => false, "message" => "Decision must be accepted or rejected."]);
        }
        if ($decision === "rejected" && $rejectReason === "") {
            respond(400, ["success" => false, "message" => "Please provide a rejection reason."]);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT appointment_id, appointment_status, doctor_response
             FROM appointments
             WHERE appointment_id = ? AND doctor_id = ? FOR UPDATE"
        );
        $stmt->execute([$appId, $doctorId]);
        $cur = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cur) {
            $pdo->rollBack();
            respond(404, ["success" => false, "message" => "Appointment not found or not assigned to you."]);
        }
        if ($cur["appointment_status"] !== "pending") {
            $pdo->rollBack();
            respond(409, ["success" => false, "message" => "Can only respond to pending appointments."]);
        }

        $pdo->prepare(
            "UPDATE appointments
             SET doctor_response = ?,
                 doctor_reject_reason = ?,
                 doctor_responded_at = NOW()
             WHERE appointment_id = ?"
        )->execute([
            $decision,
            $decision === "rejected" ? $rejectReason : null,
            $appId
        ]);

        $pdo->commit();
        respond(200, [
            "success" => true,
            "message" => "Response submitted: " . ucfirst($decision) . ". The appointment remains Pending until reviewed by Receptionist."
        ]);
    }

    respond(400, ["success" => false, "message" => "Unknown action."]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    respond(500, ["success" => false, "message" => "Server error: " . $e->getMessage()]);
}
