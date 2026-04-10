<?php
declare(strict_types=1);

$errors   = [];
$success  = false;
$dbConfig = $_SESSION['db_config'] ?? [];

if (empty($dbConfig)) {
    header('Location: ?step=2');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_next'])) {
    $username  = trim($_POST['admin_username'] ?? '');
    $email     = trim($_POST['admin_email'] ?? '');
    $password  = $_POST['admin_password'] ?? '';
    $password2 = $_POST['admin_password2'] ?? '';

    if (strlen($username) < 3)  $errors[] = 'Username must be at least 3 characters.';
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) $errors[] = 'Username can only contain letters, numbers, and underscores.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email address is required.';
    if (strlen($password) < 8)  $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $password2) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        try {
            $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // Check if admin already exists
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $errors[] = 'Username or email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$username, $email, $hash, 'admin', 'active']);

                // Create installer.lock to prevent re-running the installer
                file_put_contents(dirname(__DIR__) . '/installer.lock', date('Y-m-d H:i:s'));

                $success = true;
                $_SESSION = [];
            }
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<?php if ($success): ?>
<div class="text-center py-4">
    <svg class="checkmark-svg" width="80" height="80" viewBox="0 0 80 80" style="margin-bottom:20px;">
        <circle class="checkmark-circle" cx="40" cy="40" r="36"/>
        <polyline class="checkmark-check" points="24,40 35,52 56,28"/>
    </svg>
    <h2 style="font-size:1.5rem;font-weight:800;color:#fff;margin-bottom:8px;">Installation Complete!</h2>
    <p style="color:#666;margin-bottom:32px;">Clipaza has been successfully installed and configured.</p>
    <a href="../admin/login.php" class="btn btn-accent btn-lg pulse-accent">
        Go to Admin Panel →
    </a>
    <p style="color:#555;font-size:0.8rem;margin-top:16px;">
        ⚠️ For security, please delete the <code style="color:#CCFF00;">/install</code> directory from your server.
    </p>
</div>
<?php else: ?>
<h3 style="font-size:1.1rem;font-weight:700;margin-bottom:4px;">Create Admin Account</h3>
<p style="color:#666;font-size:0.875rem;margin-bottom:24px;">Set up your administrator credentials.</p>

<?php foreach ($errors as $err): ?>
<div class="alert-dark-danger mb-3"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<form method="POST">
    <div class="mb-3">
        <label class="form-label-dark">Admin Username</label>
        <input type="text" name="admin_username" class="form-control form-control-dark"
               value="<?= htmlspecialchars($_POST['admin_username'] ?? 'admin') ?>"
               pattern="[a-zA-Z0-9_]+" minlength="3" required>
    </div>
    <div class="mb-3">
        <label class="form-label-dark">Admin Email</label>
        <input type="email" name="admin_email" class="form-control form-control-dark"
               value="<?= htmlspecialchars($_POST['admin_email'] ?? ($_SESSION['site_config']['adminEmail'] ?? '')) ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label-dark">Password</label>
        <input type="password" name="admin_password" class="form-control form-control-dark"
               id="admin_password" data-password-strength="admin_pw" minlength="8" required>
        <div class="password-strength-bar mt-2" id="admin_pw_bar">
            <div class="strength-fill"></div>
        </div>
        <div id="admin_pw_label" class="password-strength-label"></div>
    </div>
    <div class="mb-3">
        <label class="form-label-dark">Confirm Password</label>
        <input type="password" name="admin_password2" class="form-control form-control-dark" minlength="8" required>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-4">
        <a href="?step=3" class="btn" style="color:#888;background:rgba(255,255,255,0.05);border-radius:8px;padding:10px 20px;">← Back</a>
        <button type="submit" name="admin_next" value="1" class="btn btn-accent">Complete Installation →</button>
    </div>
</form>
<?php endif; ?>