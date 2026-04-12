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
$username = $_SESSION['username'] ?? '';
$userMode = getUserMode();
$isAdmin  = ($_SESSION['user_role'] ?? '') === 'admin';

$contestId = (int)($_GET['id'] ?? 0);
if ($contestId <= 0) redirect('/dashboard');

$contest        = null;
$platforms      = [];
$platformStats  = [];
$botEntries     = [];

try {
    $db   = db();

    // Load contest — must be owned by this user or admin
    $stmt = $db->prepare('SELECT * FROM contests WHERE id = ? LIMIT 1');
    $stmt->execute([$contestId]);
    $contest = $stmt->fetch();

    if (!$contest) redirect('/dashboard');
    if (!$isAdmin && (int)$contest['creator_id'] !== $userId) redirect('/dashboard');

    // Platforms
    $stmt = $db->prepare('SELECT * FROM contest_platforms WHERE contest_id = ?');
    $stmt->execute([$contestId]);
    $platforms = $stmt->fetchAll();

    // Per-platform stats + leaderboard top 10
    foreach ($platforms as $p) {
        $platform = $p['platform'];

        // Count and views
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS entry_count, COALESCE(SUM(view_count),0) AS total_views
             FROM contest_entries WHERE contest_id = ? AND platform = ?"
        );
        $stmt->execute([$contestId, $platform]);
        $summary = $stmt->fetch();

        // Top 10 leaderboard with user info
        $stmt = $db->prepare(
            "SELECT ce.id, ce.clip_url, ce.view_count, ce.like_count, ce.comment_count,
                    ce.bot_score, ce.bot_flags, ce.status, ce.disqualified, ce.disqualify_reason,
                    ce.proof_subscribe_path, ce.proof_like_path, ce.proof_comment_path,
                    ce.submitted_at,
                    u.username
             FROM contest_entries ce
             INNER JOIN users u ON u.id = ce.user_id
             WHERE ce.contest_id = ? AND ce.platform = ?
             ORDER BY ce.view_count DESC, ce.like_count DESC
             LIMIT 10"
        );
        $stmt->bindValue(1, $contestId, PDO::PARAM_INT);
        $stmt->bindValue(2, $platform);
        $stmt->execute();
        $leaderboard = $stmt->fetchAll();

        $platformStats[$platform] = [
            'info'        => $p,
            'entry_count' => (int)$summary['entry_count'],
            'total_views' => (int)$summary['total_views'],
            'leaderboard' => $leaderboard,
        ];
    }

    // Bot-flagged entries (bot_score >= 20)
    $stmt = $db->prepare(
        "SELECT ce.id, ce.platform, ce.clip_url, ce.bot_score, ce.bot_flags,
                ce.status, ce.disqualified, ce.submitted_at,
                u.username
         FROM contest_entries ce
         INNER JOIN users u ON u.id = ce.user_id
         WHERE ce.contest_id = ? AND ce.bot_score >= 20
         ORDER BY ce.bot_score DESC
         LIMIT 50"
    );
    $stmt->execute([$contestId]);
    $botEntries = $stmt->fetchAll();

} catch (Throwable) {
    redirect('/dashboard');
}

// Total across all platforms
$totalAllEntries = 0;
$totalAllViews   = 0;
foreach ($platformStats as $ps) {
    $totalAllEntries += $ps['entry_count'];
    $totalAllViews   += $ps['total_views'];
}

$csrf      = generateCsrfToken();
$pageTitle = 'Stats: ' . $contest['title'];
renderHead($pageTitle);
renderNav(true, ['username' => $username], $userMode);
?>

<div class="public-page">
  <div class="container py-5">

    <!-- Header -->
    <div class="d-flex align-items-center gap-3 mb-4">
      <a href="/dashboard" class="text-muted text-decoration-none" style="font-size:0.85rem">← Dashboard</a>
      <h3 class="fw-900 mb-0" style="letter-spacing:-0.5px">Contest Stats</h3>
    </div>

    <!-- Contest info card -->
    <div class="card-dark p-4 mb-4">
      <div class="d-flex flex-column flex-md-row gap-3 align-items-start justify-content-between">
        <div>
          <h5 class="fw-700 mb-1"><?= e($contest['title']) ?></h5>
          <div class="d-flex flex-wrap gap-2 mb-2">
            <?php
              $statusCls = match($contest['status']) {
                'active'    => 'badge-active',
                'ended'     => 'badge-inactive',
                'cancelled' => 'badge-danger',
                default     => 'badge-muted',
              };
            ?>
            <span class="<?= $statusCls ?>"><?= e(ucfirst($contest['status'])) ?></span>
            <?php if (!empty($contest['end_date'])): ?>
              <span class="text-muted" style="font-size:0.82rem">Ends: <?= e(formatDate($contest['end_date'], 'M j, Y g:i A')) ?></span>
            <?php endif; ?>
          </div>
          <div class="text-muted" style="font-size:0.85rem">Prize Pool: <strong style="color:var(--accent)">₦<?= number_format((float)$contest['prize_pool'], 0) ?></strong></div>
        </div>
        <a href="/contest?id=<?= $contestId ?>" class="btn btn-outline-accent btn-sm">View Contest →</a>
      </div>
    </div>

    <!-- Summary stats -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="stat-card">
          <div class="stat-value"><?= number_format($totalAllEntries) ?></div>
          <div class="stat-label">Total Entries</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card">
          <div class="stat-value"><?= number_format($totalAllViews) ?></div>
          <div class="stat-label">Total Views</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card">
          <div class="stat-value"><?= count($platforms) ?></div>
          <div class="stat-label">Platforms</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card">
          <div class="stat-value"><?= count($botEntries) ?></div>
          <div class="stat-label">Flagged Entries</div>
        </div>
      </div>
    </div>

    <!-- Per-platform breakdown -->
    <?php foreach ($platformStats as $platform => $ps): ?>
    <?php
      $pIcon = match($platform) { 'tiktok'=>'🎵','instagram'=>'📸','facebook'=>'📘',default=>'' };
    ?>
    <div class="card-dark p-4 mb-4">
      <h6 class="fw-700 mb-3"><?= $pIcon ?> <?= ucfirst(e($platform)) ?> Platform</h6>
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-value"><?= number_format($ps['entry_count']) ?></div>
            <div class="stat-label">Entries</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-value"><?= number_format($ps['total_views']) ?></div>
            <div class="stat-label">Total Views</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-value">₦<?= number_format((float)$ps['info']['prize_amount'], 0) ?></div>
            <div class="stat-label">Prize Amount</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-value"><?= (int)$ps['info']['winner_count'] ?></div>
            <div class="stat-label">Winners</div>
          </div>
        </div>
      </div>

      <h6 class="fw-600 mb-2" style="font-size:0.88rem">Top 10 Leaderboard</h6>
      <?php if (empty($ps['leaderboard'])): ?>
        <p class="text-muted" style="font-size:0.85rem">No entries yet.</p>
      <?php else: ?>
      <div style="overflow-x:auto">
        <table class="table-dark-custom w-100" style="font-size:0.82rem">
          <thead>
            <tr>
              <th>#</th>
              <th>User</th>
              <th>Views</th>
              <th>Likes</th>
              <th>Comments</th>
              <th>Bot Score</th>
              <th>Status</th>
              <th>Proofs</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ps['leaderboard'] as $rank => $entry): ?>
            <?php
              $rankNum  = $rank + 1;
              $rankIcon = match($rankNum) { 1=>'🥇', 2=>'🥈', 3=>'🥉', default=>"#{$rankNum}" };
              $stCls    = match($entry['status']) {
                'approved' => 'badge-success',
                'pending'  => 'badge-warning',
                'rejected' => 'badge-danger',
                default    => 'badge-muted',
              };
              $botCls   = $entry['bot_score'] >= 60 ? 'badge-danger' : ($entry['bot_score'] >= 20 ? 'badge-warning' : '');
              $flags    = $entry['bot_flags'] ? implode(', ', json_decode($entry['bot_flags'], true) ?: []) : '';
            ?>
            <tr <?= $entry['disqualified'] ? 'style="opacity:0.5"' : '' ?>>
              <td><?= $rankIcon ?></td>
              <td>
                <?= e($entry['username']) ?>
                <?php if ($entry['disqualified']): ?>
                  <span class="badge-danger ms-1" style="font-size:0.65rem">DQ</span>
                <?php endif; ?>
              </td>
              <td><?= number_format((int)$entry['view_count']) ?></td>
              <td><?= number_format((int)$entry['like_count']) ?></td>
              <td><?= number_format((int)$entry['comment_count']) ?></td>
              <td>
                <?php if ($entry['bot_score'] > 0): ?>
                  <span class="<?= $botCls ?>" title="<?= e($flags) ?>"><?= (int)$entry['bot_score'] ?></span>
                <?php else: ?>
                  <span class="text-muted">0</span>
                <?php endif; ?>
              </td>
              <td><span class="<?= $stCls ?>"><?= e(ucfirst($entry['status'])) ?></span></td>
              <td style="font-size:0.75rem">
                <?php if (!empty($entry['proof_subscribe_path'])): ?>
                  <span class="badge-success" title="Subscribe proof uploaded">S✓</span>
                <?php endif; ?>
                <?php if (!empty($entry['proof_like_path'])): ?>
                  <span class="badge-success" title="Like proof uploaded">L✓</span>
                <?php endif; ?>
                <?php if (!empty($entry['proof_comment_path'])): ?>
                  <span class="badge-success" title="Comment proof uploaded">C✓</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!$entry['disqualified']): ?>
                <button class="btn btn-xs disqualify-btn"
                        data-id="<?= (int)$entry['id'] ?>"
                        data-username="<?= e($entry['username']) ?>"
                        style="background:rgba(220,38,38,0.1);color:#f87171;font-size:0.7rem;border:1px solid rgba(220,38,38,0.2);border-radius:5px;padding:2px 7px">
                  Disqualify
                </button>
                <?php else: ?>
                  <span class="text-muted" style="font-size:0.75rem">Disqualified</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- Bot-flagged entries -->
    <?php if (!empty($botEntries)): ?>
    <div class="card-dark p-4 mb-4">
      <h6 class="fw-700 mb-3">🤖 Bot-Flagged Entries (Score ≥ 20)</h6>
      <div style="overflow-x:auto">
        <table class="table-dark-custom w-100" style="font-size:0.82rem">
          <thead>
            <tr>
              <th>User</th>
              <th>Platform</th>
              <th>Bot Score</th>
              <th>Flags</th>
              <th>Status</th>
              <th>Submitted</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($botEntries as $entry): ?>
            <?php
              $flags  = $entry['bot_flags'] ? implode(', ', json_decode($entry['bot_flags'], true) ?: []) : '—';
              $botCls = $entry['bot_score'] >= 60 ? 'badge-danger' : 'badge-warning';
              $stCls  = match($entry['status']) {
                'approved' => 'badge-success',
                'pending'  => 'badge-warning',
                'rejected' => 'badge-danger',
                default    => 'badge-muted',
              };
              $pIcon  = match($entry['platform']) { 'tiktok'=>'🎵','instagram'=>'📸','facebook'=>'📘',default=>'' };
            ?>
            <tr>
              <td><?= e($entry['username']) ?></td>
              <td><?= $pIcon ?> <?= ucfirst(e($entry['platform'])) ?></td>
              <td><span class="<?= $botCls ?>"><?= (int)$entry['bot_score'] ?></span></td>
              <td style="color:#aaa;font-size:0.78rem"><?= e($flags) ?></td>
              <td><span class="<?= $stCls ?>"><?= e(ucfirst($entry['status'])) ?></span></td>
              <td style="color:#888;font-size:0.78rem"><?= e(formatDate($entry['submitted_at'], 'M j, Y H:i')) ?></td>
              <td>
                <?php if (!$entry['disqualified']): ?>
                <button class="btn btn-xs disqualify-btn"
                        data-id="<?= (int)$entry['id'] ?>"
                        data-username="<?= e($entry['username']) ?>"
                        style="background:rgba(220,38,38,0.1);color:#f87171;font-size:0.7rem;border:1px solid rgba(220,38,38,0.2);border-radius:5px;padding:2px 7px">
                  Disqualify
                </button>
                <?php else: ?>
                  <span class="badge-danger" style="font-size:0.72rem">Disqualified</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- Disqualify Modal -->
<div class="modal fade" id="disqualifyModal" tabindex="-1" style="display:none" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
    <div class="modal-content" style="background:#111;border:1px solid #222;border-radius:12px">
      <div class="modal-header" style="border-bottom:1px solid #222">
        <h6 class="modal-title fw-700 text-white">Disqualify Entry</h6>
        <button type="button" class="btn-close btn-close-white" id="disqualifyModalClose"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted mb-3" style="font-size:0.85rem">Disqualify entry for <strong id="disqualifyUsername" style="color:#fff"></strong>?</p>
        <div class="mb-3">
          <label class="form-label-dark">Reason (optional)</label>
          <input type="text" id="disqualifyReason" class="form-control-dark" placeholder="e.g. Bot activity detected">
        </div>
        <div id="disqualifyFeedback" class="mb-2"></div>
        <div class="d-flex gap-2">
          <button class="btn btn-sm" style="background:rgba(220,38,38,0.15);color:#f87171;border:1px solid rgba(220,38,38,0.3);border-radius:8px;padding:6px 16px"
                  id="disqualifyConfirmBtn">Disqualify</button>
          <button class="btn btn-sm btn-outline-accent" id="disqualifyModalClose2">Cancel</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const csrf = <?= json_encode($csrf) ?>;
let disqualifyEntryId = 0;

// Disqualify modal
document.querySelectorAll('.disqualify-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    disqualifyEntryId = parseInt(this.dataset.id);
    document.getElementById('disqualifyUsername').textContent = this.dataset.username;
    document.getElementById('disqualifyReason').value = '';
    document.getElementById('disqualifyFeedback').innerHTML = '';
    document.getElementById('disqualifyModal').style.display = 'flex';
    document.getElementById('disqualifyModal').style.alignItems = 'center';
    document.getElementById('disqualifyModal').style.justifyContent = 'center';
    document.getElementById('disqualifyModal').style.position = 'fixed';
    document.getElementById('disqualifyModal').style.inset = '0';
    document.getElementById('disqualifyModal').style.background = 'rgba(0,0,0,0.7)';
    document.getElementById('disqualifyModal').style.zIndex = '9999';
  });
});

['disqualifyModalClose','disqualifyModalClose2'].forEach(id => {
  document.getElementById(id)?.addEventListener('click', () => {
    document.getElementById('disqualifyModal').style.display = 'none';
  });
});

document.getElementById('disqualifyConfirmBtn')?.addEventListener('click', async function() {
  const reason = document.getElementById('disqualifyReason').value.trim();
  const fb  = document.getElementById('disqualifyFeedback');
  const btn = this;
  btn.disabled = true;
  fb.innerHTML = '';
  try {
    const fd = new URLSearchParams({
      csrf_token: csrf,
      action: 'disqualify_entry',
      entry_id: disqualifyEntryId,
      reason: reason
    });
    const r = await fetch('/ajax/contest_actions.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) {
      fb.innerHTML = '<span style="color:#4ade80">✅ ' + d.message + '</span>';
      setTimeout(() => location.reload(), 1000);
    } else {
      fb.innerHTML = '<span style="color:#f87171">' + (d.message || 'Error') + '</span>';
    }
  } catch {
    fb.innerHTML = '<span style="color:#f87171">Network error.</span>';
  }
  btn.disabled = false;
});
</script>

<?php renderFooter(); ?>
