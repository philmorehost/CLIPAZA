<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['user_id'])) redirect('/dashboard');

$error     = '';
$fieldVal  = '';
$rememberMe = false;

// Handle remember-me cookie pre-fill
if (!empty($_COOKIE['clipaza_remember'])) {
    $fieldVal = e($_COOKIE['clipaza_remember']);
}

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
renderHead('Sign In');
?>
<div class="public-page d-flex align-items-center justify-content-center" style="min-height:100vh;background:#000;padding:40px 16px">
  <div class="w-100" style="max-width:400px">
    <div class="text-center mb-4">
      <a href="/" class="text-decoration-none">
        <span style="font-size:1.5rem;font-weight:900;color:#fff;letter-spacing:-0.5px">Clipaza<span style="color:var(--accent)">.</span></span>
      </a>
      <p class="text-muted mt-2 mb-0" style="font-size:0.9rem">Sign in to your account</p>
    </div>

    <div class="card-dark p-4">
      <h5 class="fw-700 mb-4">Welcome back</h5>

      <?php if ($error): ?>
        <div class="alert-dark-danger mb-3" role="alert"><?= e($error) ?></div>
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
          <input class="form-check-input" type="checkbox" name="remember_me" id="rememberMe" style="background:#111;border-color:#333">
          <label class="form-check-label text-muted" for="rememberMe" style="font-size:0.85rem">Remember me</label>
        </div>
        <button type="submit" class="btn btn-accent w-100">Sign In</button>
      </form>

      <div class="mt-4">
        <div class="d-flex align-items-center mb-3">
          <hr class="flex-grow-1 border-secondary">
          <span class="px-2 text-muted" style="font-size:0.8rem">OR</span>
          <hr class="flex-grow-1 border-secondary">
        </div>
        <a href="/auth/google-auth.php" class="btn btn-outline-light w-100 d-flex align-items-center justify-content-center gap-2" style="background:#fff;color:#000;border:none">
          <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" width="18" height="18" alt="Google">
          Continue with Google
        </a>
      </div>
    </div>

    <p class="text-center text-muted mt-3" style="font-size:0.85rem">
      Don't have an account? <a href="/auth/register" class="text-accent text-decoration-none">Sign up free</a>
    </p>
  </div>
</div>
<?php renderFooter(); ?>
