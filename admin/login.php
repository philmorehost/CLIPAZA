<?php
declare(strict_types=1);
session_start();

$root = dirname(__DIR__);
$configFile = $root . '/config/config.php';

if (!file_exists($configFile)) {
    die('Application not installed. <a href="../install/">Run installer</a>');
}

require_once $configFile;
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/security.php';
require_once $root . '/includes/auth.php';

if (isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'admin') {
    redirect('index.php');
}

$error   = '';
$locked  = false;
$csrf    = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = $_POST['csrf_token'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!verifyCsrfToken($token)) {
        $error = 'Invalid request. Please try again.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Please enter your username and password.';
    } else {
        $result = login($username, $password);
        if ($result['success']) {
            if (($_SESSION['user_role'] ?? '') !== 'admin') {
                logout();
                $error = 'Access denied. Admin account required.';
            } else {
                redirect('index.php');
            }
        } else {
            $error = $result['message'];
            if (str_contains($error, 'locked') || str_contains($error, 'blocked')) {
                $locked = true;
            }
        }
    }
    $csrf = generateCsrfToken();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — Clipaza</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:#000;">
<div style="width:100%;max-width:400px;padding:24px;">
    <div class="text-center mb-4">
        <div style="font-size:1.8rem;font-weight:900;letter-spacing:-1px;">Clipa<span style="color:#CCFF00;">za</span></div>
        <p style="color:#555;font-size:0.875rem;margin-top:4px;">Admin Panel</p>
    </div>

    <div class="card-dark">
        <div class="card-header">Sign In</div>
        <div class="card-body">
            <?php if ($error): ?>
            <div class="alert-dark-<?= $locked ? 'warning' : 'danger' ?> mb-3">
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <div class="mb-3">
                    <label class="form-label-dark">Username or Email</label>
                    <input type="text" name="username" class="form-control form-control-dark"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           autocomplete="username" required <?= $locked ? 'disabled' : '' ?>>
                </div>
                <div class="mb-4">
                    <label class="form-label-dark">Password</label>
                    <input type="password" name="password" class="form-control form-control-dark"
                           autocomplete="current-password" required <?= $locked ? 'disabled' : '' ?>>
                </div>
                <button type="submit" class="btn btn-accent w-100" <?= $locked ? 'disabled' : '' ?>>
                    Sign In
                </button>
            </form>
        </div>
    </div>

    <p class="text-center mt-3" style="font-size:0.8rem;color:#555;">
        <a href="../" style="color:#555;">← Back to site</a>
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
</body>
</html>
