<?php
/*==============================================================
  avatar.php?id=<user_id>&v=<phiên bản>
  Trả ảnh đại diện của người dùng, hoặc avatar mặc định (chữ cái đầu của tên) nếu chưa có ảnh.
  Chỉ phục vụ khi đã đăng nhập; xem avatar của người khác chỉ dành cho admin.
==============================================================*/
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set("display_errors", "0");

const AVATAR_DIR = __DIR__ . "/../../uploads/avatars";

function fail(int $code): void {
    http_response_code($code);
    exit;
}

function initials(string $name): string {
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$words) return "?";
    $first = function (string $w): string {
        return preg_match('/\p{L}/u', $w, $m) ? $m[0] : "";      // chữ cái đầu tiên (bỏ qua dấu ngoặc, số...)
    };
    $s = $first($words[0]);
    if (count($words) > 1) $s .= $first($words[count($words) - 1]);
    return function_exists("mb_strtoupper") ? mb_strtoupper($s, "UTF-8") : strtoupper($s);
}

function defaultSvg(string $userId, string $name): string {
    $hue  = crc32($userId) % 360;
    $text = htmlspecialchars(initials($name), ENT_XML1 | ENT_QUOTES, "UTF-8");
    return '<?xml version="1.0" encoding="UTF-8"?>' .
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256">' .
        '<rect width="256" height="256" fill="hsl(' . $hue . ',55%,45%)"/>' .
        '<text x="128" y="128" fill="#ffffff" font-family="Arial, Helvetica, sans-serif" font-size="104" ' .
        'font-weight="700" text-anchor="middle" dominant-baseline="central">' . $text . '</text></svg>';
}

try {
    $uid = (string)($_SESSION["user_id"] ?? "");
    if ($uid === "") fail(401);

    $pdo = new PDO("mysql:host=localhost;dbname=hospital_management_system;charset=utf8mb4", "root", "",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);

    // Người xem phải là tài khoản còn hiệu lực
    $q = $pdo->prepare("SELECT role, user_status, is_deleted FROM users WHERE user_id = ?");
    $q->execute([$uid]);
    $viewer = $q->fetch(PDO::FETCH_ASSOC);
    if (!$viewer || (int)$viewer["user_status"] !== 1 || (int)$viewer['is_deleted'] === 1) fail(401);

    $id = trim((string)($_GET["id"] ?? $uid));
    if ($id !== $uid && $viewer["role"] !== "admin") fail(403);

    $q = $pdo->prepare("SELECT full_name, avatar FROM users WHERE user_id = ? AND is_deleted = 0");
    $q->execute([$id]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    if (!$u) fail(404);

    header("X-Content-Type-Options: nosniff");
    // URL có tham số v đổi mỗi khi ảnh đổi => có thể cache lâu
    header("Cache-Control: private, max-age=604800");

    $file = (string)($u["avatar"] ?? "");
    if ($file !== "" && preg_match('/^[a-f0-9]{32}\.jpg$/', $file)) {   // chặn tên file lạ (path traversal)
        $path = AVATAR_DIR . "/" . $file;
        if (is_file($path)) {
            header("Content-Type: image/jpeg");
            header("Content-Length: " . filesize($path));
            readfile($path);
            exit;
        }
    }

    header("Content-Type: image/svg+xml; charset=UTF-8");
    echo defaultSvg($id, (string)$u["full_name"]);

} catch (Throwable $e) {
    error_log($e->getMessage());
    fail(500);
}
