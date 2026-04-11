<?php
declare(strict_types=1);

$root = __DIR__;
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireUser();

$userId   = (int)$_SESSION['user_id'];
$mode     = getUserMode();
$username = $_SESSION['username'] ?? '';

// Load profile
$profile = [];
try {
    $db   = db();
    $stmt = $db->prepare('SELECT * FROM user_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch() ?: [];
} catch (Throwable) {}

$errorMsg   = $_GET['error'] ?? '';
$successMsg = $_GET['success'] ?? '';

// --- CREATOR stats ---
$activeContests = 0;
$totalSpent     = '0.00';
$totalEntries   = 0;
$recentContests = [];

// --- CLIPPER stats ---
$activeSubmissions = 0;
$totalEarned       = '0.00';
$myEntries         = [];

if ($mode === 'creator') {
    try {
        $db = db();
        $stmt = $db->prepare("SELECT COUNT(*) FROM contests WHERE creator_id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $activeContests = (int)$stmt->fetchColumn();

        $totalSpent = (string)($profile['total_spent'] ?? '0.00');

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM contest_entries ce
             INNER JOIN contests c ON c.id = ce.contest_id
             WHERE c.creator_id = ?"
        );
        $stmt->execute([$userId]);
        $totalEntries = (int)$stmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT c.*, 
                    (SELECT COUNT(*) FROM contest_entries WHERE contest_id = c.id) AS entry_count
             FROM contests c WHERE c.creator_id = ? ORDER BY c.created_at DESC LIMIT 5"
        );
        $stmt->execute([$userId]);
        $recentContests = $stmt->fetchAll();
    } catch (Throwable) {}
} else {
    try {
        $db = db();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM contest_entries ce
             INNER JOIN contests c ON c.id = ce.contest_id AND c.status = 'active'
             WHERE ce.user_id = ? AND ce.disqualified = 0"
        );
        $stmt->execute([$userId]);
        $activeSubmissions = (int)$stmt->fetchColumn();

        $totalEarned = (string)($profile['total_earned'] ?? '0.00');

        $stmt = $db->prepare(
            "SELECT ce.*, c.title AS contest_title, c.end_date, c.status AS contest_status,
                    c.youtube_thumbnail
             FROM contest_entries ce
             INNER JOIN contests c ON c.id = ce.contest_id
             WHERE ce.user_id = ? ORDER BY ce.submitted_at DESC LIMIT 6"
        );
        $stmt->execute([$userId]);
        $myEntries = $stmt->fetchAll();
    } catch (Throwable) {}
}

$displayName = $profile['display_name'] ?? $username;
$initials    = strtoupper(substr($displayName, 0, 1));

renderHead('Dashboard');
renderNav(true, ['username' => $username], $mode);
?>

<div class="public-page" style="min-height:calc(100vh - 120px)">
  <div class="container py-4">

    <?php if ($errorMsg === 'creator_required'): ?>
      <div class="alert-dark-danger mb-3">You need to switch to Creator mode to access that page.</div>
    <?php endif; ?>
    <?php if ($successMsg === 'contest_funded'): ?>
      <div class="alert-dark-success mb-3">🎉 Contest funded successfully! It is now live.</div>
    <?php endif; ?>

    <!-- Top bar -->
    <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3 mb-4">
      <div class="d-flex align-items-center gap-3">
        <div class="avatar-circle"><?= e($initials) ?></div>
        <div>
          <h5 class="mb-0 fw-700"><?= e($displayName) ?></h5>
          <span class="text-muted" style="font-size:0.85rem">@<?= e($username) ?></span>
        </div>
      </div>
      <button class="btn btn-sm mode-toggle" id="modeSwitchBtn"
              data-current="<?= e($mode) ?>"
              title="Switch mode">
        <?= $mode === 'creator' ? '🎬 Switch to Clipper Mode' : '📹 Switch to Creator Mode' ?>
      </button>
    </div>

    <?php if ($mode === 'creator'): ?>
      <!-- CREATOR VIEW -->
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
          <div class="stat-card">
            <div class="stat-value"><?= $activeContests ?></div>
            <div class="stat-label">Active Contests</div>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <div class="stat-card">
            <div class="stat-value">₦<?= number_format((float)$totalSpent, 0) ?></div>
            <div class="stat-label">Total Spent</div>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <div class="stat-card">
            <div class="stat-value"><?= number_format($totalEntries) ?></div>
            <div class="stat-label">Entries Received</div>
          </div>
        </div>
      </div>

      <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="fw-700 mb-0">Your Contests</h6>
        <a href="create-contest" class="btn btn-accent btn-sm">+ Create Contest</a>
      </div>

      <?php if (empty($recentContests)): ?>
        <div class="card-dark p-5 text-center">
          <div style="font-size:2.5rem;margin-bottom:12px">🎬</div>
          <h6 class="fw-700 mb-2">No contests yet</h6>
          <p class="text-muted mb-3" style="font-size:0.85rem">Create your first contest to start getting clips.</p>
          <a href="create-contest" class="btn btn-accent">Create Contest</a>
        </div>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach ($recentContests as $c): ?>
            <?php
              $isExpired  = !empty($c['end_date']) && strtotime($c['end_date']) < time();
              $statusCls  = $c['status'] === 'active' ? 'badge-active' : 'badge-inactive';
              $statusLbl  = ucfirst((string)$c['status']);
              $endLabel   = !empty($c['end_date']) ? formatDate($c['end_date'], 'M j, Y') : '—';
            ?>
            <div class="col-md-6">
              <div class="card-dark p-3">
                <?php if (!empty($c['youtube_thumbnail'])): ?>
                  <img src="<?= e($c['youtube_thumbnail']) ?>" alt="" class="rounded mb-2" style="width:100%;height:120px;object-fit:cover">
                <?php endif; ?>
                <div class="d-flex align-items-start justify-content-between gap-2">
                  <h6 class="fw-700 mb-1" style="font-size:0.9rem"><?= e($c['title']) ?></h6>
                  <span class="badge <?= $statusCls ?>" style="font-size:0.7rem;white-space:nowrap"><?= e($statusLbl) ?></span>
                </div>
                <div class="d-flex gap-3 text-muted mb-2" style="font-size:0.8rem">
                  <span>₦<?= number_format((float)$c['prize_pool'], 0) ?> prize</span>
                  <span><?= (int)$c['entry_count'] ?> entries</span>
                  <span>Ends <?= e($endLabel) ?></span>
                </div>
                <div class="d-flex gap-2">
                  <a href="/contest?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-accent">View</a>
                  <?php if ($c['escrow_status'] === 'unfunded' && $c['status'] === 'draft'): ?>
                    <a href="/payment/fund-contest?contest_id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-accent">Fund &amp; Activate</a>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <!-- CLIPPER VIEW -->
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
          <div class="stat-card">
            <div class="stat-value"><?= $activeSubmissions ?></div>
            <div class="stat-label">Active Submissions</div>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <div class="stat-card">
            <div class="stat-value">₦<?= number_format((float)$totalEarned, 0) ?></div>
            <div class="stat-label">Total Earned</div>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <div class="stat-card">
            <div class="stat-value"><?= $activeSubmissions ?></div>
            <div class="stat-label">Active Rankings</div>
          </div>
        </div>
      </div>

      <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="fw-700 mb-0">Your Submissions</h6>
        <a href="/contests" class="btn btn-accent btn-sm">Browse Contests</a>
      </div>

      <?php if (empty($myEntries)): ?>
        <div class="card-dark p-5 text-center">
          <div style="font-size:2.5rem;margin-bottom:12px">✂️</div>
          <h6 class="fw-700 mb-2">No submissions yet</h6>
          <p class="text-muted mb-3" style="font-size:0.85rem">Browse active contests and submit your clips to start earning.</p>
          <a href="/contests" class="btn btn-accent">Browse Contests</a>
        </div>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach ($myEntries as $entry): ?>
            <?php
              $platformIcon = match($entry['platform'] ?? '') {
                  'tiktok'    => '🎵',
                  'instagram' => '📸',
                  'facebook'  => '📘',
                  default     => '🎬',
              };
              $rankLabel = $entry['rank_position'] ? '#' . $entry['rank_position'] : 'Unranked';
            ?>
            <div class="col-md-6">
              <div class="card-dark p-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                  <span style="font-size:1.2rem"><?= $platformIcon ?></span>
                  <h6 class="fw-700 mb-0" style="font-size:0.9rem"><?= e($entry['contest_title']) ?></h6>
                </div>
                <div class="d-flex gap-3 text-muted mb-2" style="font-size:0.8rem">
                  <span><?= e($rankLabel) ?></span>
                  <span><?= number_format((int)$entry['view_count']) ?> views</span>
                  <span><?= number_format((int)$entry['like_count']) ?> likes</span>
                </div>
                <?php if ($entry['disqualified']): ?>
                  <span class="badge" style="background:rgba(220,38,38,0.1);color:#f87171;font-size:0.72rem">Disqualified</span>
                <?php elseif ($entry['contest_status'] === 'active'): ?>
                  <span class="badge badge-active" style="font-size:0.72rem">Active</span>
                <?php else: ?>
                  <span class="badge badge-inactive" style="font-size:0.72rem"><?= e(ucfirst($entry['contest_status'])) ?></span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Social handles quick view -->
    <?php if (!empty($profile)): ?>
      <div class="card-dark p-3 mt-4">
        <div class="d-flex flex-wrap gap-3 align-items-center">
          <span class="text-muted" style="font-size:0.8rem">Your handles:</span>
          <?php if (!empty($profile['youtube_handle'])): ?>
            <span style="font-size:0.82rem;color:#ff0000">▶ <?= e($profile['youtube_handle']) ?></span>
          <?php endif; ?>
          <?php if (!empty($profile['tiktok_handle'])): ?>
            <span style="font-size:0.82rem;color:var(--accent)">🎵 <?= e($profile['tiktok_handle']) ?></span>
          <?php endif; ?>
          <?php if (!empty($profile['instagram_handle'])): ?>
            <span style="font-size:0.82rem;color:#e1306c">📸 <?= e($profile['instagram_handle']) ?></span>
          <?php endif; ?>
          <?php if (empty($profile['youtube_handle']) && empty($profile['tiktok_handle']) && empty($profile['instagram_handle'])): ?>
            <a href="/profile" class="text-accent text-decoration-none" style="font-size:0.82rem">+ Add social handles</a>
          <?php endif; ?>
          <a href="/profile" class="ms-auto btn btn-sm btn-outline-accent">Edit Profile</a>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<script>
document.getElementById('modeSwitchBtn')?.addEventListener('click', function() {
  const btn = this;
  btn.disabled = true;
  btn.textContent = 'Switching…';
  fetch('/ajax/user_actions.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({
      action: 'switch_mode',
      csrf_token: <?= json_encode(generateCsrfToken()) ?>
    })
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) location.reload();
    else { btn.disabled = false; btn.textContent = 'Try Again'; }
  })
  .catch(() => { btn.disabled = false; btn.textContent = 'Error'; });
});
</script>

<?php renderFooter(); ?>
