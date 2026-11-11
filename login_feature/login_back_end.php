<?php
session_start();

header("Content-Type: application/json; charset=UTF-8");

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(405, [
        "success" => false,
        "message" => "Method not allowed."
    ]);
}

$inputUsername = trim($_POST["name"] ?? "");
$inputPassword = $_POST["password"] ?? "";

if ($inputUsername === "" || $inputPassword === "") {
    respond(400, [
        "success" => false,
        "message" => "Please enter your username and password."
    ]);
}

$conn = mysqli_connect(
    "localhost",
    "root",
    "",
    "hospital_management_system"
);

if (!$conn) {
    respond(500, [
        "success" => false,
        "message" => "A server error occurred. Please try again later."
    ]);
}

$sql = "SELECT user_id AS uid, username, user_password, role, user_status
        FROM users
        WHERE username = ?";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    mysqli_close($conn);

    respond(500, [
        "success" => false,
        "message" => "A server error occurred. Please try again later."
    ]);
}

mysqli_stmt_bind_param($stmt, "s", $inputUsername);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

if ($row && $row["user_status"] !== 1) {
    mysqli_stmt_close($stmt);
    mysqli_close($conn);

    respond(403, [
        "success" => false,
        "message" => "Your account is locked. Please contact the administrator."
    ]);
    exit;
}

if ($row && password_verify(
    $inputPassword,
    $row["user_password"]
)) {
    session_regenerate_id(true);

    $_SESSION["user_id"] = (string)$row["uid"]; 
    $_SESSION["username"] = $row["username"];
    $_SESSION["role"] = $row["role"];

    mysqli_stmt_close($stmt);
    mysqli_close($conn);

    if ($row["role"] === "admin") {
        respond(200, [
            "success" => true,
            "message" => "Login successful! Redirecting to admin dashboard...",
            "redirect" => "../admin_form/homepage.html"
            // "redirect" => "../admin_form/php/check_auth.php"
        ]);
    } elseif ($row["role"] === "doctor") {
        respond(200, [
            "success" => true,
            "message" => "Login successful! Redirecting to doctor dashboard...",
            "redirect" => "../doctor_dashboard/doctor_dashboard.html"
        ]);
    } elseif ($row["role"] === "patient") {
        respond(200, [
            "success" => true,
            "message" => "Login successful! Redirecting to patient dashboard...",
            "redirect" => "../patient_dashboard/patient_dashboard.html"
        ]);
    }
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

respond(401, [
    "success" => false,
    "message" => "Invalid username or password!"
]);
?>
