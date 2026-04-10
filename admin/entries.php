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
$platformF = sanitizeInput($_GET['platform'] ?? '');

$entries = [];
$total    = 0;
$pag      = paginate(0, $perPage, 1);

try {
    $db     = db();
    $where  = '1=1';
    $params = [];
    if ($filter && in_array($filter, ['pending','approved','rejected'], true)) {
        $where .= ' AND ce.status = ?'; $params[] = $filter;
    }
    if ($platformF && in_array($platformF, ['tiktok','instagram','facebook'], true)) {
        $where .= ' AND ce.platform = ?'; $params[] = $platformF;
    }

    $cnt = $db->prepare("SELECT COUNT(*) FROM contest_entries ce WHERE {$where}");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $pag   = paginate($total, $perPage, $page);

    $listParams = array_merge($params, [$perPage, $pag['offset']]);
    $stmt = $db->prepare(
        "SELECT ce.*, u.username, c.title AS contest_title
         FROM contest_entries ce
         LEFT JOIN users u ON u.id = ce.user_id
         LEFT JOIN contests c ON c.id = ce.contest_id
         WHERE {$where}
         ORDER BY ce.submitted_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute($listParams);
    $entries = $stmt->fetchAll();
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entries — Clipaza Admin</title>
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
            <li class="nav-item"><a href="entries.php" class="nav-link active"><span class="nav-icon">✂️</span> Entries</a></li>
            <li class="nav-item"><a href="payouts.php" class="nav-link"><span class="nav-icon">💸</span> Payouts</a></li>
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
            <h4 class="fw-700 mb-0">Contest Entries</h4>
            <span class="text-muted" style="font-size:0.85rem"><?= $total ?> total</span>
        </div>

        <!-- Filter tabs -->
        <form method="GET" class="d-flex gap-2 flex-wrap mb-4">
            <select name="status" class="form-control-dark" style="max-width:140px;font-size:0.85rem" onchange="this.form.submit()">
                <option value="">All Status</option>
                <?php foreach (['pending','approved','rejected'] as $s): ?>
                    <option value="<?= $s ?>" <?= $filter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="platform" class="form-control-dark" style="max-width:140px;font-size:0.85rem" onchange="this.form.submit()">
                <option value="">All Platforms</option>
                <?php foreach (['tiktok','instagram','facebook'] as $p): ?>
                    <option value="<?= $p ?>" <?= $platformF===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-accent">Filter</button>
        </form>

        <div class="table-responsive">
            <table class="table-dark-custom w-100">
                <thead>
                    <tr>
                        <th>Clipper</th>
                        <th>Contest / Platform</th>
                        <th>Verification</th>
                        <th>Stats</th>
                        <th>Clip URL</th>
                        <th>Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($entries)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No entries found.</td></tr>
                <?php else: ?>
                    <?php foreach ($entries as $e): ?>
                    <tr>
                        <td>
                            <div class="fw-600">@<?= e($e['username'] ?? 'unknown') ?></div>
                        </td>
                        <td>
                            <div style="font-size:0.82rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($e['contest_title']) ?></div>
                            <span class="badge" style="background:#1a1a1a;font-size:0.68rem"><?= e(ucfirst($e['platform'])) ?></span>
                        </td>
                        <td>
                            <div class="d-flex flex-column gap-1">
                                <span class="badge <?= $e['verified_subscribe'] ? 'badge-success' : 'badge-muted' ?>" style="font-size:0.62rem">Subs</span>
                                <span class="badge <?= $e['verified_like'] ? 'badge-success' : 'badge-muted' ?>" style="font-size:0.62rem">Like</span>
                                <span class="badge <?= $e['verified_comment'] ? 'badge-success' : 'badge-muted' ?>" style="font-size:0.62rem">Comm</span>
                            </div>
                        </td>
                        <td>
                            <div style="font-size:0.78rem">👁️ <?= number_format($e['view_count']) ?></div>
                            <div style="font-size:0.78rem">❤️ <?= number_format($e['like_count']) ?></div>
                        </td>
                        <td>
                            <a href="<?= e($e['clip_url']) ?>" target="_blank" class="text-accent" style="font-size:0.78rem;max-width:120px;display:block;overflow:hidden;text-overflow:ellipsis">Open Link</a>
                        </td>
                        <td style="font-size:0.8rem;color:#888"><?= e(timeAgo($e['submitted_at'])) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php if (!$e['disqualified']): ?>
                                    <button class="btn btn-xs dqb" style="background:rgba(220,38,38,0.1);color:#f87171;border:1px solid rgba(220,38,38,0.2)"
                                            data-id="<?= $e['id'] ?>" data-csrf="<?= e($csrf) ?>">Disqualify</button>
                                <?php else: ?>
                                    <span class="badge badge-danger">DQ'd</span>
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
                <li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="?page=<?= $i ?>&status=<?= e($filter) ?>&platform=<?= e($platformF) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul></nav>
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
document.querySelectorAll('.dqb').forEach(btn => {
    btn.addEventListener('click', async function() {
        const reason = prompt('Reason for disqualification?');
        if (reason === null) return;
        this.disabled = true;
        const r = await fetch('/admin/ajax/admin_actions.php', {
            method:'POST', body: new URLSearchParams({action:'disqualify_entry', entry_id:this.dataset.id, reason:reason, csrf_token:this.dataset.csrf})
        });
        const d = await r.json();
        if (d.success) location.reload();
        else { alert(d.message||'Error'); this.disabled=false; }
    });
});
</script>
</body></html>
