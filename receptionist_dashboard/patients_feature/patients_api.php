<?php
/*==============================================================
  patients_api.php - API for Receptionist Patients Feature
  action: list | create | update
  Receptionist can register patients at the front desk,
  search by name/phone, and update phone + address only.
==============================================================*/
session_start();
header("Content-Type: application/json; charset=UTF-8");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set("display_errors", "0");

if (!defined("PK")) define("PK", "user_id");

function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
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

function genderOptions(PDO $pdo): array {
    $row  = $pdo->query("SHOW COLUMNS FROM patients LIKE 'gender'")->fetch(PDO::FETCH_ASSOC);
    $opts = [];
    if ($row && preg_match_all("/'((?:[^']|'')*)'/", $row["Type"], $m)) {
        $opts = array_map(fn($s) => str_replace("''", "'", $s), $m[1]);
    }
    return $opts ?: ["male", "female", "others"];
}

function textLength(string $s): int {
    return function_exists("mb_strlen") ? mb_strlen($s, "UTF-8") : strlen($s);
}

require_once __DIR__ . "/../php/auth_guard.php";

try {
    $conn = mysqli_connect("localhost", "root", "", "hospital_management_system");
    mysqli_set_charset($conn, "utf8mb4");
    requireReceptionist($conn);

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
        $search = trim($_GET["search"] ?? "");

        $sql = "SELECT p.patient_id, u.full_name, u.phone_number,
                       p.dob, p.gender, p.address, u.username
                FROM patients p
                LEFT JOIN users u ON u.user_id = p.user_id
                WHERE (p.user_id IS NULL OR u.is_deleted = 0)";

        $params = [];

        if ($search !== "") {
            $sql .= " AND (u.full_name LIKE ? OR u.phone_number LIKE ?)";
            $like = "%" . $search . "%";
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= " ORDER BY p.created_at DESC, u.full_name";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt as $r) {
            $rows[] = [
                "id"        => (string)$r["patient_id"],
                "full_name" => $r["full_name"]    ?? "",
                "phone"     => $r["phone_number"] ?? "",
                "dob"       => $r["dob"]          ?? "",
                "gender"    => $r["gender"]        ?? "",
                "address"   => $r["address"]       ?? "",
                "username"  => $r["username"]      ?? "",
            ];
        }
        respond(200, ["success" => true, "data" => $rows, "genders" => $genders]);
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        respond(405, ["success" => false, "message" => "Method not allowed."]);
    }

    // ---------- CREATE ----------
    if ($action === "create") {
        $fullName = trim($_POST["full_name"] ?? "");
        $phone    = trim($_POST["phone"] ?? "");
        $email    = trim($_POST["email"] ?? "");
        $dob      = trim($_POST["dob"] ?? "");
        $gender   = trim($_POST["gender"] ?? "");
        $address  = trim($_POST["address"] ?? "");

        if ($fullName === "" || $phone === "") {
            respond(400, ["success" => false, "message" => "Full name and phone number are required."]);
        }
        if (!preg_match('/^[0-9]{9,10}$/', $phone)) {
            respond(400, ["success" => false, "message" => "Phone number must be 9 or 10 digits."]);
        }
        if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(400, ["success" => false, "message" => "Invalid email format."]);
        }

        // Validate dob
        if ($dob === "") {
            $dob = null;
        } else {
            $d = DateTime::createFromFormat("!Y-m-d", $dob);
            if (!$d || $d->format("Y-m-d") !== $dob || $d > new DateTime("today") || (int)$d->format("Y") < 1900) {
                respond(400, ["success" => false, "message" => "Invalid date of birth."]);
            }
        }

        // Validate gender
        if ($gender === "") {
            $gender = null;
        } else {
            $matched = null;
            foreach ($genders as $g) if (strcasecmp($g, $gender) === 0) $matched = $g;
            if ($matched === null) respond(400, ["success" => false, "message" => "Invalid gender."]);
            $gender = $matched;
        }

        if (textLength($address) > 255) {
            respond(400, ["success" => false, "message" => "Address is too long (max 255 characters)."]);
        }

        // Generate unique username like patient_XXXXX
        $username = "";
        for ($i = 0; $i < 20; $i++) {
            $candidate = "patient_" . str_pad(random_int(0, 99999), 5, "0", STR_PAD_LEFT);
            if (!usernameExists($pdo, $candidate)) {
                $username = $candidate;
                break;
            }
        }
        if ($username === "") {
            respond(500, ["success" => false, "message" => "Could not generate a unique username. Please try again."]);
        }

        $hash = password_hash($username, PASSWORD_DEFAULT);

        $newUserId = uuidv4();

        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO users (user_id, full_name, username, user_password, email, phone_number, role, user_status)
             VALUES (?, ?, ?, ?, ?, ?, 'patient', 1)"
        )->execute([$newUserId, $fullName, $username, $hash, $email ?: null, $phone]);

        $pdo->prepare(
            "INSERT INTO patients (user_id, dob, gender, address)
             VALUES (?, ?, ?, ?)"
        )->execute([$newUserId, $dob, $gender, $address === "" ? null : $address]);
        $pdo->commit();

        respond(200, ["success" => true, "message" => "Patient registered. Username: " . $username, "username" => $username]);
    }

    // ---------- UPDATE (phone + address only) ----------
    if ($action === "update" || $action === "edit") {
        $patientId = trim((string)($_POST["id"] ?? ""));
        if ($patientId === "") {
            respond(400, ["success" => false, "message" => "Missing patient id."]);
        }

        $phone   = trim($_POST["phone"] ?? "");
        $address = trim($_POST["address"] ?? "");

        if ($phone !== "" && !preg_match('/^[0-9]{9,10}$/', $phone)) {
            respond(400, ["success" => false, "message" => "Phone number must be 9 or 10 digits."]);
        }
        if (textLength($address) > 255) {
            respond(400, ["success" => false, "message" => "Address is too long (max 255 characters)."]);
        }

        $pdo->beginTransaction();

        // Lock the patient row
        $stmt = $pdo->prepare(
            "SELECT p.patient_id, p.user_id
             FROM patients p
             LEFT JOIN users u ON u.user_id = p.user_id
             WHERE p.patient_id = ? AND (p.user_id IS NULL OR u.is_deleted = 0)
             FOR UPDATE");
        $stmt->execute([$patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            respond(404, ["success" => false, "message" => "Patient not found."]);
        }

        // Update phone in users table
        if ($row["user_id"] && $phone !== "") {
            $pdo->prepare("UPDATE users SET phone_number = ? WHERE user_id = ?")
                ->execute([$phone, $row["user_id"]]);
        }

        // Update address in patients table
        $pdo->prepare("UPDATE patients SET address = ? WHERE patient_id = ?")
            ->execute([$address === "" ? null : $address, $patientId]);

        $pdo->commit();

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
