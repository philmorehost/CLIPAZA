<?php
/**
 * CLIPAZA - Entry Point
 *
 * This file is intentionally written using PHP 5.4-compatible syntax so that
 * the version check executes correctly even on old PHP installations. No PHP
 * 7.4+ typed properties, no PHP 8.0+ union types, no match expressions, no
 * named arguments, and no nullsafe operators are used here.
 */

define('CLIPAZA_MIN_PHP', '8.0.0');
define('CLIPAZA_ROOT', __DIR__);

// ------------------------------------------------------------------
// PHP version gate – must be the very first thing that runs, and must
// use only syntax that any PHP 5+ interpreter can parse successfully.
// ------------------------------------------------------------------
if (version_compare(PHP_VERSION, CLIPAZA_MIN_PHP, '<')) {
    header('Content-Type: text/html; charset=utf-8');
    header('HTTP/1.1 503 Service Unavailable');
    $required = htmlspecialchars(CLIPAZA_MIN_PHP, ENT_QUOTES, 'UTF-8');
    $current  = htmlspecialchars(PHP_VERSION,    ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Version Requirement Not Met &mdash; CLIPAZA</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f4f4;display:flex;
             align-items:center;justify-content:center;min-height:100vh;margin:0}
        .box{background:#fff;border-left:4px solid #e74c3c;border-radius:4px;
             padding:2rem 2.5rem;max-width:480px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h2{margin-top:0;color:#e74c3c}
        p{line-height:1.6;color:#555}
        code{background:#f0f0f0;padding:2px 6px;border-radius:3px;font-size:.9em}
    </style>
</head>
<body>
    <div class="box">
        <h2>&#9888; PHP Version Requirement Not Met</h2>
        <p>CLIPAZA requires <strong>PHP ' . $required . '</strong> or higher.</p>
        <p>Your server is running <strong>PHP <code>' . $current . '</code></strong>.</p>
        <p>Please upgrade PHP or contact your hosting provider for assistance.</p>
    </div>
</body>
</html>';
    exit;
}

// ------------------------------------------------------------------
// Past this point PHP 8.0+ syntax is safe to use.
// ------------------------------------------------------------------

// Redirect to installer if not yet installed.
if (!file_exists(CLIPAZA_ROOT . '/installer.lock')) {
    header('Location: /install/');
    exit;
}

// Load configuration and bootstrap the application.
$configFile = CLIPAZA_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    header('Location: /install/');
    exit;
}

require_once $configFile;
require_once CLIPAZA_ROOT . '/includes/functions.php';

// Application bootstrap placeholder – extend this as needed.
echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CLIPAZA</title>
</head>
<body>
    <h1>Welcome to CLIPAZA</h1>
</body>
</html>';

