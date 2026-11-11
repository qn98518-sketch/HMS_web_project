<?php
/*==============================================================
  patients_api.php - API cho module Patients Management
  action: list | create | update
  (Xóa bệnh nhân = xóa tài khoản ở Users Management)
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

// Đọc danh sách giá trị của cột ENUM gender trực tiếp từ DB (không phải đoán)
function genderOptions(PDO $pdo): array {
    $row  = $pdo->query("SHOW COLUMNS FROM patients LIKE 'gender'")->fetch(PDO::FETCH_ASSOC);
    $opts = [];
    if ($row && preg_match_all("/'((?:[^']|'')*)'/", $row["Type"], $m)) {
        $opts = array_map(fn($s) => str_replace("''", "'", $s), $m[1]);
    }
    return $opts ?: ["male", "female", "others"];
}

// Độ dài theo ký tự (tiếng Việt có dấu); dự phòng khi server chưa bật mbstring
function textLength(string $s): int {
    return function_exists("mb_strlen") ? mb_strlen($s, "UTF-8") : strlen($s);
}

// Đọc + kiểm tra phần hồ sơ bệnh nhân. Trả về [giá trị, lỗi]
function readProfile(array $genders): array {
    $dob     = trim($_POST["dob"] ?? "");
    $gender  = trim($_POST["gender"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $history = trim($_POST["medical_history"] ?? "");

    if ($dob === "") {
        $dob = null;
    } else {
        $d = DateTime::createFromFormat("!Y-m-d", $dob);
        if (!$d || $d->format("Y-m-d") !== $dob || $d > new DateTime("today") || (int)$d->format("Y") < 1900) {
            return [null, "Invalid date of birth."];
        }
    }

    if ($gender === "") {
        $gender = null;
    } else {
        $matched = null;                                    // so khớp không phân biệt hoa/thường
        foreach ($genders as $g) if (strcasecmp($g, $gender) === 0) $matched = $g;
        if ($matched === null) return [null, "Invalid gender."];
        $gender = $matched;
    }

    if (textLength($address) > 255)  return [null, "Address is too long (max 255 characters)."];
    if (textLength($history) > 5000) return [null, "Medical history is too long (max 5000 characters)."];

    return [[
        "dob"     => $dob,
        "gender"  => $gender,
        "address" => $address === "" ? null : $address,
        "history" => $history === "" ? null : $history,
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

    $action  = $_POST["action"] ?? $_GET["action"] ?? "";
    $genders = genderOptions($pdo);

    // ---------- LIST ----------
    if ($action === "list") {
        $rows = [];
        $q = $pdo->query(
            "SELECT p.patient_id, u.full_name, u.username, u.email, u.phone_number,
                    p.dob, p.gender, p.address, p.medical_history, p.created_at
             FROM patients p
             LEFT JOIN users u ON u.user_id = p.user_id
             ORDER BY p.created_at, u.full_name");
        foreach ($q as $r) {
            $rows[] = [
                "id"              => (string)$r["patient_id"],
                "full_name"       => $r["full_name"]       ?? "",
                "username"        => $r["username"]        ?? "",
                "email"           => $r["email"]           ?? "",
                "phone"           => $r["phone_number"]    ?? "",
                "dob"             => $r["dob"]             ?? "",
                "gender"          => $r["gender"]          ?? "",
                "address"         => $r["address"]         ?? "",
                "medical_history" => $r["medical_history"] ?? "",
                "created_at"      => substr((string)$r["created_at"], 0, 10),
            ];
        }
        respond(200, ["success" => true, "data" => $rows, "genders" => $genders]);
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        respond(405, ["success" => false, "message" => "Method not allowed."]);
    }

    // ---------- CREATE (tạo tài khoản role patient + hồ sơ bệnh nhân) ----------
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
        [$profile, $err] = readProfile($genders);
        if ($err) respond(400, ["success" => false, "message" => $err]);

        if (usernameExists($pdo, $username)) {
            respond(409, ["success" => false, "message" => "Username already exists."]);
        }

        $newUserId = uuidv4();
        $hash      = password_hash($password, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO users (user_id, full_name, username, user_password, email, phone_number, role, user_status)
             VALUES (?, ?, ?, ?, ?, ?, 'patient', 1)"
        )->execute([$newUserId, $fullName, $username, $hash, $email, $phone]);

        $pdo->prepare(
            "INSERT INTO patients (user_id, dob, gender, address, medical_history)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$newUserId, $profile["dob"], $profile["gender"], $profile["address"], $profile["history"]]);
        $pdo->commit();

        respond(200, ["success" => true, "message" => "Patient created."]);
    }

    // ---------- UPDATE (chỉ sửa hồ sơ bệnh nhân; thông tin tài khoản sửa ở Users Management) ----------
    if ($action === "update") {
        $patientId = trim((string)($_POST["id"] ?? ""));
        if ($patientId === "") {
            respond(400, ["success" => false, "message" => "Missing patient id."]);
        }

        $chk = $pdo->prepare("SELECT 1 FROM patients WHERE patient_id = ?");
        $chk->execute([$patientId]);
        if (!$chk->fetchColumn()) {
            respond(404, ["success" => false, "message" => "Patient not found."]);
        }

        [$profile, $err] = readProfile($genders);
        if ($err) respond(400, ["success" => false, "message" => $err]);

        $pdo->prepare(
            "UPDATE patients SET dob = ?, gender = ?, address = ?, medical_history = ? WHERE patient_id = ?"
        )->execute([$profile["dob"], $profile["gender"], $profile["address"], $profile["history"], $patientId]);

        respond(200, ["success" => true, "message" => "Patient updated."]);
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