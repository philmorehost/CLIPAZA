<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['user_id'])) redirect('/dashboard');

$errors  = [];
$success = false;
$formData = ['display_name' => ', 'email' => ', 'username' => '];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? ')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $displayName     = sanitizeInput($_POST['display_name'] ?? ');
        $email           = sanitizeInput($_POST['email'] ?? ');
        $username        = sanitizeInput($_POST['username'] ?? ');
        $password        = $_POST['password'] ?? ';
        $confirmPassword = $_POST['confirm_password'] ?? ';

        $formData = ['display_name' => $displayName, 'email' => $email, 'username' => $username];

        if (empty($displayName))                    $errors[] = 'Full name is required.';
        if (!isValidEmail($email))                  $errors[] = 'A valid email address is required.';
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) $errors[] = 'Username must be 3–20 alphanumeric characters or underscores.';
        if (strlen($password) < 8)                  $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirmPassword)          $errors[] = 'Passwords do not match.';

        if (empty($errors)) {
            try {
                $db = db();
                $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                if ($stmt->fetch()) $errors[] = 'This email address is already registered.';

                $stmt = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                $stmt->execute([$username]);
                if ($stmt->fetch()) $errors[] = 'This username is already taken.';
            } catch (Throwable) {
                $errors[] = 'A database error occurred. Please try again.';
            }
        }

        if (empty($errors)) {
            try {
                $db   = db();
                $hash = hashPassword($password);
                $stmt = $db->prepare(
                    'INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, \'user\', \'active\')'
                );
                $stmt->execute([$username, $email, $hash]);
                $userId = (int)$db->lastInsertId();

                $db->prepare(
                    'INSERT INTO user_profiles (user_id, display_name, active_mode) VALUES (?, ?, \'clipper\')'
                )->execute([$userId, $displayName]);

                session_regenerate_id(true);
                $_SESSION['user_id']    = $userId;
                $_SESSION['username']   = $username;
                $_SESSION['user_role']  = 'user';
                $_SESSION['user_email'] = $email;
                $_SESSION['user_mode']  = 'clipper';
                $_SESSION['logged_in']  = true;

                redirect('/dashboard');
            } catch (Throwable $e) {
                $errors[] = 'Registration failed. Please try again.';
            }
        }
    }
}

$csrf = generateCsrfToken();
renderHead('Create Account');
?>
<div class="public-page d-flex align-items-center justify-content-center" style="min-height:100vh;background:#000;padding:40px 16px">
  <div class="w-100" style="max-width:420px">
    <div class="text-center mb-4">
      <a href="index" class="text-decoration-none">
        <span style="font-size:1.5rem;font-weight:900;color:#fff;letter-spacing:-0.5px">Clipaza<span style="color:var(--accent)">.</span></span>
      </a>
      <p class="text-muted mt-2 mb-0" style="font-size:0.9rem">Join as a creator or clipper</p>
    </div>

    <div class="card-dark p-4">
      <h5 class="fw-700 mb-4">Create your account</h5>

      <?php foreach ($errors as $err): ?>
        <div class="alert-dark-danger mb-3" role="alert"><?= e($err) ?></div>
      <?php endforeach; ?>

      <form method="POST" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

        <div class="mb-3">
          <label class="form-label-dark">Full Name</label>
          <input type="text" name="display_name" class="form-control-dark" value="<?= e($formData['display_name']) ?>" placeholder="Your full name" required>
        </div>
        <div class="mb-3">
          <label class="form-label-dark">Email Address</label>
          <input type="email" name="email" class="form-control-dark" value="<?= e($formData['email']) ?>" placeholder="you@example.com" required>
        </div>
        <div class="mb-3">
          <label class="form-label-dark">Username</label>
          <input type="text" name="username" class="form-control-dark" value="<?= e($formData['username']) ?>" placeholder="your_username" pattern="[a-zA-Z0-9_]{3,20}" required>
          <small class="text-muted" style="font-size:0.78rem">3–20 characters, letters, numbers, underscores only</small>
        </div>
        <div class="mb-3">
          <label class="form-label-dark">Password</label>
          <input type="password" name="password" class="form-control-dark" placeholder="Minimum 8 characters" minlength="8" required>
        </div>
        <div class="mb-4">
          <label class="form-label-dark">Confirm Password</label>
          <input type="password" name="confirm_password" class="form-control-dark" placeholder="Repeat password" required>
        </div>
        <button type="submit" class="btn btn-accent w-100">Create Account</button>
      </form>

      <div class="mt-4">
        <div class="d-flex align-items-center mb-3">
          <hr class="flex-grow-1 border-secondary">
          <span class="px-2 text-muted" style="font-size:0.8rem">OR</span>
          <hr class="flex-grow-1 border-secondary">
        </div>
        <a href="auth/google-auth" class="btn btn-outline-light w-100 d-flex align-items-center justify-content-center gap-2" style="background:#fff;color:#000;border:none">
          <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" width="18" height="18" alt="Google">
          Continue with Google
        </a>
      </div>
    </div>

    <p class="text-center text-muted mt-3" style="font-size:0.85rem">
      Already have an account? <a href="auth/login" class="text-accent text-decoration-none">Sign in</a>
    </p>
  </div>
</div>
<?php renderFooter(); ?>
