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

$csrf = generateCsrfToken();
$filter = sanitizeInput($_GET['status'] ?? 'pending');

try {
    $db = db();
    $stmt = $db->prepare("
        SELECT up.*, u.username, u.email
        FROM user_profiles up
        INNER JOIN users u ON u.id = up.user_id
        WHERE up.kyc_status = ?
        ORDER BY up.updated_at ASC
    ");
    $stmt->execute([$filter]);
    $requests = $stmt->fetchAll();
} catch (Throwable $e) { $requests = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>KYC Management — Clipaza Admin</title>
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
            <li class="nav-item"><a href="kyc.php" class="nav-link active"><span class="nav-icon">🆔</span> KYC Review</a></li>
            <li class="nav-item"><a href="contests.php" class="nav-link"><span class="nav-icon">🏆</span> Contests</a></li>
            <li class="nav-item"><a href="payouts.php" class="nav-link"><span class="nav-icon">💸</span> Payouts</a></li>
            <li class="nav-item"><a href="settings.php" class="nav-link"><span class="nav-icon">⚙</span> Settings</a></li>
        </ul>
    </div>
</nav>

<main class="admin-main">
    <div class="admin-topbar"><h1>KYC Review</h1></div>
    <div class="p-4">
        <div class="d-flex gap-2 mb-4">
            <a href="?status=pending" class="btn btn-sm <?= $filter==='pending'?'btn-accent':'btn-outline-accent' ?>">Pending</a>
            <a href="?status=approved" class="btn btn-sm <?= $filter==='approved'?'btn-accent':'btn-outline-accent' ?>">Approved</a>
            <a href="?status=rejected" class="btn btn-sm <?= $filter==='rejected'?'btn-accent':'btn-outline-accent' ?>">Rejected</a>
        </div>

        <div class="row g-4">
            <?php foreach ($requests as $r): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card-dark p-3 h-100">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <div class="avatar-circle"><?= strtoupper(substr($r['username'], 0, 1)) ?></div>
                            <div>
                                <div class="fw-700">@<?= e($r['username']) ?></div>
                                <div class="text-muted small"><?= e($r['email']) ?></div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="small text-muted mb-1">ID Type: <?= e(strtoupper($r['kyc_id_type'])) ?></div>
                            <?php if ($r['kyc_id_path']): ?>
                                <a href="/<?= e($r['kyc_id_path']) ?>" target="_blank" class="d-block mb-2">
                                    <img src="/<?= e($r['kyc_id_path']) ?>" class="img-fluid rounded border border-secondary" style="max-height:100px">
                                </a>
                            <?php endif; ?>
                            <?php if ($r['kyc_snapshot_path']): ?>
                                <div class="small text-muted mb-1">Live Snapshot:</div>
                                <img src="/<?= e($r['kyc_snapshot_path']) ?>" class="img-fluid rounded border border-accent" style="max-height:100px">
                            <?php endif; ?>
                        </div>

                        <div class="p-2 rounded bg-black mb-3 small">
                            <div class="fw-600 text-accent"><?= e($r['account_name']) ?></div>
                            <div class="text-muted"><?= e($r['bank_name']) ?> (<?= e($r['account_number']) ?>)</div>
                        </div>

                        <?php if ($filter === 'pending'): ?>
                            <div class="d-grid gap-2">
                                <button class="btn btn-sm btn-accent kyc-action" data-id="<?= $r['user_id'] ?>" data-st="approved">Approve</button>
                                <button class="btn btn-sm btn-outline-danger kyc-action" data-id="<?= $r['user_id'] ?>" data-st="rejected">Reject</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; if (empty($requests)) echo '<div class="col-12 text-center py-5 text-muted">No requests found</div>'; ?>
        </div>
    </div>
</main>

<script>
document.querySelectorAll('.kyc-action').forEach(btn => {
    btn.addEventListener('click', async function() {
        const st = this.dataset.st;
        let reason = '';
        if (st === 'rejected') {
            reason = prompt('Enter rejection reason:');
            if (reason === null) return;
        } else {
            if (!confirm('Approve this KYC request?')) return;
        }

        this.disabled = true;
        const r = await fetch('/admin/ajax/admin_actions.php', {
            method:'POST',
            body: new URLSearchParams({
                action:'review_kyc',
                user_id:this.dataset.id,
                status:st,
                reason:reason,
                csrf_token:'<?= $csrf ?>'
            })
        });
        const d = await r.json();
        if (d.success) location.reload(); else { alert(d.message); this.disabled = false; }
    });
});
</script>
</body></html>
