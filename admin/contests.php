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

$contests = [];
$total    = 0;
$pag      = paginate(0, $perPage, 1);

try {
    $db     = db();
    $where  = '1=1';
    $params = [];
    if ($filter && in_array($filter, ['draft','active','expired','cancelled'], true)) {
        $where .= ' AND c.status = ?'; $params[] = $filter;
    }
    $cnt = $db->prepare("SELECT COUNT(*) FROM contests c WHERE {$where}");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $pag   = paginate($total, $perPage, $page);
    $listParams = array_merge($params, [$perPage, $pag['offset']]);
    $stmt = $db->prepare(
        "SELECT c.*, u.username AS creator_name,
                (SELECT COUNT(*) FROM contest_entries WHERE contest_id = c.id) AS entry_count
         FROM contests c LEFT JOIN users u ON u.id = c.creator_id
         WHERE {$where}
         ORDER BY c.created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute($listParams);
    $contests = $stmt->fetchAll();
} catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contests — Clipaza Admin</title>
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
            <li class="nav-item"><a href="contests.php" class="nav-link active"><span class="nav-icon">🏆</span> Contests</a></li>
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
            <span style="color:#888;font-size:0.9rem">Welcome, <strong style="color:#fff"><?= e($_SESSION['username'] ?? '') ?></strong></span>
        </div>
    </div>
    <div class="p-4">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h4 class="fw-700 mb-0">Contests</h4>
            <span class="text-muted" style="font-size:0.85rem"><?= $total ?> total</span>
        </div>

        <!-- Filter tabs -->
        <div class="d-flex gap-2 mb-4 flex-wrap">
            <?php foreach (['' => 'All', 'active' => 'Active', 'draft' => 'Draft', 'expired' => 'Expired', 'cancelled' => 'Cancelled'] as $val => $label): ?>
                <a href="?status=<?= $val ?>" class="btn btn-sm <?= $filter===$val ? 'btn-accent' : 'btn-outline-accent' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <div class="table-responsive">
            <table class="table-dark-custom w-100">
                <thead>
                    <tr>
                        <th>Contest</th>
                        <th>Creator</th>
                        <th>Prize Pool</th>
                        <th>Status</th>
                        <th>Entries</th>
                        <th>End Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($contests)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No contests found.</td></tr>
                <?php else: ?>
                    <?php foreach ($contests as $c): ?>
                    <tr>
                        <td>
                            <div class="fw-600" style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($c['title']) ?></div>
                            <?php if ($c['escrow_status'] !== 'unfunded'): ?>
                                <span class="badge" style="background:rgba(34,197,94,0.1);color:#4ade80;font-size:0.68rem"><?= e(ucfirst($c['escrow_status'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.85rem;color:#ccc"><?= e($c['creator_name'] ?? '—') ?></td>
                        <td style="font-size:0.88rem;font-weight:600">₦<?= number_format((float)$c['prize_pool'], 0) ?></td>
                        <td>
                            <?php $sc = $c['status']==='active' ? 'badge-success' : ($c['status']==='cancelled' ? 'badge-danger' : 'badge-muted'); ?>
                            <span class="<?= $sc ?>" style="font-size:0.72rem"><?= e(ucfirst($c['status'])) ?></span>
                        </td>
                        <td style="font-size:0.85rem;text-align:center"><?= (int)$c['entry_count'] ?></td>
                        <td style="font-size:0.8rem;color:#888"><?= !empty($c['end_date']) ? e(formatDate($c['end_date'],'M j, Y')) : '—' ?></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <a href="/contest?id=<?= $c['id'] ?>" target="_blank" class="btn btn-xs btn-outline-accent">View</a>
                                <?php if ($c['status']==='draft'): ?>
                                    <button class="btn btn-xs cab" style="background:rgba(34,197,94,0.1);color:#4ade80;font-size:0.72rem;border:1px solid rgba(34,197,94,0.2)"
                                            data-id="<?= $c['id'] ?>" data-st="active" data-csrf="<?= e($csrf) ?>">Activate</button>
                                <?php endif; ?>
                                <?php if (!in_array($c['status'], ['cancelled','expired'], true)): ?>
                                    <button class="btn btn-xs cab" style="background:rgba(220,38,38,0.1);color:#f87171;font-size:0.72rem;border:1px solid rgba(220,38,38,0.2)"
                                            data-id="<?= $c['id'] ?>" data-st="cancelled" data-csrf="<?= e($csrf) ?>">Cancel</button>
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
document.querySelectorAll('.cab').forEach(btn => {
    btn.addEventListener('click', async function() {
        const label = this.dataset.st === 'cancelled' ? 'cancel' : 'activate';
        if (!confirm('Are you sure you want to ' + label + ' this contest?')) return;
        this.disabled = true;
        const r = await fetch('/admin/ajax/admin_actions.php', {
            method:'POST', body: new URLSearchParams({action:'update_contest_status', contest_id:this.dataset.id, status:this.dataset.st, csrf_token:this.dataset.csrf})
        });
        const d = await r.json();
        if (d.success) location.reload();
        else { alert(d.message||'Error'); this.disabled=false; }
    });
});
</script>
</body></html>
