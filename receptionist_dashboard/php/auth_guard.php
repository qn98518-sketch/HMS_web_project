<?php
if (session_status() === PHP_SESSION_NONE) session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!defined("PK")) define("PK", "user_id");

function forceLogout(string $message, string $code): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), "", time() - 42000,
                  $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();

    http_response_code(401);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["success" => false, "code" => $code, "message" => $message]);
    exit;
}

function requireReceptionist(mysqli $conn): void {
    $uid = (string)($_SESSION["user_id"] ?? "");
    if (($_SESSION["role"] ?? "") !== "receptionist" || $uid === "") {
        forceLogout("Unauthorized access", "NOT_LOGGED_IN");
    }

    $stmt = mysqli_prepare($conn,
        "SELECT role, user_status, is_deleted FROM users WHERE " . PK . " = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $uid);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$row || (int)$row["is_deleted"] === 1) forceLogout("Your account has been deleted.", "ACCOUNT_REVOKED");
    if ((int)$row["user_status"] !== 1) forceLogout("Your account has been locked.", "ACCOUNT_REVOKED");
    if ($row["role"] !== "receptionist") forceLogout("You do not have receptionist permission.", "ACCOUNT_REVOKED");
}
