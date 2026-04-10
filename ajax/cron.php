<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/contest_manager.php';

// This could be protected by a key
// if (($_GET['key'] ?? '') !== getSetting('cron_key')) die('Unauthorized');

processExpiredContests();

echo "Cron executed successfully at " . date('Y-m-d H:i:s');
