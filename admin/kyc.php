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
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$filter  = sanitizeInput($_GET['status'] ?? 'pending');

$submissions = [];
$total       = 0;
$pag         = paginate(0, $perPage, 1);

try {
    $db = db();
    $where  = '1=1';
    $params = [];
    if ($filter && in_array($filter, ['pending', 'approved', 'rejected', 'none'], true)) {
        $where .= ' AND up.kyc_status = ?';
        $params[] = $filter;
    } elseif ($filter === 'all') {
        $where .= " AND up.kyc_status != 'none'";
    } else {
        $where .= " AND up.kyc_status = 'pending'";
        $params[] = 'pending';
    }
    $cnt = $db->prepare("SELECT COUNT(*) FROM user_profiles up WHERE {$where}");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $pag   = paginate($total, $perPage, $page);
    $listParams = array_merge($params, [$perPage, $pag['offset']]);
    $stmt = $db->prepare(
        "SELECT up.*, u.username, u.email FROM user_profiles up
         LEFT JOIN users u ON u.id = up.user_id
         WHERE {$where}
         ORDER BY up.updated_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute($listParams);
    $submissions = $stmt->fetchAll();
} catch (Throwable) {}

$siteUrl = rtrim(getSetting('site_url', ''), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KYC Review — Clipaza Admin</title>
    <meta name="csrf" content="<?= e($csrf) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="admin-sidebar">
    <div class="sidebar-brand">Clipa<span>za</span></div>
    <div class="sidebar-nav">
        <ul class="nav flex-column">
            <li class="nav-item"><a href="index.php" class="nav-link"><span class="nav-icon">⊞</span> Dashboard</a></li>
            <li class="nav-item"><a href="users.php" class="nav-link"><span class="nav-icon">👥</span> Users</a></li>
            <li class="nav-item"><a href="contests.php" class="nav-link"><span class="nav-icon">🏆</span> Contests</a></li>
            <li class="nav-item"><a href="payouts.php" class="nav-link"><span class="nav-icon">💸</span> Payouts</a></li>
            <li class="nav-item"><a href="kyc.php" class="nav-link active"><span class="nav-icon">🪪</span> KYC</a></li>
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
            <h1>KYC Review</h1>
        </div>
        <span class="text-muted" style="font-size:0.85rem"><?= $total ?> records</span>
    </div>
    <div class="p-4">

        <!-- Filter tabs -->
        <div class="d-flex gap-2 mb-4 flex-wrap">
            <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All Submitted'] as $val => $label): ?>
                <a href="?status=<?= $val ?>" class="btn btn-sm <?= $filter===$val ? 'btn-accent' : 'btn-outline-accent' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($submissions)): ?>
        <div class="card-dark p-5 text-center">
            <div style="font-size:3rem;margin-bottom:12px">🪪</div>
            <h6 class="fw-700">No KYC submissions found</h6>
        </div>
        <?php else: ?>
        <div class="row g-3">
        <?php foreach ($submissions as $s): ?>
        <?php
            $stClass = match($s['kyc_status']) {
                'pending'  => 'badge-warning',
                'approved' => 'badge-success',
                'rejected' => 'badge-danger',
                default    => 'badge-muted',
            };
            $isExpiry = in_array($s['kyc_id_type'] ?? '', ['driver_license', 'international_passport'], true);
            $isExpired = $isExpiry && !empty($s['kyc_id_expiry']) && strtotime($s['kyc_id_expiry']) < time();
        ?>
        <div class="col-12">
        <div class="card-dark p-4">
            <div class="row g-3 align-items-start">
                <!-- User info -->
                <div class="col-md-3">
                    <div class="fw-700" style="font-size:0.95rem;color:#fff"><?= e($s['username'] ?? '—') ?></div>
                    <div style="font-size:0.8rem;color:#888"><?= e($s['email'] ?? '') ?></div>
                    <div class="mt-2">
                        <span class="<?= $stClass ?>" style="font-size:0.72rem"><?= e(ucfirst($s['kyc_status'])) ?></span>
                    </div>
                    <?php if (!empty($s['bank_name'])): ?>
                    <div class="mt-2" style="font-size:0.78rem;color:#aaa">
                        Bank: <?= e($s['bank_name']) ?><br>
                        Acct: <?= e($s['account_number'] ?? '') ?><br>
                        Name: <?= e($s['account_name'] ?? '') ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ID Info -->
                <div class="col-md-4">
                    <div style="font-size:0.75rem;color:#888;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:8px">Identity Document</div>
                    <div class="mb-2">
                        <span class="badge-info" style="font-size:0.75rem"><?= e(str_replace('_', ' ', ucfirst($s['kyc_id_type'] ?? 'N/A'))) ?></span>
                        <?php if ($isExpired): ?>
                            <span class="badge-danger ms-1" style="font-size:0.7rem">⚠ EXPIRED</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isExpiry && !empty($s['kyc_id_expiry'])): ?>
                    <div style="font-size:0.8rem;color:<?= $isExpired ? 'var(--danger)' : '#888' ?>">
                        Expiry: <?= e(formatDate($s['kyc_id_expiry'], 'M j, Y')) ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($s['kyc_id_path'])): ?>
                    <a href="<?= e($siteUrl . $s['kyc_id_path']) ?>" target="_blank" class="btn btn-sm btn-outline-accent mt-2" style="font-size:0.78rem">
                        📄 View ID Document
                    </a>
                    <?php endif; ?>
                </div>

                <!-- Snapshot -->
                <div class="col-md-3">
                    <div style="font-size:0.75rem;color:#888;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:8px">Live Snapshot</div>
                    <?php if (!empty($s['kyc_snapshot_path'])): ?>
                    <a href="<?= e($siteUrl . $s['kyc_snapshot_path']) ?>" target="_blank">
                        <img src="<?= e($siteUrl . $s['kyc_snapshot_path']) ?>" alt="Snapshot"
                             style="width:100%;max-width:140px;border-radius:8px;border:1px solid #333;object-fit:cover;aspect-ratio:3/4">
                    </a>
                    <?php else: ?>
                    <span style="font-size:0.82rem;color:#555">No snapshot uploaded</span>
                    <?php endif; ?>
                </div>

                <!-- Actions -->
                <div class="col-md-2">
                    <div style="font-size:0.75rem;color:#888;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:8px">Actions</div>
                    <?php if ($s['kyc_status'] === 'pending'): ?>
                    <div class="d-flex flex-column gap-2">
                        <button class="btn btn-sm kyc-action-btn" style="background:rgba(0,204,102,0.1);color:#4ade80;border:1px solid rgba(0,204,102,0.2)"
                                data-id="<?= (int)$s['user_id'] ?>" data-action="approve_kyc">
                            ✓ Approve
                        </button>
                        <button class="btn btn-sm kyc-action-btn" style="background:rgba(220,38,38,0.1);color:#f87171;border:1px solid rgba(220,38,38,0.2)"
                                data-id="<?= (int)$s['user_id'] ?>" data-action="reject_kyc">
                            ✗ Reject
                        </button>
                    </div>
                    <?php elseif ($s['kyc_status'] === 'rejected'): ?>
                    <div style="font-size:0.78rem;color:var(--danger)"><?= e($s['kyc_rejection_reason'] ?? '') ?></div>
                    <?php endif; ?>
                    <?php if ($isExpired): ?>
                    <div class="mt-2" style="font-size:0.75rem;color:var(--warning)">⚠ ID Expired — re-upload required</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        </div>
        <?php endforeach; ?>
        </div>

        <?php if ($pag['pages'] > 1): ?>
        <nav class="mt-4"><ul class="pagination pagination-dark justify-content-center">
            <?php for ($i = 1; $i <= $pag['pages']; $i++): ?>
                <li class="page-item <?= $i===$page?'active':'' ?>">
                    <a class="page-link" href="?page=<?= $i ?>&status=<?= e($filter) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
        </ul></nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<!-- KYC Action Modal -->
<div class="modal fade modal-dark" id="kycActionModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title fw-700" id="kycModalTitle">KYC Action</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="kycActionForm">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" id="kycActionType">
          <input type="hidden" name="kyc_user_id" id="kycUserId">
          <div class="mb-3" id="kycReasonGroup" style="display:none">
            <label class="form-label-dark">Rejection Reason (required)</label>
            <textarea name="reason" class="form-control-dark" rows="3" id="kycReasonTextarea"
                      placeholder="Explain why the KYC was rejected…"></textarea>
          </div>
          <div id="kycFeedback" class="mb-2"></div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-accent" id="kycConfirmBtn">Confirm</button>
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

document.querySelectorAll('.kyc-action-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const action = this.dataset.action;
        const userId = this.dataset.id;
        document.getElementById('kycActionType').value = action;
        document.getElementById('kycUserId').value = userId;
        document.getElementById('kycModalTitle').textContent = action === 'approve_kyc' ? 'Approve KYC' : 'Reject KYC';
        const reasonGroup = document.getElementById('kycReasonGroup');
        const reasonTextarea = document.getElementById('kycReasonTextarea');
        if (action === 'reject_kyc') {
            reasonGroup.style.display = 'block';
            reasonTextarea.required = true;
        } else {
            reasonGroup.style.display = 'none';
            reasonTextarea.required = false;
        }
        document.getElementById('kycFeedback').innerHTML = '';
        new bootstrap.Modal(document.getElementById('kycActionModal')).show();
    });
});

document.getElementById('kycActionForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fb = document.getElementById('kycFeedback');
    const btn = document.getElementById('kycConfirmBtn');
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
            setTimeout(() => location.reload(), 1200);
        } else {
            fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">' + (d.message||'Error') + '</div>';
            btn.disabled = false; btn.textContent = 'Confirm';
        }
    } catch {
        fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">Network error.</div>';
        btn.disabled = false; btn.textContent = 'Confirm';
    }
});
</script>
</body>
</html>
