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

$csrf     = generateCsrfToken();
$plans    = [];
$featured = [];

try {
    $db   = db();
    $plans = $db->query("SELECT * FROM featured_contest_plans ORDER BY sort_order ASC, id ASC")->fetchAll();
    $featured = $db->query(
        "SELECT c.id, c.title, c.prize_pool, c.is_featured, c.featured_until, c.featured_plan_id, c.creator_id,
                u.username AS creator_username
         FROM contests c LEFT JOIN users u ON u.id = c.creator_id
         WHERE c.is_featured = 1 AND (c.featured_until IS NULL OR c.featured_until > NOW())
         ORDER BY c.featured_until ASC"
    )->fetchAll();
} catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Featured Contests — Clipaza Admin</title>
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
            <li class="nav-item"><a href="featured-contests.php" class="nav-link active"><span class="nav-icon">⭐</span> Featured</a></li>
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
            <h1>Featured Contests</h1>
        </div>
        <button class="btn btn-accent btn-sm" id="createPlanBtn">+ Create Plan</button>
    </div>
    <div class="p-4">
        <div id="planFeedback" class="mb-3"></div>

        <!-- Feature Plans -->
        <h5 class="fw-700 mb-3">Feature Plans</h5>
        <?php if (empty($plans)): ?>
        <div class="card-dark p-4 text-center mb-4">
            <p class="text-muted mb-0">No plans yet. Create one to enable featured contests.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive mb-4">
        <table class="table table-dark-custom">
            <thead><tr><th>Name</th><th>Price</th><th>Duration</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($plans as $p): ?>
            <tr>
                <td class="fw-600"><?= e($p['name']) ?></td>
                <td>₦<?= number_format((float)$p['price'], 0) ?></td>
                <td><?= (int)$p['duration_days'] ?> days</td>
                <td class="text-muted" style="font-size:0.85rem"><?= e($p['description'] ?? '') ?></td>
                <td><?= $p['is_active'] ? '<span class="badge-success">Active</span>' : '<span class="badge-muted">Inactive</span>' ?></td>
                <td>
                    <button class="btn btn-xs btn-outline-accent me-1 edit-plan-btn"
                        data-id="<?= (int)$p['id'] ?>"
                        data-name="<?= e($p['name']) ?>"
                        data-description="<?= e($p['description'] ?? '') ?>"
                        data-price="<?= (float)$p['price'] ?>"
                        data-days="<?= (int)$p['duration_days'] ?>"
                        data-active="<?= (int)$p['is_active'] ?>">Edit</button>
                    <button class="btn btn-xs btn-danger-custom delete-plan-btn" data-id="<?= (int)$p['id'] ?>">Delete</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <!-- Currently Featured Contests -->
        <h5 class="fw-700 mb-3">Currently Featured</h5>
        <?php if (empty($featured)): ?>
        <div class="card-dark p-4 text-center">
            <p class="text-muted mb-0">No contests currently featured.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-dark-custom">
            <thead><tr><th>Contest</th><th>Creator</th><th>Prize Pool</th><th>Featured Until</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($featured as $fc): ?>
            <tr>
                <td><a href="/contest?id=<?= (int)$fc['id'] ?>" class="text-white text-decoration-none fw-600" target="_blank"><?= e($fc['title']) ?></a></td>
                <td class="text-muted">@<?= e($fc['creator_username'] ?? '') ?></td>
                <td>₦<?= number_format((float)$fc['prize_pool'], 0) ?></td>
                <td class="text-muted" style="font-size:0.85rem"><?= !empty($fc['featured_until']) ? date('M j, Y g:i A', strtotime($fc['featured_until'])) : 'Indefinite' ?></td>
                <td>
                    <button class="btn btn-xs btn-danger-custom unfeature-btn" data-id="<?= (int)$fc['id'] ?>">Unfeature</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</main>

<!-- Create/Edit Plan Modal -->
<div class="modal fade" id="planModal" tabindex="-1">
    <div class="modal-dialog modal-dark">
        <div class="modal-content modal-dark">
            <div class="modal-header" style="border-bottom:1px solid #1a1a1a">
                <h5 class="modal-title fw-700" id="planModalTitle">Create Plan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1)"></button>
            </div>
            <div class="modal-body">
                <div id="planModalFeedback" class="mb-3"></div>
                <input type="hidden" id="planId">
                <div class="mb-3">
                    <label class="form-label-dark">Plan Name</label>
                    <input type="text" id="planName" class="form-control form-control-dark" placeholder="e.g. Standard" maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label-dark">Description</label>
                    <input type="text" id="planDescription" class="form-control form-control-dark" placeholder="e.g. Feature for 7 days" maxlength="500">
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label-dark">Price (₦)</label>
                        <input type="number" id="planPrice" class="form-control form-control-dark" min="0" step="100" placeholder="5000">
                    </div>
                    <div class="col-6">
                        <label class="form-label-dark">Duration (days)</label>
                        <input type="number" id="planDays" class="form-control form-control-dark" min="1" max="365" placeholder="7">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="d-flex align-items-center gap-2" style="cursor:pointer">
                        <input type="checkbox" id="planActive" checked> <span style="font-size:0.9rem">Active (visible to users)</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #1a1a1a">
                <button type="button" class="btn btn-outline-accent btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-accent btn-sm" id="savePlanBtn">Save Plan</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const csrf = document.querySelector('meta[name="csrf"]').content;
const feedback = id => document.getElementById(id);

document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    document.querySelector('.admin-sidebar').classList.toggle('open');
});

document.getElementById('createPlanBtn').addEventListener('click', () => {
    document.getElementById('planModalTitle').textContent = 'Create Plan';
    document.getElementById('planId').value = '';
    document.getElementById('planName').value = '';
    document.getElementById('planDescription').value = '';
    document.getElementById('planPrice').value = '';
    document.getElementById('planDays').value = '7';
    document.getElementById('planActive').checked = true;
    document.getElementById('planModalFeedback').innerHTML = '';
    new bootstrap.Modal(document.getElementById('planModal')).show();
});

document.querySelectorAll('.edit-plan-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('planModalTitle').textContent = 'Edit Plan';
        document.getElementById('planId').value = this.dataset.id;
        document.getElementById('planName').value = this.dataset.name;
        document.getElementById('planDescription').value = this.dataset.description;
        document.getElementById('planPrice').value = this.dataset.price;
        document.getElementById('planDays').value = this.dataset.days;
        document.getElementById('planActive').checked = this.dataset.active === '1';
        document.getElementById('planModalFeedback').innerHTML = '';
        new bootstrap.Modal(document.getElementById('planModal')).show();
    });
});

document.getElementById('savePlanBtn').addEventListener('click', async function() {
    const id    = document.getElementById('planId').value;
    const name  = document.getElementById('planName').value.trim();
    const desc  = document.getElementById('planDescription').value.trim();
    const price = parseFloat(document.getElementById('planPrice').value);
    const days  = parseInt(document.getElementById('planDays').value);
    const active = document.getElementById('planActive').checked ? 1 : 0;
    const fb = document.getElementById('planModalFeedback');
    if (!name) { fb.innerHTML = '<div class="alert-dark-danger">Name required.</div>'; return; }
    if (isNaN(price) || price < 0) { fb.innerHTML = '<div class="alert-dark-danger">Valid price required.</div>'; return; }
    if (!days || days < 1) { fb.innerHTML = '<div class="alert-dark-danger">Valid duration required.</div>'; return; }
    this.disabled = true; this.textContent = 'Saving…';
    const action = id ? 'update_feature_plan' : 'create_feature_plan';
    const body = new URLSearchParams({action, csrf_token: csrf, plan_id: id, name, description: desc, price, duration_days: days, is_active: active});
    try {
        const r = await fetch('ajax/admin_actions.php', {method:'POST', body});
        const d = await r.json();
        if (d.success) { location.reload(); }
        else { fb.innerHTML = '<div class="alert-dark-danger">' + (d.message||'Error') + '</div>'; }
    } catch { fb.innerHTML = '<div class="alert-dark-danger">Network error.</div>'; }
    this.disabled = false; this.textContent = 'Save Plan';
});

document.querySelectorAll('.delete-plan-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        if (!confirm('Delete this plan?')) return;
        const fb = feedback('planFeedback');
        try {
            const r = await fetch('ajax/admin_actions.php', {method:'POST', body: new URLSearchParams({action:'delete_feature_plan', csrf_token:csrf, plan_id:this.dataset.id})});
            const d = await r.json();
            if (d.success) { location.reload(); }
            else fb.innerHTML = '<div class="alert-dark-danger">' + (d.message||'Error') + '</div>';
        } catch { fb.innerHTML = '<div class="alert-dark-danger">Network error.</div>'; }
    });
});

document.querySelectorAll('.unfeature-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        if (!confirm('Remove from featured?')) return;
        const fb = feedback('planFeedback');
        try {
            const r = await fetch('ajax/admin_actions.php', {method:'POST', body: new URLSearchParams({action:'unfeature_contest', csrf_token:csrf, contest_id:this.dataset.id})});
            const d = await r.json();
            if (d.success) { location.reload(); }
            else fb.innerHTML = '<div class="alert-dark-danger">' + (d.message||'Error') + '</div>';
        } catch { fb.innerHTML = '<div class="alert-dark-danger">Network error.</div>'; }
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
