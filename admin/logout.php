<?php
declare(strict_types=1);
session_start();

$root = dirname(__DIR__);
if (file_exists($root . '/config/config')) {
    require_once $root . '/config/config.php';
    require_once $root . '/includes/db.php';
    require_once $root . '/includes/functions.php';
    require_once $root . '/includes/security.php';
    require_once $root . '/includes/auth.php';
    logout();
} else {
    session_destroy();
}

header('Location: login');
exit;
