<?php
/**
 * PHP version gate.
 * This block uses only PHP 5.4-compatible syntax so it can be parsed and
 * executed on any PHP version, displaying a friendly error instead of a
 * cryptic internal server error when the server runs PHP < 8.0.
 * NOTE: declare(strict_types=1) is intentionally omitted from this entry-
 * point file because it must be the first statement, which would prevent
 * placing the version check first. Strict type checking is enforced in
 * all included library files (includes/*.php).
 */
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    header('Content-Type: text/html; charset=utf-8');
    header('HTTP/1.1 503 Service Unavailable');
    $required = htmlspecialchars('8.0.0', ENT_QUOTES, 'UTF-8');
    $current  = htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PHP Version Requirement Not Met</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f4f4;display:flex;
             align-items:center;justify-content:center;min-height:100vh;margin:0}
        .box{background:#fff;border-left:4px solid #e74c3c;border-radius:4px;
             padding:2rem 2.5rem;max-width:480px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h2{margin-top:0;color:#e74c3c}
        p{line-height:1.6;color:#888}
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

session_start();

$root = dirname(__DIR__);

// Guard against re-installation
if (file_exists(dirname(__DIR__) . '/installer.lock')) {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><title>Already Installed</title><style>body{background:#000;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;}</style></head><body><div style="text-align:center"><h1 style="color:#CCFF00">Clipaza</h1><p>This application has already been installed.</p><a href="../admin/login.php" style="color:#CCFF00">Go to Admin Panel</a></div></body></html>');
}

$step = max(1, min(4, (int)($_GET['step'] ?? 1)));

// Enforce sequential steps via session
if ($step > 1 && empty($_SESSION['install_step_' . ($step - 1) . '_done'])) {
    header('Location: ?step=1');
    exit;
}

function stepClass(int $s, int $current): string {
    if ($s < $current) return 'completed';
    if ($s === $current) return 'active';
    return '';
}

function stepLine(int $s, int $current): string {
    return $s < $current ? 'completed' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clipaza Installer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="installer-wrapper">
    <div class="installer-logo">Clipa<span>za</span></div>
    <p style="color:#888;font-size:0.875rem;">Installation Wizard</p>

    <div class="installer-card">
        <div class="installer-header">
            <!-- Progress Steps -->
            <div class="progress-steps">
                <div class="step <?= stepClass(1, $step) ?>">
                    <div class="step-circle"><?= $step > 1 ? '✓' : '1' ?></div>
                    <div class="step-label">Requirements</div>
                </div>
                <div class="step-line <?= stepLine(1, $step) ?>"></div>
                <div class="step <?= stepClass(2, $step) ?>">
                    <div class="step-circle"><?= $step > 2 ? '✓' : '2' ?></div>
                    <div class="step-label">Database</div>
                </div>
                <div class="step-line <?= stepLine(2, $step) ?>"></div>
                <div class="step <?= stepClass(3, $step) ?>">
                    <div class="step-circle"><?= $step > 3 ? '✓' : '3' ?></div>
                    <div class="step-label">Configuration</div>
                </div>
                <div class="step-line <?= stepLine(3, $step) ?>"></div>
                <div class="step <?= stepClass(4, $step) ?>">
                    <div class="step-circle">4</div>
                    <div class="step-label">Admin Account</div>
                </div>
            </div>
        </div>
        <div class="installer-body">
            <?php
            $stepFile = __DIR__ . "/steps/step{$step}.php";
            if (file_exists($stepFile)) {
                include $stepFile;
            } else {
                echo '<div class="alert-dark-danger">Step file not found.</div>';
            }
            ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
</body>
</html>
