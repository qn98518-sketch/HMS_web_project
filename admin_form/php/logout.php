<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit;
}
// Chặn trang web khác ép người dùng đăng xuất: trình duyệt không cho form/ảnh từ trang khác tự thêm header này
if (($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") !== "XMLHttpRequest") {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden."]);
    exit;
}

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), "", time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
}
session_destroy();

echo json_encode(["success" => true, "message" => "Logged out."]);
