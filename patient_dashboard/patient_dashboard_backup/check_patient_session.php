
<?php
session_start();

header("Content-Type: application/json; charset=UTF-8");

// =====================================
// FUNCTION: RETURN JSON RESPONSE
// =====================================

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================
// 1. CHECK LOGIN SESSION
// =====================================

if (!isset($_SESSION["user_id"]) || empty($_SESSION["user_id"])) {
    respond(401, [
        "success" => false,
        "message" => "Please login first."
    ]);
}

// =====================================
// 2. CHECK PATIENT ROLE
// =====================================

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "patient") {
    respond(403, [
        "success" => false,
        "message" => "Access denied. Patient account required."
    ]);
}

// =====================================
// 3. CONNECT TO DATABASE
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
// 4. GET PATIENT INFORMATION
// =====================================

$sql = "SELECT
            u.user_id,
            u.username,
            u.full_name,
            u.email,
            u.phone_number,
            u.avatar,
            u.role,
            u.user_status,
            u.is_deleted,

            p.patient_id,
            p.dob,
            p.gender,
            p.address

        FROM users u

        INNER JOIN patients p
            ON u.user_id = p.user_id

        WHERE u.user_id = ?
        LIMIT 1";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    mysqli_close($conn);

    respond(500, [
        "success" => false,
        "message" => "Database query failed."
    ]);
}

$userId = $_SESSION["user_id"];

mysqli_stmt_bind_param($stmt, "s", $userId);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$patient = mysqli_fetch_assoc($result);

// =====================================
// 5. CHECK ACCOUNT STATUS
// =====================================

if (
    !$patient ||
    $patient["role"] !== "patient" ||
    (int)$patient["user_status"] !== 1 ||
    (int)$patient["is_deleted"] !== 0
) {
    mysqli_stmt_close($stmt);
    mysqli_close($conn);

    respond(403, [
        "success" => false,
        "message" => "Your patient account is unavailable."
    ]);
}

// =====================================
// 6. RETURN PATIENT INFORMATION
// =====================================

// Do not return password or sensitive account data.

respond(200, [
    "success" => true,
    "message" => "Patient information loaded successfully.",
    "user" => [
        "user_id" => $patient["user_id"],
        "username" => $patient["username"],
        "full_name" => $patient["full_name"],
        "email" => $patient["email"],
        "phone_number" => $patient["phone_number"],
        "avatar" => $patient["avatar"]
    ],
    "patient" => [
        "patient_id" => $patient["patient_id"],
        "dob" => $patient["dob"],
        "gender" => $patient["gender"],
        "address" => $patient["address"]
    ]
]);

mysqli_stmt_close($stmt);
mysqli_close($conn);
?>
