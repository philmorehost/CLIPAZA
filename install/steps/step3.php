<?php
declare(strict_types=1);

$errors   = [];
$dbConfig = $_SESSION['db_config'] ?? [];

if (empty($dbConfig)) {
    header('Location: ?step=2');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['site_next'])) {
    $siteName       = trim($_POST['site_name'] ?? '');
    $siteUrl        = rtrim(trim($_POST['site_url'] ?? ''), '/');
    $adminEmail     = trim($_POST['admin_email'] ?? '');
    $smtpHost       = trim($_POST['smtp_host'] ?? '');
    $smtpPort       = trim($_POST['smtp_port'] ?? '587');
    $smtpUser       = trim($_POST['smtp_user'] ?? '');
    $smtpPass       = $_POST['smtp_pass'] ?? '';
    $smtpEncryption = $_POST['smtp_encryption'] ?? 'tls';

    if (empty($siteName))   $errors[] = 'Site name is required.';
    if (empty($siteUrl))    $errors[] = 'Site URL is required.';
    if (empty($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid admin email is required.';

    if (empty($errors)) {
        $root       = dirname(__DIR__, 2);
        $configDir  = $root . '/config';
        $schemaFile = __DIR__ . '/../schema.sql';
        $tmplFile   = $configDir . '/config.template.php';

        // Run schema
        try {
            // Re-validate DB name from session (alphanumeric + underscores only) before using in DDL
            $safeDbName = $dbConfig['name'];
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $safeDbName)) {
                throw new RuntimeException('Invalid database name stored in session.');
            }
            $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$safeDbName}`");

            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                // Split on semicolons and execute each statement
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                $schemaErrors = [];
                foreach ($statements as $stmt) {
                    if (!empty($stmt)) {
                        try { $pdo->exec($stmt); } catch (PDOException $ex) {
                            // Collect non-trivial errors (ignore duplicate/already-exists)
                            if (!str_contains($ex->getMessage(), 'already exists') &&
                                !str_contains($ex->getMessage(), 'Duplicate entry')) {
                                $schemaErrors[] = $ex->getMessage();
                            }
                        }
                    }
                }
                if (!empty($schemaErrors)) {
                    $errors[] = 'Schema error: ' . implode('; ', array_slice($schemaErrors, 0, 3));
                }
            }

            // Save site settings
            $pdo->exec("USE `{$safeDbName}`");
            $settings = [
                'site_name'  => $siteName,
                'site_url'   => $siteUrl,
                'admin_email'=> $adminEmail,
            ];
            $settingStmt = $pdo->prepare(
                'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            foreach ($settings as $k => $v) {
                $settingStmt->execute([$k, $v]);
            }
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }

        if (empty($errors)) {
            // Write config
            $appKey = bin2hex(random_bytes(32));
            if (file_exists($tmplFile)) {
                $config = file_get_contents($tmplFile);
                $replacements = [
                    '{{DB_HOST}}'        => $dbConfig['host'],
                    '{{DB_PORT}}'        => $dbConfig['port'],
                    '{{DB_NAME}}'        => $dbConfig['name'],
                    '{{DB_USER}}'        => $dbConfig['user'],
                    '{{DB_PASS}}'        => $dbConfig['pass'],
                    '{{SITE_NAME}}'      => $siteName,
                    '{{SITE_URL}}'       => $siteUrl,
                    '{{ADMIN_EMAIL}}'    => $adminEmail,
                    '{{SMTP_HOST}}'      => $smtpHost,
                    '{{SMTP_PORT}}'      => $smtpPort,
                    '{{SMTP_USER}}'      => $smtpUser,
                    '{{SMTP_PASS}}'      => $smtpPass,
                    '{{SMTP_ENCRYPTION}}'=> $smtpEncryption,
                    '{{APP_KEY}}'        => $appKey,
                ];
                $config = str_replace(array_keys($replacements), array_values($replacements), $config);
                if (!file_put_contents($configDir . '/config.php', $config)) {
                    $errors[] = 'Failed to write config.php. Check directory permissions.';
                }
            } else {
                // Write config directly
                $config  = "<?php\n";
                $config .= "define('DB_HOST', '" . addslashes($dbConfig['host']) . "');\n";
                $config .= "define('DB_PORT', '" . addslashes($dbConfig['port']) . "');\n";
                $config .= "define('DB_NAME', '" . addslashes($dbConfig['name']) . "');\n";
                $config .= "define('DB_USER', '" . addslashes($dbConfig['user']) . "');\n";
                $config .= "define('DB_PASS', '" . addslashes($dbConfig['pass']) . "');\n";
                $config .= "define('SITE_NAME', '" . addslashes($siteName) . "');\n";
                $config .= "define('SITE_URL', '" . addslashes($siteUrl) . "');\n";
                $config .= "define('ADMIN_EMAIL', '" . addslashes($adminEmail) . "');\n";
                $config .= "define('SMTP_HOST', '" . addslashes($smtpHost) . "');\n";
                $config .= "define('SMTP_PORT', '" . addslashes($smtpPort) . "');\n";
                $config .= "define('SMTP_USER', '" . addslashes($smtpUser) . "');\n";
                $config .= "define('SMTP_PASS', '" . addslashes($smtpPass) . "');\n";
                $config .= "define('SMTP_ENCRYPTION', '" . addslashes($smtpEncryption) . "');\n";
                $config .= "define('APP_KEY', '" . $appKey . "');\n";
                $config .= "define('INSTALLED', true);\n";
                if (!file_put_contents($configDir . '/config.php', $config)) {
                    $errors[] = 'Failed to write config.php. Check directory permissions.';
                }
            }
        }

        if (empty($errors)) {
            $_SESSION['site_config'] = compact('siteName', 'siteUrl', 'adminEmail');
            $_SESSION['install_step_3_done'] = true;
            header('Location: ?step=4');
            exit;
        }
    }
}

$siteUrl = $_SESSION['site_config']['siteUrl'] ?? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
?>
<h3 style="font-size:1.1rem;font-weight:700;margin-bottom:4px;">Site Configuration</h3>
<p style="color:#666;font-size:0.875rem;margin-bottom:24px;">Configure your site and email settings.</p>

<?php foreach ($errors as $err): ?>
<div class="alert-dark-danger mb-3"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<form method="POST">
    <div class="mb-3">
        <label class="form-label-dark">Site Name</label>
        <input type="text" name="site_name" class="form-control form-control-dark"
               value="<?= htmlspecialchars($_POST['site_name'] ?? 'Clipaza') ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label-dark">Site URL</label>
        <input type="url" name="site_url" class="form-control form-control-dark"
               value="<?= htmlspecialchars($_POST['site_url'] ?? $siteUrl) ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label-dark">Admin Email</label>
        <input type="email" name="admin_email" class="form-control form-control-dark"
               value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>
    </div>

    <hr class="divider-dark">
    <p style="font-size:0.8rem;color:#555;margin-bottom:16px;">SMTP Settings (optional — leave blank to use PHP mail)</p>

    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label-dark">SMTP Host</label>
            <input type="text" name="smtp_host" class="form-control form-control-dark"
                   value="<?= htmlspecialchars($_POST['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com">
        </div>
        <div class="col-md-4">
            <label class="form-label-dark">SMTP Port</label>
            <input type="number" name="smtp_port" class="form-control form-control-dark"
                   value="<?= htmlspecialchars($_POST['smtp_port'] ?? '587') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label-dark">SMTP User</label>
            <input type="email" name="smtp_user" class="form-control form-control-dark"
                   value="<?= htmlspecialchars($_POST['smtp_user'] ?? '') ?>" placeholder="user@gmail.com">
        </div>
        <div class="col-md-6">
            <label class="form-label-dark">SMTP Password</label>
            <input type="password" name="smtp_pass" class="form-control form-control-dark"
                   value="<?= htmlspecialchars($_POST['smtp_pass'] ?? '') ?>">
        </div>
        <div class="col-12">
            <label class="form-label-dark">Encryption</label>
            <select name="smtp_encryption" class="form-select form-select-dark">
                <option value="tls" <?= ($_POST['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (Recommended)</option>
                <option value="ssl" <?= ($_POST['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                <option value="none" <?= ($_POST['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
            </select>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-4">
        <a href="?step=2" class="btn" style="color:#888;background:rgba(255,255,255,0.05);border-radius:8px;padding:10px 20px;">← Back</a>
        <button type="submit" name="site_next" value="1" class="btn btn-accent">Continue →</button>
    </div>
</form>