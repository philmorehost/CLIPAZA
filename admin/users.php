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
$roleF   = sanitizeInput($_GET['role'] ?? '');
$search  = sanitizeInput($_GET['q'] ?? '');

$users = [];
$total = 0;
$pag   = paginate(0, $perPage, 1);

try {
    $db     = db();
    $where  = '1=1';
    $params = [];
    if ($filter && in_array($filter, ['active','inactive','banned','pending'], true)) {
        $where .= ' AND u.status = ?'; $params[] = $filter;
    }
    if ($roleF && in_array($roleF, ['admin','user','moderator'], true)) {
        $where .= ' AND u.role = ?'; $params[] = $roleF;
    }
    if ($search) {
        $where .= ' AND (u.username LIKE ? OR u.email LIKE ?)';
        $params[] = '%'.$search.'%'; $params[] = '%'.$search.'%';
    }
    $cnt = $db->prepare("SELECT COUNT(*) FROM users u WHERE {$where}");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $pag   = paginate($total, $perPage, $page);
    $listParams = array_merge($params, [$perPage, $pag['offset']]);
    $stmt = $db->prepare("SELECT u.*, up.active_mode FROM users u LEFT JOIN user_profiles up ON up.user_id = u.id WHERE {$where} ORDER BY u.created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute($listParams);
    $users = $stmt->fetchAll();
} catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users — Clipaza Admin</title>
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
            <li class="nav-item"><a href="users.php" class="nav-link active"><span class="nav-icon">👥</span> Users</a></li>
            <li class="nav-item"><a href="/contests.php" class="nav-link"><span class="nav-icon">🏆</span> Contests</a></li>
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
            <h4 class="fw-700 mb-0">Users</h4>
            <span class="text-muted" style="font-size:0.85rem"><?= $total ?> total</span>
        </div>
        <form method="GET" class="d-flex gap-2 flex-wrap mb-4">
            <input type="text" name="q" class="form-control-dark" style="max-width:200px;font-size:0.85rem" placeholder="Search…" value="<?= e($search) ?>">
            <select name="status" class="form-control-dark" style="max-width:140px;font-size:0.85rem" onchange="this.form.submit()">
                <option value="">All Status</option>
                <?php foreach (['active','inactive','banned','pending'] as $s): ?>
                    <option value="<?= $s ?>" <?= $filter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="role" class="form-control-dark" style="max-width:140px;font-size:0.85rem" onchange="this.form.submit()">
                <option value="">All Roles</option>
                <?php foreach (['admin','user','moderator'] as $r): ?>
                    <option value="<?= $r ?>" <?= $roleF===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-accent">Filter</button>
        </form>
        <div class="table-responsive">
            <table class="table-dark-custom w-100">
                <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Mode</th><th>Joined</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No users found.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><div class="fw-600"><?= e($u['username']) ?></div><div class="text-muted" style="font-size:0.78rem"><?= e($u['email']) ?></div></td>
                        <td><span class="badge" style="background:#1a1a1a;font-size:0.72rem"><?= e($u['role']) ?></span></td>
                        <td>
                            <?php $sc = $u['status']==='active' ? 'badge-success' : ($u['status']==='banned' ? 'badge-danger' : 'badge-muted'); ?>
                            <span class="<?= $sc ?>" style="font-size:0.75rem"><?= e(ucfirst($u['status'])) ?></span>
                        </td>
                        <td style="font-size:0.82rem;color:#888"><?= e(ucfirst($u['active_mode'] ?? 'clipper')) ?></td>
                        <td style="font-size:0.8rem;color:#888"><?= e(formatDate($u['created_at'],'M j, Y')) ?></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                            <?php if ($u['status']!=='active'): ?><button class="btn btn-xs btn-outline-accent uab" data-id="<?= $u['id'] ?>" data-st="active" data-csrf="<?= e($csrf) ?>">Activate</button><?php endif; ?>
                            <?php if ($u['status']!=='inactive'): ?><button class="btn btn-xs uab" style="background:#1a1a1a;color:#ccc;font-size:0.72rem;border:1px solid #2a2a2a" data-id="<?= $u['id'] ?>" data-st="inactive" data-csrf="<?= e($csrf) ?>">Suspend</button><?php endif; ?>
                            <?php if ($u['status']!=='banned'): ?><button class="btn btn-xs uab" style="background:rgba(220,38,38,0.1);color:#f87171;font-size:0.72rem;border:1px solid rgba(220,38,38,0.2)" data-id="<?= $u['id'] ?>" data-st="banned" data-csrf="<?= e($csrf) ?>">Ban</button><?php endif; ?>
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
                <li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="?page=<?= $i ?>&status=<?= e($filter) ?>&role=<?= e($roleF) ?>&q=<?= urlencode($search) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul></nav>
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
document.querySelectorAll('.uab').forEach(btn => {
    btn.addEventListener('click', async function() {
        if (!confirm('Change user status?')) return;
        this.disabled = true;
        const r = await fetch('/admin/ajax/admin_actions.php', {
            method:'POST', body: new URLSearchParams({action:'update_user_status', user_id:this.dataset.id, status:this.dataset.st, csrf_token:this.dataset.csrf})
        });
        const d = await r.json();
        if (d.success) location.reload();
        else { alert(d.message||'Error'); this.disabled=false; }
    });
});
</script>
</body></html>
