<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set('display_errors', '0');
require_once __DIR__ . '/../php/auth_guard.php';
require_once __DIR__ . '/bills_service.php';

function billsRespond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = mysqli_connect('localhost', 'root', '', 'hospital_management_system');
    mysqli_set_charset($conn, 'utf8mb4');
    requireAdmin($conn);
    mysqli_close($conn);
    $method = $_SERVER['REQUEST_METHOD'];
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
    if (!in_array($method, ['GET', 'POST'], true) || ($action !== 'list' && $method !== 'POST')) {
        billsRespond(405, ['success' => false, 'message' => 'Method not allowed.']);
    }
    if (empty($_SESSION['bills_csrf'])) $_SESSION['bills_csrf'] = bin2hex(random_bytes(32));
    $token = $_SESSION['bills_csrf'];
    if ($action !== 'list' && !hash_equals($token, (string)($_POST['csrf_token'] ?? ''))) {
        billsRespond(403, ['success' => false, 'message' => 'Your form has expired. Refresh the page and try again.']);
    }
    session_write_close();
    $pdo = new PDO('mysql:host=localhost;dbname=hospital_management_system;charset=utf8mb4', 'root', '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $result = billsAction($pdo, $action, $_POST);
    if ($action === 'list') $result['csrf_token'] = $token;
    billsRespond(200, $result);
} catch (BillError $error) {
    billsRespond($error->status, ['success' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    billsRespond(500, ['success' => false, 'message' => 'Cannot process bills. Please try again later.']);
}
