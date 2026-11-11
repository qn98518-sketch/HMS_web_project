<?php
/*==============================================================
  doctors_api.php - API cho module Doctors Management
  action: list | create | update
  (Vô hiệu hóa bác sĩ = đổi status = Disabled; xóa tài khoản ở Users Management)
==============================================================*/
session_start();
header("Content-Type: application/json; charset=UTF-8");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set("display_errors", "0");

if (!defined("PK")) define("PK", "user_id");      // auth_guard.php dùng hằng số này

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

function usernameExists(PDO $pdo, string $username): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
    $stmt->execute([$username]);
    return (bool)$stmt->fetchColumn();
}

// Độ dài theo ký tự (tiếng Việt có dấu); dự phòng khi server chưa bật mbstring
function textLength(string $s): int {
    return function_exists("mb_strlen") ? mb_strlen($s, "UTF-8") : strlen($s);
}

// Đọc + kiểm tra phần hồ sơ bác sĩ. Trả về [giá trị, lỗi]
function readProfile(): array {
    $spec   = trim($_POST["specialization"] ?? "");
    $qual   = trim($_POST["qualification"] ?? "");
    $room   = trim((string)($_POST["room_number"] ?? ""));
    $status = trim((string)($_POST["status"] ?? "1"));

    if ($spec === "")                 return [null, "Specialization is required."];
    if (textLength($spec) > 100)      return [null, "Specialization is too long (max 100 characters)."];
    if (textLength($qual) > 100)      return [null, "Qualification is too long (max 100 characters)."];

    $roomInt = filter_var($room, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 2147483647]]);
    if ($roomInt === false)           return [null, "Room number must be a positive whole number."];

    if ($status !== "0" && $status !== "1") return [null, "Invalid status."];

    return [[
        "specialization" => $spec,
        "qualification"  => $qual === "" ? null : $qual,
        "room"           => $roomInt,
        "status"         => (int)$status,
    ], null];
}

require_once __DIR__ . "/../php/auth_guard.php";

try {
    $conn = mysqli_connect("localhost", "root", "", "hospital_management_system");
    mysqli_set_charset($conn, "utf8mb4");
    requireAdmin($conn);                                    // chỉ admin còn hiệu lực mới được gọi

    $pdo = new PDO(
        "mysql:host=localhost;dbname=hospital_management_system;charset=utf8mb4",
        "root", "",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => false]
    );

    $action = $_POST["action"] ?? $_GET["action"] ?? "";

    // ---------- LIST ----------
    if ($action === "list") {
        $rows = [];
        $q = $pdo->query(
            "SELECT d.doctor_id, u.full_name, u.username, u.email, u.phone_number,
                    d.specialization, d.qualification, d.room_number, d.status
             FROM doctors d
             LEFT JOIN users u ON u.user_id = d.user_id
             ORDER BY u.full_name, d.specialization");
        foreach ($q as $r) {
            $rows[] = [
                "id"             => (string)$r["doctor_id"],
                "full_name"      => $r["full_name"]      ?? "",
                "username"       => $r["username"]       ?? "",
                "email"          => $r["email"]          ?? "",
                "phone"          => $r["phone_number"]   ?? "",
                "specialization" => $r["specialization"] ?? "",
                "qualification"  => $r["qualification"]  ?? "",
                "room_number"    => (int)$r["room_number"],
                "status"         => (int)$r["status"],
            ];
        }
        respond(200, ["success" => true, "data" => $rows]);
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        respond(405, ["success" => false, "message" => "Method not allowed."]);
    }

    // ---------- CREATE (tạo tài khoản role doctor + hồ sơ bác sĩ) ----------
    if ($action === "create") {
        $fullName = trim($_POST["full_name"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";
        $email    = trim($_POST["email"] ?? "");
        $phone    = trim($_POST["phone"] ?? "");

        if ($fullName === "" || $username === "" || $password === "") {
            respond(400, ["success" => false, "message" => "Missing required fields."]);
        }
        if (strlen($password) < 6) {
            respond(400, ["success" => false, "message" => "Password must be at least 6 characters long."]);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(400, ["success" => false, "message" => "Invalid email format."]);
        }
        if (!preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
            respond(400, ["success" => false, "message" => "Invalid phone number format."]);
        }
        [$profile, $err] = readProfile();
        if ($err) respond(400, ["success" => false, "message" => $err]);

        if (usernameExists($pdo, $username)) {
            respond(409, ["success" => false, "message" => "Username already exists."]);
        }

        $newUserId = uuidv4();
        $hash      = password_hash($password, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO users (user_id, full_name, username, user_password, email, phone_number, role, user_status)
             VALUES (?, ?, ?, ?, ?, ?, 'doctor', 1)"
        )->execute([$newUserId, $fullName, $username, $hash, $email, $phone]);

        $pdo->prepare(
            "INSERT INTO doctors (user_id, specialization, qualification, room_number, status)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$newUserId, $profile["specialization"], $profile["qualification"], $profile["room"], $profile["status"]]);
        $pdo->commit();

        respond(200, ["success" => true, "message" => "Doctor created."]);
    }

    // ---------- UPDATE (chỉ sửa hồ sơ bác sĩ; thông tin tài khoản sửa ở Users Management) ----------
    if ($action === "update") {
        $doctorId = trim((string)($_POST["id"] ?? ""));
        if ($doctorId === "") {
            respond(400, ["success" => false, "message" => "Missing doctor id."]);
        }

        $chk = $pdo->prepare("SELECT 1 FROM doctors WHERE doctor_id = ?");
        $chk->execute([$doctorId]);
        if (!$chk->fetchColumn()) {
            respond(404, ["success" => false, "message" => "Doctor not found."]);
        }

        [$profile, $err] = readProfile();
        if ($err) respond(400, ["success" => false, "message" => $err]);

        $pdo->prepare(
            "UPDATE doctors SET specialization = ?, qualification = ?, room_number = ?, status = ? WHERE doctor_id = ?"
        )->execute([$profile["specialization"], $profile["qualification"], $profile["room"], $profile["status"], $doctorId]);

        respond(200, ["success" => true, "message" => "Doctor updated."]);
    }

    respond(400, ["success" => false, "message" => "Unknown action."]);

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