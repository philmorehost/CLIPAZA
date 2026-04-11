<?php
declare(strict_types=1);

$checks = [];
$allPass = true;

// PHP Version
$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
$checks[] = ['label' => 'PHP Version (>= 8.0)', 'pass' => $phpOk, 'value' => PHP_VERSION, 'required' => true];
if (!$phpOk) $allPass = false;

// PDO
$pdoOk = extension_loaded('pdo');
$checks[] = ['label' => 'PDO Extension', 'pass' => $pdoOk, 'value' => $pdoOk ? 'Enabled' : 'Missing', 'required' => true];
if (!$pdoOk) $allPass = false;

// PDO MySQL
$pdoMysqlOk = extension_loaded('pdo_mysql');
$checks[] = ['label' => 'PDO MySQL Driver', 'pass' => $pdoMysqlOk, 'value' => $pdoMysqlOk ? 'Enabled' : 'Missing', 'required' => true];
if (!$pdoMysqlOk) $allPass = false;

// cURL
$curlOk = extension_loaded('curl');
$checks[] = ['label' => 'cURL Extension', 'pass' => $curlOk, 'value' => $curlOk ? 'Enabled' : 'Missing', 'required' => true];
if (!$curlOk) $allPass = false;

// mbstring
$mbOk = extension_loaded('mbstring');
$checks[] = ['label' => 'mbstring Extension', 'pass' => $mbOk, 'value' => $mbOk ? 'Enabled' : 'Missing', 'required' => true];
if (!$mbOk) $allPass = false;

// openssl
$sslOk = extension_loaded('openssl');
$checks[] = ['label' => 'OpenSSL Extension', 'pass' => $sslOk, 'value' => $sslOk ? 'Enabled' : 'Missing', 'required' => true];
if (!$sslOk) $allPass = false;

// json
$jsonOk = extension_loaded('json');
$checks[] = ['label' => 'JSON Extension', 'pass' => $jsonOk, 'value' => $jsonOk ? 'Enabled' : 'Missing', 'required' => true];
if (!$jsonOk) $allPass = false;

// Writable config dir
$configDir = dirname(__DIR__, 2) . '/config';
$dirWritable = is_writable($configDir) || (!file_exists($configDir) && is_writable(dirname($configDir)));
$checks[] = ['label' => 'Config Directory Writable', 'pass' => $dirWritable, 'value' => $dirWritable ? 'Writable' : 'Not Writable', 'required' => true];
if (!$dirWritable) $allPass = false;

if ($allPass && isset($_POST['next'])) {
    $_SESSION['install_step_1_done'] = true;
    header('Location: ?step=2');
    exit;
}
?>
<h3 style="font-size:1.1rem;font-weight:700;margin-bottom:4px;">System Requirements</h3>
<p style="color:#666;font-size:0.875rem;margin-bottom:24px;">Checking your server environment.</p>

<div>
    <?php foreach ($checks as $check): ?>
    <div class="req-item">
        <span class="req-icon <?= $check['pass'] ? 'pass' : 'fail' ?>">
            <?= $check['pass'] ? '✓' : '✗' ?>
        </span>
        <span class="req-label"><?= htmlspecialchars($check['label']) ?></span>
        <span class="req-value"><?= htmlspecialchars($check['value']) ?></span>
    </div>
    <?php endforeach; ?>
</div>

<?php if (!$allPass): ?>
<div class="alert-dark-danger mt-4">
    Please fix the requirements above before continuing.
</div>
<?php endif; ?>

<div class="d-flex justify-content-end mt-4">
    <form method="POST">
        <button type="submit" name="next" value="1"
                class="btn btn-accent"
                <?= !$allPass ? 'disabled' : ' ?>>
            Continue →
        </button>
    </form>
</div>