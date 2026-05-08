<?php
declare(strict_types=1);

$root = __DIR__;
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireUser();

$userId   = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$userMode = getUserMode();

$errors  = [];
$success = '';

// Load current profile
$profile = [];
try {
    $db   = db();
    $stmt = $db->prepare('SELECT * FROM user_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch() ?: [];
} catch (Throwable) {}

// Load user record
$user = [];
try {
    $db   = db();
    $stmt = $db->prepare('SELECT id, username, email, login_notifications FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch() ?: [];
} catch (Throwable) {}

// Handle password change form via POST action=change_password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request.';
    } else {
        $currentPw  = $_POST['current_password'] ?? '';
        $newPw      = $_POST['new_password'] ?? '';
        $confirmPw  = $_POST['confirm_password'] ?? '';

        if (strlen($newPw) < 8)          $errors[] = 'New password must be at least 8 characters.';
        if ($newPw !== $confirmPw)        $errors[] = 'Passwords do not match.';

        if (empty($errors)) {
            try {
                $db   = db();
                $stmt = $db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $row = $stmt->fetch();
                if (!$row || !password_verify($currentPw, $row['password'])) {
                    $errors[] = 'Current password is incorrect.';
                } else {
                    $db->prepare('UPDATE users SET password = ? WHERE id = ?')
                       ->execute([hashPassword($newPw), $userId]);
                    $success = 'password_changed';
                }
            } catch (Throwable) {
                $errors[] = 'Failed to update password.';
            }
        }
    }
}

$csrf = generateCsrfToken();
renderHead('My Profile');
renderNav(true, ['username' => $username], $userMode);

$dn  = $profile['display_name'] ?? $username;
$ini = strtoupper(substr($dn, 0, 1));
?>

<div class="public-page">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-lg-7">
        <h3 class="fw-900 mb-4" style="letter-spacing:-0.5px">My Profile</h3>

        <?php foreach ($errors as $err): ?>
          <div class="alert-dark-danger mb-3"><?= e($err) ?></div>
        <?php endforeach; ?>
        <?php if ($success === 'password_changed'): ?>
          <div class="alert-dark-success mb-3">Password updated successfully!</div>
        <?php endif; ?>

        <!-- Avatar & basic info -->
        <div class="card-dark p-4 mb-4">
          <div class="d-flex align-items-center gap-4 mb-4">
            <div class="avatar-circle avatar-circle--lg"><?= e($ini) ?></div>
            <div>
              <h5 class="fw-700 mb-0"><?= e($dn) ?></h5>
              <span class="text-muted" style="font-size:0.85rem">@<?= e($username) ?> · <?= e($user['email'] ?? '') ?></span>
            </div>
          </div>

          <h6 class="fw-700 mb-3">Edit Profile</h6>
          <form id="profileForm">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="mb-3">
              <label class="form-label-dark">Display Name</label>
              <input type="text" name="display_name" class="form-control-dark" value="<?= e($profile['display_name'] ?? '') ?>" maxlength="100">
            </div>
            <div class="mb-3">
              <label class="form-label-dark">Bio</label>
              <textarea name="bio" class="form-control-dark" rows="3" maxlength="500" placeholder="Tell us about yourself…"><?= e($profile['bio'] ?? '') ?></textarea>
            </div>
            <?php if ($userMode === 'creator'): ?>
            <div class="mb-3">
              <label class="form-label-dark">Brand Description / Clipper Instructions</label>
              <textarea name="brand_description" class="form-control-dark" rows="5" placeholder="Describe your brand and provide specific instructions for clippers…"><?= e($profile['brand_description'] ?? '') ?></textarea>
              <div class="form-text text-muted" style="font-size:0.75rem">This will be displayed on the Clipper Dashboard for clippers who join your contests.</div>
            </div>
            <?php endif; ?>
            <div id="profileFeedback" class="mb-2"></div>
            <button type="submit" class="btn btn-accent">Save Changes</button>
          </form>
        </div>

        <!-- Social Handles -->
        <div class="card-dark p-4 mb-4">
          <h6 class="fw-700 mb-3">Social Handles</h6>
          <form id="socialForm">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label-dark">YouTube Handle</label>
                <input type="text" name="youtube_handle" class="form-control-dark" value="<?= e($profile['youtube_handle'] ?? '') ?>" placeholder="@channel">
              </div>
              <div class="col-md-6">
                <label class="form-label-dark">TikTok Handle</label>
                <input type="text" name="tiktok_handle" class="form-control-dark" value="<?= e($profile['tiktok_handle'] ?? '') ?>" placeholder="@tiktok">
              </div>
              <div class="col-md-6">
                <label class="form-label-dark">Instagram Handle</label>
                <input type="text" name="instagram_handle" class="form-control-dark" value="<?= e($profile['instagram_handle'] ?? '') ?>" placeholder="@instagram">
              </div>
              <div class="col-md-6">
                <label class="form-label-dark">Facebook Handle</label>
                <input type="text" name="facebook_handle" class="form-control-dark" value="<?= e($profile['facebook_handle'] ?? '') ?>" placeholder="@facebook">
              </div>
            </div>
            <div id="socialFeedback" class="mt-2 mb-2"></div>
            <button type="submit" class="btn btn-accent mt-3">Save Handles</button>
          </form>
        </div>

        <!-- Preferences -->
        <div class="card-dark p-4 mb-4">
          <h6 class="fw-700 mb-3">Preferences</h6>
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="fw-600" style="font-size:0.9rem">Login Notifications</div>
              <div class="text-muted" style="font-size:0.8rem">Get an email each time you sign in</div>
            </div>
            <button class="btn btn-sm <?= ($user['login_notifications'] ?? 0) ? 'btn-accent' : 'btn-outline-accent' ?>"
                    id="notifToggle" data-value="<?= (int)($user['login_notifications'] ?? 0) ?>">
              <?= ($user['login_notifications'] ?? 0) ? 'On' : 'Off' ?>
            </button>
          </div>
          <hr class="divider-dark">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="fw-600" style="font-size:0.9rem">Active Mode</div>
              <div class="text-muted" style="font-size:0.8rem">Switch between Creator and Clipper</div>
            </div>
            <button class="btn btn-sm mode-toggle" id="modeSwitchBtn" data-current="<?= e($userMode) ?>">
              <?= $userMode === 'creator' ? 'Switch to Clipper' : 'Switch to Creator' ?>
            </button>
          </div>
        </div>

        <!-- Change Password -->
        <div class="card-dark p-4">
          <h6 class="fw-700 mb-3">Change Password</h6>
          <form method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="change_password">
            <div class="mb-3">
              <label class="form-label-dark">Current Password</label>
              <input type="password" name="current_password" class="form-control-dark" required>
            </div>
            <div class="mb-3">
              <label class="form-label-dark">New Password</label>
              <input type="password" name="new_password" class="form-control-dark" minlength="8" required>
            </div>
            <div class="mb-4">
              <label class="form-label-dark">Confirm New Password</label>
              <input type="password" name="confirm_password" class="form-control-dark" required>
            </div>
            <button type="submit" class="btn btn-outline-accent">Update Password</button>
          </form>
        </div>

      </div>
    </div>
  </div>
</div>

<script>
const csrf = <?= json_encode(generateCsrfToken()) ?>;

async function submitAjaxForm(formId, feedbackId) {
  const form = document.getElementById(formId);
  const fb   = document.getElementById(feedbackId);
  form.addEventListener('submit', async function(e) {
    e.preventDefault();
    fb.innerHTML = '';
    const data = new FormData(this);
    try {
      const r = await fetch('/ajax/user_actions.php', { method:'POST', body: new URLSearchParams(data) });
      const d = await r.json();
      fb.innerHTML = d.success
        ? '<div class="alert-dark-success" style="font-size:0.82rem">✅ ' + d.message + '</div>'
        : '<div class="alert-dark-danger" style="font-size:0.82rem">' + d.message + '</div>';
    } catch { fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">Network error.</div>'; }
  });
}
submitAjaxForm('profileForm', 'profileFeedback');
submitAjaxForm('socialForm', 'socialFeedback');

document.getElementById('modeSwitchBtn')?.addEventListener('click', async function() {
  const btn = this;
  btn.disabled = true;
  const originalText = btn.textContent;
  btn.textContent = 'Switching...';
  try {
    const r = await fetch('/ajax/user_actions.php', {
      method:'POST',
      body: new URLSearchParams({ action:'switch_mode', csrf_token: csrf })
    });
    const d = await r.json();
    if (d.success) location.reload();
    else {
        btn.disabled = false;
        btn.textContent = originalText;
        alert(d.message || 'Failed to switch mode.');
    }
  } catch(e) {
    btn.disabled = false;
    btn.textContent = originalText;
    alert('Network error.');
  }
});

document.getElementById('notifToggle')?.addEventListener('click', async function() {
  const newVal = this.dataset.value === '1' ? '0' : '1';
  const r = await fetch('/ajax/user_actions.php', {
    method:'POST',
    body: new URLSearchParams({ action:'toggle_notifications', csrf_token: csrf, value: newVal })
  });
  const d = await r.json();
  if (d.success) {
    this.dataset.value = newVal;
    this.textContent = newVal === '1' ? 'On' : 'Off';
    this.className = 'btn btn-sm ' + (newVal === '1' ? 'btn-accent' : 'btn-outline-accent');
  }
});
</script>

<?php renderFooter(); ?>
