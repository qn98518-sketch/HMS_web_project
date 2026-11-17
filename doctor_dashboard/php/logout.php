<?php
/*==============================================================
  logout.php - Đăng xuất Doctor
==============================================================*/
session_start();
header("Content-Type: application/json; charset=UTF-8");

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), "", time() - 42000,
              $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
}
session_destroy();

http_response_code(200);
echo json_encode(["success" => true, "message" => "Logged out."]);
