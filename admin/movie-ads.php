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
$filter  = sanitizeInput($_GET['status'] ?? 'all');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$ads     = [];
$total   = 0;
$pag     = paginate(0, $perPage, 1);

try {
    $db     = db();
    $where  = '1=1';
    $params = [];

    $validFilters = ['all', 'pending_review', 'approved', 'rejected', 'draft', 'expired', 'cancelled'];
    if ($filter !== 'all' && in_array($filter, $validFilters, true)) {
        $where   .= ' AND ma.status = ?';
        $params[] = $filter;
    }

    $cntStmt = $db->prepare("SELECT COUNT(*) FROM movie_ads ma WHERE {$where}");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();
    $pag   = paginate($total, $perPage, $page);

    $stmt = $db->prepare(
        "SELECT ma.*, u.username, u.email, ap.name AS package_name, ap.price AS package_price
         FROM movie_ads ma
         LEFT JOIN users u ON u.id = ma.user_id
         LEFT JOIN ad_packages ap ON ap.id = ma.package_id
         WHERE {$where}
         ORDER BY ma.created_at DESC LIMIT ? OFFSET ?"
    );
    $listParams = array_merge($params, [$perPage, $pag['offset']]);
    $baseCount  = count($params);
    foreach ($params as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }
    $stmt->bindValue($baseCount + 1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($baseCount + 2, $pag['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $ads = $stmt->fetchAll();
} catch (Throwable) {}

$siteUrl = rtrim(getSetting('site_url', ''), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movie Ads — Clipaza Admin</title>
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
    <?php $sn = getSetting("site_name", "Clipaza"); $sl = getSetting("site_logo", ""); if ($sl): ?><div class="sidebar-brand"><img src="<?= e($sl) ?>" alt="<?= e($sn) ?>" style="height:28px"></div><?php else: ?><div class="sidebar-brand"><?= e($sn) ?></div><?php endif; ?>
    <div class="sidebar-nav">
        <ul class="nav flex-column">
            <li class="nav-item"><a href="index.php" class="nav-link"><span class="nav-icon">⊞</span> Dashboard</a></li>
            <li class="nav-item"><a href="users.php" class="nav-link"><span class="nav-icon">👥</span> Users</a></li>
            <li class="nav-item"><a href="contests.php" class="nav-link"><span class="nav-icon">🏆</span> Contests</a></li>
            <li class="nav-item"><a href="featured-contests.php" class="nav-link"><span class="nav-icon">⭐</span> Featured</a></li>
            <li class="nav-item"><a href="payouts.php" class="nav-link"><span class="nav-icon">💸</span> Payouts</a></li>
            <li class="nav-item"><a href="kyc.php" class="nav-link"><span class="nav-icon">🪪</span> KYC</a></li>
            <li class="nav-item"><a href="ad-packages.php" class="nav-link"><span class="nav-icon">📦</span> Ad Packages</a></li>
            <li class="nav-item"><a href="movie-ads.php" class="nav-link active"><span class="nav-icon">🎞</span> Movie Ads</a></li>
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
            <button id="sidebarToggle" class="btn d-lg-none" style="color:var(--text-muted);background:var(--subtle-bg);border-radius:8px;padding:6px 10px;">☰</button>
            <button id="adminThemeToggle" class="btn-theme-toggle" title="Toggle light/dark mode" aria-label="Toggle theme" style="margin-left:4px">☀️</button>
            <h1>Movie Ads</h1>
        </div>
        <span class="text-muted" style="font-size:0.85rem"><?= $total ?> records</span>
    </div>
    <div class="p-4">
        <div id="adsFeedback" class="mb-3"></div>

        <!-- Filter tabs -->
        <div class="d-flex gap-2 mb-4 flex-wrap">
            <?php
            $tabs = [
                'all'            => 'All',
                'pending_review' => 'Pending Review',
                'approved'       => 'Approved',
                'rejected'       => 'Rejected',
                'draft'          => 'Draft',
                'expired'        => 'Expired',
            ];
            foreach ($tabs as $val => $label):
            ?>
            <a href="?status=<?= $val ?>" class="btn btn-sm <?= $filter === $val ? 'btn-accent' : 'btn-outline-accent' ?>">
                <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($ads)): ?>
        <div class="card-dark p-5 text-center">
            <div style="font-size:3rem;margin-bottom:12px">🎞</div>
            <h6 class="fw-700">No movie ads found</h6>
        </div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-dark-custom">
            <thead>
                <tr>
                    <th>Movie</th>
                    <th>Creator</th>
                    <th>Package</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ads as $ad): ?>
            <?php
                $statusClass = match($ad['status']) {
                    'approved'       => 'badge-success',
                    'rejected'       => 'badge-danger',
                    'pending_review' => 'badge-warning',
                    'expired'        => 'badge-muted',
                    default          => 'badge-muted',
                };
                $payClass = match($ad['payment_status']) {
                    'paid'                   => 'badge-success',
                    'pending_verification'   => 'badge-warning',
                    default                  => 'badge-muted',
                };
            ?>
            <tr>
                <td>
                    <div class="fw-700" style="font-size:0.9rem"><?= e($ad['movie_title']) ?></div>
                    <?php if ($ad['genre']): ?>
                    <div style="font-size:0.78rem;color:var(--text-muted)"><?= e($ad['genre']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="font-size:0.88rem"><?= e($ad['username'] ?? '—') ?></div>
                    <div style="font-size:0.75rem;color:var(--text-muted)"><?= e($ad['email'] ?? '') ?></div>
                </td>
                <td>
                    <div style="font-size:0.85rem"><?= e($ad['package_name'] ?? '—') ?></div>
                    <?php if ($ad['package_price']): ?>
                    <div style="font-size:0.78rem;color:var(--accent)">₦<?= number_format((float)$ad['package_price'], 2) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="<?= $payClass ?>" style="font-size:0.72rem">
                        <?= e(str_replace('_', ' ', ucfirst($ad['payment_status']))) ?>
                    </span>
                    <div style="font-size:0.72rem;color:var(--text-muted)"><?= e(ucfirst($ad['payment_method'])) ?></div>
                </td>
                <td>
                    <span class="<?= $statusClass ?>" style="font-size:0.72rem">
                        <?= e(str_replace('_', ' ', ucfirst($ad['status']))) ?>
                    </span>
                </td>
                <td style="font-size:0.78rem;color:var(--text-muted)"><?= e(formatDate($ad['created_at'], 'M j, Y')) ?></td>
                <td>
                    <div class="d-flex gap-1 flex-wrap">
                        <button class="btn btn-sm btn-outline-accent view-ad-btn"
                                data-id="<?= (int)$ad['id'] ?>"
                                style="font-size:0.75rem">View</button>
                        <?php if ($ad['status'] === 'pending_review'): ?>
                        <button class="btn btn-sm approve-ad-btn"
                                data-id="<?= (int)$ad['id'] ?>"
                                data-title="<?= e($ad['movie_title']) ?>"
                                style="font-size:0.75rem;background:rgba(0,204,102,0.1);color:#4ade80;border:1px solid rgba(0,204,102,0.2)">
                            ✓ Approve
                        </button>
                        <button class="btn btn-sm reject-ad-btn"
                                data-id="<?= (int)$ad['id'] ?>"
                                data-title="<?= e($ad['movie_title']) ?>"
                                style="font-size:0.75rem;background:rgba(220,38,38,0.1);color:#f87171;border:1px solid rgba(220,38,38,0.2)">
                            ✗ Reject
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <!-- Detail row (hidden) -->
            <tr class="ad-detail-row" id="detail-<?= (int)$ad['id'] ?>" style="display:none">
                <td colspan="7">
                    <div class="p-3" style="background:var(--input-bg);border-radius:8px">
                        <div class="row g-3">
                            <?php if ($ad['poster_path']): ?>
                            <div class="col-md-2">
                                <div style="font-size:0.72rem;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase">Poster</div>
                                <a href="<?= e($siteUrl . $ad['poster_path']) ?>" target="_blank">
                                    <img src="<?= e($siteUrl . $ad['poster_path']) ?>" alt="Poster"
                                         style="width:100%;max-width:120px;border-radius:6px;border:1px solid var(--border)">
                                </a>
                            </div>
                            <?php endif; ?>
                            <?php if ($ad['flyer_path']): ?>
                            <div class="col-md-2">
                                <div style="font-size:0.72rem;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase">E-Flyer</div>
                                <a href="<?= e($siteUrl . $ad['flyer_path']) ?>" target="_blank">
                                    <img src="<?= e($siteUrl . $ad['flyer_path']) ?>" alt="Flyer"
                                         style="width:100%;max-width:120px;border-radius:6px;border:1px solid var(--border)">
                                </a>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-4">
                                <div style="font-size:0.72rem;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px">Movie Details</div>
                                <?php if ($ad['tagline']): ?>
                                <div style="font-size:0.85rem;color:#ddd;font-style:italic;margin-bottom:4px">"<?= e($ad['tagline']) ?>"</div>
                                <?php endif; ?>
                                <?php if ($ad['description']): ?>
                                <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:6px"><?= e($ad['description']) ?></div>
                                <?php endif; ?>
                                <?php if ($ad['release_date']): ?>
                                <div style="font-size:0.78rem;color:var(--text-muted)">Release: <?= e(formatDate($ad['release_date'], 'M j, Y')) ?></div>
                                <?php endif; ?>
                                <?php if ($ad['trailer_url']): ?>
                                <a href="<?= e($ad['trailer_url']) ?>" target="_blank" class="btn btn-sm btn-outline-accent mt-2" style="font-size:0.75rem">
                                    ▶ Trailer
                                </a>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <div style="font-size:0.72rem;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px">Contact & Payment</div>
                                <?php if ($ad['contact_email']): ?>
                                <div style="font-size:0.8rem;color:var(--text-muted)">📧 <?= e($ad['contact_email']) ?></div>
                                <?php endif; ?>
                                <?php if ($ad['contact_phone']): ?>
                                <div style="font-size:0.8rem;color:var(--text-muted)">📞 <?= e($ad['contact_phone']) ?></div>
                                <?php endif; ?>
                                <?php if ($ad['website_url']): ?>
                                <div style="font-size:0.8rem"><a href="<?= e($ad['website_url']) ?>" target="_blank" style="color:var(--accent)">🌐 Website</a></div>
                                <?php endif; ?>
                                <?php if ($ad['manual_deposit_proof']): ?>
                                <div class="mt-2">
                                    <div style="font-size:0.72rem;color:var(--text-muted);margin-bottom:4px">Deposit Proof</div>
                                    <a href="<?= e($siteUrl . $ad['manual_deposit_proof']) ?>" target="_blank" class="btn btn-sm btn-outline-accent" style="font-size:0.75rem">
                                        📄 View Proof
                                    </a>
                                    <?php if ($ad['manual_deposit_amount']): ?>
                                    <div style="font-size:0.78rem;color:var(--text-muted);margin-top:4px">Amount: ₦<?= number_format((float)$ad['manual_deposit_amount'], 2) ?></div>
                                    <?php endif; ?>
                                    <?php if ($ad['manual_deposit_note']): ?>
                                    <div style="font-size:0.78rem;color:var(--text-muted)"><?= e($ad['manual_deposit_note']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($ad['review_note']): ?>
                                <div class="mt-2" style="font-size:0.78rem;color:var(--danger)">
                                    Review note: <?= e($ad['review_note']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php if ($pag['pages'] > 1): ?>
        <nav class="mt-4"><ul class="pagination pagination-dark justify-content-center">
            <?php for ($i = 1; $i <= $pag['pages']; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $i ?>&status=<?= e($filter) ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<!-- Reject Modal -->
<div class="modal fade modal-dark" id="rejectModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title fw-700">Reject Movie Ad</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="rejectForm">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="reject_movie_ad">
          <input type="hidden" name="ad_id" id="rejectAdId">
          <p class="text-muted" style="font-size:0.85rem">Rejecting: <strong id="rejectAdTitle"></strong></p>
          <div class="mb-3">
            <label class="form-label-dark">Rejection Reason <span style="color:var(--danger)">*</span></label>
            <textarea name="reason" class="form-control-dark" rows="3" id="rejectReason" required
                      placeholder="Please explain why this ad is being rejected…"></textarea>
          </div>
          <div id="rejectFeedback" class="mb-2"></div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-accent" id="rejectConfirmBtn">Confirm Rejection</button>
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
const rejectModal = new bootstrap.Modal(document.getElementById('rejectModal'));

document.querySelectorAll('.view-ad-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const row = document.getElementById('detail-' + this.dataset.id);
        if (row.style.display === 'none') {
            row.style.display = '';
            this.textContent = 'Hide';
        } else {
            row.style.display = 'none';
            this.textContent = 'View';
        }
    });
});

document.querySelectorAll('.approve-ad-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        if (!confirm('Approve ad "' + this.dataset.title + '"?')) return;
        const id = this.dataset.id;
        try {
            const r = await fetch('ajax/admin_actions.php', {
                method: 'POST',
                body: new URLSearchParams({csrf_token: csrf, action: 'approve_movie_ad', ad_id: id})
            });
            const d = await r.json();
            if (d.success) {
                showFeedback('✅ ' + d.message, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showFeedback(d.message || 'Error', 'danger');
            }
        } catch {
            showFeedback('Network error.', 'danger');
        }
    });
});

document.querySelectorAll('.reject-ad-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('rejectAdId').value = this.dataset.id;
        document.getElementById('rejectAdTitle').textContent = this.dataset.title;
        document.getElementById('rejectReason').value = '';
        document.getElementById('rejectFeedback').innerHTML = '';
        rejectModal.show();
    });
});

document.getElementById('rejectForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fb  = document.getElementById('rejectFeedback');
    const btn = document.getElementById('rejectConfirmBtn');
    btn.disabled = true; btn.textContent = 'Processing…'; fb.innerHTML = '';
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
            btn.disabled = false; btn.textContent = 'Confirm Rejection';
        }
    } catch {
        fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">Network error.</div>';
        btn.disabled = false; btn.textContent = 'Confirm Rejection';
    }
});

function showFeedback(msg, type) {
    const fb = document.getElementById('adsFeedback');
    fb.innerHTML = '<div class="alert-dark-' + type + '" style="font-size:0.85rem">' + msg + '</div>';
    setTimeout(() => { fb.innerHTML = ''; }, 4000);
}
</script>
<script src="assets/js/theme_sync.js"></script>
</body>
</html>
