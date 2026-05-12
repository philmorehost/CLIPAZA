<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/mailer.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['user_id'])) redirect('/dashboard');

$submitted = false;
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = sanitizeInput($_POST['email'] ?? '');
        if (!isValidEmail($email)) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                $db   = db();
                $stmt = $db->prepare('SELECT id, username FROM users WHERE email = ? AND status = \'active\' LIMIT 1');
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user) {
                    // Invalidate previous tokens
                    $db->prepare('UPDATE password_resets SET used = 1 WHERE email = ?')->execute([$email]);

                    $token  = generateToken(32);
                    $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    $db->prepare(
                        'INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)'
                    )->execute([$email, $token, $expiry]);

                    $siteUrl  = defined('SITE_URL') ? SITE_URL : getSetting('site_url', '');
                    $resetUrl = rtrim($siteUrl, '/') . '/auth/reset-password?token=' . urlencode($token);
                    $siteName = getSetting('site_name', 'Clipaza');
                    $username = e($user['username']);

                    $html = "<p>Hi {$username},</p>"
                          . "<p>We received a request to reset your password. Click the link below to set a new password:</p>"
                          . "<p><a href='{$resetUrl}'>{$resetUrl}</a></p>"
                          . "<p>This link expires in 1 hour. If you didn't request this, ignore this email.</p>"
                          . "<p>— {$siteName} Team</p>";

                    $mailer = new Mailer();
                    $mailer->send($email, 'Reset your ' . $siteName . ' password', $html);
                }
                // Always show success to prevent email enumeration
                $submitted = true;
            } catch (Throwable) {
                $error = 'An error occurred. Please try again.';
            }
        }
    }
}

$csrf = generateCsrfToken();
$siteName = getSetting('site_name', 'Clipaza');
$siteLogo = getSetting('site_logo', '');

renderHead('Forgot Password');
?>
<div class="public-page d-flex align-items-center justify-content-center" style="min-height:100vh;background:var(--bg);padding:40px 16px">
  <div class="w-100" style="max-width:400px">
    <div class="text-center mb-4">
      <a href="/" class="text-decoration-none">
        <?php if ($siteLogo): ?>
          <img src="<?= e($siteLogo) ?>" alt="<?= e($siteName) ?>" style="height:48px;max-width:100%;object-fit:contain">
        <?php else: ?>
          <span style="font-size:1.5rem;font-weight:900;color:var(--text);letter-spacing:-0.5px"><?= formatSiteName($siteName) ?></span>
        <?php endif; ?>
      </a>
    </div>

    <div class="card-dark p-4">
      <?php if ($submitted): ?>
        <div class="text-center py-3">
          <div style="font-size:2.5rem;margin-bottom:12px">📬</div>
          <h5 class="fw-700 mb-2">Check your inbox</h5>
          <p class="text-muted" style="font-size:0.9rem">If an account exists for that email, we've sent a password reset link. It expires in 1 hour.</p>
          <a href="/auth/login" class="btn btn-outline-accent mt-2">Back to Login</a>
        </div>
      <?php else: ?>
        <h5 class="fw-700 mb-2">Reset your password</h5>
        <p class="text-muted mb-4" style="font-size:0.85rem">Enter your email and we'll send you a reset link.</p>

        <?php if ($error): ?>
          <div class="alert-dark-danger mb-3"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <div class="mb-4">
            <label class="form-label-dark">Email Address</label>
            <input type="email" name="email" class="form-control-dark" placeholder="you@example.com" required>
          </div>
          <button type="submit" class="btn btn-accent w-100">Send Reset Link</button>
        </form>
      <?php endif; ?>
    </div>

    <p class="text-center text-muted mt-3" style="font-size:0.85rem">
      Remembered it? <a href="/auth/login" class="text-accent text-decoration-none">Sign in</a>
    </p>
  </div>
</div>
<?php renderFooter(); ?>
