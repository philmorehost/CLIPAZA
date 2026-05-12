<?php
declare(strict_types=1);

$root = __DIR__;
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();

autoArchiveContests();

$isLoggedIn = !empty($_SESSION['user_id']);
$userId     = $isLoggedIn ? (int)$_SESSION['user_id'] : 0;
$username   = $_SESSION['username'] ?? '';
$userMode   = getUserMode();

$contestId = (int)($_GET['id'] ?? 0);
if ($contestId <= 0) redirect('/contests');

$contest   = null;
$platforms = [];
$myEntries = []; // keyed by platform
$hasBankDetails = false;

try {
    $db   = db();
    $stmt = $db->prepare('SELECT c.*, u.username AS creator_username FROM contests c LEFT JOIN users u ON u.id = c.creator_id WHERE c.id = ? LIMIT 1');
    $stmt->execute([$contestId]);
    $contest = $stmt->fetch();
    if (!$contest) redirect('/contests');

    $stmt = $db->prepare('SELECT * FROM contest_platforms WHERE contest_id = ?');
    $stmt->execute([$contestId]);
    $platforms = $stmt->fetchAll();

    if ($isLoggedIn) {
        $stmt = $db->prepare('SELECT * FROM contest_entries WHERE contest_id = ? AND user_id = ?');
        $stmt->execute([$contestId, $userId]);
        foreach ($stmt->fetchAll() as $e) {
            $myEntries[$e['platform'] ?? 'all'] = $e;
        }

        $stmt = $db->prepare('SELECT account_number FROM user_profiles WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $profileRow = $stmt->fetch();
        $hasBankDetails = !empty($profileRow['account_number']);
    }
} catch (Throwable) {
    redirect('/contests');
}

$isActive  = $contest['status'] === 'active'
    && (empty($contest['end_date']) || strtotime($contest['end_date']) > time());
$timeLeft  = '';
if (!empty($contest['end_date'])) {
    $secs = strtotime($contest['end_date']) - time();
    if ($secs > 0) {
        $d = floor($secs / 86400);
        $h = floor(($secs % 86400) / 3600);
        $m = floor(($secs % 3600) / 60);
        $timeLeft = "{$d}d {$h}h {$m}m remaining";
    } else {
        $timeLeft = 'Expired';
    }
}

// Build leaderboard per platform
function getLeaderboard(PDO $db, int $contestId, string $platform, int $limit = 10): array {
    try {
        $stmt = $db->prepare(
            "SELECT ce.*, u.username FROM contest_entries ce
             INNER JOIN users u ON u.id = ce.user_id
             WHERE ce.contest_id = ? AND ce.platform = ? AND ce.disqualified = 0
             ORDER BY ce.view_count DESC, ce.like_count DESC, ce.comment_count DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $contestId, PDO::PARAM_INT);
        $stmt->bindValue(2, $platform);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable) { return []; }
}

$csrf = generateCsrfToken();

// Load disclaimer status for logged-in user
$disclaimerAccepted = false;
if ($isLoggedIn) {
    try {
        $stmtDisc = db()->prepare("SELECT disclaimer_accepted FROM user_profiles WHERE user_id = ? LIMIT 1");
        $stmtDisc->execute([$userId]);
        $rowDisc = $stmtDisc->fetch();
        $disclaimerAccepted = !empty($rowDisc['disclaimer_accepted']);
    } catch (Throwable) {}
}

// Load sidebar featured contests (excluding current)
$sidebarFeatured = [];
try {
    $db2   = db();
    $stmt2 = $db2->prepare(
        "SELECT c.id, c.title, c.prize_pool, c.youtube_thumbnail, c.end_date,
                GROUP_CONCAT(DISTINCT cp.platform ORDER BY cp.platform SEPARATOR ',') AS platforms
         FROM contests c
         LEFT JOIN contest_platforms cp ON cp.contest_id = c.id
         WHERE c.id != ? AND c.status = 'active' AND c.is_featured = 1
           AND (c.featured_until IS NULL OR c.featured_until > NOW())
           AND (c.end_date IS NULL OR c.end_date > NOW())
         GROUP BY c.id
         ORDER BY RAND()
         LIMIT 8"
    );
    $stmt2->execute([$contestId]);
    $sidebarFeatured = $stmt2->fetchAll();
} catch (Throwable) {}

$pageTitle  = $contest['title'];
$siteUrl    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'clipaza.com');
$siteName   = getSetting('site_name', 'Clipaza');
$contestUrl = $siteUrl . '/contest?id=' . $contestId;
$ogImage    = $contest['youtube_thumbnail'] ?? '';
$prize      = number_format((float)($contest['prize_pool'] ?? 0), 0);
$metaDesc   = 'Enter the "' . addslashes($contest['title']) . '" clipping contest on ' . $siteName . ' and compete for a ₦' . $prize . ' prize. Post your best clip on TikTok or Reels — most authentic views wins.';

$extraHead  = '<meta name="csrf" content="' . e($csrf) . '">' . "\n";
$extraHead .= '  <meta name="description" content="' . e($metaDesc) . '">' . "\n";
$extraHead .= '  <link rel="canonical" href="' . e($contestUrl) . '">' . "\n";
$extraHead .= '  <meta property="og:type" content="website">' . "\n";
$extraHead .= '  <meta property="og:title" content="' . e($pageTitle . ' — ' . $siteName) . '">' . "\n";
$extraHead .= '  <meta property="og:description" content="' . e($metaDesc) . '">' . "\n";
$extraHead .= '  <meta property="og:url" content="' . e($contestUrl) . '">' . "\n";
if ($ogImage !== '') {
    $extraHead .= '  <meta property="og:image" content="' . e($ogImage) . '">' . "\n";
}
$extraHead .= '  <meta name="twitter:card" content="summary_large_image">' . "\n";
$extraHead .= '  <meta name="twitter:title" content="' . e($pageTitle . ' — ' . $siteName) . '">' . "\n";
$extraHead .= '  <meta name="twitter:description" content="' . e($metaDesc) . '">' . "\n";
if ($ogImage !== '') {
    $extraHead .= '  <meta name="twitter:image" content="' . e($ogImage) . '">' . "\n";
}
// JSON-LD Event schema for contest
$schemaEndDate = !empty($contest['end_date']) ? date('c', strtotime($contest['end_date'])) : '';
$schemaJson = json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'Event',
    'name'        => $contest['title'],
    'description' => $metaDesc,
    'url'         => $contestUrl,
    'eventStatus' => $isActive ? 'https://schema.org/EventScheduled' : 'https://schema.org/EventCancelled',
    'organizer'   => ['@type' => 'Organization', 'name' => $siteName, 'url' => $siteUrl],
    'image'       => $ogImage ?: null,
    'endDate'     => $schemaEndDate ?: null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$extraHead .= '  <script type="application/ld+json">' . $schemaJson . '</script>' . "\n";

renderHead($pageTitle, $extraHead);
renderNav($isLoggedIn, ['username' => $username], $userMode);
?>

<div class="public-page">
  <div class="container py-5">
    <div class="row g-4">
      <div class="<?= !empty($sidebarFeatured) ? 'col-lg-8' : 'col-12' ?>">
    <!-- Header -->
    <div class="d-flex flex-column flex-md-row align-items-start gap-3 mb-4">
      <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
          <?php if ($isActive): ?>
            <span class="badge badge-active">🟢 Active</span>
          <?php else: ?>
            <span class="badge badge-inactive">⚫ <?= e(ucfirst($contest['status'])) ?></span>
          <?php endif; ?>
          <?php if ($timeLeft): ?>
            <span class="countdown-timer <?= strpos($timeLeft, 'Expired') !== false ? 'countdown-urgent' : '' ?>"><?= e($timeLeft) ?></span>
          <?php endif; ?>
        </div>
        <h1 class="fw-900 mb-1" style="font-size:clamp(1.4rem,3vw,2rem);letter-spacing:-0.5px"><?= e($contest['title']) ?></h1>
        <?php if (!empty($contest['creator_username'])): ?>
          <p class="text-muted mb-0" style="font-size:0.85rem">by @<?= e($contest['creator_username']) ?></p>
        <?php endif; ?>
      </div>
      <?php if (!empty($contest['prize_pool'])): ?>
        <div class="text-center" style="background:var(--accent-dim);border:1px solid rgba(204,255,0,0.3);border-radius:var(--radius);padding:16px 24px">
          <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--accent)">Prize Pool</div>
          <div style="font-size:1.6rem;font-weight:900;color:var(--accent)">₦<?= number_format((float)$contest['prize_pool'], 0) ?></div>
        </div>
      <?php endif; ?>
    </div>

    <div class="row g-4">
      <!-- Left column: video + requirements -->
      <div class="col-lg-8">
        <?php if (!empty($contest['youtube_video_id'])): ?>
          <div class="ratio ratio-16x9 mb-4 rounded overflow-hidden">
            <iframe src="https://www.youtube.com/embed/<?= e($contest['youtube_video_id']) ?>"
                    title="<?= e($contest['title']) ?>"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen></iframe>
          </div>
        <?php endif; ?>

        <!-- Clip Instructions -->
        <?php if (!empty($contest['clip_start_time']) || !empty($contest['clip_instructions'])): ?>
          <div class="card-dark p-3 mb-4">
            <h6 class="fw-700 mb-3">📋 Clip Instructions</h6>
            <?php if (!empty($contest['clip_start_time']) || !empty($contest['clip_end_time'])): ?>
              <div class="d-flex gap-3 mb-2">
                <?php if (!empty($contest['clip_start_time'])): ?>
                  <span class="requirement-badge">▶ Start: <?= e($contest['clip_start_time']) ?></span>
                <?php endif; ?>
                <?php if (!empty($contest['clip_end_time'])): ?>
                  <span class="requirement-badge">⏹ End: <?= e($contest['clip_end_time']) ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if (!empty($contest['clip_instructions'])): ?>
              <p class="text-muted mb-0" style="font-size:0.9rem"><?= nl2br(e($contest['clip_instructions'])) ?></p>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- Requirements -->
        <?php if ($contest['must_subscribe'] || $contest['must_like'] || $contest['must_comment']): ?>
          <div class="card-dark p-3 mb-4">
            <h6 class="fw-700 mb-3">✅ Requirements</h6>
            <div class="d-flex flex-wrap gap-2">
              <?php if ($contest['must_subscribe']): ?>
                <span class="requirement-badge requirement-badge--required">Must Subscribe</span>
              <?php endif; ?>
              <?php if ($contest['must_like']): ?>
                <span class="requirement-badge requirement-badge--required">Must Like</span>
              <?php endif; ?>
              <?php if ($contest['must_comment']): ?>
                <span class="requirement-badge requirement-badge--required">Must Comment</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Prize Breakdown -->
        <?php if (!empty($platforms)): ?>
          <div class="card-dark p-3 mb-4">
            <h6 class="fw-700 mb-3">🏆 Prize Breakdown</h6>
            <div class="row g-2">
              <?php foreach ($platforms as $p): ?>
                <?php
                  $pIcon = getPlatformIcon($p['platform'], '1.5rem');
                  $perWinner = $p['winner_count'] > 0
                      ? number_format((float)$p['prize_amount'] / (int)$p['winner_count'], 0)
                      : '0';
                ?>
                <div class="col-md-4">
                  <div style="background:var(--input-bg);border:1px solid var(--card-border);border-radius:8px;padding:12px;text-align:center">
                    <div style="font-size:1.5rem"><?= $pIcon ?></div>
                    <div class="fw-700" style="font-size:0.85rem"><?= ucfirst(e($p['platform'])) ?></div>
                    <div style="color:var(--accent);font-weight:700">₦<?= number_format((float)$p['prize_amount'], 0) ?></div>
                    <div class="text-muted" style="font-size:0.75rem">~₦<?= $perWinner ?>/winner · <?= (int)$p['winner_count'] ?> winners</div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Leaderboard Tabs -->
        <?php if (!empty($platforms)): ?>
          <div class="card-dark p-3">
            <h6 class="fw-700 mb-3">📊 Leaderboard</h6>
            <ul class="nav nav-tabs mb-3" id="lbTabs" style="border-bottom:1px solid var(--border)">
              <?php foreach ($platforms as $idx => $p): ?>
                <?php
                  $pIcon = getPlatformIcon($p['platform'], '1rem');
                ?>
                <li class="nav-item">
                  <button class="nav-link <?= $idx===0?'active':'' ?> text-theme"
                          data-bs-toggle="tab"
                          data-bs-target="#lb-<?= e($p['platform']) ?>"
                          style="background:none;border:none;border-bottom:2px solid transparent;font-size:0.85rem;padding:8px 16px">
                    <?= $pIcon ?> <?= ucfirst(e($p['platform'])) ?>
                  </button>
                </li>
              <?php endforeach; ?>
            </ul>
            <div class="tab-content">
              <?php foreach ($platforms as $idx => $p): ?>
                <?php
                  $lb  = getLeaderboard(db(), $contestId, $p['platform']);
                  $perW = (int)$p['winner_count'] > 0 ? (float)$p['prize_amount'] / (int)$p['winner_count'] : 0;
                ?>
                <div class="tab-pane fade <?= $idx===0?'show active':'' ?>" id="lb-<?= e($p['platform']) ?>">
                  <?php if (empty($lb)): ?>
                    <p class="text-muted text-center py-3" style="font-size:0.85rem">No entries yet. Be the first!</p>
                  <?php else: ?>
                    <div class="leaderboard-table">
                      <?php foreach ($lb as $rank => $row): ?>
                        <?php
                          $isMe     = $isLoggedIn && (int)$row['user_id'] === $userId;
                          $rankNum  = $rank + 1;
                          $rankIcon = match($rankNum) { 1=>'🥇', 2=>'🥈', 3=>'🥉', default=>"#{$rankNum}" };
                          $prizeAmt = $rankNum <= (int)$p['winner_count'] ? '₦' . number_format($perW, 0) : '—';
                        ?>
                        <div class="leaderboard-row <?= $isMe ? 'leaderboard-row--me' : '' ?>">
                          <span class="lb-rank"><?= $rankIcon ?></span>
                          <span class="lb-name">
                            <?= e($row['username']) ?>
                            <?php if ($isMe): ?><span class="badge ms-1" style="background:var(--accent);color:#000;font-size:0.65rem">You</span><?php endif; ?>
                          </span>
                          <span class="lb-stat text-muted"><?= number_format((int)$row['view_count']) ?> views</span>
                          <span class="lb-stat text-muted"><?= number_format((int)$row['like_count']) ?> likes</span>
                          <span class="lb-stat text-muted"><?= number_format((int)$row['comment_count']) ?> comments</span>
                          <span class="lb-prize" style="color:var(--accent)"><?= $prizeAmt ?></span>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Right column: join panel -->
      <div class="col-lg-4">
        <div class="card-dark p-4 sticky-top" style="top:80px">
          <?php if (!$isActive): ?>
            <div class="text-center py-3">
              <div style="font-size:2rem">⚫</div>
              <h6 class="fw-700 mt-2">Contest <?= e(ucfirst($contest['status'])) ?></h6>
              <p class="text-muted" style="font-size:0.85rem">This contest is no longer accepting entries.</p>
            </div>
          <?php elseif (!$isLoggedIn): ?>
            <div class="text-center">
              <div style="font-size:2rem;margin-bottom:12px">✂️</div>
              <h6 class="fw-700 mb-2">Ready to clip?</h6>
              <p class="text-muted mb-3" style="font-size:0.85rem">Create a free account to submit your clip and win.</p>
              <a href="/auth/register" class="btn btn-accent w-100 mb-2">Sign Up Free</a>
              <a href="/auth/login" class="btn btn-outline-accent w-100">Sign In</a>
            </div>
          <?php else: ?>
            <h6 class="fw-700 mb-3">Submit Your Clip</h6>
            <?php if (!$disclaimerAccepted): ?>
            <div class="disclaimer-join-box mb-3" id="joinDisclaimerBox">
              <div class="d-flex align-items-start gap-2 mb-2">
                <span style="font-size:1.1rem">⚠️</span>
                <div>
                  <div class="fw-600" style="font-size:0.82rem;color:var(--warning)">Reward Eligibility Notice</div>
                </div>
              </div>
              <ol style="font-size:0.78rem;line-height:1.6;color:var(--text-secondary);margin:0;padding-left:18px">
                <li style="margin-bottom:4px">Submit a <strong style="color:var(--text)">2-min analytics screen-recording</strong> within 72 hours of contest end to claim your prize. Late submissions forfeit the reward to the next eligible runner-up.</li>
                <li style="margin-bottom:4px"><strong style="color:var(--text)">Comment &amp; like screenshot proof</strong> on the creator's video is mandatory for prize collection.</li>
                <li style="margin-bottom:4px"><strong style="color:var(--text)">No paid promotions</strong> or sponsored boosts on your submitted video — entries with paid reach are ineligible.</li>
                <li style="margin-bottom:4px" style="color:var(--danger)"><strong style="color:var(--danger)">Bot/artificial engagement is strictly prohibited</strong> and results in immediate permanent account suspension with full prize forfeiture.</li>
              </ol>
              <label style="display:flex;align-items:center;gap:8px;margin-top:10px;cursor:pointer;font-size:0.8rem">
                <input type="checkbox" id="agreeToJoin" style="accent-color:var(--accent)">
                <span style="color:var(--text-secondary)">I understand and agree to the contest rules</span>
              </label>
              <button type="button" class="btn btn-accent btn-sm w-100 mt-2" id="proceedJoinBtn" disabled>Proceed to Submit</button>
            </div>
            <div id="clipFormWrapper" style="display:none">
            <?php else: ?>
            <div id="clipFormWrapper">
            <?php endif; ?>
            <?php if (!empty($myEntries)): ?>
              <div class="alert-dark-success mb-3" style="font-size:0.82rem">
                ✅ You've already submitted <?= count($myEntries) ?> clip<?= count($myEntries)!==1?'s':'' ?>.
              </div>
            <?php endif; ?>
            <form id="clipForm" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="contest_id" value="<?= $contestId ?>">
              <div class="mb-3">
                <label class="form-label-dark">Platform</label>
                <select name="platform" class="form-control-dark" id="platformSelect">
                  <option value="">Select platform</option>
                  <?php foreach ($platforms as $p): ?>
                    <?php
                      $alreadySubmitted = isset($myEntries[$p['platform']]);
                    ?>
                    <option value="<?= e($p['platform']) ?>" <?= $alreadySubmitted ? 'disabled' : '' ?>>
                      <?= ucfirst(e($p['platform'])) ?> <?= $alreadySubmitted ? '(submitted)' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label-dark">Clip URL</label>
                <input type="url" name="clip_url" class="form-control-dark" placeholder="https://www.tiktok.com/..." id="clipUrl">
                <small class="text-muted" style="font-size:0.75rem">Must be a direct link to your clip on the selected platform</small>
              </div>
              <div class="mb-3">
                <label class="form-label-dark">Your YouTube Handle <span class="text-muted">(for verification)</span></label>
                <input type="text" name="youtube_handle" class="form-control-dark" placeholder="@yourhandle">
              </div>

              <?php if ($contest['must_subscribe'] || $contest['must_like'] || $contest['must_comment']): ?>
              <div class="mb-3">
                <div class="fw-600 mb-2" style="font-size:0.85rem">📎 Proof Screenshots</div>
                <small class="text-muted d-block mb-2" style="font-size:0.75rem">Max 5MB per image. Uploading all proofs may speed up approval.</small>
                <?php if ($contest['must_subscribe']): ?>
                <div class="mb-2">
                  <label class="form-label-dark" style="font-size:0.8rem">Screenshot proof of subscription</label>
                  <input type="file" name="proof_subscribe" class="form-control-dark" accept="image/*">
                </div>
                <?php endif; ?>
                <?php if ($contest['must_like']): ?>
                <div class="mb-2">
                  <label class="form-label-dark" style="font-size:0.8rem">Screenshot proof of like</label>
                  <input type="file" name="proof_like" class="form-control-dark" accept="image/*">
                </div>
                <?php endif; ?>
                <?php if ($contest['must_comment']): ?>
                <div class="mb-2">
                  <label class="form-label-dark" style="font-size:0.8rem">Screenshot proof of comment</label>
                  <input type="file" name="proof_comment" class="form-control-dark" accept="image/*">
                </div>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <?php if (!$hasBankDetails): ?>
              <div class="mb-3">
                <button type="button" class="btn btn-sm btn-outline-accent w-100 mb-2" id="toggleBankDetails"
                        style="font-size:0.8rem">
                  💳 Add Payment Details (optional)
                </button>
                <div id="bankDetailsSection" style="display:none">
                  <p class="text-muted mb-2" style="font-size:0.75rem">These details will be used to pay you if you win.</p>
                  <div class="mb-2">
                    <label class="form-label-dark" style="font-size:0.8rem">Bank</label>
                    <select name="bank_code" id="bankSelectContest" class="form-control-dark">
                      <option value="">Loading banks…</option>
                    </select>
                  </div>
                  <div class="mb-2">
                    <label class="form-label-dark" style="font-size:0.8rem">Account Number</label>
                    <div class="d-flex gap-2">
                      <input type="text" name="account_number" id="contestAcctNum" class="form-control-dark flex-grow-1"
                             maxlength="10" placeholder="0000000000" inputmode="numeric">
                      <button type="button" class="btn btn-outline-accent btn-sm" id="contestVerifyBtn" style="white-space:nowrap;font-size:0.8rem">Verify</button>
                    </div>
                  </div>
                  <div class="mb-2" id="contestAcctNameWrap" style="display:none">
                    <label class="form-label-dark" style="font-size:0.8rem">Account Name</label>
                    <input type="text" name="account_name" id="contestAcctName" class="form-control-dark" readonly>
                    <input type="hidden" name="bank_name" id="contestBankName">
                  </div>
                </div>
              </div>
              <?php endif; ?>

              <div id="submitFeedback" class="mb-2"></div>
              <div class="mb-3">
                <label class="form-label-dark" style="font-size:0.85rem">📹 Analytics Video Proof <span style="color:var(--warning);font-size:0.75rem">(Required to claim prize)</span></label>
                <input type="file" name="proof_video" class="form-control-dark" accept="video/mp4,video/webm,video/quicktime">
                <small class="text-muted d-block mt-1" style="font-size:0.73rem">
                  Record a 2-minute screen video showing your authentic video analytics (views, likes, comments). Max 50MB. MP4/WebM/MOV. You can submit without it now and upload later when claiming your prize.
                </small>
              </div>
              <button type="submit" class="btn btn-accent w-100" id="submitClipBtn">Submit Clip</button>
            </form>
            </div><!-- end clipFormWrapper -->
          <?php endif; ?>
        </div>
      </div>
    </div>
      </div><!-- end main col -->

      <?php if (!empty($sidebarFeatured)): ?>
      <div class="col-lg-4">
        <div class="featured-sidebar-widget" style="position:sticky;top:80px">
          <div class="d-flex align-items-center gap-2 mb-3">
            <span style="font-size:1.1rem">⭐</span>
            <span class="fw-700" style="font-size:0.95rem">Featured Contests</span>
          </div>
          <div class="featured-sidebar-slider" id="featuredSidebarSlider">
            <?php foreach ($sidebarFeatured as $sf): ?>
              <?php
                $sfTime = '';
                if (!empty($sf['end_date'])) {
                    $secs = strtotime($sf['end_date']) - time();
                    if ($secs > 0) {
                        $d = floor($secs / 86400);
                        $h = floor(($secs % 86400) / 3600);
                        $sfTime = $d > 0 ? "{$d}d {$h}h left" : "{$h}h left";
                    }
                }
              ?>
              <div class="featured-sidebar-card">
                <?php if (!empty($sf['youtube_thumbnail'])): ?>
                  <img src="<?= e($sf['youtube_thumbnail']) ?>" alt="<?= e($sf['title']) ?>" class="featured-sidebar-thumb">
                <?php else: ?>
                  <div class="featured-sidebar-thumb featured-sidebar-thumb--placeholder"><span>🎬</span></div>
                <?php endif; ?>
                <div class="featured-sidebar-info">
                  <div class="fw-600 mb-1" style="font-size:0.82rem;line-height:1.3"><?= e($sf['title']) ?></div>
                  <div class="d-flex align-items-center justify-content-between">
                    <span style="font-size:0.78rem;color:var(--accent);font-weight:700">₦<?= number_format((float)$sf['prize_pool'], 0) ?></span>
                    <?php if ($sfTime): ?><span class="text-muted" style="font-size:0.72rem"><?= e($sfTime) ?></span><?php endif; ?>
                  </div>
                  <a href="/contest?id=<?= (int)$sf['id'] ?>" class="btn btn-xs btn-outline-accent mt-2 d-block text-center">Enter Now</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if (count($sidebarFeatured) > 1): ?>
          <div class="featured-sidebar-dots" id="sidebarDots">
            <?php for ($i = 0; $i < count($sidebarFeatured); $i++): ?>
              <button class="sidebar-dot <?= $i === 0 ? 'active' : '' ?>" data-index="<?= $i ?>"></button>
            <?php endfor; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- end row -->
  </div>
</div>

<script>
document.getElementById('clipForm')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = document.getElementById('submitClipBtn');
  const fb  = document.getElementById('submitFeedback');
  btn.disabled = true;
  btn.textContent = 'Submitting…';
  fb.innerHTML = '';

  const data = new FormData(this);
  data.set('action', 'submit_clip');

  try {
    const r = await fetch('/ajax/contest_actions.php', { method: 'POST', body: data });
    const d = await r.json();
    if (d.success) {
      fb.innerHTML = '<div class="alert-dark-success" style="font-size:0.82rem">✅ ' + d.message + '</div>';
      btn.textContent = 'Submitted!';
      setTimeout(() => location.reload(), 1500);
    } else {
      fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">' + d.message + '</div>';
      btn.disabled = false;
      btn.textContent = 'Submit Clip';
    }
  } catch {
    fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">Network error. Please try again.</div>';
    btn.disabled = false;
    btn.textContent = 'Submit Clip';
  }
});

// Validate clip URL matches platform
document.getElementById('platformSelect')?.addEventListener('change', function() {
  const url = document.getElementById('clipUrl');
  url.placeholder = {
    tiktok: 'https://www.tiktok.com/...',
    instagram: 'https://www.instagram.com/...',
    facebook: 'https://www.facebook.com/...',
  }[this.value] || 'https://...';
});

// Toggle bank details section
document.getElementById('toggleBankDetails')?.addEventListener('click', function() {
  const sec = document.getElementById('bankDetailsSection');
  sec.style.display = sec.style.display === 'none' ? 'block' : 'none';
  this.textContent = sec.style.display === 'none' ? '💳 Add Payment Details (optional)' : '💳 Hide Payment Details';
  if (sec.style.display !== 'none' && document.getElementById('bankSelectContest').options.length <= 1) {
    loadBanksForContest();
  }
});

async function loadBanksForContest() {
  try {
    const r = await fetch('/ajax/payout_actions.php?action=get_banks');
    const d = await r.json();
    const sel = document.getElementById('bankSelectContest');
    sel.innerHTML = '<option value="">Select bank</option>';
    if (d.success && d.banks) {
      d.banks.forEach(b => {
        const o = document.createElement('option');
        o.value = b.code; o.textContent = b.name;
        sel.appendChild(o);
      });
    }
  } catch {}
}

document.getElementById('contestVerifyBtn')?.addEventListener('click', async function() {
  const acctNum  = document.getElementById('contestAcctNum').value.trim();
  const bankCode = document.getElementById('bankSelectContest').value;
  const csrf     = document.querySelector('[name="csrf_token"]').value;
  if (!/^\d{10}$/.test(acctNum)) { alert('Account number must be 10 digits.'); return; }
  if (!bankCode) { alert('Please select a bank.'); return; }
  this.disabled = true; this.textContent = 'Verifying…';
  try {
    const fd = new FormData();
    fd.append('action', 'verify_account');
    fd.append('account_number', acctNum);
    fd.append('bank_code', bankCode);
    fd.append('csrf_token', csrf);
    const r = await fetch('/ajax/payout_actions.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) {
      document.getElementById('contestAcctName').value = d.account_name;
      const sel = document.getElementById('bankSelectContest');
      document.getElementById('contestBankName').value = sel.options[sel.selectedIndex]?.textContent || '';
      document.getElementById('contestAcctNameWrap').style.display = 'block';
    } else {
      alert(d.message || 'Verification failed.');
    }
  } catch { alert('Network error.'); }
  this.disabled = false; this.textContent = 'Verify';
});
</script>

<script>
(function() {
  const slider = document.getElementById('featuredSidebarSlider');
  if (!slider) return;
  const cards = slider.querySelectorAll('.featured-sidebar-card');
  if (cards.length <= 1) return;
  const dots = document.querySelectorAll('.sidebar-dot');
  let current = 0;
  let timer;

  function show(idx) {
    current = (idx + cards.length) % cards.length;
    cards.forEach((c, i) => c.classList.toggle('active', i === current));
    dots.forEach((d, i) => d.classList.toggle('active', i === current));
  }

  function startAuto() {
    timer = setInterval(() => show(current + 1), 4000);
  }

  show(0);
  startAuto();

  dots.forEach((dot, i) => {
    dot.addEventListener('click', () => {
      clearInterval(timer);
      show(i);
      startAuto();
    });
  });

  let startX = 0;
  slider.addEventListener('touchstart', e => { startX = e.touches[0].clientX; }, {passive: true});
  slider.addEventListener('touchend', e => {
    const diff = startX - e.changedTouches[0].clientX;
    if (Math.abs(diff) > 40) {
      clearInterval(timer);
      show(diff > 0 ? current + 1 : current - 1);
      startAuto();
    }
  }, {passive: true});
})();
</script>

<script>
// Join disclaimer logic
document.getElementById('agreeToJoin')?.addEventListener('change', function() {
  document.getElementById('proceedJoinBtn').disabled = !this.checked;
});
document.getElementById('proceedJoinBtn')?.addEventListener('click', async function() {
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
  try {
    await fetch('/ajax/disclaimer_actions.php', {
      method: 'POST',
      body: new URLSearchParams({action: 'accept_disclaimer', csrf_token: csrf})
    });
  } catch {}
  document.getElementById('joinDisclaimerBox').style.display = 'none';
  document.getElementById('clipFormWrapper').style.display = 'block';
});
</script>

<?php renderFooter(); ?>
