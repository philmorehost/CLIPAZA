<?php
declare(strict_types=1);

$root = __DIR__;
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$isLoggedIn = !empty($_SESSION['user_id']);
$userMode   = getUserMode();
$username   = $_SESSION['username'] ?? '';

autoArchiveContests();

$db = db();

// 1. Global Top Performers
$globalLB = [];
try {
    $stmt = $db->query(
        "SELECT u.username, COUNT(ce.id) AS clip_count,
                SUM(ce.view_count) AS total_views,
                SUM(ce.like_count) AS total_likes,
                SUM(ce.comment_count) AS total_comments
         FROM contest_entries ce
         INNER JOIN users u ON u.id = ce.user_id
         WHERE ce.status = 'approved' AND ce.disqualified = 0
         GROUP BY ce.user_id
         ORDER BY total_views DESC
         LIMIT 10"
    );
    $globalLB = $stmt->fetchAll();
} catch (Throwable $e) {}

// 2. Per-Platform Leaders
$platformLeaders = [];
foreach (['tiktok', 'instagram', 'facebook'] as $p) {
    try {
        $stmt = $db->prepare(
            "SELECT u.username, SUM(ce.view_count) AS total_views,
                    SUM(ce.like_count) AS total_likes,
                    SUM(ce.comment_count) AS total_comments
             FROM contest_entries ce
             INNER JOIN users u ON u.id = ce.user_id
             WHERE ce.platform = ? AND ce.status = 'approved' AND ce.disqualified = 0
             GROUP BY ce.user_id
             ORDER BY total_views DESC
             LIMIT 5"
        );
        $stmt->execute([$p]);
        $platformLeaders[$p] = $stmt->fetchAll();
    } catch (Throwable $e) {}
}

// 3. Current Contest Leaders
$activeContestsLeaders = [];
try {
    $stmt = $db->query(
        "SELECT c.id, c.title, c.youtube_thumbnail
         FROM contests c
         WHERE c.status = 'active'
         ORDER BY c.created_at DESC"
    );
    $contests = $stmt->fetchAll();

    foreach ($contests as $c) {
        $leaders = [];
        foreach (['tiktok', 'instagram', 'facebook'] as $p) {
            $lStmt = $db->prepare(
                "SELECT u.username, ce.view_count, ce.like_count, ce.comment_count
                 FROM contest_entries ce
                 INNER JOIN users u ON u.id = ce.user_id
                 WHERE ce.contest_id = ? AND ce.platform = ? AND ce.status = 'approved' AND ce.disqualified = 0
                 ORDER BY ce.view_count DESC, ce.like_count DESC, ce.comment_count DESC
                 LIMIT 1"
            );
            $lStmt->execute([$c['id'], $p]);
            $lead = $lStmt->fetch();
            if ($lead) {
                $lead['platform'] = $p;
                $leaders[] = $lead;
            }
        }
        if (!empty($leaders)) {
            $c['leaders'] = $leaders;
            $activeContestsLeaders[] = $c;
        }
    }
} catch (Throwable $e) {}

renderHead('Leaderboards');
renderNav($isLoggedIn, ['username' => $username], $userMode);
?>

<div class="public-page py-5">
    <div class="container">
        <div class="text-center mb-5">
            <h1 class="fw-900 mb-2">Platform <span class="text-accent">Leaderboards</span></h1>
            <p class="text-muted">Real-time rankings of our top performing clippers.</p>
        </div>

        <!-- Global Rankings -->
        <div class="card-dark mb-5">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 class="mb-0 fw-700">🏆 All-Time Top Clippers</h5>
                <span class="badge-accent">Global</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table-dark-custom w-100">
                        <thead>
                            <tr>
                                <th style="width: 60px">Rank</th>
                                <th>Clipper</th>
                                <th>Clips</th>
                                <th>Total Views</th>
                                <th>Likes</th>
                                <th>Comments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($globalLB)): ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted">No data yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($globalLB as $idx => $row): ?>
                                    <tr>
                                        <td>
                                            <?php
                                            $rank = $idx + 1;
                                            if ($rank === 1) echo '🥇';
                                            elseif ($rank === 2) echo '🥈';
                                            elseif ($rank === 3) echo '🥉';
                                            else echo '#'.$rank;
                                            ?>
                                        </td>
                                        <td><strong style="color:var(--text)">@<?= e($row['username']) ?></strong></td>
                                        <td><?= number_format((int)$row['clip_count']) ?></td>
                                        <td style="color:var(--accent); font-weight:700"><?= number_format((int)$row['total_views']) ?></td>
                                        <td><?= number_format((int)$row['total_likes']) ?></td>
                                        <td><?= number_format((int)$row['total_comments']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-5">
            <?php foreach (['tiktok' => '🎵 TikTok', 'instagram' => '📸 Instagram', 'facebook' => '📘 Facebook'] as $p => $label): ?>
                <div class="col-lg-4">
                    <div class="card-dark h-100">
                        <div class="card-header"><h6 class="mb-0 fw-700"><?= $label ?> Leaders</h6></div>
                        <div class="card-body p-0">
                            <?php if (empty($platformLeaders[$p])): ?>
                                <div class="p-4 text-center text-muted">No entries yet.</div>
                            <?php else: ?>
                                <?php foreach ($platformLeaders[$p] as $idx => $row): ?>
                                    <div class="d-flex align-items-center justify-content-between p-3 border-bottom border-secondary">
                                        <div class="d-flex align-items-center gap-3">
                                            <span class="fw-700 text-muted" style="width:20px"><?= $idx+1 ?>.</span>
                                            <span style="font-size:0.9rem">@<?= e($row['username']) ?></span>
                                        </div>
                                        <div class="text-end">
                                            <div style="font-size:0.85rem; color:var(--accent); font-weight:700"><?= number_format((int)$row['total_views']) ?></div>
                                            <div style="font-size:0.7rem" class="text-muted">views</div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="text-center mb-4">
            <h2 class="fw-900">Current Contest <span class="text-accent">Leaders</span></h2>
            <p class="text-muted">Who's currently leading the active contests.</p>
        </div>

        <div class="row g-4">
            <?php if (empty($activeContestsLeaders)): ?>
                <div class="col-12 text-center py-5">
                    <div class="card-dark p-5">
                        <h5 class="text-muted">No active contests with entries right now.</h5>
                        <a href="/contests" class="btn btn-accent mt-3">Join a Contest</a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($activeContestsLeaders as $cl): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card-dark h-100">
                            <?php if ($cl['youtube_thumbnail']): ?>
                                <img src="<?= e($cl['youtube_thumbnail']) ?>" alt="" style="width:100%; height:150px; object-fit:cover" class="rounded-top">
                            <?php endif; ?>
                            <div class="p-3">
                                <h6 class="fw-700 mb-3 text-truncate"><?= e($cl['title']) ?></h6>
                                <?php foreach ($cl['leaders'] as $l): ?>
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <span><?= match($l['platform']){'tiktok'=>'🎵','instagram'=>'📸','facebook'=>'📘',default=>'🎬'} ?></span>
                                            <span style="font-size:0.85rem">@<?= e($l['username']) ?></span>
                                        </div>
                                        <div class="text-end">
                                            <span style="font-size:0.85rem; color:var(--accent); font-weight:700"><?= number_format((int)$l['view_count']) ?></span>
                                            <span style="font-size:0.75rem" class="text-muted">views</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <a href="/contest?id=<?= $cl['id'] ?>" class="btn btn-xs btn-outline-accent w-100 mt-3">View Full Leaderboard</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
