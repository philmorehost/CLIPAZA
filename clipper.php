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

$targetUsername = sanitizeInput($_GET['username'] ?? '');
if (!$targetUsername) redirect('/leaderboards');

$clipper = null;
$profile = null;
$stats   = [];
$topClips = [];

try {
    $db = db();
    $stmt = $db->prepare("SELECT id, username, created_at FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$targetUsername]);
    $clipper = $stmt->fetch();

    if (!$clipper) redirect('/leaderboards');

    $stmt = $db->prepare("SELECT * FROM user_profiles WHERE user_id = ? LIMIT 1");
    $stmt->execute([$clipper['id']]);
    $profile = $stmt->fetch();

    // Aggregate Stats
    $stmt = $db->prepare(
        "SELECT COUNT(id) AS total_clips,
                SUM(view_count) AS total_views,
                SUM(like_count) AS total_likes,
                SUM(comment_count) AS total_comments
         FROM contest_entries
         WHERE user_id = ? AND status = 'approved' AND disqualified = 0"
    );
    $stmt->execute([$clipper['id']]);
    $stats = $stmt->fetch();

    // Top 10 Clips
    $stmt = $db->prepare(
        "SELECT ce.*, c.title AS contest_title, c.youtube_thumbnail
         FROM contest_entries ce
         INNER JOIN contests c ON c.id = ce.contest_id
         WHERE ce.user_id = ? AND ce.status = 'approved' AND ce.disqualified = 0
         ORDER BY ce.view_count DESC
         LIMIT 10"
    );
    $stmt->execute([$clipper['id']]);
    $topClips = $stmt->fetchAll();

} catch (Throwable $e) {
    redirect('/leaderboards');
}

$dn  = $profile['display_name'] ?? $clipper['username'];
$ini = strtoupper(substr($dn, 0, 1));

renderHead('Clipper: ' . $targetUsername);
renderNav($isLoggedIn, ['username' => $username], $userMode);
?>

<div class="public-page py-5">
    <div class="container">
        <div class="row g-4">
            <!-- Sidebar: Profile Card -->
            <div class="col-lg-4">
                <div class="card-dark p-4 text-center">
                    <div class="avatar-circle avatar-circle--lg mx-auto mb-3"><?= e($ini) ?></div>
                    <h4 class="fw-900 mb-0"><?= e($dn) ?></h4>
                    <p class="text-accent fw-600">@<?= e($clipper['username']) ?></p>

                    <?php if (!empty($profile['bio'])): ?>
                        <p class="text-muted mt-3" style="font-size:0.9rem"><?= nl2br(e($profile['bio'])) ?></p>
                    <?php endif; ?>

                    <hr class="divider-dark my-4">

                    <div class="row g-3">
                        <div class="col-6">
                            <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em" class="text-muted">Total Clips</div>
                            <div class="fw-700" style="font-size:1.2rem"><?= number_format((int)($stats['total_clips'] ?? 0)) ?></div>
                        </div>
                        <div class="col-6">
                            <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em" class="text-muted">Total Views</div>
                            <div class="fw-700 text-accent" style="font-size:1.2rem"><?= number_format((int)($stats['total_views'] ?? 0)) ?></div>
                        </div>
                    </div>

                    <div class="mt-4 d-flex justify-content-center gap-3">
                        <?php if (!empty($profile['youtube_handle'])): ?>
                            <span title="YouTube" style="color:#ff0000; font-size:1.2rem">▶</span>
                        <?php endif; ?>
                        <?php if (!empty($profile['tiktok_handle'])): ?>
                            <span title="TikTok"><?= getPlatformIcon('tiktok', '1.2rem') ?></span>
                        <?php endif; ?>
                        <?php if (!empty($profile['instagram_handle'])): ?>
                            <span title="Instagram"><?= getPlatformIcon('instagram', '1.2rem') ?></span>
                        <?php endif; ?>
                    </div>

                    <p class="text-muted mt-4" style="font-size:0.75rem">Joined <?= formatDate($clipper['created_at'], 'M Y') ?></p>
                </div>
            </div>

            <!-- Main Content: Top Clips -->
            <div class="col-lg-8">
                <div class="card-dark">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h5 class="mb-0 fw-700">🚀 Top Performing Clips</h5>
                        <span class="text-muted" style="font-size:0.8rem">Ranked by Views</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($topClips)): ?>
                            <div class="p-5 text-center text-muted">No clips submitted yet.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table-dark-custom w-100">
                                    <thead>
                                        <tr>
                                            <th>Contest</th>
                                            <th>Platform</th>
                                            <th>Views</th>
                                            <th>Engagement</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($topClips as $clip): ?>
                                            <tr>
                                                <td style="max-width:200px">
                                                    <div class="d-flex align-items-center gap-2">
                                                        <?php if ($clip['youtube_thumbnail']): ?>
                                                            <img src="<?= e($clip['youtube_thumbnail']) ?>" alt="" style="width:40px; height:30px; object-fit:cover" class="rounded">
                                                        <?php endif; ?>
                                                        <span class="text-truncate fw-600" style="font-size:0.85rem"><?= e($clip['contest_title']) ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span style="font-size:0.9rem"><?= getPlatformIcon($clip['platform'], '1.2rem') ?></span>
                                                </td>
                                                <td class="text-accent fw-700"><?= number_format((int)$clip['view_count']) ?></td>
                                                <td>
                                                    <div style="font-size:0.75rem" class="text-muted">
                                                        <div>❤️ <?= number_format((int)$clip['like_count']) ?></div>
                                                        <div>💬 <?= number_format((int)$clip['comment_count']) ?></div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <a href="<?= e($clip['clip_url']) ?>" target="_blank" class="btn btn-xs btn-outline-accent">View Clip</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
