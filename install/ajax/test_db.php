<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$host = trim($_POST['db_host'] ?? 'localhost');
$port = (int)($_POST['db_port'] ?? 3306);
$name = trim($_POST['db_name'] ?? ');
$user = trim($_POST['db_user'] ?? ');
$pass = $_POST['db_pass'] ?? ';

if (empty($user)) {
    echo json_encode(['success' => false, 'message' => 'Database user is required.']);
    exit;
}

// Validate host: allow hostname chars and IPv4/IPv6 only
if (!preg_match('/^[a-zA-Z0-9.\-_\[\]:]+$/', $host)) {
    echo json_encode(['success' => false, 'message' => 'Invalid database host.']);
    exit;
}

if ($port < 1 || $port > 65535) {
    echo json_encode(['success' => false, 'message' => 'Invalid database port.']);
    exit;
}

// Validate db name: alphanumeric and underscores only
if (!empty($name) && !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
    echo json_encode(['success' => false, 'message' => 'Database name must contain only letters, numbers, and underscores.']);
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
        // Name is validated as alphanumeric+underscore above — safe to interpolate with backtick quoting
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
    $message = preg_replace('/\[.*?\]/', ', $message);
    $message = trim($message);
    echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $message]);
}