<?php
/*==============================================================
  profile_api.php - API hồ sơ bác sĩ (Doctor Dashboard)

  action (GET):  me
  action (POST): update_info | avatar_upload | avatar_remove | change_password

  Schema thực tế:
    doctors : doctor_id, user_id, specialization, qualification, room_number (INT), status
    users   : user_id, full_name, username, email, phone_number, avatar, ...
==============================================================*/
session_start();
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set("display_errors", "0");

if (!defined("PK")) define("PK", "user_id");

// Thư mục lưu avatar
define("AVATAR_DIR", __DIR__ . "/../../uploads/avatars");

// ----- Hàm trả JSON -----
function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ----- Hàm build payload (dùng schema thực tế) -----
function buildPayload(array $d, array $u): array {
    $userId   = (string)$u["user_id"];
    $fullName = (string)($u["full_name"] ?? "");
    $avatar   = $u["avatar"] ?? null;

    // URL avatar: trỏ tới admin_form/php/avatar.php (đã có sẵn trong project)
    $avatarUrl = "admin_form/php/avatar.php?id=" . rawurlencode($userId)
               . "&v=" . ($avatar ? substr($avatar, 0, 8) : dechex(crc32($fullName)));

    return [
        "doctor_id"      => (string)$d["doctor_id"],
        "user_id"        => $userId,
        "full_name"      => $fullName,
        "specialization" => (string)($d["specialization"] ?? ""),
        "qualification"  => (string)($d["qualification"]  ?? ""),
        "room_number"    => (string)($d["room_number"]    ?? ""),
        "email"          => (string)($u["email"]          ?? ""),
        "phone"          => (string)($u["phone_number"]   ?? ""),
        "has_avatar"     => !empty($avatar),
        "avatar_url"     => $avatarUrl,
    ];
}

// ----- Main (bọc try-catch để không crash im lặng) -----
try {

    $conn = mysqli_connect("localhost", "root", "", "hospital_management_system");
    if (!$conn) {
        respond(500, ["success" => false, "message" => "Database connection failed."]);
    }
    mysqli_set_charset($conn, "utf8mb4");

    // Guard: bắt buộc đăng nhập là Doctor hợp lệ
    require_once __DIR__ . "/doctor_guard.php";
    $doctorId = requireDoctor($conn);
    $userId   = (string)$_SESSION["user_id"];

    // Lấy action
    $action = $_POST["action"] ?? $_GET["action"] ?? "";

    // ========================================================
    // GET ?action=me  → trả thông tin bác sĩ
    // ========================================================
    if ($action === "me") {
        // Lấy từ bảng doctors
        $stmtD = mysqli_prepare($conn,
            "SELECT doctor_id, user_id, specialization, qualification, room_number
             FROM doctors WHERE doctor_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmtD, "s", $doctorId);
        mysqli_stmt_execute($stmtD);
        $dRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtD));
        mysqli_stmt_close($stmtD);

        if (!$dRow) {
            respond(404, ["success" => false, "message" => "Doctor profile not found."]);
        }

        // Lấy từ bảng users
        $stmtU = mysqli_prepare($conn,
            "SELECT user_id, full_name, email, phone_number, avatar
             FROM users WHERE user_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmtU, "s", $userId);
        mysqli_stmt_execute($stmtU);
        $uRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtU));
        mysqli_stmt_close($stmtU);

        if (!$uRow) {
            respond(404, ["success" => false, "message" => "User not found."]);
        }

        respond(200, ["success" => true, "data" => buildPayload($dRow, $uRow)]);
    }

    // POST: kiểm tra method và CSRF header
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        respond(405, ["success" => false, "message" => "Method not allowed."]);
    }
    if (($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") !== "XMLHttpRequest") {
        respond(403, ["success" => false, "message" => "Forbidden."]);
    }

    // ========================================================
    // POST action=update_info → cập nhật thông tin bác sĩ
    // ========================================================
    if ($action === "update_info") {
        $fullName       = trim((string)($_POST["full_name"]      ?? ""));
        $specialization = trim((string)($_POST["specialization"] ?? ""));
        $qualification  = trim((string)($_POST["qualification"]  ?? ""));
        $roomRaw        = trim((string)($_POST["room_number"]    ?? ""));

        // Validate
        if ($fullName === "") {
            respond(400, ["success" => false, "message" => "Please enter your full name."]);
        }
        if (mb_strlen($fullName, "UTF-8") > 100) {
            respond(400, ["success" => false, "message" => "Full name is too long (max 100 characters)."]);
        }
        if ($specialization === "") {
            respond(400, ["success" => false, "message" => "Please select a specialization."]);
        }

        // room_number là INT trong DB
        $roomNumber = 1; // mặc định
        if ($roomRaw !== "") {
            $roomInt = filter_var($roomRaw, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
            if ($roomInt === false) {
                respond(400, ["success" => false, "message" => "Room number must be a positive whole number."]);
            }
            $roomNumber = $roomInt;
        }

        $qualVal = $qualification === "" ? null : $qualification;

        // Cập nhật bảng doctors (specialization, qualification, room_number)
        $stmtD = mysqli_prepare($conn,
            "UPDATE doctors SET specialization = ?, qualification = ?, room_number = ?
             WHERE doctor_id = ?");
        mysqli_stmt_bind_param($stmtD, "ssss", $specialization, $qualVal, $roomNumber, $doctorId);
        // room_number là INT nhưng bind_param "s" vẫn hoạt động với MySQL
        mysqli_stmt_execute($stmtD);
        mysqli_stmt_close($stmtD);

        // Cập nhật full_name trong bảng users
        $stmtU = mysqli_prepare($conn,
            "UPDATE users SET full_name = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmtU, "ss", $fullName, $userId);
        mysqli_stmt_execute($stmtU);
        mysqli_stmt_close($stmtU);

        // Lấy lại data mới để trả về
        $stmtD2 = mysqli_prepare($conn,
            "SELECT doctor_id, user_id, specialization, qualification, room_number
             FROM doctors WHERE doctor_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmtD2, "s", $doctorId);
        mysqli_stmt_execute($stmtD2);
        $dRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtD2));
        mysqli_stmt_close($stmtD2);

        $stmtU2 = mysqli_prepare($conn,
            "SELECT user_id, full_name, email, phone_number, avatar
             FROM users WHERE user_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmtU2, "s", $userId);
        mysqli_stmt_execute($stmtU2);
        $uRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtU2));
        mysqli_stmt_close($stmtU2);

        respond(200, ["success" => true, "message" => "Profile updated.", "data" => buildPayload($dRow, $uRow)]);
    }

    // ========================================================
    // POST action=avatar_upload → tải ảnh đại diện
    // ========================================================
    if ($action === "avatar_upload") {
        if (!isset($_FILES["avatar"]) || $_FILES["avatar"]["error"] === UPLOAD_ERR_NO_FILE) {
            respond(400, ["success" => false, "message" => "No image selected."]);
        }
        $file = $_FILES["avatar"];
        if ($file["error"] !== UPLOAD_ERR_OK) {
            respond(400, ["success" => false, "message" => "Upload error. Please try again."]);
        }
        if ($file["size"] > 2 * 1024 * 1024) {
            respond(400, ["success" => false, "message" => "Image is too large (max 2 MB)."]);
        }

        // Kiểm tra loại file bằng finfo
        $allowed = ["image/jpeg", "image/png", "image/webp", "image/gif"];
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $file["tmp_name"]);
        finfo_close($finfo);
        if (!in_array($mime, $allowed, true)) {
            respond(400, ["success" => false, "message" => "Only JPG, PNG, WEBP or GIF images are allowed."]);
        }

        // Lấy avatar cũ để xóa sau
        $stmtOld = mysqli_prepare($conn, "SELECT avatar FROM users WHERE user_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmtOld, "s", $userId);
        mysqli_stmt_execute($stmtOld);
        $oldRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtOld));
        mysqli_stmt_close($stmtOld);

        // Tên file mới
        $newFile  = md5(uniqid($userId, true)) . ".jpg";
        $destPath = AVATAR_DIR . "/" . $newFile;

        // Dùng GD để resize 256×256 (nếu có), không thì copy thẳng
        if (extension_loaded("gd")) {
            $src = false;
            switch ($mime) {
                case "image/jpeg": $src = @imagecreatefromjpeg($file["tmp_name"]); break;
                case "image/png":  $src = @imagecreatefrompng($file["tmp_name"]);  break;
                case "image/webp": $src = @imagecreatefromwebp($file["tmp_name"]); break;
                case "image/gif":  $src = @imagecreatefromgif($file["tmp_name"]);  break;
            }
            if ($src) {
                $w = imagesx($src); $h = imagesy($src);
                $size = min($w, $h);
                $x = (int)(($w - $size) / 2);
                $y = (int)(($h - $size) / 2);
                $dst = imagecreatetruecolor(256, 256);
                imagecopyresampled($dst, $src, 0, 0, $x, $y, 256, 256, $size, $size);
                imagedestroy($src);
                imagejpeg($dst, $destPath, 90);
                imagedestroy($dst);
            } else {
                move_uploaded_file($file["tmp_name"], $destPath);
            }
        } else {
            if (!move_uploaded_file($file["tmp_name"], $destPath)) {
                respond(500, ["success" => false, "message" => "Could not save image."]);
            }
        }

        // Xóa avatar cũ
        if (!empty($oldRow["avatar"])) {
            $oldPath = AVATAR_DIR . "/" . $oldRow["avatar"];
            if (is_file($oldPath)) @unlink($oldPath);
        }

        // Lưu tên file mới vào DB
        $stmtUpd = mysqli_prepare($conn, "UPDATE users SET avatar = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmtUpd, "ss", $newFile, $userId);
        mysqli_stmt_execute($stmtUpd);
        mysqli_stmt_close($stmtUpd);

        respond(200, ["success" => true, "message" => "Photo uploaded."]);
    }

    // ========================================================
    // POST action=avatar_remove → xóa ảnh đại diện
    // ========================================================
    if ($action === "avatar_remove") {
        $stmtSel = mysqli_prepare($conn, "SELECT avatar FROM users WHERE user_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmtSel, "s", $userId);
        mysqli_stmt_execute($stmtSel);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtSel));
        mysqli_stmt_close($stmtSel);

        if (!empty($row["avatar"])) {
            $filePath = AVATAR_DIR . "/" . $row["avatar"];
            if (is_file($filePath)) @unlink($filePath);
        }

        $stmtUpd = mysqli_prepare($conn, "UPDATE users SET avatar = NULL WHERE user_id = ?");
        mysqli_stmt_bind_param($stmtUpd, "s", $userId);
        mysqli_stmt_execute($stmtUpd);
        mysqli_stmt_close($stmtUpd);

        respond(200, ["success" => true, "message" => "Photo removed."]);
    }

    // ========================================================
    // POST action=change_password → đổi mật khẩu
    // ========================================================
    if ($action === "change_password") {
        $current = (string)($_POST["current_password"] ?? "");
        $new     = (string)($_POST["new_password"]     ?? "");

        if ($current === "") {
            respond(400, ["success" => false, "message" => "Please enter your current password."]);
        }
        if (strlen($new) < 6) {
            respond(400, ["success" => false, "message" => "New password must be at least 6 characters long."]);
        }

        // Lấy mật khẩu hash hiện tại
        $stmtPw = mysqli_prepare($conn,
            "SELECT user_password FROM users WHERE user_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmtPw, "s", $userId);
        mysqli_stmt_execute($stmtPw);
        $pwRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtPw));
        mysqli_stmt_close($stmtPw);

        if (!$pwRow || !password_verify($current, (string)$pwRow["user_password"])) {
            respond(400, ["success" => false, "message" => "Current password is incorrect."]);
        }
        if ($current === $new) {
            respond(400, ["success" => false, "message" => "New password must be different from the current one."]);
        }

        $hashed  = password_hash($new, PASSWORD_DEFAULT);
        $stmtUpd = mysqli_prepare($conn, "UPDATE users SET user_password = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmtUpd, "ss", $hashed, $userId);
        mysqli_stmt_execute($stmtUpd);
        mysqli_stmt_close($stmtUpd);

        session_regenerate_id(true);
        respond(200, ["success" => true, "message" => "Password changed successfully."]);
    }

    // Action không hợp lệ
    respond(400, ["success" => false, "message" => "Invalid action: " . htmlspecialchars($action)]);

} catch (mysqli_sql_exception $e) {
    error_log("profile_api mysqli error: " . $e->getMessage());
    respond(500, ["success" => false, "message" => "Database error: " . $e->getMessage()]);
} catch (Throwable $e) {
    error_log("profile_api error: " . $e->getMessage());
    respond(500, ["success" => false, "message" => "Server error: " . $e->getMessage()]);
}
