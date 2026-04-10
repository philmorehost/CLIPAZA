<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$host = trim($_POST['db_host'] ?? 'localhost');
$port = trim($_POST['db_port'] ?? '3306');
$name = trim($_POST['db_name'] ?? '');
$user = trim($_POST['db_user'] ?? '');
$pass = $_POST['db_pass'] ?? '';

if (empty($user)) {
    echo json_encode(['success' => false, 'message' => 'Database user is required.']);
    exit;
}

try {
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT    => 5,
    ]);

    $version = $pdo->query('SELECT VERSION()')->fetchColumn();

    if (!empty($name)) {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$name}`");
        $msg = "Connected successfully! MySQL {$version}. Database '{$name}' is ready.";
    } else {
        $msg = "Connected successfully! MySQL {$version}.";
    }

    echo json_encode(['success' => true, 'message' => $msg]);
} catch (PDOException $e) {
    $message = $e->getMessage();
    // Sanitize error message to avoid leaking credentials
    $message = preg_replace('/\[.*?\]/', '', $message);
    $message = trim($message);
    echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $message]);
}