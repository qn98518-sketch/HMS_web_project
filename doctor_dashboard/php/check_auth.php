<?php
/*==============================================================
  check_auth.php - Kiểm tra xác thực cho Doctor dashboard
  Trả JSON {success: true} nếu đang đăng nhập là Doctor hợp lệ
  Frontend auth.js gọi mỗi 5 giây để kiểm tra session
==============================================================*/
session_start();
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set("display_errors", "0");

if (!defined("PK")) define("PK", "user_id");

function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . "/doctor_guard.php";

try {
    $conn = mysqli_connect("localhost", "root", "", "hospital_management_system");
    if (!$conn) {
        respond(500, ["success" => false, "message" => "Database connection failed."]);
    }
    mysqli_set_charset($conn, "utf8mb4");

    // requireDoctor sẽ tự dừng nếu không hợp lệ
    requireDoctor($conn);
    mysqli_close($conn);

    respond(200, ["success" => true]);
} catch (Throwable $e) {
    error_log($e->getMessage());
    respond(500, ["success" => false, "message" => "Server error."]);
}
