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
$formData = ['display_name' => '', 'email' => '', 'username' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $displayName     = sanitizeInput($_POST['display_name'] ?? '');
        $email           = sanitizeInput($_POST['email'] ?? '');
        $username        = sanitizeInput($_POST['username'] ?? '');
        $password        = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $formData = ['display_name' => $displayName, 'email' => $email, 'username' => $username];

        if (empty($displayName))                    $errors[] = 'Full name is required.';
        if (!isValidEmail($email))                  $errors[] = 'A valid email address is required.';
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) $errors[] = 'Username must be 3–20 alphanumeric characters or underscores.';
        if (strlen($password) < 8)                  $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirmPassword)          $errors[] = 'Passwords do not match.';
        if (empty($_POST['agree_disclaimer']))       $errors[] = 'You must read and agree to the Participation Disclaimer to register.';

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

                // Send welcome email
                try {
                    require_once $root . '/includes/email_templates.php';
                    sendEmail($email, 'Welcome to Clipaza! 🎬', emailWelcome($username, $email));
                } catch (Throwable) {}

                // Mark disclaimer accepted on registration
                try {
                    $db->prepare(
                        "UPDATE user_profiles SET disclaimer_accepted = 1, disclaimer_accepted_at = NOW() WHERE user_id = ?"
                    )->execute([$userId]);
                } catch (Throwable) {}

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
<div class="public-page d-flex align-items-center justify-content-center" style="min-height:100vh;background:var(--bg);padding:40px 16px">
  <div class="w-100" style="max-width:420px">
    <div class="text-center mb-4">
      <a href="/" class="text-decoration-none">
        <span style="font-size:1.5rem;font-weight:900;color:var(--text);letter-spacing:-0.5px">Clipaza<span style="color:var(--accent)">.</span></span>
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
        <div class="mb-3">
          <button type="button" class="btn btn-outline-accent w-100 mb-2" id="toggleDisclaimer" style="font-size:0.82rem;text-align:left">
            ⚠️ Read Reward Eligibility Notice <span id="disclaimerArrow">▼</span>
          </button>
          <div id="disclaimerText" style="display:none;background:var(--bg-secondary);border:1px solid rgba(255,170,0,0.3);border-radius:8px;padding:16px;font-size:0.8rem;line-height:1.6;color:var(--text-secondary);margin-bottom:12px">
            <strong style="color:var(--warning);text-transform:uppercase;letter-spacing:0.06em;font-size:0.79rem">Reward Eligibility Notice</strong>
            <p style="margin:8px 0 10px;color:var(--text-secondary);line-height:1.5">To qualify for prize collection, you must comply with all of the following conditions. Non-compliance will result in disqualification and forfeiture of any earned rewards.</p>
            <ol style="margin:10px 0 0 16px;padding:0">
              <li style="margin-bottom:6px"><strong style="color:var(--text)">Analytics Video Proof:</strong> Submit a minimum 2-minute screen-recorded video showing authentic analytics (views, likes, comments) within <strong style="color:var(--text)">72 hours</strong> of the contest closing. Failure to do so transfers the prize to the next eligible runner-up with valid proof.</li>
              <li style="margin-bottom:6px"><strong style="color:var(--text)">Engagement Verification:</strong> Screenshot proof of your comment and like on the contest creator's original video is required for prize collection.</li>
              <li style="margin-bottom:6px"><strong style="color:var(--text)">No Paid Promotions:</strong> Running any paid advertisement, sponsored boost, or promotion on your submitted video renders your entry ineligible. Entries backed by paid reach will be disqualified upon detection.</li>
              <li style="margin-bottom:6px"><strong style="color:var(--danger)">No Artificial Engagement:</strong> The use of bots, automation tools, view-purchasing services, or any means of artificially inflating metrics is strictly forbidden. Violations will result in <strong style="color:var(--danger)">immediate permanent account suspension</strong> and full prize forfeiture, with no right of appeal.</li>
              <li>All submissions must represent genuine, organic engagement. Clipaza employs automated monitoring systems to detect fraudulent activity. Confirmed violations are subject to permanent account termination.</li>
            </ol>
          </div>
          <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:0.85rem;color:var(--text-secondary)">
            <input type="checkbox" name="agree_disclaimer" required style="margin-top:2px;accent-color:var(--accent)">
            <span>I have read and agree to the Reward Eligibility Notice and Contest Rules</span>
          </label>
        </div>
        <button type="submit" class="btn btn-accent w-100">Create Account</button>
      </form>
    </div>

    <p class="text-center text-muted mt-3" style="font-size:0.85rem">
      Already have an account? <a href="/auth/login" class="text-accent text-decoration-none">Sign in</a>
    </p>
  </div>
</div>
<script>
document.getElementById('toggleDisclaimer')?.addEventListener('click', function() {
  const t = document.getElementById('disclaimerText');
  const a = document.getElementById('disclaimerArrow');
  if (t.style.display === 'none') { t.style.display = 'block'; a.textContent = '▲'; }
  else { t.style.display = 'none'; a.textContent = '▼'; }
});
</script>
<?php renderFooter(); ?>
