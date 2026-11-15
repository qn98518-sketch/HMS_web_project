<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set("display_errors", "0");

const PK = "user_id";
const ALLOWED_ROLES = ["admin", "doctor", "patient", "receptionist"];

function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function usernameExists(PDO $pdo, string $username, string $excludeId = ""): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM users WHERE LOWER(username) = LOWER(?) AND " . PK . " <> ? LIMIT 1");
    $stmt->execute([$username, $excludeId]);
    return (bool)$stmt->fetchColumn();
}

// Tài khoản có tồn tại và chưa bị ẩn (is_deleted = 0)?
function accountVisible(PDO $pdo, string $id): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE " . PK . " = ? AND is_deleted = 0 LIMIT 1");
    $stmt->execute([$id]);
    return (bool)$stmt->fetchColumn();
}

// Trả về chuỗi lỗi, hoặc null nếu hợp lệ
function validateUser(string $fullName, string $username, string $email,
                      string $phone, string $role, int $status): ?string {
    if ($fullName === "" || $username === "")            return "Missing required fields.";
    if (!in_array($role, ALLOWED_ROLES, true))           return "Invalid role.";
    if (!in_array($status, [0, 1], true))                return "Invalid status.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))      return "Invalid email format.";
    if (!preg_match('/^\+?[0-9]{7,15}$/', $phone))       return "Invalid phone number format.";
    return null;
}

require_once __DIR__ . "/../php/auth_guard.php";
require_once __DIR__ . "/../php/avatar_helpers.php";

try {
    $conn = mysqli_connect("localhost", "root", "", "hospital_management_system");
    mysqli_set_charset($conn, "utf8mb4");
    requireAdmin($conn);

    $pdo = new PDO(
        "mysql:host=localhost;dbname=hospital_management_system;charset=utf8mb4",
        "root", "",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => false]
    );

    $action = $_POST["action"] ?? $_GET["action"] ?? "";

    $id       = trim((string)($_POST["id"] ?? ""));      // UUID: là CHUỖI, không ép (int)
    $fullName = trim($_POST["full_name"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $email    = trim($_POST["email"] ?? "");
    $phone    = trim($_POST["phone"] ?? "");
    $role     = strtolower(trim($_POST["role"] ?? ""));
    $status   = (int)($_POST["status"] ?? 1);

    $myId = (string)($_SESSION["user_id"] ?? "");

    // ---------- LIST ----------
    if ($action === "list") {
        $rows = [];
        $q = $pdo->query(
            "SELECT " . PK . " AS uid, full_name, username, email, phone_number, role, user_status, create_at, avatar
             FROM users
             WHERE is_deleted = 0
             ORDER BY create_at, username");
        foreach ($q as $r) {
            $rows[] = [
                "id"         => (string)$r["uid"],
                "full_name"  => $r["full_name"],
                "username"   => $r["username"],
                "email"      => $r["email"],
                "phone"      => $r["phone_number"],
                "role"       => ucfirst($r["role"]),
                "status"     => (int)$r["user_status"],
                "created_at" => substr((string)$r["create_at"], 0, 10),
                "avatar_v"   => avatarVersion($r['avatar'], (string)$r['full_name']),
            ];
        }
        respond(200, ["success" => true, "data" => $rows]);
    }

    // ---------- CREATE ----------
    if ($action === "create") {
        if ($password === "") {
            respond(400, ["success" => false, "message" => "Missing required fields."]);
        }
        if ($err = validateUser($fullName, $username, $email, $phone, $role, $status)) {
            respond(400, ["success" => false, "message" => $err]);
        }
        if (usernameExists($pdo, $username)) {
            respond(409, ["success" => false, "message" => "Username already exists."]);
        }

        $newId = uuidv4();
        $hash  = password_hash($password, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO users (" . PK . ", full_name, username, user_password, email, phone_number, role, user_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$newId, $fullName, $username, $hash, $email, $phone, $role, $status]);

        if ($role === "patient") {
            $pdo->prepare("INSERT INTO patients (user_id, gender) VALUES (?, ?)")
                ->execute([$newId, "others"]);
        }

        if ($role === "doctor") {
            $pdo->prepare("INSERT INTO doctors (user_id) VALUES (?)")
                ->execute([$newId]);
        }
        $pdo->commit();

        respond(200, ["success" => true, "message" => "Account created."]);
    }

    // ---------- UPDATE ----------
    if ($action === "update") {
        if ($id === "") {
            respond(400, ["success" => false, "message" => "Missing required fields."]);
        }
        if (!accountVisible($pdo, $id)) {
            respond(404, ["success" => false, "message" => "Account not found."]);
        }
        if ($err = validateUser($fullName, $username, $email, $phone, $role, $status)) {
            respond(400, ["success" => false, "message" => $err]);
        }
        if (usernameExists($pdo, $username, $id)) {
            respond(409, ["success" => false, "message" => "Username already exists."]);
        }
        // Admin không được tự hạ quyền / khóa chính mình (sẽ tự đá mình ra)
        if ($id === $myId && ($role !== "admin" || $status !== 1)) {
            respond(400, ["success" => false,
                "message" => "You cannot change your own role or disable your own account."]);
        }

        $pdo->beginTransaction();

        if ($password !== "") {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare(
                "UPDATE users SET full_name=?, username=?, user_password=?, email=?, phone_number=?, role=?, user_status=?
                 WHERE " . PK . "=?"
            )->execute([$fullName, $username, $hash, $email, $phone, $role, $status, $id]);
        } else {
            $pdo->prepare(
                "UPDATE users SET full_name=?, username=?, email=?, phone_number=?, role=?, user_status=?
                 WHERE " . PK . "=?"
            )->execute([$fullName, $username, $email, $phone, $role, $status, $id]);
        }

        // Đổi sang patient mà chưa có hồ sơ bệnh nhân thì tạo
        if ($role === "patient") {
            $pdo->prepare(
                "INSERT INTO patients (user_id, gender)
                 SELECT ?, 'others' FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM patients WHERE user_id = ?)"
            )->execute([$id, $id]);
        }

        if ($role === "doctor") {
            $pdo->prepare(
                "INSERT INTO doctors (user_id)
                 SELECT ? FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM doctors WHERE user_id = ?)"
            )->execute([$id, $id]);
        }

        $pdo->commit();
        respond(200, ["success" => true, "message" => "Account updated."]);
    }

    // ---------- DELETE ----------
    // 1) Chưa có lịch hẹn / dữ liệu nào tham chiếu tới tài khoản  => XÓA VĨNH VIỄN.
    // 2) Đã có (khóa ngoại chặn, lỗi MySQL 1451)                   => ẨN: is_deleted = 1.
    //    Tài khoản bị ẩn không còn hiện ở Users / Patients / Doctors management,
    //    không đăng nhập được; chỉ còn xuất hiện trong lịch hẹn cũ (Appointments management).
    if ($action === "delete") {
        if ($id === "") {
            respond(400, ["success" => false, "message" => "Missing id."]);
        }
        if ($id === $myId) {
            respond(400, ["success" => false, "message" => "You cannot delete your own account."]);
        }
        if (!accountVisible($pdo, $id)) {
            respond(404, ["success" => false, "message" => "Account not found."]);
        }

        try {
            $pdo->beginTransaction();
            $avatarQuery = $pdo->prepare('SELECT avatar FROM users WHERE user_id = ? FOR UPDATE');
            $avatarQuery->execute([$id]);
            $oldAvatar = $avatarQuery->fetchColumn();

            // Hồ sơ bệnh nhân / bác sĩ phải xóa trước vì đang tham chiếu tới users.
            $pdo->prepare("DELETE FROM patients WHERE user_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM doctors WHERE user_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM users WHERE " . PK . " = ?")->execute([$id]);

            $pdo->commit();   // cả 3 bước thành công => đã xóa vĩnh viễn
            removeAvatarFile($oldAvatar ?: null);
            respond(200, ["success" => true, "mode" => "deleted", "message" => "Account deleted."]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();          // giữ nguyên cả tài khoản lẫn hồ sơ
            if ((int)($e->errorInfo[1] ?? 0) !== 1451) throw $e;  // lỗi khác: báo lỗi bình thường
        }

        // Tới đây: còn dữ liệu tham chiếu (lịch hẹn...) => ẩn tài khoản thay vì xóa.
        // user_status = 0 để chắc chắn không đăng nhập được.
        $pdo->prepare("UPDATE users SET is_deleted = 1, user_status = 0 WHERE " . PK . " = ?")
            ->execute([$id]);

        respond(200, ["success" => true, "mode" => "hidden",
            "message" => "This account has related records (appointments or bills), so it was hidden instead of permanently deleted."]);
    }

    respond(400, [
        "success" => false,
        "message" => "Unknown action. Received: " . json_encode([
            "action" => $action, "method" => $_SERVER["REQUEST_METHOD"],
            "post" => $_POST, "get" => $_GET
        ])
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        respond(409, ["success" => false, "message" => "Username already exists."]);
    }
    error_log($e->getMessage());
    respond(500, ["success" => false, "message" => "Database error."]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log($e->getMessage());
    respond(500, ["success" => false, "message" => "Server error."]);
}
