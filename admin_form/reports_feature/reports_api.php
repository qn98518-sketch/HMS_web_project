<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set('display_errors', '0');
require_once __DIR__ . '/../php/auth_guard.php';
require_once __DIR__ . '/reports_service.php';
try {
    $conn = mysqli_connect('localhost', 'root', '', 'hospital_management_system');
    mysqli_set_charset($conn, 'utf8mb4');
    requireAdmin($conn);
    mysqli_close($conn);
    session_write_close();
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }
    $year = null;
    if (isset($_GET['year'])) {
        $year = filter_var($_GET['year'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1900, 'max_range' => 2100]]);
        if ($year === false) throw new InvalidArgumentException('Year must be between 1900 and 2100.');
    }
    $pdo = new PDO('mysql:host=localhost;dbname=hospital_management_system;charset=utf8mb4', 'root', '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    echo json_encode(reportsList($pdo, $year), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Cannot load reports. Please try again later.']);
}
