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

$csrf   = generateCsrfToken();
$userId = (int)($_GET['id'] ?? 0);

if (!$userId) {
    redirect('users.php');
}

$user    = [];
$profile = [];

try {
    $db   = db();
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch() ?: [];

    $stmt = $db->prepare('SELECT * FROM user_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch() ?: [];
} catch (Throwable) {}

if (empty($user)) {
    redirect('users.php');
}

$txHistory = [];
try {
    $db   = db();
    $stmt = $db->prepare('SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
    $stmt->execute([$userId]);
    $txHistory = $stmt->fetchAll();
} catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User #<?= $userId ?> — Clipaza Admin</title>
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
            <li class="nav-item"><a href="users.php" class="nav-link active"><span class="nav-icon">👥</span> Users</a></li>
            <li class="nav-item"><a href="contests.php" class="nav-link"><span class="nav-icon">🏆</span> Contests</a></li>
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
            <h1>Edit User: @<?= e($user['username']) ?></h1>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm login-as-btn" data-id="<?= $userId ?>"
                    style="background:rgba(0,153,255,0.1);color:var(--info);border:1px solid rgba(0,153,255,0.3);border-radius:8px;padding:6px 14px;font-size:0.82rem">
                👤 Login as User
            </button>
            <a href="users.php" class="btn btn-sm btn-outline-accent">← Back</a>
        </div>
    </div>

    <div class="p-4">
        <div id="editFeedback" class="mb-3"></div>

        <div class="row g-4">
            <!-- Edit Form -->
            <div class="col-lg-7">
                <div class="card-dark p-4">
                    <h6 class="fw-700 mb-4">Account Details</h6>
                    <form id="editUserForm">
                        <input type="hidden" name="action" value="edit_user">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="edit_user_id" value="<?= $userId ?>">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label-dark">Email</label>
                                <input type="email" name="email" class="form-control-dark" value="<?= e($user['email']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-dark">Display Name</label>
                                <input type="text" name="display_name" class="form-control-dark"
                                       value="<?= e($profile['display_name'] ?? '') ?>" maxlength="100">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-dark">Role</label>
                                <select name="role" class="form-control-dark">
                                    <?php foreach (['user', 'admin', 'moderator'] as $r): ?>
                                    <option value="<?= $r ?>" <?= $user['role']===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-dark">Status</label>
                                <select name="status" class="form-control-dark">
                                    <?php foreach (['active', 'inactive', 'banned', 'pending'] as $st): ?>
                                    <option value="<?= $st ?>" <?= $user['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <hr class="divider-dark">
                        <h6 class="fw-600 mb-3" style="font-size:0.88rem;color:#ccc">New Password (leave blank to keep current)</h6>
                        <div class="mb-3">
                            <label class="form-label-dark">New Password</label>
                            <input type="password" name="new_password" class="form-control-dark" minlength="8" placeholder="Min 8 characters">
                        </div>

                        <hr class="divider-dark">
                        <h6 class="fw-600 mb-3" style="font-size:0.88rem;color:#ccc">Wallet Adjustment</h6>
                        <div class="mb-4">
                            <label class="form-label-dark">Adjust Wallet Balance (₦)</label>
                            <input type="number" name="wallet_adjust" class="form-control-dark" step="1" value="0"
                                   placeholder="+ to add, - to deduct">
                            <div style="font-size:0.78rem;color:#888;margin-top:4px">Current balance: <strong style="color:var(--accent)">₦<?= number_format((float)($profile['wallet_balance'] ?? 0), 2) ?></strong></div>
                        </div>

                        <button type="submit" class="btn btn-accent">Save Changes</button>
                    </form>
                </div>
            </div>

            <!-- Stats & Info -->
            <div class="col-lg-5">
                <div class="card-dark p-4 mb-4">
                    <h6 class="fw-700 mb-3">User Info</h6>
                    <div class="d-flex flex-column gap-2" style="font-size:0.85rem">
                        <div class="d-flex justify-content-between">
                            <span style="color:#888">Username</span>
                            <span style="color:#ccc">@<?= e($user['username']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span style="color:#888">Joined</span>
                            <span style="color:#ccc"><?= e(formatDate($user['created_at'], 'M j, Y')) ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span style="color:#888">Role</span>
                            <span class="badge-<?= $user['role']==='admin'?'danger':'info' ?>" style="font-size:0.72rem"><?= e(ucfirst($user['role'])) ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span style="color:#888">Status</span>
                            <span class="badge-<?= $user['status']==='active'?'success':($user['status']==='banned'?'danger':'muted') ?>" style="font-size:0.72rem"><?= e(ucfirst($user['status'])) ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span style="color:#888">KYC</span>
                            <?php $kycCls = match($profile['kyc_status']??'none') { 'approved'=>'badge-success','pending'=>'badge-warning','rejected'=>'badge-danger', default=>'badge-muted' }; ?>
                            <span class="<?= $kycCls ?>" style="font-size:0.72rem"><?= e(ucfirst($profile['kyc_status']??'none')) ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span style="color:#888">Wallet</span>
                            <span style="color:var(--accent);font-weight:700">₦<?= number_format((float)($profile['wallet_balance'] ?? 0), 2) ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span style="color:#888">Total Earned</span>
                            <span style="color:#ccc">₦<?= number_format((float)($profile['total_earned'] ?? 0), 2) ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span style="color:#888">Total Spent</span>
                            <span style="color:#ccc">₦<?= number_format((float)($profile['total_spent'] ?? 0), 2) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Transaction History -->
                <div class="card-dark">
                    <div class="card-header">Recent Transactions</div>
                    <div class="card-body p-0">
                        <?php if (empty($txHistory)): ?>
                        <div class="text-center py-4" style="color:#888;font-size:0.85rem">No transactions.</div>
                        <?php else: ?>
                        <?php foreach (array_slice($txHistory, 0, 8) as $tx): ?>
                        <?php
                            $txClass = match($tx['type']) { 'credit'=>'badge-success','debit'=>'badge-danger','withdrawal'=>'badge-warning','refund'=>'badge-info', default=>'badge-muted' };
                        ?>
                        <div class="d-flex align-items-center justify-content-between px-3 py-2" style="border-bottom:1px solid var(--border)">
                            <div>
                                <span class="<?= $txClass ?>" style="font-size:0.68rem"><?= e(ucfirst($tx['type'])) ?></span>
                                <div style="font-size:0.75rem;color:#888;margin-top:2px"><?= e($tx['description'] ?? '') ?></div>
                            </div>
                            <div class="text-end">
                                <div style="font-weight:700;font-size:0.85rem">₦<?= number_format((float)$tx['amount'], 0) ?></div>
                                <div style="font-size:0.72rem;color:#888"><?= e(timeAgo($tx['created_at'])) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
const csrf = document.querySelector('meta[name="csrf"]').content;

document.getElementById('editUserForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fb = document.getElementById('editFeedback');
    const btn = this.querySelector('[type="submit"]');
    btn.disabled = true; btn.textContent = 'Saving…'; fb.innerHTML = '';
    try {
        const r = await fetch('ajax/admin_actions.php', {
            method:'POST', body: new URLSearchParams(new FormData(this))
        });
        const d = await r.json();
        if (d.success) {
            fb.innerHTML = '<div class="alert-dark-success">✅ ' + d.message + '</div>';
            btn.textContent = 'Saved ✅';
            setTimeout(() => { btn.disabled = false; btn.textContent = 'Save Changes'; }, 2000);
        } else {
            fb.innerHTML = '<div class="alert-dark-danger">' + (d.message||'Error') + '</div>';
            btn.disabled = false; btn.textContent = 'Save Changes';
        }
    } catch {
        fb.innerHTML = '<div class="alert-dark-danger">Network error.</div>';
        btn.disabled = false; btn.textContent = 'Save Changes';
    }
});

document.querySelectorAll('.login-as-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        if (!confirm('Login as this user? You will be redirected to their dashboard.')) return;
        this.disabled = true; this.textContent = 'Switching…';
        try {
            const r = await fetch('ajax/admin_actions.php', {
                method:'POST',
                body: new URLSearchParams({ action:'login_as_user', target_user_id: this.dataset.id, csrf_token: csrf })
            });
            const d = await r.json();
            if (d.success) {
                window.location.href = d.redirect || '/dashboard';
            } else {
                alert(d.message || 'Failed to switch.');
                this.disabled = false; this.textContent = '👤 Login as User';
            }
        } catch {
            alert('Network error.');
            this.disabled = false; this.textContent = '👤 Login as User';
        }
    });
});
</script>
<script>
(function() {
  var btn = document.getElementById('adminThemeToggle');
  if (!btn) return;
  function current() { return document.documentElement.dataset.theme || 'dark'; }
  function setIcon() { btn.textContent = current() === 'dark' ? '☀️' : '🌙'; }
  setIcon();
  btn.addEventListener('click', function() {
    var next = current() === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    localStorage.setItem('clipaza_theme', next);
    setIcon();
  });
})();
</script>
</body>
</html>
