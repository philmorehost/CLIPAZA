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
$filter  = sanitizeInput($_GET['status'] ?? ');
$roleF   = sanitizeInput($_GET['role'] ?? ');
$search  = sanitizeInput($_GET['q'] ?? ');

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
    $stmt = $db->prepare("SELECT u.*, up.active_mode, up.wallet_balance FROM users u LEFT JOIN user_profiles up ON up.user_id = u.id WHERE {$where} ORDER BY u.created_at DESC LIMIT ? OFFSET ?");
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
            <li class="nav-item"><a href="index" class="nav-link"><span class="nav-icon">⊞</span> Dashboard</a></li>
            <li class="nav-item"><a href="users" class="nav-link active"><span class="nav-icon">👥</span> Users</a></li>
            <li class="nav-item"><a href="contests" class="nav-link"><span class="nav-icon">🏆</span> Contests</a></li>
            <li class="nav-item"><a href="entries" class="nav-link"><span class="nav-icon">✂️</span> Entries</a></li>
            <li class="nav-item"><a href="payouts" class="nav-link"><span class="nav-icon">💸</span> Payouts</a></li>
            <li class="nav-item"><a href="security" class="nav-link"><span class="nav-icon">🛡</span> Security</a></li>
            <li class="nav-item"><a href="settings" class="nav-link"><span class="nav-icon">⚙</span> Settings</a></li>
        </ul>
        <hr class="divider-dark mx-3">
        <ul class="nav flex-column">
            <li class="nav-item"><a href="logout" class="nav-link" style="color:var(--danger)"><span class="nav-icon">⇤</span> Logout</a></li>
        </ul>
    </div>
</nav>
<main class="admin-main">
    <div class="admin-topbar">
        <div class="d-flex align-items-center gap-3">
            <button id="sidebarToggle" class="btn d-lg-none" style="color:#ccc;background:rgba(255,255,255,0.05);border-radius:8px;padding:6px 10px;">☰</button>
            <span style="color:#ccc;font-size:0.9rem">Welcome, <strong style="color:#fff"><?= e($_SESSION['username'] ?? ') ?></strong></span>
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
                    <option value="<?= $s ?>" <?= $filter===$s?'selected':' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="role" class="form-control-dark" style="max-width:140px;font-size:0.85rem" onchange="this.form.submit()">
                <option value="">All Roles</option>
                <?php foreach (['admin','user','moderator'] as $r): ?>
                    <option value="<?= $r ?>" <?= $roleF===$r?'selected':' ?>><?= ucfirst($r) ?></option>
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
                        <td style="font-size:0.82rem;color:#ccc"><?= e(ucfirst($u['active_mode'] ?? 'clipper')) ?></td>
                        <td style="font-size:0.8rem;color:#ccc"><?= e(formatDate($u['created_at'],'M j, Y')) ?></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <button class="btn btn-xs btn-outline-accent edit-user-btn"
                                        data-user='<?= json_encode([
                                            "id" => $u["id"],
                                            "username" => $u["username"],
                                            "email" => $u["email"],
                                            "role" => $u["role"],
                                            "status" => $u["status"],
                                            "wallet_balance" => $u["wallet_balance"] ?? 0
                                        ]) ?>'>Edit</button>
                                <button class="btn btn-xs btn-outline-info impersonate-btn" data-id="<?= $u['id'] ?>" data-csrf="<?= e($csrf) ?>">Login as</button>
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
                <li class="page-item <?= $i===$page?'active':' ?>"><a class="page-link" href="?page=<?= $i ?>&status=<?= e($filter) ?>&role=<?= e($roleF) ?>&q=<?= urlencode($search) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul></nav>
        <?php endif; ?>
    </div>
</main>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content card-dark border-0">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-700">Edit User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editUserForm">
                <div class="modal-body py-4">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="user_id" id="edit_user_id">

                    <div class="mb-3">
                        <label class="form-label-dark">Username</label>
                        <input type="text" name="username" id="edit_username" class="form-control form-control-dark" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-dark">Email Address</label>
                        <input type="email" name="email" id="edit_email" class="form-control form-control-dark" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label-dark">Role</label>
                            <select name="role" id="edit_role" class="form-control-dark">
                                <option value="user">User</option>
                                <option value="moderator">Moderator</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label-dark">Status</label>
                            <select name="status" id="edit_status" class="form-control-dark">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="banned">Banned</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label-dark">Wallet Balance (₦)</label>
                        <input type="number" step="0.01" name="wallet_balance" id="edit_wallet" class="form-control form-control-dark">
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-sm text-white" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-accent">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
document.querySelectorAll('.uab').forEach(btn => {
    btn.addEventListener('click', async function() {
        if (!confirm('Change user status?')) return;
        this.disabled = true;
        const r = await fetch('ajax/admin_actions', {
            method:'POST', body: new URLSearchParams({action:'update_user_status', user_id:this.dataset.id, status:this.dataset.st, csrf_token:this.dataset.csrf})
        });
        const d = await r.json();
        if (d.success) location.reload();
        else { alert(d.message||'Error'); this.disabled=false; }
    });
});

document.querySelectorAll('.impersonate-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        if (!confirm('Login as this user? You will be redirected to their dashboard.')) return;
        this.disabled = true;
        const r = await fetch('ajax/admin_actions', {
            method:'POST', body: new URLSearchParams({action:'login_as_user', user_id:this.dataset.id, csrf_token:this.dataset.csrf})
        });
        const d = await r.json();
        if (d.success) window.location.href = '../dashboard';
        else { alert(d.message||'Error'); this.disabled=false; }
    });
});

const editModal = new bootstrap.Modal(document.getElementById('editUserModal'));
document.querySelectorAll('.edit-user-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const u = JSON.parse(this.dataset.user);
        document.getElementById('edit_user_id').value = u.id;
        document.getElementById('edit_username').value = u.username;
        document.getElementById('edit_email').value = u.email;
        document.getElementById('edit_role').value = u.role;
        document.getElementById('edit_status').value = u.status;
        document.getElementById('edit_wallet').value = u.wallet_balance;
        editModal.show();
    });
});

document.getElementById('editUserForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    const r = await fetch('ajax/admin_actions', {
        method:'POST', body: new FormData(this)
    });
    const d = await r.json();
    if (d.success) location.reload();
    else { alert(d.message||'Error'); btn.disabled=false; }
});
</script>
</body></html>
