<?php
declare(strict_types=1);
session_start();

$root = dirname(__DIR__);

// Guard against re-installation
if (file_exists(__DIR__ . '/installed.lock') || (file_exists($root . '/config/config.php') && file_exists(__DIR__ . '/installed.lock'))) {
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
    <p style="color:#555;font-size:0.875rem;">Installation Wizard</p>

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