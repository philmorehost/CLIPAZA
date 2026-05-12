<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';
require_once $root . '/includes/google_auth_helper.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['user_id'])) redirect('/dashboard');

$error     = '';
$fieldVal  = '';
$rememberMe = false;

// Handle remember-me cookie pre-fill
if (!empty($_COOKIE['clipaza_remember'])) {
    $fieldVal = e($_COOKIE['clipaza_remember']);
}

$googleHelper = new GoogleAuthHelper();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $identifier = sanitizeInput($_POST['identifier'] ?? '');
        $password   = $_POST['password'] ?? '';
        $remember   = !empty($_POST['remember_me']);

        if (empty($identifier) || empty($password)) {
            $error = 'Please enter your username/email and password.';
        } else {
            $result = login($identifier, $password);
            if ($result['success']) {
                if ($remember) {
                    setcookie('clipaza_remember', $identifier, time() + (86400 * 30), '/', '', false, true);
                }
                redirect('/dashboard');
            } else {
                $error    = $result['message'];
                $fieldVal = $identifier;
            }
        }
    }
}

$csrf = generateCsrfToken();
$siteName = getSetting('site_name', 'Clipaza');
$siteLogo = getSetting('site_logo', '');

renderHead('Sign In');
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
      <p class="text-muted mt-2 mb-0" style="font-size:0.9rem">Sign in to your account</p>
    </div>

    <div class="card-dark p-4">
      <h5 class="fw-700 mb-4">Welcome back</h5>

      <?php if ($error): ?>
        <div class="alert-dark-danger mb-3" role="alert"><?= e($error) ?></div>
      <?php endif; ?>
      <?php if (($_GET['error'] ?? '') === 'google_failed'): ?>
        <div class="alert-dark-danger mb-3" role="alert">Google authentication failed. Please try again.</div>
      <?php endif; ?>

      <?php if ($googleHelper->isConfigured()): ?>
        <a href="<?= e($googleHelper->getAuthUrl()) ?>" class="btn btn-outline-light w-100 mb-3 d-flex align-items-center justify-content-center gap-2" style="border-color: #555; background: #fff; color: #333; font-weight: 600;">
          <img src="https://www.gstatic.com/images/branding/product/1x/gsa_48dp.png" alt="" style="width:18px;height:18px">
          Continue with Google
        </a>
        <div class="d-flex align-items-center gap-2 mb-3">
          <hr class="flex-grow-1 border-secondary">
          <span class="text-muted" style="font-size:0.75rem">OR</span>
          <hr class="flex-grow-1 border-secondary">
        </div>
      <?php endif; ?>

      <form method="POST" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

        <div class="mb-3">
          <label class="form-label-dark">Username or Email</label>
          <input type="text" name="identifier" class="form-control-dark" value="<?= e($fieldVal) ?>" placeholder="username or email" autocomplete="username" required>
        </div>
        <div class="mb-3">
          <label class="form-label-dark d-flex justify-content-between">
            <span>Password</span>
            <a href="/auth/forgot-password" class="text-accent text-decoration-none" style="font-size:0.82rem">Forgot password?</a>
          </label>
          <input type="password" name="password" class="form-control-dark" placeholder="Your password" autocomplete="current-password" required>
        </div>
        <div class="mb-4 d-flex align-items-center gap-2">
          <input class="form-check-input" type="checkbox" name="remember_me" id="rememberMe" style="border-color:#555">
          <label class="form-check-label text-muted" for="rememberMe" style="font-size:0.85rem; cursor:pointer">Remember me</label>
        </div>
        <button type="submit" class="btn btn-accent w-100">Sign In</button>
      </form>
    </div>

    <p class="text-center text-muted mt-3" style="font-size:0.85rem">
      Don't have an account? <a href="/auth/register" class="text-accent text-decoration-none">Sign up free</a>
    </p>
  </div>
</div>
<?php renderFooter(); ?>
