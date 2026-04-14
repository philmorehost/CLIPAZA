<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['user_id'])) redirect('/dashboard');

$token    = sanitizeInput($_GET['token'] ?? '');
$error    = '';
$success  = false;
$tokenRow = null;

if (empty($token)) redirect('/auth/forgot-password');

try {
    $db   = db();
    $stmt = $db->prepare(
        'SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([$token]);
    $tokenRow = $stmt->fetch();
} catch (Throwable) {
    $error = 'A database error occurred.';
}

if (!$tokenRow && !$error) {
    $error = 'This reset link is invalid or has expired. Please request a new one.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenRow) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $password        = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $db   = db();
                $hash = hashPassword($password);
                $db->prepare('UPDATE users SET password = ? WHERE email = ?')->execute([$hash, $tokenRow['email']]);
                $db->prepare('UPDATE password_resets SET used = 1 WHERE token = ?')->execute([$token]);
                $success = true;
            } catch (Throwable) {
                $error = 'Failed to update password. Please try again.';
            }
        }
    }
}

$csrf = generateCsrfToken();
$siteName = getSetting('site_name', 'Clipaza');
$siteLogo = getSetting('site_logo', '');

renderHead('Reset Password');
?>
<div class="public-page d-flex align-items-center justify-content-center" style="min-height:100vh;background:var(--bg);padding:40px 16px">
  <div class="w-100" style="max-width:400px">
    <div class="text-center mb-4">
      <a href="/" class="text-decoration-none">
        <?php if ($siteLogo): ?>
          <img src="<?= e($siteLogo) ?>" alt="<?= e($siteName) ?>" style="height:48px;max-width:100%;object-fit:contain">
        <?php else: ?>
          <span style="font-size:1.5rem;font-weight:900;color:var(--text);letter-spacing:-0.5px"><?= e($siteName) ?><span style="color:var(--accent)">.</span></span>
        <?php endif; ?>
      </a>
    </div>

    <div class="card-dark p-4">
      <?php if ($success): ?>
        <div class="text-center py-3">
          <div style="font-size:2.5rem;margin-bottom:12px">✅</div>
          <h5 class="fw-700 mb-2">Password updated!</h5>
          <p class="text-muted" style="font-size:0.9rem">Your password has been reset successfully.</p>
          <a href="/auth/login" class="btn btn-accent mt-2">Sign In Now</a>
        </div>
      <?php elseif ($error && !$tokenRow): ?>
        <div class="text-center py-3">
          <div style="font-size:2.5rem;margin-bottom:12px">⚠️</div>
          <h5 class="fw-700 mb-2">Invalid Reset Link</h5>
          <p class="text-muted" style="font-size:0.9rem"><?= e($error) ?></p>
          <a href="/auth/forgot-password" class="btn btn-outline-accent mt-2">Request New Link</a>
        </div>
      <?php else: ?>
        <h5 class="fw-700 mb-4">Set new password</h5>

        <?php if ($error): ?>
          <div class="alert-dark-danger mb-3"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <div class="mb-3">
            <label class="form-label-dark">New Password</label>
            <input type="password" name="password" class="form-control-dark" placeholder="Minimum 8 characters" minlength="8" required>
          </div>
          <div class="mb-4">
            <label class="form-label-dark">Confirm New Password</label>
            <input type="password" name="confirm_password" class="form-control-dark" placeholder="Repeat password" required>
          </div>
          <button type="submit" class="btn btn-accent w-100">Update Password</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php renderFooter(); ?>
