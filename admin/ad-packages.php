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
$packages = [];

try {
    $stmt = db()->prepare("SELECT * FROM ad_packages ORDER BY sort_order ASC, id ASC");
    $stmt->execute();
    $packages = $stmt->fetchAll();
} catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ad Packages — Clipaza Admin</title>
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
            <li class="nav-item"><a href="ad-packages.php" class="nav-link active"><span class="nav-icon">📦</span> Ad Packages</a></li>
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
            <h1>Ad Packages</h1>
        </div>
        <button class="btn btn-accent btn-sm" id="createPackageBtn">+ Create Package</button>
    </div>
    <div class="p-4">
        <div id="pkgFeedback" class="mb-3"></div>

        <?php if (empty($packages)): ?>
        <div class="card-dark p-5 text-center">
            <div style="font-size:3rem;margin-bottom:12px">📦</div>
            <h6 class="fw-700">No ad packages yet</h6>
            <p class="text-muted" style="font-size:0.85rem">Create a package to let movie creators advertise on the platform.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-dark-custom">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Price</th>
                    <th>Duration</th>
                    <th>Placement Zones</th>
                    <th>Max Ads</th>
                    <th>Sort</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($packages as $pkg): ?>
            <?php
                $zones    = json_decode($pkg['placement_zones'] ?? '[]', true) ?: [];
                $features = json_decode($pkg['features'] ?? '[]', true) ?: [];
            ?>
            <tr>
                <td>
                    <div class="fw-700" style="font-size:0.9rem"><?= e($pkg['name']) ?></div>
                    <?php if ($pkg['description']): ?>
                    <div style="font-size:0.78rem;color:#888"><?= e(mb_strimwidth($pkg['description'], 0, 60, '…')) ?></div>
                    <?php endif; ?>
                </td>
                <td class="fw-700" style="color:var(--accent)">₦<?= number_format((float)$pkg['price'], 2) ?></td>
                <td><?= (int)$pkg['duration_days'] ?> days</td>
                <td>
                    <?php foreach ($zones as $z): ?>
                    <span class="badge-muted" style="font-size:0.7rem;margin:1px"><?= e(str_replace('_', ' ', $z)) ?></span>
                    <?php endforeach; ?>
                </td>
                <td><?= (int)$pkg['max_ads'] ?></td>
                <td><?= (int)$pkg['sort_order'] ?></td>
                <td>
                    <span class="<?= $pkg['is_active'] ? 'badge-success' : 'badge-danger' ?>" style="font-size:0.75rem">
                        <?= $pkg['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </td>
                <td>
                    <div class="d-flex gap-1 flex-wrap">
                        <button class="btn btn-sm btn-outline-accent edit-pkg-btn"
                                data-id="<?= (int)$pkg['id'] ?>"
                                data-name="<?= e($pkg['name']) ?>"
                                data-description="<?= e($pkg['description'] ?? '') ?>"
                                data-price="<?= e($pkg['price']) ?>"
                                data-duration="<?= (int)$pkg['duration_days'] ?>"
                                data-features="<?= e(implode("\n", $features)) ?>"
                                data-zones="<?= e(implode(',', $zones)) ?>"
                                data-maxads="<?= (int)$pkg['max_ads'] ?>"
                                data-sort="<?= (int)$pkg['sort_order'] ?>"
                                data-active="<?= (int)$pkg['is_active'] ?>"
                                style="font-size:0.75rem">Edit</button>
                        <button class="btn btn-sm toggle-pkg-btn"
                                data-id="<?= (int)$pkg['id'] ?>"
                                data-active="<?= (int)$pkg['is_active'] ?>"
                                style="font-size:0.75rem;background:rgba(255,255,255,0.05);color:#aaa;border:1px solid #333">
                            <?= $pkg['is_active'] ? 'Disable' : 'Enable' ?>
                        </button>
                        <button class="btn btn-sm delete-pkg-btn"
                                data-id="<?= (int)$pkg['id'] ?>"
                                data-name="<?= e($pkg['name']) ?>"
                                style="font-size:0.75rem;background:rgba(220,38,38,0.1);color:#f87171;border:1px solid rgba(220,38,38,0.2)">
                            Delete
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</main>

<!-- Package Modal -->
<div class="modal fade modal-dark" id="packageModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title fw-700" id="pkgModalTitle">Create Ad Package</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="packageForm">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" id="pkgAction" value="create_ad_package">
          <input type="hidden" name="package_id" id="pkgId" value="">

          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label-dark">Package Name <span style="color:var(--danger)">*</span></label>
              <input type="text" name="name" id="pkgName" class="form-control-dark" required maxlength="255">
            </div>
            <div class="col-md-4">
              <label class="form-label-dark">Price (₦) <span style="color:var(--danger)">*</span></label>
              <input type="number" name="price" id="pkgPrice" class="form-control-dark" min="1" step="0.01" required>
            </div>
            <div class="col-12">
              <label class="form-label-dark">Description</label>
              <textarea name="description" id="pkgDescription" class="form-control-dark" rows="2"></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label-dark">Duration (days) <span style="color:var(--danger)">*</span></label>
              <input type="number" name="duration_days" id="pkgDuration" class="form-control-dark" min="1" value="30" required>
            </div>
            <div class="col-md-4">
              <label class="form-label-dark">Max Ads</label>
              <input type="number" name="max_ads" id="pkgMaxAds" class="form-control-dark" min="1" value="1">
            </div>
            <div class="col-md-4">
              <label class="form-label-dark">Sort Order</label>
              <input type="number" name="sort_order" id="pkgSort" class="form-control-dark" min="0" value="0">
            </div>
            <div class="col-12">
              <label class="form-label-dark">Features (one per line)</label>
              <textarea name="features" id="pkgFeatures" class="form-control-dark" rows="4"
                        placeholder="Poster placement on homepage&#10;Trailer link visible&#10;Mobile optimized"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label-dark">Placement Zones</label>
              <div class="d-flex gap-3 flex-wrap mt-2">
                <?php foreach (['homepage' => 'Homepage', 'contests_page' => 'Contests Page', 'sidebar' => 'Sidebar', 'contest_detail' => 'Contest Detail'] as $val => $lbl): ?>
                <div class="form-check" style="min-width:160px">
                  <input type="checkbox" class="form-check-input zone-check" name="placement_zones[]"
                         id="zone_<?= $val ?>" value="<?= $val ?>">
                  <label class="form-check-label text-white" for="zone_<?= $val ?>"><?= $lbl ?></label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input type="checkbox" class="form-check-input" name="is_active" id="pkgIsActive" value="1" checked>
                <label class="form-check-label text-white" for="pkgIsActive">Active (visible to users)</label>
              </div>
            </div>
          </div>

          <div id="pkgModalFeedback" class="mt-3"></div>
          <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-accent" id="pkgSubmitBtn">Save Package</button>
            <button type="button" class="btn btn-outline-accent" data-bs-dismiss="modal">Cancel</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
const csrf = document.querySelector('meta[name="csrf"]').content;
const pkgModal = new bootstrap.Modal(document.getElementById('packageModal'));

function resetForm() {
    document.getElementById('packageForm').reset();
    document.getElementById('pkgId').value = '';
    document.getElementById('pkgAction').value = 'create_ad_package';
    document.getElementById('pkgModalTitle').textContent = 'Create Ad Package';
    document.getElementById('pkgModalFeedback').innerHTML = '';
    document.getElementById('pkgIsActive').checked = true;
}

document.getElementById('createPackageBtn').addEventListener('click', () => {
    resetForm();
    pkgModal.show();
});

document.querySelectorAll('.edit-pkg-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        resetForm();
        document.getElementById('pkgId').value = this.dataset.id;
        document.getElementById('pkgAction').value = 'update_ad_package';
        document.getElementById('pkgModalTitle').textContent = 'Edit Ad Package';
        document.getElementById('pkgName').value = this.dataset.name;
        document.getElementById('pkgDescription').value = this.dataset.description;
        document.getElementById('pkgPrice').value = this.dataset.price;
        document.getElementById('pkgDuration').value = this.dataset.duration;
        document.getElementById('pkgFeatures').value = this.dataset.features;
        document.getElementById('pkgMaxAds').value = this.dataset.maxads;
        document.getElementById('pkgSort').value = this.dataset.sort;
        document.getElementById('pkgIsActive').checked = this.dataset.active === '1';
        const activeZones = this.dataset.zones ? this.dataset.zones.split(',') : [];
        document.querySelectorAll('.zone-check').forEach(cb => {
            cb.checked = activeZones.includes(cb.value);
        });
        pkgModal.show();
    });
});

document.getElementById('packageForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fb  = document.getElementById('pkgModalFeedback');
    const btn = document.getElementById('pkgSubmitBtn');
    btn.disabled = true; btn.textContent = 'Saving…'; fb.innerHTML = '';
    try {
        const r = await fetch('ajax/admin_actions.php', {
            method: 'POST',
            body: new URLSearchParams(new FormData(this))
        });
        const d = await r.json();
        if (d.success) {
            fb.innerHTML = '<div class="alert-dark-success" style="font-size:0.82rem">✅ ' + d.message + '</div>';
            btn.textContent = 'Done ✅';
            setTimeout(() => location.reload(), 1000);
        } else {
            fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">' + (d.message || 'Error') + '</div>';
            btn.disabled = false; btn.textContent = 'Save Package';
        }
    } catch {
        fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">Network error.</div>';
        btn.disabled = false; btn.textContent = 'Save Package';
    }
});

document.querySelectorAll('.toggle-pkg-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const id = this.dataset.id;
        try {
            const r = await fetch('ajax/admin_actions.php', {
                method: 'POST',
                body: new URLSearchParams({csrf_token: csrf, action: 'toggle_ad_package', package_id: id})
            });
            const d = await r.json();
            if (d.success) {
                showFeedback('✅ ' + d.message, 'success');
                setTimeout(() => location.reload(), 800);
            } else {
                showFeedback(d.message || 'Error', 'danger');
            }
        } catch {
            showFeedback('Network error.', 'danger');
        }
    });
});

document.querySelectorAll('.delete-pkg-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        if (!confirm('Delete package "' + this.dataset.name + '"? This cannot be undone.')) return;
        const id = this.dataset.id;
        try {
            const r = await fetch('ajax/admin_actions.php', {
                method: 'POST',
                body: new URLSearchParams({csrf_token: csrf, action: 'delete_ad_package', package_id: id})
            });
            const d = await r.json();
            if (d.success) {
                showFeedback('✅ ' + d.message, 'success');
                setTimeout(() => location.reload(), 800);
            } else {
                showFeedback(d.message || 'Error', 'danger');
            }
        } catch {
            showFeedback('Network error.', 'danger');
        }
    });
});

function showFeedback(msg, type) {
    const fb = document.getElementById('pkgFeedback');
    fb.innerHTML = '<div class="alert-dark-' + type + '" style="font-size:0.85rem">' + msg + '</div>';
    setTimeout(() => { fb.innerHTML = ''; }, 4000);
}
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
