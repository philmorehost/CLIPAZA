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

$contestId = (int)($_GET['id'] ?? 0);
if ($contestId <= 0) redirect('contests.php');

$db = db();
$stmt = $db->prepare("SELECT c.*, u.username AS creator_name FROM contests c LEFT JOIN users u ON u.id = c.creator_id WHERE c.id = ?");
$stmt->execute([$contestId]);
$contest = $stmt->fetch();

if (!$contest) redirect('contests.php');

// Fetch full leaderboard analytics for all entries in this contest
$stmt = $db->prepare(
    "SELECT ce.*, u.username, u.email
     FROM contest_entries ce
     INNER JOIN users u ON u.id = ce.user_id
     WHERE ce.contest_id = ?
     ORDER BY ce.view_count DESC, ce.like_count DESC, ce.comment_count DESC"
);
$stmt->execute([$contestId]);
$entries = $stmt->fetchAll();

// Platform summary
$stmt = $db->prepare(
    "SELECT platform, COUNT(*) as count, SUM(view_count) as total_views, SUM(like_count) as total_likes, SUM(comment_count) as total_comments
     FROM contest_entries
     WHERE contest_id = ?
     GROUP BY platform"
);
$stmt->execute([$contestId]);
$platformSummary = $stmt->fetchAll();

$csrf = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contest Analytics — <?= e($contest['title']) ?></title>
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
    <div class="sidebar-brand">Clipa<span>za</span></div>
    <div class="sidebar-nav">
        <ul class="nav flex-column">
            <li class="nav-item"><a href="index.php" class="nav-link"><span class="nav-icon">⊞</span> Dashboard</a></li>
            <li class="nav-item"><a href="users.php" class="nav-link"><span class="nav-icon">👥</span> Users</a></li>
            <li class="nav-item"><a href="contests.php" class="nav-link active"><span class="nav-icon">🏆</span> Contests</a></li>
            <li class="nav-item"><a href="featured-contests.php" class="nav-link"><span class="nav-icon">⭐</span> Featured</a></li>
            <li class="nav-item"><a href="settings.php" class="nav-link"><span class="nav-icon">⚙</span> Settings</a></li>
        </ul>
    </div>
</nav>
<main class="admin-main">
    <div class="admin-topbar">
        <div class="d-flex align-items-center gap-3">
            <a href="contests.php" class="btn btn-sm btn-outline-secondary">← Back</a>
            <h4 class="mb-0">Analytics: <?= e($contest['title']) ?></h4>
        </div>
    </div>
    <div class="p-4">
        <!-- Platform Summary Cards -->
        <div class="row g-3 mb-4">
            <?php foreach ($platformSummary as $ps): ?>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label"><?= ucfirst(e($ps['platform'])) ?> Summary</div>
                    <div class="stat-value" style="font-size:1.2rem">
                        <?= number_format((int)$ps['total_views']) ?> Views<br>
                        <small style="font-size:0.8rem;opacity:0.7"><?= number_format((int)$ps['total_likes']) ?> Likes • <?= number_format((int)$ps['total_comments']) ?> Comments</small>
                    </div>
                    <div class="stat-label"><?= (int)$ps['count'] ?> Entries</div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="card-dark">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Full Leaderboard Analytics</h5>
                <span class="badge-accent"><?= count($entries) ?> total entries</span>
            </div>
            <div class="table-responsive">
                <table class="table-dark-custom w-100">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>User</th>
                            <th>Platform</th>
                            <th>Views</th>
                            <th>Likes</th>
                            <th>Comments</th>
                            <th>Bot Score</th>
                            <th>Status</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($entries)): ?>
                            <tr><td colspan="9" class="text-center py-4 text-muted">No entries found for this contest.</td></tr>
                        <?php else: ?>
                            <?php foreach ($entries as $idx => $entry): ?>
                            <tr>
                                <td>#<?= $idx + 1 ?></td>
                                <td>
                                    <div class="fw-600"><?= e($entry['username']) ?></div>
                                    <div style="font-size:0.75rem;color:#888"><?= e($entry['email']) ?></div>
                                </td>
                                <td><?= ucfirst(e($entry['platform'])) ?></td>
                                <td class="fw-600"><?= number_format((int)$entry['view_count']) ?></td>
                                <td><?= number_format((int)$entry['like_count']) ?></td>
                                <td><?= number_format((int)$entry['comment_count']) ?></td>
                                <td>
                                    <?php $bc = $entry['bot_score'] >= 50 ? 'text-danger' : ($entry['bot_score'] >= 20 ? 'text-warning' : 'text-success'); ?>
                                    <span class="<?= $bc ?>"><?= (int)$entry['bot_score'] ?></span>
                                </td>
                                <td>
                                    <span class="badge <?= $entry['status'] === 'approved' ? 'badge-success' : ($entry['status'] === 'rejected' ? 'badge-danger' : 'badge-warning') ?>">
                                        <?= ucfirst(e($entry['status'])) ?>
                                    </span>
                                </td>
                                <td style="font-size:0.8rem;color:#888"><?= e(timeAgo($entry['submitted_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>
<script src="assets/js/theme_sync.js"></script>
</body>
</html>
