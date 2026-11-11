<?php
require_once __DIR__ . "/auth_guard.php";
header("Content-Type: application/json; charset=UTF-8");

try {
    $conn = mysqli_connect("localhost", "root", "", "hospital_management_system");
    requireAdmin($conn);
    mysqli_close($conn);

    echo json_encode(["success" => true, "message" => "Authorized"]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error."]);
}