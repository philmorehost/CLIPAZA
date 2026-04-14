<?php
declare(strict_types=1);
session_start();

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/security.php';
require_once $root . '/includes/auth.php';

requireAdmin();

$csrf    = generateCsrfToken();
$adminId = (int)($_SESSION['user_id'] ?? 0);

$admin = [];
try {
    $stmt = db()->prepare("SELECT id, username, email, created_at FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch() ?: [];
} catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — Clipaza Admin</title>
    <meta name="csrf" content="<?= e($csrf) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
  <script>
    (function() {
      var t = localStorage.getItem('clipaza_theme') || 'dark';
      document.documentElement.dataset.theme = t;
    })();
  </script>
</head>
<body>

<nav class="admin-sidebar">
    <div class="sidebar-brand">Clipa<span>za</span></div>
    <div class="sidebar-nav">
        <ul class="nav flex-column">
            <li class="nav-item"><a href="index.php" class="nav-link"><span class="nav-icon">⊞</span> Dashboard</a></li>
            <li class="nav-item"><a href="users.php" class="nav-link"><span class="nav-icon">👥</span> Users</a></li>
            <li class="nav-item"><a href="contests.php" class="nav-link"><span class="nav-icon">🏆</span> Contests</a></li>
            <li class="nav-item"><a href="featured-contests.php" class="nav-link"><span class="nav-icon">⭐</span> Featured</a></li>
            <li class="nav-item"><a href="payouts.php" class="nav-link"><span class="nav-icon">💸</span> Payouts</a></li>
            <li class="nav-item"><a href="kyc.php" class="nav-link"><span class="nav-icon">🪪</span> KYC</a></li>
            <li class="nav-item"><a href="ad-packages.php" class="nav-link"><span class="nav-icon">📦</span> Ad Packages</a></li>
            <li class="nav-item"><a href="movie-ads.php" class="nav-link"><span class="nav-icon">🎞</span> Movie Ads</a></li>
            <li class="nav-item"><a href="security.php" class="nav-link"><span class="nav-icon">🛡</span> Security</a></li>
            <li class="nav-item"><a href="settings.php" class="nav-link"><span class="nav-icon">⚙</span> Settings</a></li>
            <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">👤</span> Profile</a></li>
        </ul>
        <hr class="divider-dark mx-3">
        <ul class="nav flex-column">
            <li class="nav-item"><a href="logout.php" class="nav-link" style="color:var(--danger)"><span class="nav-icon">⇤</span> Logout</a></li>
        </ul>
    </div>
</nav>

<main class="admin-main">
    <div class="admin-topbar">
        <div class="d-flex align-items-center gap-3">
            <button id="sidebarToggle" class="btn d-lg-none" style="color:#888;background:rgba(255,255,255,0.05);border-radius:8px;padding:6px 10px;">☰</button>
            <button id="adminThemeToggle" class="btn-theme-toggle" title="Toggle light/dark mode" aria-label="Toggle theme" style="margin-left:4px">☀️</button>
            <span style="color:#888;font-size:0.9rem">Welcome, <strong style="color:#fff"><?= e($_SESSION['username'] ?? '') ?></strong></span>
        </div>
    </div>

    <div class="p-4" style="max-width:700px">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div style="width:54px;height:54px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#000;font-weight:700">
                <?= strtoupper(substr(e($admin['username'] ?? 'A'), 0, 1)) ?>
            </div>
            <div>
                <h4 class="fw-700 mb-0"><?= e($admin['username'] ?? '') ?></h4>
                <span style="font-size:0.8rem;color:#888">Administrator · Member since <?= e(formatDate($admin['created_at'] ?? '', 'M Y')) ?></span>
            </div>
        </div>

        <!-- Alert banner -->
        <div id="profileAlert" class="mb-3" style="display:none"></div>

        <!-- Account Info -->
        <div class="card-dark mb-4">
            <div class="card-header-dark d-flex align-items-center gap-2">
                <span>✏️</span>
                <span class="fw-600">Account Information</span>
            </div>
            <div style="padding:1.25rem">
                <form id="infoForm">
                    <div class="mb-3">
                        <label class="form-label-dark">Username</label>
                        <input type="text" id="infoUsername" name="username" class="form-control-dark"
                               value="<?= e($admin['username'] ?? '') ?>" maxlength="40" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-dark">Email Address</label>
                        <input type="email" id="infoEmail" name="email" class="form-control-dark"
                               value="<?= e($admin['email'] ?? '') ?>" maxlength="120" required>
                    </div>
                    <button type="submit" class="btn btn-accent" id="infoBtn">Save Changes</button>
                </form>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card-dark">
            <div class="card-header-dark d-flex align-items-center gap-2">
                <span>🔒</span>
                <span class="fw-600">Change Password</span>
            </div>
            <div style="padding:1.25rem">
                <form id="pwForm">
                    <div class="mb-3">
                        <label class="form-label-dark">Current Password</label>
                        <input type="password" name="current_password" class="form-control-dark" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-dark">New Password <span style="color:#888;font-size:0.78rem">(min 8 characters)</span></label>
                        <input type="password" name="new_password" class="form-control-dark" autocomplete="new-password" minlength="8" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-dark">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control-dark" autocomplete="new-password" minlength="8" required>
                    </div>
                    <button type="submit" class="btn btn-accent" id="pwBtn">Change Password</button>
                </form>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
const csrf = document.querySelector('meta[name="csrf"]').content;

function showAlert(msg, ok) {
    const el = document.getElementById('profileAlert');
    el.innerHTML = `<div class="alert ${ok ? 'alert-success' : 'alert-danger'} py-2 px-3" style="border-radius:8px;font-size:0.88rem">${msg}</div>`;
    el.style.display = 'block';
    setTimeout(() => { el.style.display = 'none'; }, 5000);
}

async function postAction(body) {
    body.append('csrf_token', csrf);
    const r = await fetch('/admin/ajax/admin_actions.php', { method: 'POST', body });
    return r.json();
}

document.getElementById('infoForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('infoBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    const fd = new FormData(this);
    fd.append('action', 'update_admin_profile');
    fd.append('field', 'info');
    try {
        const d = await postAction(new URLSearchParams(fd));
        showAlert(d.message || (d.success ? 'Saved.' : 'Error.'), d.success);
    } catch { showAlert('Network error.', false); }
    btn.disabled = false; btn.textContent = 'Save Changes';
});

document.getElementById('pwForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('pwBtn');
    btn.disabled = true; btn.textContent = 'Changing…';
    const fd = new FormData(this);
    fd.append('action', 'update_admin_profile');
    fd.append('field', 'password');
    try {
        const d = await postAction(new URLSearchParams(fd));
        showAlert(d.message || (d.success ? 'Password changed.' : 'Error.'), d.success);
        if (d.success) this.reset();
    } catch { showAlert('Network error.', false); }
    btn.disabled = false; btn.textContent = 'Change Password';
});
</script>
<script src="assets/js/theme_sync.js"></script>
</body>
</html>
