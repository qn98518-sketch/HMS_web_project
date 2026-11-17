
<?php
session_start();

header("Content-Type: application/json; charset=UTF-8");

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================
// 1. CHECK SESSION
// =====================================

if (
    !isset($_SESSION["user_id"]) ||
    empty($_SESSION["user_id"])
) {
    respond(401, [
        "success" => false,
        "message" => "Please login first."
    ]);
}

if (
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "patient"
) {
    respond(403, [
        "success" => false,
        "message" => "Patient access required."
    ]);
}

// =====================================
// 2. DATABASE CONNECTION
// =====================================

$conn = mysqli_connect(
    "localhost",
    "root",
    "",
    "hospital_management_system"
);

if (!$conn) {
    respond(500, [
        "success" => false,
        "message" => "Database connection failed."
    ]);
}

mysqli_set_charset($conn, "utf8mb4");

// =====================================
// 3. GET CURRENT PATIENT ID
// =====================================

$sql = "SELECT p.patient_id
        FROM users u
        INNER JOIN patients p ON u.user_id = p.user_id
        WHERE u.user_id = ?
        AND u.role = 'patient'
        AND u.user_status = 1
        AND u.is_deleted = 0
        LIMIT 1";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    mysqli_close($conn);
    respond(500, [
        "success" => false,
        "message" => "Unable to check patient information."
    ]);
}

$userId = $_SESSION["user_id"];

mysqli_stmt_bind_param($stmt, "s", $userId);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$patient = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$patient) {
    mysqli_close($conn);

    respond(403, [
        "success" => false,
        "message" => "Patient account is unavailable."
    ]);
}

$patientId = $patient["patient_id"];

// =====================================
// 4. GET APPOINTMENT STATISTICS
// =====================================

$statsSql = "SELECT
    COUNT(*) AS total_appointments,

    COALESCE(SUM(
        CASE
            WHEN appointment_status IN ('pending', 'confirmed')
            AND appointment_datetime >= NOW()
            THEN 1 ELSE 0
        END
    ), 0) AS upcoming_appointments,

    COALESCE(SUM(
        CASE
            WHEN appointment_status = 'completed'
            THEN 1 ELSE 0
        END
    ), 0) AS completed_visits

    FROM appointments
    WHERE patient_id = ?";

$stmt = mysqli_prepare($conn, $statsSql);

if (!$stmt) {
    mysqli_close($conn);
    respond(500, [
        "success" => false,
        "message" => "Unable to load appointment statistics."
    ]);
}

mysqli_stmt_bind_param($stmt, "s", $patientId);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$stats = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

// =====================================
// 5. COUNT UNPAID BILLS
// =====================================

$billSql = "SELECT COUNT(*) AS unpaid_bills
            FROM bills
            WHERE patient_id = ?
            AND payment_status IN ('Unpaid', 'Partially Paid')";

$stmt = mysqli_prepare($conn, $billSql);

if (!$stmt) {
    mysqli_close($conn);
    respond(500, [
        "success" => false,
        "message" => "Unable to load bill statistics."
    ]);
}

mysqli_stmt_bind_param($stmt, "s", $patientId);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$billStats = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

// =====================================
// 6. GET UPCOMING APPOINTMENTS
// =====================================

$appointmentSql = "SELECT
    a.appointment_id,
    a.appointment_datetime,
    a.visit_reason,
    a.appointment_status,
    u.full_name AS doctor_name

    FROM appointments a

    INNER JOIN doctors d
        ON a.doctor_id = d.doctor_id

    INNER JOIN users u
        ON d.user_id = u.user_id

    WHERE a.patient_id = ?
    AND a.appointment_status IN ('pending', 'confirmed')
    AND a.appointment_datetime >= NOW()

    ORDER BY a.appointment_datetime ASC
    LIMIT 5";

$stmt = mysqli_prepare($conn, $appointmentSql);

if (!$stmt) {
    mysqli_close($conn);
    respond(500, [
        "success" => false,
        "message" => "Unable to load upcoming appointments."
    ]);
}

mysqli_stmt_bind_param($stmt, "s", $patientId);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$appointments = [];

while ($row = mysqli_fetch_assoc($result)) {
    $appointments[] = $row;
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

// =====================================
// 7. RETURN DASHBOARD DATA
// =====================================

respond(200, [
    "success" => true,
    "message" => "Dashboard data loaded successfully.",

    "statistics" => [
        "total_appointments" =>
            (int)$stats["total_appointments"],

        "upcoming_appointments" =>
            (int)$stats["upcoming_appointments"],

        "completed_visits" =>
            (int)$stats["completed_visits"],

        "unpaid_bills" =>
            (int)$billStats["unpaid_bills"]
    ],

    "appointments" => $appointments
]);
?>
