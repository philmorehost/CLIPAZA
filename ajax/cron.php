<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/contest_manager.php';

// Protected by a key
$cronKey = getSetting('cron_key', 'default_cron_key');
if (($_GET['key'] ?? '') !== $cronKey) {
    http_response_code(403);
    die('Unauthorized');
}

processExpiredContests();

echo "Cron executed successfully at " . date('Y-m-d H:i:s');
