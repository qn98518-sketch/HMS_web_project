<?php
/*==============================================================
  account_api.php - tài khoản cá nhân + ảnh đại diện
  Dùng được cho MỌI vai trò đã đăng nhập (không chỉ admin).

  action (GET):  me
  action (POST): update_profile | change_password | avatar_upload | avatar_remove | lock | delete

  - avatar_upload / avatar_remove: mặc định là của chính mình; admin có thể truyền id để đổi cho người khác.
  - lock / delete: KHÔNG áp dụng cho tài khoản admin.
==============================================================*/
session_start();
header("Content-Type: application/json; charset=UTF-8");
header('Cache-Control: no-store');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set("display_errors", "0");

if (!defined("PK")) define("PK", "user_id");

const AVATAR_DIR       = __DIR__ . "/../../uploads/avatars";

function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . "/auth_guard.php";       // forceLogout()
require_once __DIR__ . "/avatar_helpers.php";
require_once __DIR__ . "/avatar_image.php";

function textLength(string $s): int {
    return function_exists("mb_strlen") ? mb_strlen($s, "UTF-8") : strlen($s);
}

/* ------------------------------ đăng nhập ------------------------------ */

function requireLogin(PDO $pdo): array {
    $uid = (string)($_SESSION["user_id"] ?? "");
    if ($uid === "") forceLogout("Unauthorized access", "NOT_LOGGED_IN");

    $sql = "SELECT user_id, full_name, username, email, phone_number, role, user_status, avatar, user_password, is_deleted
            FROM users WHERE " . PK . " = ? LIMIT 1";
    $q = $pdo->prepare($sql);
    $q->execute([$uid]);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int)$row["is_deleted"] === 1) {
        forceLogout("Your account has been deleted.", "ACCOUNT_REVOKED");
    }
    if ((int)$row["user_status"] !== 1) {
        forceLogout("Your account has been locked.", "ACCOUNT_REVOKED");
    }
    return $row;
}

function mePayload(array $r): array {
    return [
        "id"        => (string)$r["user_id"],
        "full_name" => (string)$r["full_name"],
        "username"  => (string)$r["username"],
        "email"     => (string)($r["email"] ?? ""),
        "phone"     => (string)($r["phone_number"] ?? ""),
        "role"      => (string)$r["role"],
        "has_avatar" => !empty($r["avatar"]),
        "avatar_url" => avatarUrl((string)$r["user_id"], $r["avatar"] ?? null, (string)$r["full_name"]),
        // Admin không được tự khóa / xóa
        "can_lock_delete" => $r["role"] !== "admin",
    ];
}

/* ------------------------------ xử lý ảnh ------------------------------ */

// Nhận file tải lên, trả về nội dung JPEG 256x256 (đã cắt vuông, bỏ metadata). Lỗi => RuntimeException.
function processAvatar(array $file): string {
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('Image is too large (max 2 MB).');
    }
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed. Please choose the image again.');
    $path = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($path)) throw new RuntimeException('Invalid upload.');
    return renderAvatarJpeg($path);
}

/* ------------------------- xóa / ẩn tài khoản ------------------------- */

// Use the same hard-delete / soft-delete policy as Users Management.
function deleteOrHide(PDO $pdo, string $id): string {
    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM patients WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM doctors WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$id]);
        $pdo->commit();
        return 'deleted';
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((int)($error->errorInfo[1] ?? 0) !== 1451) throw $error;
    }
    $pdo->prepare('UPDATE users SET is_deleted = 1, user_status = 0 WHERE user_id = ?')->execute([$id]);
    return 'hidden';
}

function endSession(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), "", time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();
}

/* ------------------------------ main ------------------------------ */

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=hospital_management_system;charset=utf8mb4",
        "root", "",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );

    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array("avatar", $cols, true)) {
        respond(500, ["success" => false, "message" =>
            "Database is not ready: users.avatar is missing. Run sql_migrations/03_user_avatar.sql first."]);
    }
    $me      = requireLogin($pdo);
    $isAdmin = $me["role"] === "admin";
    $action  = $_POST["action"] ?? $_GET["action"] ?? "";

    // ---------- ME ----------
    if ($action === "me") {
        respond(200, ["success" => true, "data" => mePayload($me)]);
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        respond(405, ["success" => false, "message" => "Method not allowed."]);
    }
    // Chống CSRF: form của trang web khác không thể tự thêm header này
    if (($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") !== "XMLHttpRequest") {
        respond(403, ["success" => false, "message" => "Forbidden."]);
    }

    // ---------- UPDATE PROFILE (thông tin của chính mình) ----------
    if ($action === "update_profile") {
        $fullName = trim((string)($_POST["full_name"] ?? ""));
        $email    = trim((string)($_POST["email"] ?? ""));
        $phone    = trim((string)($_POST["phone"] ?? ""));

        if ($fullName === "") {
            respond(400, ["success" => false, "message" => "Please enter your full name."]);
        }
        if (textLength($fullName) > 100) {
            respond(400, ["success" => false, "message" => "Full name is too long (max 100 characters)."]);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || textLength($email) > 100) {
            respond(400, ["success" => false, "message" => "Invalid email format."]);
        }
        if (!preg_match('/^\+?[0-9]{7,15}$/', $phone) || strlen($phone) > 10) {
            respond(400, ["success" => false, "message" => "Invalid phone number (max 10 characters)."]);
        }

        $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone_number = ? WHERE " . PK . " = ?")
            ->execute([$fullName, $email, $phone, $me["user_id"]]);

        $me["full_name"] = $fullName; $me["email"] = $email; $me["phone_number"] = $phone;
        respond(200, ["success" => true, "message" => "Profile updated.", "data" => mePayload($me)]);
    }

    // ---------- CHANGE PASSWORD ----------
    if ($action === "change_password") {
        $current = (string)($_POST["current_password"] ?? "");
        $new     = (string)($_POST["new_password"] ?? "");

        if (!password_verify($current, (string)$me["user_password"])) {
            respond(400, ["success" => false, "message" => "Current password is incorrect."]);
        }
        if (strlen($new) < 6) {
            respond(400, ["success" => false, "message" => "New password must be at least 6 characters long."]);
        }
        if ($new === $current) {
            respond(400, ["success" => false, "message" => "New password must be different from the current one."]);
        }

        $pdo->prepare("UPDATE users SET user_password = ? WHERE " . PK . " = ?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $me["user_id"]]);
        session_regenerate_id(true);
        respond(200, ["success" => true, "message" => "Password changed."]);
    }

    // ---------- AVATAR (tải lên / gỡ) ----------
    if ($action === "avatar_upload" || $action === "avatar_remove") {
        $targetId = trim((string)($_POST["id"] ?? ""));
        if ($targetId === "" || $targetId === (string)$me["user_id"]) {
            $target = $me;
        } else {
            if (!$isAdmin) {
                respond(403, ["success" => false, "message" => "You can only change your own avatar."]);
            }
            $q = $pdo->prepare("SELECT user_id, full_name, avatar, is_deleted FROM users WHERE " . PK . " = ?");
            $q->execute([$targetId]);
            $target = $q->fetch(PDO::FETCH_ASSOC);
            if (!$target || (int)$target["is_deleted"] === 1) {
                respond(404, ["success" => false, "message" => "Account not found."]);
            }
        }

        $pdo->beginTransaction();
        $currentAvatar = $pdo->prepare('SELECT avatar FROM users WHERE user_id = ? AND is_deleted = 0 FOR UPDATE');
        $currentAvatar->execute([$target['user_id']]);
        $avatarRow = $currentAvatar->fetch(PDO::FETCH_ASSOC);
        if (!$avatarRow) {
            $pdo->rollBack();
            respond(404, ['success' => false, 'message' => 'Account not found.']);
        }
        $old = $avatarRow['avatar'];

        if ($action === "avatar_remove") {
            $pdo->prepare("UPDATE users SET avatar = NULL WHERE " . PK . " = ?")->execute([$target["user_id"]]);
            $pdo->commit();
            removeAvatarFile($old);
            respond(200, ["success" => true, "message" => "Avatar removed.",
                          "avatar_url" => avatarUrl((string)$target["user_id"], null, (string)$target["full_name"])]);
        }

        if (!isset($_FILES["avatar"])) {
            respond(400, ["success" => false, "message" => "Please choose an image."]);
        }
        try {
            $jpeg = processAvatar($_FILES["avatar"]);
        } catch (RuntimeException $e) {
            respond(400, ["success" => false, "message" => $e->getMessage()]);
        }

        if (!is_dir(AVATAR_DIR) && !@mkdir(AVATAR_DIR, 0755, true)) {
            error_log("Cannot create " . AVATAR_DIR);
            respond(500, ["success" => false, "message" => "Cannot save the image on the server."]);
        }
        $name = bin2hex(random_bytes(16)) . ".jpg";
        if (file_put_contents(AVATAR_DIR . "/" . $name, $jpeg) === false) {
            respond(500, ["success" => false, "message" => "Cannot save the image on the server."]);
        }

        try {
            $pdo->prepare("UPDATE users SET avatar = ? WHERE " . PK . " = ?")->execute([$name, $target["user_id"]]);
            $pdo->commit();
        } catch (Throwable $e) {
            @unlink(AVATAR_DIR . "/" . $name);                // DB lỗi thì không để file mồ côi
            throw $e;
        }
        removeAvatarFile($old);                               // chỉ xóa ảnh cũ sau khi ảnh mới đã lưu xong

        respond(200, ["success" => true, "message" => "Avatar updated.",
                      "avatar_url" => avatarUrl((string)$target["user_id"], $name, (string)$target["full_name"])]);
    }

    // ---------- LOCK / DELETE (không áp dụng cho admin) ----------
    if ($action === "lock" || $action === "delete") {
        if ($isAdmin) {
            respond(403, ["success" => false, "message" => "Admin accounts cannot be locked or deleted here."]);
        }
        if (!password_verify((string)($_POST["password"] ?? ""), (string)$me["user_password"])) {
            respond(400, ["success" => false, "message" => "Password is incorrect."]);
        }

        if ($action === "lock") {
            $pdo->prepare("UPDATE users SET user_status = 0 WHERE " . PK . " = ?")->execute([$me["user_id"]]);
            endSession();
            respond(200, ["success" => true, "message" => "Your account has been locked. Contact an administrator to reactivate it."]);
        }

        $result = deleteOrHide($pdo, (string)$me["user_id"]);
        if ($result === "deleted") removeAvatarFile($me["avatar"] ?? null);
        endSession();
        respond(200, ["success" => true, "mode" => $result,
            "message" => $result === "deleted"
                ? "Your account has been deleted."
                : "Your account has been hidden. Existing appointments and bills are retained."]);
    }

    respond(400, ["success" => false, "message" => "Unknown action."]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log($e->getMessage());
    respond(500, ["success" => false, "message" => "Database error."]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log($e->getMessage());
    respond(500, ["success" => false, "message" => "Server error."]);
}
