<?php
declare(strict_types=1);

$root = __DIR__;
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';
require_once $root . '/includes/payhub.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireUser();

autoArchiveContests();

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
$activeContests     = 0;
$totalSpent         = '0.00';
$totalEntries       = 0;
$totalViewsReceived = 0;
$recentContests     = [];

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
            "SELECT COALESCE(SUM(ce.view_count), 0) FROM contest_entries ce
             INNER JOIN contests c ON c.id = ce.contest_id
             WHERE c.creator_id = ?"
        );
        $stmt->execute([$userId]);
        $totalViewsReceived = (int)$stmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT c.*, 
                    (SELECT COUNT(*) FROM contest_entries WHERE contest_id = c.id) AS entry_count
             FROM contests c WHERE c.creator_id = ? ORDER BY c.created_at DESC LIMIT 5"
        );
        $stmt->execute([$userId]);
        $recentContests = $stmt->fetchAll();

        // Top clippers summary for creator
        $creatorLB = [];
        $stmt = $db->prepare(
            "SELECT u.username, SUM(ce.view_count) AS total_views, COUNT(ce.id) AS clip_count
             FROM contest_entries ce
             INNER JOIN contests c ON c.id = ce.contest_id
             INNER JOIN users u ON u.id = ce.user_id
             WHERE c.creator_id = ? AND ce.status = 'approved' AND ce.disqualified = 0
             GROUP BY ce.user_id
             ORDER BY total_views DESC
             LIMIT 5"
        );
        $stmt->execute([$userId]);
        $creatorLB = $stmt->fetchAll();

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

        // Top 5 Global leaderboard for clipper dashboard
        $clipperLB = [];
        $stmt = $db->query(
            "SELECT u.username, SUM(ce.view_count) AS total_views
             FROM contest_entries ce
             INNER JOIN users u ON u.id = ce.user_id
             WHERE ce.status = 'approved' AND ce.disqualified = 0
             GROUP BY ce.user_id
             ORDER BY total_views DESC
             LIMIT 5"
        );
        $clipperLB = $stmt->fetchAll();

    } catch (Throwable) {}
}

$displayName = $profile['display_name'] ?? $username;
$initials    = strtoupper(substr($displayName, 0, 1));
$csrf        = generateCsrfToken();
$disclaimerAccepted = !empty($profile['disclaimer_accepted']);

renderHead('Dashboard', '<meta name="csrf" content="' . e($csrf) . '">');
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
    <?php if ($successMsg === 'contest_featured'): ?>
      <div class="alert-dark-success mb-3">⭐ Your contest is now featured! It will appear at the top of the contests page.</div>
    <?php endif; ?>

    <?php if (!$disclaimerAccepted): ?>
    <div class="disclaimer-banner card-dark mb-4" id="disclaimerBanner">
      <div class="d-flex align-items-start justify-content-between gap-3">
        <div class="d-flex align-items-center gap-2">
          <span style="font-size:1.3rem">⚠️</span>
          <div>
            <div class="fw-700" style="font-size:0.9rem">Reward Eligibility Notice</div>
            <div class="text-muted" style="font-size:0.78rem">Please review and acknowledge these rules before participating in any contest.</div>
          </div>
        </div>
        <button type="button" class="btn btn-xs btn-outline-accent" id="toggleDisclaimerBtn">Read &amp; Acknowledge</button>
      </div>
      <div id="disclaimerFullContent" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
        <div style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--accent);margin-bottom:10px">Reward Eligibility Notice</div>
        <p style="font-size:0.82rem;color:var(--text-secondary);line-height:1.6;margin-bottom:12px">
          To be considered eligible for prize collection on Clipaza, participants must comply with the following conditions. Non-compliance will result in disqualification and forfeiture of any earned rewards.
        </p>
        <ol style="font-size:0.82rem;line-height:1.7;color:var(--text-secondary);margin:0;padding-left:20px">
          <li style="margin-bottom:8px"><strong style="color:var(--text)">Analytics Video Proof:</strong> Prize claimants must submit a minimum 2-minute screen-recorded video demonstrating authentic video analytics (views, likes, and comments) within <strong style="color:var(--text)">72 hours</strong> of the contest closing date. Failure to provide this within the stipulated timeframe will result in the prize being transferred to the next eligible runner-up who can furnish valid proof.</li>
          <li style="margin-bottom:8px"><strong style="color:var(--text)">Engagement Verification:</strong> Screenshot proof confirming your comment and like on the contest creator's original video is required as part of the prize collection process.</li>
          <li style="margin-bottom:8px"><strong style="color:var(--text)">No Paid Promotions:</strong> Participants must not run any paid promotion, sponsored boost, or advertising campaign on their submitted video. Entries backed by paid reach are ineligible for rewards and will be disqualified upon detection.</li>
          <li style="margin-bottom:8px"><strong style="color:var(--danger)">Strict Prohibition on Artificial Engagement:</strong> The use of bots, automation tools, view-purchasing services, or any mechanism designed to artificially inflate video metrics is strictly prohibited. Any account found engaging in such practices will be <strong style="color:var(--danger)">immediately and permanently suspended</strong> from the platform, and all associated prize claims will be voided without recourse.</li>
          <li>All submissions must reflect genuine, organic audience engagement. Clipaza employs automated detection systems that continuously monitor for fraudulent activity. Confirmed violations are non-appealable and subject to permanent account termination.</li>
        </ol>
        <button type="button" class="btn btn-accent btn-sm mt-3" id="acceptDisclaimerBtn">✓ I Understand &amp; Agree</button>
        <div id="disclaimerFeedback" class="mt-2"></div>
      </div>
    </div>
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
      <div class="row g-4">
        <div class="col-lg-8">
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
      <div class="row g-3 mb-4">
        <div class="col-12">
          <div class="stat-card">
            <div class="stat-value"><?= number_format($totalViewsReceived) ?></div>
            <div class="stat-label">Total Views Received</div>
          </div>
        </div>
      </div>

      <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="fw-700 mb-0">Your Contests</h6>
        <a href="/create-contest" class="btn btn-accent btn-sm">+ Create Contest</a>
      </div>

      <?php if (empty($recentContests)): ?>
        <div class="card-dark p-5 text-center">
          <div style="font-size:2.5rem;margin-bottom:12px">🎬</div>
          <h6 class="fw-700 mb-2">No contests yet</h6>
          <p class="text-muted mb-3" style="font-size:0.85rem">Create your first contest to start getting clips.</p>
          <a href="/create-contest" class="btn btn-accent">Create Contest</a>
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
                  <a href="/contest-stats?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-accent">Stats</a>
                  <?php if ($c['escrow_status'] === 'unfunded' && $c['status'] === 'draft'): ?>
                    <a href="/payment/fund-contest?contest_id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-accent">Fund &amp; Activate</a>
                  <?php endif; ?>
                  <?php if ($c['status'] === 'active' && $c['escrow_status'] === 'funded'): ?>
                    <?php $alreadyFeatured = !empty($c['is_featured']) && !empty($c['featured_until']) && strtotime($c['featured_until']) > time(); ?>
                    <?php if ($alreadyFeatured): ?>
                      <span class="badge-accent" style="font-size:0.72rem;padding:3px 8px">⭐ Featured</span>
                    <?php else: ?>
                      <button class="btn btn-sm btn-outline-accent feature-btn"
                              data-contest-id="<?= (int)$c['id'] ?>"
                              data-contest-title="<?= e($c['title']) ?>">⭐ Feature</button>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
        </div> <!-- end col-lg-8 -->

        <div class="col-lg-4">
          <div class="card-dark h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
              <h6 class="mb-0 fw-700">🏆 Top Clippers</h6>
              <a href="/leaderboards" class="text-accent" style="font-size:0.75rem">View All</a>
            </div>
            <div class="card-body p-0">
              <?php if (empty($creatorLB)): ?>
                <div class="p-4 text-center text-muted" style="font-size:0.85rem">No entries in your contests yet.</div>
              <?php else: ?>
                <?php foreach ($creatorLB as $idx => $row): ?>
                  <div class="d-flex align-items-center justify-content-between p-3 border-bottom border-secondary">
                    <div class="d-flex align-items-center gap-2">
                      <span class="fw-700 text-muted" style="font-size:0.8rem"><?= $idx+1 ?>.</span>
                      <span style="font-size:0.85rem">@<?= e($row['username']) ?></span>
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
      </div> <!-- end row -->

    <?php else: ?>
      <!-- CLIPPER VIEW -->
      <div class="row g-4">
        <div class="col-lg-8">
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
                  <span><?= number_format((int)$entry['comment_count']) ?> comments</span>
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
        </div> <!-- end col-lg-8 -->

        <div class="col-lg-4">
          <div class="card-dark h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
              <h6 class="mb-0 fw-700">🏆 Global Rankings</h6>
              <a href="/leaderboards" class="text-accent" style="font-size:0.75rem">View All</a>
            </div>
            <div class="card-body p-0">
              <?php if (empty($clipperLB)): ?>
                <div class="p-4 text-center text-muted" style="font-size:0.85rem">No rankings data yet.</div>
              <?php else: ?>
                <?php foreach ($clipperLB as $idx => $row): ?>
                  <div class="d-flex align-items-center justify-content-between p-3 border-bottom border-secondary">
                    <div class="d-flex align-items-center gap-2">
                      <span class="fw-700 text-muted" style="font-size:0.8rem"><?= $idx+1 ?>.</span>
                      <span style="font-size:0.85rem" class="<?= $row['username'] === $username ? 'text-accent fw-700' : '' ?>">@<?= e($row['username']) ?></span>
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
      </div> <!-- end row -->
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
      csrf_token: <?= json_encode($csrf) ?>
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

<!-- Feature Contest Modal -->
<div class="modal fade modal-dark" id="featureModal" tabindex="-1">
  <div class="modal-dialog modal-dark">
    <div class="modal-content modal-dark">
      <div class="modal-header" style="border-bottom:1px solid var(--border)">
        <h5 class="modal-title fw-700">⭐ Feature Contest</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted mb-3" style="font-size:0.85rem">
          Featuring your contest puts it at the top of the contests page and in the sidebar — maximizing visibility.
        </p>
        <div id="featurePlansContainer">
          <div class="text-center py-3"><span class="text-muted" style="font-size:0.85rem">Loading plans…</span></div>
        </div>
        <div class="mt-3" id="featureGatewayRow" style="display:none">
          <label class="form-label-dark">Payment Method</label>
          <div class="d-flex gap-3">
            <label style="cursor:pointer;display:flex;align-items:center;gap:6px;font-size:0.88rem">
              <input type="radio" name="feature_gateway" value="paystack" checked> Paystack
            </label>
            <?php if (function_exists('payhubEnabled') && payhubEnabled()): ?>
            <label style="cursor:pointer;display:flex;align-items:center;gap:6px;font-size:0.88rem">
              <input type="radio" name="feature_gateway" value="payhub"> PayHub
            </label>
            <?php endif; ?>
          </div>
        </div>
        <div id="featureFeedback" class="mt-3"></div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--border)">
        <button type="button" class="btn btn-outline-accent btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-accent btn-sm" id="featurePayBtn" disabled>Select a Plan</button>
      </div>
    </div>
  </div>
</div>

<script>
let selectedPlanId = null;
let featureContestId = null;
const featureCsrf = <?= json_encode($csrf) ?>;

document.querySelectorAll('.feature-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    featureContestId = this.dataset.contestId;
    document.getElementById('featurePayBtn').disabled = true;
    document.getElementById('featurePayBtn').textContent = 'Select a Plan';
    document.getElementById('featureFeedback').innerHTML = '';
    selectedPlanId = null;

    document.getElementById('featurePlansContainer').innerHTML = '<div class="text-center py-3"><span class="text-muted">Loading…</span></div>';
    document.getElementById('featureGatewayRow').style.display = 'none';

    fetch('/ajax/feature_actions.php?action=get_plans')
      .then(r => r.json())
      .then(d => {
        if (!d.success || !d.plans?.length) {
          document.getElementById('featurePlansContainer').innerHTML = '<p class="text-muted text-center">No feature plans available. Contact admin.</p>';
          return;
        }
        let html = '<div class="feature-plans-grid">';
        d.plans.forEach(p => {
          html += `<div class="feature-plan-card" data-plan-id="${p.id}" data-price="${p.price}">
            <div class="fw-700 mb-1">${p.name}</div>
            <div class="text-muted mb-1" style="font-size:0.8rem">${p.description||''}</div>
            <div style="color:var(--accent);font-weight:700;font-size:1.1rem">₦${Number(p.price).toLocaleString()}</div>
            <div class="text-muted" style="font-size:0.75rem">${p.duration_days} days</div>
          </div>`;
        });
        html += '</div>';
        document.getElementById('featurePlansContainer').innerHTML = html;
        document.getElementById('featureGatewayRow').style.display = 'block';

        document.querySelectorAll('.feature-plan-card').forEach(card => {
          card.addEventListener('click', function() {
            document.querySelectorAll('.feature-plan-card').forEach(c => c.classList.remove('selected'));
            this.classList.add('selected');
            selectedPlanId = this.dataset.planId;
            const price = Number(this.dataset.price).toLocaleString();
            document.getElementById('featurePayBtn').disabled = false;
            document.getElementById('featurePayBtn').textContent = 'Pay ₦' + price;
          });
        });
      })
      .catch(() => {
        document.getElementById('featurePlansContainer').innerHTML = '<p class="text-muted text-center">Failed to load plans.</p>';
      });

    new bootstrap.Modal(document.getElementById('featureModal')).show();
  });
});

document.getElementById('featurePayBtn')?.addEventListener('click', async function() {
  if (!selectedPlanId || !featureContestId) return;
  const gateway = document.querySelector('input[name="feature_gateway"]:checked')?.value || 'paystack';
  this.disabled = true; this.textContent = 'Initializing…';
  document.getElementById('featureFeedback').innerHTML = '';
  try {
    const r = await fetch('/ajax/feature_actions.php', {
      method: 'POST',
      body: new URLSearchParams({action:'init_feature', contest_id:featureContestId, plan_id:selectedPlanId, gateway, csrf_token:featureCsrf})
    });
    const d = await r.json();
    if (d.success) {
      const url = d.checkout_url || d.authorization_url;
      if (url) window.location.href = url;
      else document.getElementById('featureFeedback').innerHTML = '<div class="alert-dark-danger">No checkout URL returned.</div>';
    } else {
      document.getElementById('featureFeedback').innerHTML = '<div class="alert-dark-danger">' + (d.message||'Error') + '</div>';
      this.disabled = false; this.textContent = 'Retry';
    }
  } catch {
    document.getElementById('featureFeedback').innerHTML = '<div class="alert-dark-danger">Network error.</div>';
    this.disabled = false; this.textContent = 'Retry';
  }
});
</script>

<script>
// Disclaimer banner
document.getElementById('toggleDisclaimerBtn')?.addEventListener('click', function() {
  const content = document.getElementById('disclaimerFullContent');
  const visible = content.style.display !== 'none';
  content.style.display = visible ? 'none' : 'block';
  this.textContent = visible ? 'Read & Acknowledge' : 'Collapse';
});

document.getElementById('acceptDisclaimerBtn')?.addEventListener('click', async function() {
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
  this.disabled = true; this.textContent = 'Saving…';
  try {
    const r = await fetch('/ajax/disclaimer_actions.php', {
      method: 'POST',
      body: new URLSearchParams({action: 'accept_disclaimer', csrf_token: csrf})
    });
    const d = await r.json();
    if (d.success) {
      const banner = document.getElementById('disclaimerBanner');
      banner.style.transition = 'opacity 0.4s';
      banner.style.opacity = '0';
      setTimeout(() => banner?.remove(), 400);
    } else {
      document.getElementById('disclaimerFeedback').innerHTML = '<span style="color:var(--danger);font-size:0.8rem">' + (d.message || 'Error') + '</span>';
      this.disabled = false; this.textContent = '✓ I Understand & Agree';
    }
  } catch {
    document.getElementById('disclaimerFeedback').innerHTML = '<span style="color:var(--danger);font-size:0.8rem">Network error.</span>';
    this.disabled = false; this.textContent = '✓ I Understand & Agree';
  }
});
</script>

<?php renderFooter(); ?>
