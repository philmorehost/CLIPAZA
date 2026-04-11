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

// Filters
$filter = sanitizeInput($_GET['platform'] ?? 'all');
$sort   = sanitizeInput($_GET['sort'] ?? 'latest');

$contests     = [];
$totalContests = 0;

try {
    $db = db();
    $where = "c.status = 'active' AND (c.end_date IS NULL OR c.end_date > NOW())";
    $params = [];

    if (in_array($filter, ['tiktok', 'instagram', 'facebook'], true)) {
        $where .= ' AND EXISTS (SELECT 1 FROM contest_platforms cp WHERE cp.contest_id = c.id AND cp.platform = ?)';
        $params[] = $filter;
    }

    $orderBy = match($sort) {
        'popular'    => 'entry_count DESC',
        'ending_soon'=> 'c.end_date ASC',
        default      => 'c.created_at DESC',
    };

    $stmt = $db->prepare(
        "SELECT c.*,
                u.username AS creator_username,
                (SELECT COUNT(*) FROM contest_entries WHERE contest_id = c.id AND disqualified = 0) AS entry_count,
                GROUP_CONCAT(DISTINCT cp.platform ORDER BY cp.platform SEPARATOR ',') AS platforms
         FROM contests c
         LEFT JOIN users u ON u.id = c.creator_id
         LEFT JOIN contest_platforms cp ON cp.contest_id = c.id
         WHERE {$where}
         GROUP BY c.id
         ORDER BY {$orderBy}
         LIMIT 50"
    );
    $stmt->execute($params);
    $contests = $stmt->fetchAll();
    $totalContests = count($contests);
} catch (Throwable) {}

renderHead('Browse Contests');
renderNav($isLoggedIn, ['username' => $username], $userMode);
?>

<div class="public-page">
  <div class="container py-5">
    <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3 mb-4">
      <div>
        <h2 class="fw-900 mb-1" style="letter-spacing:-0.5px">Browse Contests</h2>
        <p class="text-muted mb-0" style="font-size:0.9rem"><?= $totalContests ?> active contest<?= $totalContests !== 1 ? 's' : '' ?></p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <select class="form-control-dark" id="sortSelect" style="max-width:160px;font-size:0.85rem" onchange="applyFilters()">
          <option value="latest"      <?= $sort==='latest'     ?'selected':'' ?>>Latest</option>
          <option value="popular"     <?= $sort==='popular'    ?'selected':'' ?>>Most Popular</option>
          <option value="ending_soon" <?= $sort==='ending_soon'?'selected':'' ?>>Ending Soon</option>
        </select>
      </div>
    </div>

    <!-- Platform filter tabs -->
    <div class="d-flex gap-2 mb-4 flex-wrap">
      <?php foreach (['all' => 'All', 'tiktok' => '🎵 TikTok', 'instagram' => '📸 Instagram', 'facebook' => '📘 Facebook'] as $val => $label): ?>
        <button class="btn btn-sm <?= $filter === $val ? 'btn-accent' : 'btn-outline-accent' ?>"
                onclick="setFilter('<?= $val ?>')"><?= $label ?></button>
      <?php endforeach; ?>
    </div>

    <?php if (empty($contests)): ?>
      <div class="text-center py-5">
        <div style="font-size:3rem;margin-bottom:16px">🎬</div>
        <h5 class="fw-700 mb-2">No contests found</h5>
        <p class="text-muted" style="font-size:0.9rem">Check back soon or try a different filter.</p>
        <?php if ($isLoggedIn && $userMode === 'creator'): ?>
          <a href="/create-contest" class="btn btn-accent mt-2">Create First Contest</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="row g-4">
        <?php foreach ($contests as $c): ?>
          <?php
            $platformList = array_filter(explode(',', $c['platforms'] ?? ''));
            $timeLeft     = '';
            $isExpiringSoon = false;
            if (!empty($c['end_date'])) {
                $secs = strtotime($c['end_date']) - time();
                if ($secs > 0) {
                    $days  = floor($secs / 86400);
                    $hours = floor(($secs % 86400) / 3600);
                    if ($days > 0)       $timeLeft = $days . 'd ' . $hours . 'h left';
                    elseif ($hours > 0)  $timeLeft = $hours . 'h left';
                    else                 $timeLeft = 'Ending soon';
                    $isExpiringSoon = $secs < 86400 * 2;
                } else {
                    $timeLeft = 'Expired';
                }
            }
            $platformIcons = '';
            foreach ($platformList as $p) {
                $platformIcons .= match(trim($p)) {
                    'tiktok'    => '<span class="platform-icon" title="TikTok">🎵</span>',
                    'instagram' => '<span class="platform-icon" title="Instagram">📸</span>',
                    'facebook'  => '<span class="platform-icon" title="Facebook">📘</span>',
                    default     => '',
                };
            }
          ?>
          <div class="col-12 col-md-6 col-lg-4">
            <div class="contest-card card-dark">
              <?php if (!empty($c['youtube_thumbnail'])): ?>
                <div class="contest-thumb">
                  <img src="<?= e($c['youtube_thumbnail']) ?>" alt="<?= e($c['title']) ?>">
                  <div class="contest-badge-prize pulse-accent">₦<?= number_format((float)$c['prize_pool'], 0) ?></div>
                </div>
              <?php else: ?>
                <div class="contest-thumb contest-thumb--placeholder">
                  <span style="font-size:2.5rem">🎬</span>
                  <div class="contest-badge-prize">₦<?= number_format((float)$c['prize_pool'], 0) ?></div>
                </div>
              <?php endif; ?>
              <div class="p-3">
                <div class="d-flex align-items-start gap-2 mb-2">
                  <h6 class="fw-700 mb-0 flex-grow-1" style="font-size:0.9rem;line-height:1.4"><?= e($c['title']) ?></h6>
                </div>
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                  <?= $platformIcons ?>
                  <?php if ($timeLeft): ?>
                    <span class="countdown-timer <?= $isExpiringSoon ? 'countdown-urgent' : '' ?>" style="font-size:0.75rem"><?= e($timeLeft) ?></span>
                  <?php endif; ?>
                </div>
                <div class="d-flex align-items-center justify-content-between">
                  <span class="text-muted" style="font-size:0.78rem"><?= (int)$c['entry_count'] ?> participant<?= (int)$c['entry_count'] !== 1 ? 's' : '' ?></span>
                  <a href="/contest?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-accent">View Contest</a>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
function setFilter(platform) {
  const url = new URL(window.location.href);
  url.searchParams.set('platform', platform);
  window.location.href = url.toString();
}
function applyFilters() {
  const url = new URL(window.location.href);
  url.searchParams.set('sort', document.getElementById('sortSelect').value);
  window.location.href = url.toString();
}
</script>

<?php renderFooter(); ?>
