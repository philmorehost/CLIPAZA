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
$filter  = sanitizeInput($_GET['status'] ?? '');

$requests = [];
$total    = 0;
$pag      = paginate(0, $perPage, 1);

try {
    $db     = db();
    $where  = '1=1';
    $params = [];
    if ($filter && in_array($filter, ['pending','approved','rejected','cancelled','on_hold'], true)) {
        $where .= ' AND pr.status = ?';
        $params[] = $filter;
    }
    $cnt = $db->prepare("SELECT COUNT(*) FROM payout_requests pr WHERE {$where}");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $pag   = paginate($total, $perPage, $page);
    $listParams = array_merge($params, [$perPage, $pag['offset']]);
    $stmt = $db->prepare(
        "SELECT pr.*, u.username, u.email, up.kyc_status
         FROM payout_requests pr
         LEFT JOIN users u ON u.id = pr.user_id
         LEFT JOIN user_profiles up ON up.user_id = pr.user_id
         WHERE {$where}
         ORDER BY pr.created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute($listParams);
    $requests = $stmt->fetchAll();
} catch (Throwable) {}

// Stats
$stats = [];
try {
    $db = db();
    foreach (['pending','approved','rejected','cancelled','on_hold'] as $s) {
        $stmt = $db->prepare("SELECT COUNT(*), COALESCE(SUM(amount),0) FROM payout_requests WHERE status = ?");
        $stmt->execute([$s]);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $stats[$s] = ['count' => (int)$row[0], 'total' => (float)$row[1]];
    }
} catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payout Requests — Clipaza Admin</title>
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
            <li class="nav-item"><a href="payouts.php" class="nav-link active"><span class="nav-icon">💸</span> Payouts</a></li>
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
            <h1>Payout Requests</h1>
        </div>
        <span class="text-muted" style="font-size:0.85rem"><?= $total ?> total</span>
    </div>
    <div class="p-4">

        <!-- Stats row -->
        <div class="row g-3 mb-4">
            <?php foreach ([
                'pending'  => ['label'=>'Pending',   'color'=>'warning'],
                'approved' => ['label'=>'Approved',  'color'=>'success'],
                'rejected' => ['label'=>'Rejected',  'color'=>'danger'],
                'on_hold'  => ['label'=>'On Hold',   'color'=>'info'],
                'cancelled'=> ['label'=>'Cancelled', 'color'=>'muted'],
            ] as $st => $meta): ?>
            <div class="col-6 col-md">
                <div class="stat-card">
                    <div class="stat-value" style="font-size:1.5rem"><?= $stats[$st]['count'] ?? 0 ?></div>
                    <div class="stat-label"><?= $meta['label'] ?></div>
                    <div style="font-size:0.75rem;color:#888;margin-top:4px">₦<?= number_format($stats[$st]['total'] ?? 0, 0) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Filter tabs -->
        <div class="d-flex gap-2 mb-4 flex-wrap">
            <?php foreach (['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'on_hold' => 'On Hold', 'cancelled' => 'Cancelled'] as $val => $label): ?>
                <a href="?status=<?= $val ?>" class="btn btn-sm <?= $filter===$val ? 'btn-accent' : 'btn-outline-accent' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <div style="overflow-x:auto">
            <table class="table-dark-custom w-100" id="payoutsTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>User</th>
                        <th>Amount</th>
                        <th>Bank Details</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="7" class="text-center py-5" style="color:#888">No payout requests found.</td></tr>
                <?php else: ?>
                    <?php foreach ($requests as $r): ?>
                    <?php
                        $stClass = match($r['status']) {
                            'pending'   => 'badge-warning',
                            'approved'  => 'badge-success',
                            'rejected'  => 'badge-danger',
                            'cancelled' => 'badge-muted',
                            'on_hold'   => 'badge-info',
                            default     => 'badge-muted',
                        };
                    ?>
                    <tr data-id="<?= (int)$r['id'] ?>">
                        <td style="font-size:0.82rem;color:#888">#<?= (int)$r['id'] ?></td>
                        <td>
                            <div class="fw-600" style="color:#fff;font-size:0.88rem"><?= e($r['username'] ?? '—') ?></div>
                            <div style="font-size:0.75rem;color:#888"><?= e($r['email'] ?? '') ?></div>
                            <?php if (($r['kyc_status'] ?? 'none') === 'approved'): ?>
                                <span class="badge-success" style="font-size:0.65rem">KYC ✓</span>
                            <?php else: ?>
                                <span class="badge-warning" style="font-size:0.65rem">KYC: <?= e($r['kyc_status'] ?? 'none') ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:700;font-size:0.95rem;color:#fff">₦<?= number_format((float)$r['amount'], 0) ?></td>
                        <td style="font-size:0.8rem">
                            <div style="color:#ccc"><?= e($r['bank_name'] ?? '—') ?></div>
                            <div style="color:#888"><?= e($r['account_number'] ?? '') ?></div>
                            <div style="color:#aaa"><?= e($r['account_name'] ?? '') ?></div>
                        </td>
                        <td>
                            <span class="<?= $stClass ?>" style="font-size:0.75rem"><?= e(ucfirst(str_replace('_',' ',$r['status']))) ?></span>
                            <?php if (!empty($r['rejection_reason'])): ?>
                            <div style="font-size:0.72rem;color:var(--danger);margin-top:4px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($r['rejection_reason']) ?>">
                                <?= e($r['rejection_reason']) ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($r['appeal_message'])): ?>
                            <div class="badge-info mt-1" style="font-size:0.65rem;display:inline-block">Appeal submitted</div>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;font-size:0.78rem;color:#888"><?= e(formatDate($r['created_at'], 'M j, Y')) ?></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <button class="btn btn-xs view-detail-btn" data-id="<?= (int)$r['id'] ?>"
                                        style="background:rgba(255,255,255,0.05);color:#ccc;font-size:0.72rem;border:1px solid rgba(255,255,255,0.1);border-radius:6px;padding:3px 8px">
                                    View
                                </button>
                                <?php if ($r['status'] === 'pending'): ?>
                                <button class="btn btn-xs payout-action-btn"
                                        data-id="<?= (int)$r['id'] ?>" data-action="approve"
                                        data-bank="<?= e($r['bank_name'] ?? '') ?>"
                                        data-acct="<?= e($r['account_number'] ?? '') ?>"
                                        data-acctname="<?= e($r['account_name'] ?? '') ?>"
                                        style="background:rgba(0,204,102,0.1);color:#4ade80;font-size:0.72rem;border:1px solid rgba(0,204,102,0.2);border-radius:6px;padding:3px 8px">
                                    ✓ Approve
                                </button>
                                <button class="btn btn-xs mark-paid-btn"
                                        data-id="<?= (int)$r['id'] ?>"
                                        data-bank="<?= e($r['bank_name'] ?? '') ?>"
                                        data-acct="<?= e($r['account_number'] ?? '') ?>"
                                        data-acctname="<?= e($r['account_name'] ?? '') ?>"
                                        data-amount="₦<?= number_format((float)$r['amount'], 0) ?>"
                                        style="background:rgba(100,100,255,0.1);color:#a5b4fc;font-size:0.72rem;border:1px solid rgba(100,100,255,0.2);border-radius:6px;padding:3px 8px">
                                    ✓ Mark Paid
                                </button>
                                <button class="btn btn-xs payout-action-btn"
                                        data-id="<?= (int)$r['id'] ?>" data-action="reject"
                                        style="background:rgba(220,38,38,0.1);color:#f87171;font-size:0.72rem;border:1px solid rgba(220,38,38,0.2);border-radius:6px;padding:3px 8px">
                                    ✗ Reject
                                </button>
                                <button class="btn btn-xs payout-action-btn"
                                        data-id="<?= (int)$r['id'] ?>" data-action="cancel"
                                        style="background:rgba(255,170,0,0.1);color:var(--warning);font-size:0.72rem;border:1px solid rgba(255,170,0,0.2);border-radius:6px;padding:3px 8px">
                                    ⏸ Cancel
                                </button>
                                <?php elseif ($r['status'] === 'on_hold'): ?>
                                <button class="btn btn-xs payout-action-btn"
                                        data-id="<?= (int)$r['id'] ?>" data-action="restore"
                                        style="background:rgba(0,153,255,0.1);color:var(--info);font-size:0.72rem;border:1px solid rgba(0,153,255,0.2);border-radius:6px;padding:3px 8px">
                                    ↺ Restore
                                </button>
                                <button class="btn btn-xs mark-paid-btn"
                                        data-id="<?= (int)$r['id'] ?>"
                                        data-bank="<?= e($r['bank_name'] ?? '') ?>"
                                        data-acct="<?= e($r['account_number'] ?? '') ?>"
                                        data-acctname="<?= e($r['account_name'] ?? '') ?>"
                                        data-amount="₦<?= number_format((float)$r['amount'], 0) ?>"
                                        style="background:rgba(100,100,255,0.1);color:#a5b4fc;font-size:0.72rem;border:1px solid rgba(100,100,255,0.2);border-radius:6px;padding:3px 8px">
                                    ✓ Mark Paid
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pag['pages'] > 1): ?>
        <nav class="mt-3"><ul class="pagination pagination-dark justify-content-center">
            <?php for ($i = 1; $i <= $pag['pages']; $i++): ?>
                <li class="page-item <?= $i===$page?'active':'' ?>">
                    <a class="page-link" href="?page=<?= $i ?>&status=<?= e($filter) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
        </ul></nav>
        <?php endif; ?>
    </div>
</main>

<!-- View Detail Modal -->
<div class="modal fade modal-dark" id="detailModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title fw-700">Payout Request Details</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="detailModalBody">Loading…</div>
    </div>
  </div>
</div>

<!-- Action Modal (for reason input) -->
<div class="modal fade modal-dark" id="actionModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title fw-700" id="actionModalTitle">Confirm Action</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="actionForm">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" id="actionType">
          <input type="hidden" name="payout_request_id" id="actionPayoutId">
          <div class="mb-3" id="reasonGroup">
            <label class="form-label-dark" id="reasonLabel">Reason</label>
            <textarea name="reason" class="form-control-dark" rows="3" id="reasonTextarea"
                      placeholder="Enter reason…"></textarea>
          </div>
          <div class="mb-3" id="adminNoteGroup">
            <label class="form-label-dark">Admin Note (optional, internal)</label>
            <textarea name="admin_note" class="form-control-dark" rows="2"
                      placeholder="Internal note…"></textarea>
          </div>
          <div class="mb-3" id="pinGroup" style="display:none">
            <label class="form-label-dark">Approval PIN</label>
            <input type="password" name="payout_pin" id="actionPinInput" class="form-control-dark"
                   maxlength="6" placeholder="••••" inputmode="numeric" autocomplete="off">
          </div>
          <div id="actionFeedback" class="mb-2"></div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-accent" id="actionConfirmBtn">Confirm</button>
            <button type="button" class="btn btn-outline-accent" data-bs-dismiss="modal">Cancel</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Mark as Paid Modal -->
<div class="modal fade modal-dark" id="markPaidModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title fw-700">✓ Mark as Paid (Manual Transfer)</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3 p-3" style="background:#0d0d0d;border:1px solid #222;border-radius:8px">
          <div class="fw-700 mb-1" style="color:var(--accent)" id="markPaidAmount"></div>
          <div style="font-size:0.85rem;color:#ccc">Bank: <span id="markPaidBank"></span></div>
          <div style="font-size:0.85rem;color:#ccc">Account: <span id="markPaidAcct"></span></div>
          <div style="font-size:0.85rem;color:#aaa">Name: <span id="markPaidAcctName"></span></div>
        </div>
        <p class="text-muted mb-3" style="font-size:0.82rem">Confirm you have manually transferred the funds to the above bank account.</p>
        <form id="markPaidForm">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="mark_paid">
          <input type="hidden" name="payout_request_id" id="markPaidId">
          <div class="mb-3">
            <label class="form-label-dark">Admin Note (optional)</label>
            <textarea name="admin_note" id="markPaidNote" class="form-control-dark" rows="2"
                      placeholder="e.g. Transferred via bank app at 2:30pm"></textarea>
          </div>
          <div class="mb-3" id="markPaidPinWrap" style="display:none">
            <label class="form-label-dark">Approval PIN</label>
            <input type="password" name="payout_pin" id="markPaidPin" class="form-control-dark"
                   maxlength="6" placeholder="••••" inputmode="numeric" autocomplete="off">
          </div>
          <div id="markPaidFeedback" class="mb-2"></div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-accent" id="markPaidConfirmBtn">Confirm Payment</button>
            <button type="button" class="btn btn-outline-accent" data-bs-dismiss="modal">Cancel</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="../assets/js/main.js"></script>
<script>
const csrf = document.querySelector('meta[name="csrf"]').content;

// Store full request data
const requestData = <?= json_encode(array_column($requests, null, 'id')) ?>;

// Check if payout PIN is configured
let pinRequired = false;
(async () => {
    try {
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'verify_payout_pin');
        fd.append('payout_pin', '');
        const r = await fetch('ajax/admin_actions.php', { method: 'POST', body: fd });
        const d = await r.json();
        pinRequired = !!(d.pin_required);
    } catch {}
})();

// View detail
document.querySelectorAll('.view-detail-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        const r = requestData[id];
        if (!r) return;
        const modal = new bootstrap.Modal(document.getElementById('detailModal'));
        document.getElementById('detailModalBody').innerHTML = `
            <div class="row g-3">
                <div class="col-md-6">
                    <div style="font-size:0.75rem;color:#888;text-transform:uppercase;letter-spacing:0.06em">User</div>
                    <div style="font-weight:600;color:#fff">${r.username || '—'}</div>
                    <div style="font-size:0.82rem;color:#888">${r.email || ''}</div>
                </div>
                <div class="col-md-6">
                    <div style="font-size:0.75rem;color:#888;text-transform:uppercase;letter-spacing:0.06em">Amount</div>
                    <div style="font-weight:700;font-size:1.4rem;color:var(--accent)">₦${parseFloat(r.amount).toLocaleString()}</div>
                </div>
                <div class="col-md-6">
                    <div style="font-size:0.75rem;color:#888;text-transform:uppercase;letter-spacing:0.06em">Bank</div>
                    <div style="color:#ccc">${r.bank_name || '—'}</div>
                    <div style="font-size:0.82rem;color:#888">Account: ${r.account_number || '—'}</div>
                    <div style="font-size:0.82rem;color:#aaa">Name: ${r.account_name || '—'}</div>
                </div>
                <div class="col-md-6">
                    <div style="font-size:0.75rem;color:#888;text-transform:uppercase;letter-spacing:0.06em">Status</div>
                    <div style="color:#fff;font-weight:600;text-transform:capitalize">${(r.status||'').replace('_',' ')}</div>
                    ${r.processed_at ? `<div style="font-size:0.78rem;color:#888">Processed: ${r.processed_at}</div>` : ''}
                </div>
                ${r.rejection_reason ? `<div class="col-12"><div style="font-size:0.75rem;color:#888;text-transform:uppercase;letter-spacing:0.06em">Rejection Reason</div><div style="color:var(--danger)">${r.rejection_reason}</div></div>` : ''}
                ${r.cancel_reason ? `<div class="col-12"><div style="font-size:0.75rem;color:#888;text-transform:uppercase;letter-spacing:0.06em">Cancel Reason</div><div style="color:var(--warning)">${r.cancel_reason}</div></div>` : ''}
                ${r.appeal_message ? `<div class="col-12"><div style="font-size:0.75rem;color:#888;text-transform:uppercase;letter-spacing:0.06em">Appeal Message</div><div style="color:var(--info)">${r.appeal_message}</div></div>` : ''}
                ${r.admin_note ? `<div class="col-12"><div style="font-size:0.75rem;color:#888;text-transform:uppercase;letter-spacing:0.06em">Admin Note</div><div style="color:#aaa">${r.admin_note}</div></div>` : ''}
                <div class="col-12"><div style="font-size:0.75rem;color:#888">Requested: ${r.created_at}</div></div>
            </div>
        `;
        modal.show();
    });
});

// Payout action (approve/reject/cancel/restore)
document.querySelectorAll('.payout-action-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const id     = this.dataset.id;
        const action = this.dataset.action;
        document.getElementById('actionPayoutId').value = id;
        document.getElementById('actionType').value = action;

        const titles = { approve:'Approve Payout', reject:'Reject Payout', cancel:'Cancel Payout', restore:'Restore to Pending' };
        document.getElementById('actionModalTitle').textContent = titles[action] || 'Confirm Action';

        const reasonGroup    = document.getElementById('reasonGroup');
        const reasonTextarea = document.getElementById('reasonTextarea');
        const reasonLabel    = document.getElementById('reasonLabel');
        const adminNoteGroup = document.getElementById('adminNoteGroup');
        const pinGroup       = document.getElementById('pinGroup');
        const pinInput       = document.getElementById('actionPinInput');

        pinGroup.style.display = (action === 'approve' && pinRequired) ? 'block' : 'none';
        if (pinInput) { pinInput.value = ''; pinInput.required = (action === 'approve' && pinRequired); }

        if (action === 'approve') {
            reasonGroup.style.display = 'none';
            reasonTextarea.required = false;
            adminNoteGroup.style.display = 'block';
        } else if (action === 'reject') {
            reasonGroup.style.display = 'block';
            reasonTextarea.required = true;
            reasonLabel.textContent = 'Rejection Reason (required)';
            adminNoteGroup.style.display = 'block';
        } else if (action === 'cancel') {
            reasonGroup.style.display = 'block';
            reasonTextarea.required = true;
            reasonLabel.textContent = 'Cancellation Reason (shown to user)';
            adminNoteGroup.style.display = 'block';
        } else if (action === 'restore') {
            reasonGroup.style.display = 'none';
            reasonTextarea.required = false;
            adminNoteGroup.style.display = 'block';
        }

        document.getElementById('actionFeedback').innerHTML = '';
        const modal = new bootstrap.Modal(document.getElementById('actionModal'));
        modal.show();
    });
});

document.getElementById('actionForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fb  = document.getElementById('actionFeedback');
    const btn = document.getElementById('actionConfirmBtn');
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

// Mark as Paid (manual transfer)
document.querySelectorAll('.mark-paid-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const id      = this.dataset.id;
        const bank    = this.dataset.bank;
        const acct    = this.dataset.acct;
        const acctName = this.dataset.acctname;
        const amount  = this.dataset.amount;

        document.getElementById('markPaidId').value = id;
        document.getElementById('markPaidAmount').textContent = amount;
        document.getElementById('markPaidBank').textContent = bank || '—';
        document.getElementById('markPaidAcct').textContent = acct || '—';
        document.getElementById('markPaidAcctName').textContent = acctName || '—';
        document.getElementById('markPaidPinWrap').style.display = pinRequired ? 'block' : 'none';
        const pinInput = document.getElementById('markPaidPin');
        if (pinInput) { pinInput.value = ''; pinInput.required = pinRequired; }
        document.getElementById('markPaidNote').value = '';
        document.getElementById('markPaidFeedback').innerHTML = '';

        const modal = new bootstrap.Modal(document.getElementById('markPaidModal'));
        modal.show();
    });
});

document.getElementById('markPaidForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const fb  = document.getElementById('markPaidFeedback');
    const btn = document.getElementById('markPaidConfirmBtn');
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
<script src="assets/js/theme_sync.js"></script>
</body>
</html>
