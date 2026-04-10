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

$payouts = [];
$total    = 0;
$pag      = paginate(0, $perPage, 1);

try {
    $db     = db();
    $where  = '1=1';
    $params = [];
    if ($filter && in_array($filter, ['pending','claimed','processing','completed','failed'], true)) {
        $where .= ' AND p.status = ?'; $params[] = $filter;
    }

    $cnt = $db->prepare("SELECT COUNT(*) FROM payouts p WHERE {$where}");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $pag   = paginate($total, $perPage, $page);

    $listParams = array_merge($params, [$perPage, $pag['offset']]);
    $stmt = $db->prepare(
        "SELECT p.*, u.username, c.title AS contest_title
         FROM payouts p
         LEFT JOIN users u ON u.id = p.user_id
         LEFT JOIN contests c ON c.id = p.contest_id
         WHERE {$where}
         ORDER BY p.created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute($listParams);
    $payouts = $stmt->fetchAll();
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payouts — Clipaza Admin</title>
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
            <li class="nav-item"><a href="entries.php" class="nav-link"><span class="nav-icon">✂️</span> Entries</a></li>
            <li class="nav-item"><a href="payouts.php" class="nav-link active"><span class="nav-icon">💸</span> Payouts</a></li>
            <li class="nav-item"><a href="security.php" class="nav-link"><span class="nav-icon">🛡</span> Security</a></li>
            <li class="nav-item"><a href="settings.php" class="nav-link"><span class="nav-icon">⚙</span> Settings</a></li>
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
            <span style="color:#888;font-size:0.9rem">Welcome, <strong style="color:#fff"><?= e($_SESSION['username'] ?? '') ?></strong></span>
        </div>
    </div>
    <div class="p-4">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h4 class="fw-700 mb-0">Payout Management</h4>
            <span class="text-muted" style="font-size:0.85rem"><?= $total ?> total</span>
        </div>

        <!-- Filter tabs -->
        <div class="d-flex gap-2 mb-4 flex-wrap">
            <?php foreach (['' => 'All', 'claimed' => 'Claimed', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed', 'pending' => 'Pending'] as $val => $label): ?>
                <a href="?status=<?= $val ?>" class="btn btn-sm <?= $filter===$val ? 'btn-accent' : 'btn-outline-accent' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <div class="table-responsive">
            <table class="table-dark-custom w-100">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Contest</th>
                        <th>Amount</th>
                        <th>Bank Details</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($payouts)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No payouts found.</td></tr>
                <?php else: ?>
                    <?php foreach ($payouts as $p): ?>
                    <tr>
                        <td>
                            <div class="fw-600">@<?= e($p['username'] ?? 'unknown') ?></div>
                        </td>
                        <td>
                            <div style="font-size:0.85rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($p['contest_title']) ?></div>
                            <div class="text-muted" style="font-size:0.75rem"><?= e(ucfirst($p['platform'])) ?> Rank #<?= $p['rank_position'] ?></div>
                        </td>
                        <td style="font-size:0.88rem;font-weight:600">₦<?= number_format((float)$p['amount'], 0) ?></td>
                        <td>
                            <?php if ($p['account_number']): ?>
                                <div style="font-size:0.82rem"><?= e($p['account_name']) ?></div>
                                <div class="text-muted" style="font-size:0.75rem"><?= e($p['bank_name']) ?> (<?= e($p['account_number']) ?>)</div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                                $sc = match($p['status']) {
                                    'completed' => 'badge-success',
                                    'failed' => 'badge-danger',
                                    'processing' => 'badge-warning',
                                    'claimed' => 'badge-accent',
                                    default => 'badge-muted'
                                };
                            ?>
                            <span class="<?= $sc ?>" style="font-size:0.72rem"><?= e(ucfirst($p['status'])) ?></span>
                        </td>
                        <td style="font-size:0.8rem;color:#888"><?= e(formatDate($p['created_at'], 'M j, Y')) ?></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <?php if ($p['status'] === 'claimed' || ($p['status'] === 'pending' && $p['account_number'])): ?>
                                    <button class="btn btn-xs ppb" style="background:rgba(204,255,0,0.1);color:var(--accent);border:1px solid rgba(204,255,0,0.2)"
                                            data-id="<?= $p['id'] ?>" data-csrf="<?= e($csrf) ?>">Approve & Pay</button>
                                <?php endif; ?>
                                <?php if (in_array($p['status'], ['claimed', 'pending'])): ?>
                                    <button class="btn btn-xs usb" data-id="<?= $p['id'] ?>" data-st="rejected" data-csrf="<?= e($csrf) ?>">Reject</button>
                                    <button class="btn btn-xs usb" data-id="<?= $p['id'] ?>" data-st="cancelled" data-csrf="<?= e($csrf) ?>">Cancel</button>
                                <?php endif; ?>
                                <?php if (in_array($p['status'], ['rejected', 'cancelled', 'failed']) || ($p['appeal_message'] && $p['status']!=='processing')): ?>
                                    <button class="btn btn-xs usb badge-info" data-id="<?= $p['id'] ?>" data-st="pending" data-csrf="<?= e($csrf) ?>">Set Pending</button>
                                <?php endif; ?>
                                <?php if ($p['status'] === 'processing'): ?>
                                    <button class="btn btn-xs usb" data-id="<?= $p['id'] ?>" data-st="completed" data-csrf="<?= e($csrf) ?>">Mark Success</button>
                                <?php endif; ?>
                            </div>
                            <?php if ($p['appeal_message']): ?>
                                <div class="mt-2 p-2 bg-black small rounded border border-info">
                                    <strong>Appeal:</strong> <?= e($p['appeal_message']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pag['pages'] > 1): ?>
        <nav class="mt-3"><ul class="pagination pagination-dark justify-content-center">
            <?php for ($i=1;$i<=$pag['pages'];$i++): ?>
                <li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="?page=<?= $i ?>&status=<?= e($filter) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul></nav>
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
document.querySelectorAll('.ppb').forEach(btn => {
    btn.addEventListener('click', async function() {
        if (!confirm('Initiate payout via Paystack?')) return;
        this.disabled = true;
        const r = await fetch('/admin/ajax/admin_actions.php', {
            method:'POST', body: new URLSearchParams({action:'process_payout', payout_id:this.dataset.id, csrf_token:this.dataset.csrf})
        });
        const d = await r.json();
        if (d.success) { alert(d.message); location.reload(); }
        else { alert(d.message||'Error'); this.disabled=false; }
    });
});

document.querySelectorAll('.usb').forEach(btn => {
    btn.addEventListener('click', async function() {
        const st = this.dataset.st;
        let reason = '';
        if (st === 'rejected' || st === 'cancelled') {
            reason = prompt('Reason for ' + st + ':');
            if (reason === null) return;
        } else {
            if (!confirm('Update payout status to ' + st + '?')) return;
        }

        this.disabled = true;
        const r = await fetch('/admin/ajax/admin_actions.php', {
            method:'POST', body: new URLSearchParams({action:'update_payout_status', payout_id:this.dataset.id, status:st, reason:reason, csrf_token:this.dataset.csrf})
        });
        const d = await r.json();
        if (d.success) location.reload();
        else { alert(d.message||'Error'); this.disabled=false; }
    });
});
</script>
</body></html>
