<?php
/*==============================================================
  doctor_guard.php - Xác thực bác sĩ đang đăng nhập
  Dùng cho mọi API trong doctor_dashboard/
  
  Hàm chính: requireDoctor($conn) → trả doctor_id của bác sĩ
  - Kiểm tra session role = 'doctor'
  - Kiểm tra tài khoản vẫn còn hoạt động trong DB
  - Tra doctor_id từ user_id trong session
==============================================================*/
if (session_status() === PHP_SESSION_NONE) session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!defined("PK")) define("PK", "user_id");

/**
 * Buộc đăng xuất và trả JSON lỗi
 */
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

/**
 * Yêu cầu người dùng phải là Doctor hợp lệ.
 * Trả về doctor_id (string) nếu hợp lệ, nếu không thì dừng và trả lỗi.
 *
 * @param mysqli $conn
 * @return string doctor_id
 */
function requireDoctor(mysqli $conn): string {
    // Kiểm tra session
    $uid = (string)($_SESSION["user_id"] ?? "");
    if (($_SESSION["role"] ?? "") !== "doctor" || $uid === "") {
        forceLogout("Unauthorized access", "NOT_LOGGED_IN");
    }

    // Kiểm tra tài khoản trong DB (không dùng session cũ)
    $stmt = mysqli_prepare($conn,
        "SELECT role, user_status, is_deleted FROM users WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $uid);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row || (int)$row["is_deleted"] === 1) {
        forceLogout("Your account has been deleted.", "ACCOUNT_REVOKED");
    }
    if ((int)$row["user_status"] !== 1) {
        forceLogout("Your account has been locked.", "ACCOUNT_REVOKED");
    }
    if ($row["role"] !== "doctor") {
        forceLogout("You do not have doctor permission.", "ACCOUNT_REVOKED");
    }

    // Lấy doctor_id từ bảng doctors
    $stmt2 = mysqli_prepare($conn,
        "SELECT doctor_id FROM doctors WHERE user_id = ? AND status = 1 LIMIT 1");
    mysqli_stmt_bind_param($stmt2, "s", $uid);
    mysqli_stmt_execute($stmt2);
    $dRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt2));
    mysqli_stmt_close($stmt2);

    if (!$dRow) {
        forceLogout("Doctor profile not found or inactive.", "ACCOUNT_REVOKED");
    }

    return (string)$dRow["doctor_id"];
}
