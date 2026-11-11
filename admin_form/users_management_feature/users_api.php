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
            "SELECT " . PK . " AS uid, full_name, username, email, phone_number, role, user_status, create_at
             FROM users ORDER BY create_at, username");
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
    if ($action === "delete") {
        if ($id === "") {
            respond(400, ["success" => false, "message" => "Missing id."]);
        }
        if ($id === $myId) {
            respond(400, ["success" => false, "message" => "You cannot delete your own account."]);
        }

        try {
            $pdo->beginTransaction();

            // 1) Xóa hồ sơ bệnh nhân của tài khoản này (nếu có).
            //    Phải xóa trước, vì patients.user_id đang tham chiếu tới users.
            $pdo->prepare("DELETE FROM patients WHERE user_id = ?")->execute([$id]);

            $pdo->prepare("DELETE FROM doctors WHERE user_id = ?")->execute([$id]);

            // 2) Xóa tài khoản
            $stmt = $pdo->prepare("DELETE FROM users WHERE " . PK . " = ?");
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                respond(404, ["success" => false, "message" => "Account not found."]);
            }

            $pdo->commit();   // chỉ khi cả 2 bước thành công mới xóa thật
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();   // lỗi giữa chừng: giữ nguyên cả tài khoản lẫn hồ sơ
            if ((int)($e->errorInfo[1] ?? 0) === 1451) {   // còn dữ liệu khác tham chiếu tới (lịch hẹn, bệnh án...)
                respond(409, ["success" => false,
                    "message" => "This account has related records (e.g. appointments) and cannot be deleted. Disable it instead."]);
            }
            throw $e;
        }

        respond(200, ["success" => true, "message" => "Account deleted."]);
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