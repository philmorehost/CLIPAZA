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
    $totalContestFunded = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='debit' AND status='completed'")->fetchColumn();
    $blockedIps      = (int)$db->query("SELECT COUNT(*) FROM ip_blocks WHERE blocked_until IS NULL OR blocked_until > NOW()")->fetchColumn();
    $totalDeposits   = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='credit' AND status='completed'")->fetchColumn();
    $pendingPayouts  = (int)$db->query("SELECT COUNT(*) FROM payout_requests WHERE status='pending'")->fetchColumn();
    $pendingKyc      = (int)$db->query("SELECT COUNT(*) FROM user_profiles WHERE kyc_status='pending'")->fetchColumn();
    $pendingMovieAds = (int)$db->query("SELECT COUNT(*) FROM movie_ads WHERE status='pending_review'")->fetchColumn();
    $totalPayoutsAmt = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payout_requests WHERE status='approved'")->fetchColumn();

    // Recent transactions (last 10)
    $txStmt = $db->query(
        "SELECT t.*, u.username FROM transactions t
         LEFT JOIN users u ON u.id = t.user_id
         ORDER BY t.created_at DESC LIMIT 10"
    );
    $recentTx = $txStmt->fetchAll();

    // Recent payout requests (last 5)
    $prStmt = $db->query(
        "SELECT pr.*, u.username FROM payout_requests pr
         LEFT JOIN users u ON u.id = pr.user_id
         ORDER BY pr.created_at DESC LIMIT 5"
    );
    $recentPayouts = $prStmt->fetchAll();

    // Recent logins
    $historyStmt = $db->query(
        'SELECT lh.*, u.role FROM login_history lh
         LEFT JOIN users u ON u.username = lh.username
         ORDER BY lh.created_at DESC LIMIT 8'
    );
    $loginHistory = $historyStmt->fetchAll();

    // Recent KYC
    $kycStmt = $db->query(
        "SELECT up.*, u.username, u.email FROM user_profiles up
         LEFT JOIN users u ON u.id = up.user_id
         WHERE up.kyc_status IN ('pending','approved','rejected')
         ORDER BY up.updated_at DESC LIMIT 5"
    );
    $recentKyc = $kycStmt->fetchAll();

    // Global Leaderboard for Admin
    $adminLB = $db->query(
        "SELECT u.username, SUM(ce.view_count) AS total_views, COUNT(ce.id) AS clip_count
         FROM contest_entries ce
         INNER JOIN users u ON u.id = ce.user_id
         WHERE ce.status = 'approved' AND ce.disqualified = 0
         GROUP BY ce.user_id
         ORDER BY total_views DESC
         LIMIT 10"
    )->fetchAll();

} catch (Throwable) {
    $totalUsers = $activeContests = $blockedIps = $pendingPayouts = $pendingKyc = $pendingMovieAds = 0;
    $totalRevenue = $totalDeposits = $totalPayoutsAmt = 0.0;
    $recentTx = $loginHistory = $recentPayouts = $recentKyc = [];
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
  <script>
    (function() {
      var t = localStorage.getItem('clipaza_theme') || 'dark';
      document.documentElement.dataset.theme = t;
    })();
  </script>
</head>
<body>

<!-- Sidebar -->
<nav class="admin-sidebar">
    <?php $sn = getSetting("site_name", "Clipaza"); $sl = getSetting("site_logo", ""); if ($sl): ?><div class="sidebar-brand"><img src="<?= e($sl) ?>" alt="<?= e($sn) ?>" style="height:28px"></div><?php else: ?><div class="sidebar-brand"><?= formatSiteName($sn) ?></div><?php endif; ?>
    <div class="sidebar-nav">
        <ul class="nav flex-column">
            <li class="nav-item"><a href="index.php" class="nav-link active"><span class="nav-icon">⊞</span> Dashboard</a></li>
            <li class="nav-item"><a href="users.php" class="nav-link"><span class="nav-icon">👥</span> Users</a></li>
            <li class="nav-item"><a href="contests.php" class="nav-link"><span class="nav-icon">🏆</span> Contests</a></li>
            <li class="nav-item"><a href="featured-contests.php" class="nav-link"><span class="nav-icon">⭐</span> Featured</a></li>
            <li class="nav-item"><a href="payouts.php" class="nav-link"><span class="nav-icon">💸</span> Payouts <?php if ($pendingPayouts > 0): ?><span class="badge-accent ms-1" style="font-size:0.65rem;padding:2px 6px"><?= $pendingPayouts ?></span><?php endif; ?></a></li>
            <li class="nav-item"><a href="kyc.php" class="nav-link"><span class="nav-icon">🪪</span> KYC <?php if ($pendingKyc > 0): ?><span class="badge-warning ms-1" style="font-size:0.65rem;padding:2px 6px"><?= $pendingKyc ?></span><?php endif; ?></a></li>
            <li class="nav-item"><a href="ad-packages.php" class="nav-link"><span class="nav-icon">📦</span> Ad Packages</a></li>
            <li class="nav-item"><a href="movie-ads.php" class="nav-link"><span class="nav-icon">🎞</span> Movie Ads <?php if ($pendingMovieAds > 0): ?><span class="badge-warning ms-1" style="font-size:0.65rem;padding:2px 6px"><?= $pendingMovieAds ?></span><?php endif; ?></a></li>
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

<!-- Main -->
<main class="admin-main">
    <div class="admin-topbar">
        <div class="d-flex align-items-center gap-3">
            <button id="sidebarToggle" class="btn d-lg-none" style="color:var(--text-muted);background:var(--subtle-bg);border-radius:8px;padding:6px 10px;">☰</button>
            <button id="adminThemeToggle" class="btn-theme-toggle" title="Toggle light/dark mode" aria-label="Toggle theme" style="margin-left:4px">☀️</button>
            <h1>Dashboard</h1>
        </div>
        <div style="font-size:0.875rem;color:var(--text-muted);">
            Welcome, <strong style="color:var(--text);"><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="card-dark mb-4 p-3">
        <div class="quick-actions-row">
            <span class="quick-actions-label">Quick Actions:</span>
            <div class="quick-actions-btns">
                <a href="users.php" class="btn btn-sm btn-outline-accent">👥 Manage Users</a>
                <a href="contests.php" class="btn btn-sm btn-outline-accent">🏆 Manage Contests</a>
                <a href="../leaderboards" class="btn btn-sm btn-outline-accent">🏆 GLB</a>
                <a href="payouts.php?status=pending" class="btn btn-sm action-btn-accent">💸 Pending Payouts <?php if ($pendingPayouts): ?><span class="badge-accent ms-1" style="font-size:0.65rem"><?= $pendingPayouts ?></span><?php endif; ?></a>
                <a href="kyc.php?status=pending" class="btn btn-sm action-btn-warning">🪪 KYC Reviews <?php if ($pendingKyc): ?><span class="badge-warning ms-1" style="font-size:0.65rem"><?= $pendingKyc ?></span><?php endif; ?></a>
                <a href="security.php?tab=blocked" class="btn btn-sm action-btn-danger">🚫 Blocked IPs</a>
                <a href="settings.php?tab=payment" class="btn btn-sm action-btn-info">⚙ Payment Settings</a>
            </div>
        </div>
    </div>

    <!-- Stats Row 1 -->
    <div class="row g-3 mb-3">
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
                <div class="stat-value">₦<?= number_format($totalDeposits, 0) ?></div>
                <div class="stat-label">Total Deposits</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon">💸</div>
                <div class="stat-value">₦<?= number_format($totalPayoutsAmt, 0) ?></div>
                <div class="stat-label">Total Paid Out</div>
            </div>
        </div>
    </div>

    <!-- Stats Row 2 -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon">⏳</div>
                <div class="stat-value"><?= number_format($pendingPayouts) ?></div>
                <div class="stat-label">Pending Payouts</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon">🪪</div>
                <div class="stat-value"><?= number_format($pendingKyc) ?></div>
                <div class="stat-label">KYC Pending</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-value">₦<?= number_format($totalContestFunded, 0) ?></div>
                <div class="stat-label">Contest Funded</div>
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

    <div class="row g-3 mb-4">
        <!-- Recent Transactions -->
        <div class="col-lg-8">
            <div class="card-dark">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>💳 Recent Transactions</span>
                    <a href="payouts.php" style="font-size:0.78rem;color:var(--text-muted)">View Payouts →</a>
                </div>
                <div class="card-body p-0">
                    <div style="overflow-x:auto">
                        <table class="table-dark-custom w-100">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Description</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentTx)): ?>
                                <tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted)">No transactions yet.</td></tr>
                                <?php else: ?>
                                <?php foreach ($recentTx as $tx): ?>
                                <tr>
                                    <td><strong style="color:var(--text);font-size:0.82rem"><?= htmlspecialchars($tx['username'] ?? '—') ?></strong></td>
                                    <td>
                                        <?php
                                        $txClass = match($tx['type']) {
                                            'credit'     => 'badge-success',
                                            'debit'      => 'badge-danger',
                                            'withdrawal' => 'badge-warning',
                                            'refund'     => 'badge-info',
                                            default      => 'badge-muted',
                                        };
                                        ?>
                                        <span class="<?= $txClass ?>" style="font-size:0.72rem"><?= htmlspecialchars(ucfirst($tx['type'])) ?></span>
                                    </td>
                                    <td style="font-weight:600;font-size:0.88rem">₦<?= number_format((float)$tx['amount'], 0) ?></td>
                                    <td>
                                        <?php $sc = $tx['status']==='completed'?'badge-success':($tx['status']==='failed'?'badge-danger':'badge-muted'); ?>
                                        <span class="<?= $sc ?>" style="font-size:0.72rem"><?= htmlspecialchars(ucfirst($tx['status'])) ?></span>
                                    </td>
                                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.8rem;color:var(--text-muted)">
                                        <?= htmlspecialchars($tx['description'] ?? '') ?>
                                    </td>
                                    <td style="white-space:nowrap;font-size:0.78rem;color:var(--text-muted)"><?= htmlspecialchars(timeAgo($tx['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Payout Requests -->
        <div class="col-lg-4">
            <div class="card-dark">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>💸 Payout Requests</span>
                    <a href="payouts.php" style="font-size:0.78rem;color:var(--text-muted)">All →</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentPayouts)): ?>
                    <div class="text-center py-4" style="color:var(--text-muted);font-size:0.85rem">No payout requests.</div>
                    <?php else: ?>
                    <?php foreach ($recentPayouts as $pr): ?>
                    <?php
                        $prClass = match($pr['status']) {
                            'pending'   => 'badge-warning',
                            'approved'  => 'badge-success',
                            'rejected'  => 'badge-danger',
                            'cancelled' => 'badge-muted',
                            'on_hold'   => 'badge-info',
                            default     => 'badge-muted',
                        };
                    ?>
                    <div class="d-flex align-items-center justify-content-between px-3 py-2" style="border-bottom:1px solid var(--border)">
                        <div>
                            <div style="font-size:0.83rem;font-weight:600;color:var(--text)"><?= htmlspecialchars($pr['username'] ?? '—') ?></div>
                            <div style="font-size:0.75rem;color:var(--text-muted)">₦<?= number_format((float)$pr['amount'], 0) ?></div>
                        </div>
                        <div class="text-end">
                            <span class="<?= $prClass ?>" style="font-size:0.7rem"><?= htmlspecialchars(ucfirst($pr['status'])) ?></span>
                            <div style="font-size:0.72rem;color:var(--text-muted);margin-top:2px"><?= htmlspecialchars(timeAgo($pr['created_at'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- KYC Status -->
        <div class="col-md-6">
            <div class="card-dark">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>🪪 Recent KYC Submissions</span>
                    <a href="kyc.php" style="font-size:0.78rem;color:var(--text-muted)">All →</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentKyc)): ?>
                    <div class="text-center py-4" style="color:var(--text-muted);font-size:0.85rem">No KYC submissions.</div>
                    <?php else: ?>
                    <?php foreach ($recentKyc as $kyc): ?>
                    <?php
                        $kClass = match($kyc['kyc_status']) {
                            'pending'  => 'badge-warning',
                            'approved' => 'badge-success',
                            'rejected' => 'badge-danger',
                            default    => 'badge-muted',
                        };
                    ?>
                    <div class="d-flex align-items-center justify-content-between px-3 py-2" style="border-bottom:1px solid var(--border)">
                        <div>
                            <div style="font-size:0.83rem;font-weight:600;color:var(--text)"><?= htmlspecialchars($kyc['username'] ?? '—') ?></div>
                            <div style="font-size:0.75rem;color:var(--text-muted)"><?= htmlspecialchars($kyc['kyc_id_type'] ?? 'N/A') ?></div>
                        </div>
                        <span class="<?= $kClass ?>" style="font-size:0.7rem"><?= htmlspecialchars(ucfirst($kyc['kyc_status'])) ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="p-3">
                        <a href="kyc.php?status=pending" class="btn btn-outline-accent w-100" style="font-size:0.82rem">Review KYC</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Logins -->
        <div class="col-md-6">
            <div class="card-dark h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>🔐 Recent Logins</span>
                    <a href="security.php?tab=history" style="font-size:0.78rem;color:var(--text-muted)">All →</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($loginHistory)): ?>
                    <div class="text-center py-4" style="color:var(--text-muted);font-size:0.85rem">No login history.</div>
                    <?php else: ?>
                    <?php foreach ($loginHistory as $entry): ?>
                    <?php
                        $actionClass = match($entry['action']) {
                            'login_success' => 'badge-success',
                            'failed_login'  => 'badge-danger',
                            'logout'        => 'badge-muted',
                            default         => 'badge-info',
                        };
                    ?>
                    <div class="d-flex align-items-center justify-content-between px-3 py-2" style="border-bottom:1px solid var(--border)">
                        <div>
                            <div style="font-size:0.83rem;font-weight:600;color:var(--text)"><?= htmlspecialchars($entry['username']) ?></div>
                            <div style="font-size:0.72rem;color:var(--text-muted)"><code><?= htmlspecialchars($entry['ip_address']) ?></code></div>
                        </div>
                        <div class="text-end">
                            <span class="<?= $actionClass ?>" style="font-size:0.7rem"><?= htmlspecialchars($entry['action']) ?></span>
                            <div style="font-size:0.72rem;color:var(--text-muted);margin-top:2px"><?= htmlspecialchars(timeAgo($entry['created_at'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Global Leaderboard for Admin -->
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card-dark">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>🏆 Global Leaderboard</span>
                    <a href="../leaderboards" target="_blank" style="font-size:0.78rem;color:var(--text-muted)">Public View ↗</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table-dark-custom w-100">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Clipper</th>
                                    <th>Clips</th>
                                    <th>Total Views</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($adminLB)): ?>
                                    <tr><td colspan="5" class="text-center py-4 text-muted">No data.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($adminLB as $idx => $row): ?>
                                        <tr>
                                            <td>#<?= $idx+1 ?></td>
                                            <td><strong style="color:var(--text)">@<?= htmlspecialchars($row['username']) ?></strong></td>
                                            <td><?= number_format((int)$row['clip_count']) ?></td>
                                            <td style="color:var(--accent); font-weight:700"><?= number_format((int)$row['total_views']) ?></td>
                                            <td>
                                                <a href="users.php?q=<?= urlencode($row['username']) ?>" class="btn btn-xs btn-outline-accent">Manage</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script src="assets/js/theme_sync.js"></script>
</body>
</html>
