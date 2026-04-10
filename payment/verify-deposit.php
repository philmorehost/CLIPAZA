<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$reference = sanitizeInput($_GET['reference'] ?? '');

if (empty($reference)) {
    header('Location: /wallet?error=no_reference');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifying Deposit... — Clipaza</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/style.css" rel="stylesheet">
</head>
<body style="background:#000;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh">
    <div class="text-center">
        <div class="spinner-accent mb-4" style="width:48px;height:48px;border-width:4px"></div>
        <h2 id="msg" class="fw-900">Verifying your deposit...</h2>
        <p class="text-muted" id="subtext">Please do not close this window.</p>
    </div>
    <script>
        const ref = '<?= e($reference) ?>';
        fetch('/ajax/payment_actions.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action:'verify_deposit', reference: ref})
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                document.getElementById('msg').textContent = 'Deposit Successful! ✅';
                document.getElementById('subtext').textContent = 'Redirecting to your wallet...';
                setTimeout(() => window.location.href = '/wallet?success=deposited', 2000);
            } else {
                document.getElementById('msg').textContent = 'Deposit Failed ❌';
                document.getElementById('subtext').textContent = d.message || 'Verification failed.';
                setTimeout(() => window.location.href = '/wallet?error=failed', 3000);
            }
        })
        .catch(() => {
            document.getElementById('msg').textContent = 'Verification error.';
            document.getElementById('subtext').textContent = 'Please check your wallet in a few minutes.';
        });
    </script>
</body>
</html>
