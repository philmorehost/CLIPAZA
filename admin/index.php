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

// Stats
try {
    $db = db();
    $totalUsers      = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $activeContests  = (int)$db->query("SELECT COUNT(*) FROM contests WHERE status = 'active'")->fetchColumn();
    $totalRevenue    = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='credit' AND status='completed'")->fetchColumn();
    $blockedIps      = (int)$db->query("SELECT COUNT(*) FROM ip_blocks WHERE blocked_until IS NULL OR blocked_until > NOW()")->fetchColumn();

    $historyStmt = $db->query(
        'SELECT lh.*, u.role FROM login_history lh
         LEFT JOIN users u ON u.username = lh.username
         ORDER BY lh.created_at DESC LIMIT 15'
    );
    $loginHistory = $historyStmt->fetchAll();
} catch (Throwable) {
    $totalUsers = $activeContests = $blockedIps = 0;
    $totalRevenue = 0.0;
    $loginHistory = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Clipaza Admin</title>
    <meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- Sidebar -->
<nav class="admin-sidebar">
    <div class="sidebar-brand">Clipa<span>za</span></div>
    <div class="sidebar-nav">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a href="/" class="nav-link active">
                    <span class="nav-icon">⊞</span> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="security.php" class="nav-link">
                    <span class="nav-icon">🛡</span> Security
                </a>
            </li>
            <li class="nav-item">
                <a href="users.php" class="nav-link">
                    <span class="nav-icon">👥</span> Users
                </a>
            </li>
            <li class="nav-item">
                <a href="/contests.php" class="nav-link">
                    <span class="nav-icon">🏆</span> Contests
                </a>
            </li>
            <li class="nav-item">
                <a href="settings.php" class="nav-link">
                    <span class="nav-icon">⚙</span> Settings
                </a>
            </li>
        </ul>
        <hr class="divider-dark mx-3">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a href="logout.php" class="nav-link" style="color:var(--danger);">
                    <span class="nav-icon">⇤</span> Logout
                </a>
            </li>
        </ul>
    </div>
</nav>

<!-- Main -->
<main class="admin-main">
    <div class="admin-topbar">
        <div class="d-flex align-items-center gap-3">
            <button id="sidebarToggle" class="btn d-lg-none" style="color:#888;background:rgba(255,255,255,0.05);border-radius:8px;padding:6px 10px;">☰</button>
            <h1>Dashboard</h1>
        </div>
        <div style="font-size:0.875rem;color:#888;">
            Welcome, <strong style="color:#fff;"><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-value"><?= number_format($totalUsers) ?></div>
                <div class="stat-label">Total Users</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon">🏆</div>
                <div class="stat-value"><?= number_format($activeContests) ?></div>
                <div class="stat-label">Active Contests</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon">💰</div>
                <div class="stat-value">$<?= number_format($totalRevenue, 2) ?></div>
                <div class="stat-label">Total Revenue</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon">🚫</div>
                <div class="stat-value"><?= number_format($blockedIps) ?></div>
                <div class="stat-label">Blocked IPs</div>
            </div>
        </div>
    </div>

    <!-- Recent Login History -->
    <div class="card-dark mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span>Recent Login Activity</span>
            <a href="security.php?tab=history" style="font-size:0.8rem;color:#888;">View All →</a>
        </div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
                <table class="table-dark-custom w-100">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>IP Address</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($loginHistory)): ?>
                        <tr><td colspan="5" class="text-center py-4" style="color:#888;">No login history yet.</td></tr>
                        <?php else: ?>
                        <?php foreach ($loginHistory as $entry): ?>
                        <tr>
                            <td>
                                <strong style="color:#fff;"><?= htmlspecialchars($entry['username']) ?></strong>
                                <?php if (($entry['role'] ?? '') === 'admin'): ?>
                                <span class="badge-accent ms-1" style="font-size:0.65rem;">admin</span>
                                <?php endif; ?>
                            </td>
                            <td><code style="color:#ccc;font-size:0.8rem;"><?= htmlspecialchars($entry['ip_address']) ?></code></td>
                            <td>
                                <?php
                                $actionClass = match($entry['action']) {
                                    'login_success' => 'badge-success',
                                    'failed_login'  => 'badge-danger',
                                    'logout'        => 'badge-muted',
                                    default         => 'badge-info',
                                };
                                ?>
                                <span class="<?= $actionClass ?>"><?= htmlspecialchars($entry['action']) ?></span>
                            </td>
                            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= htmlspecialchars($entry['details'] ?? '') ?>
                            </td>
                            <td style="white-space:nowrap;"><?= htmlspecialchars(timeAgo($entry['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Security Status -->
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card-dark">
                <div class="card-header">Security Status</div>
                <div class="card-body">
                    <?php
                    $ipEnabled   = getSecuritySetting('ip_protection_enabled', '1') === '1';
                    $userEnabled = getSecuritySetting('username_protection_enabled', '1') === '1';
                    ?>
                    <div class="d-flex align-items-center justify-content-between py-2 border-bottom" style="border-color:#222!important;">
                        <span style="color:#ccc;font-size:0.875rem;">IP Brute Force Protection</span>
                        <span class="<?= $ipEnabled ? 'badge-success' : 'badge-danger' ?>"><?= $ipEnabled ? 'Active' : 'Disabled' ?></span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between py-2 border-bottom" style="border-color:#222!important;">
                        <span style="color:#ccc;font-size:0.875rem;">Username Protection</span>
                        <span class="<?= $userEnabled ? 'badge-success' : 'badge-danger' ?>"><?= $userEnabled ? 'Active' : 'Disabled' ?></span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between py-2">
                        <span style="color:#ccc;font-size:0.875rem;">Blocked IPs</span>
                        <span class="<?= $blockedIps > 0 ? 'badge-warning' : 'badge-success' ?>"><?= $blockedIps ?></span>
                    </div>
                    <div class="mt-3">
                        <a href="security.php" class="btn btn-outline-accent" style="font-size:0.8rem;padding:8px 16px;">Manage Security →</a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card-dark">
                <div class="card-header">Quick Actions</div>
                <div class="card-body">
                    <div class="d-flex flex-column gap-2">
                        <a href="security.php?tab=blocked" class="btn btn-outline-accent" style="font-size:0.875rem;text-align:left;">🚫 View Blocked IPs</a>
                        <a href="security.php?tab=locks" class="btn btn-outline-accent" style="font-size:0.875rem;text-align:left;">🔒 View Locked Accounts</a>
                        <a href="security.php?tab=countries" class="btn btn-outline-accent" style="font-size:0.875rem;text-align:left;">🌍 Country Rules</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
</body>
</html>
