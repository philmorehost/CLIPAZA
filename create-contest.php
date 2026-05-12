<?php
declare(strict_types=1);

$root = __DIR__;
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireCreatorMode();

$userId   = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
        $title            = sanitizeInput($_POST['title'] ?? '');
        $youtubeUrl       = sanitizeInput($_POST['youtube_url'] ?? '');
        $youtubeVideoId   = sanitizeInput($_POST['youtube_video_id'] ?? '');
        $youtubeTitle     = sanitizeInput($_POST['youtube_title'] ?? '');
        $youtubeThumbnail = sanitizeInput($_POST['youtube_thumbnail'] ?? '');
        $mustSubscribe    = !empty($_POST['must_subscribe']) ? 1 : 0;
        $mustLike         = !empty($_POST['must_like']) ? 1 : 0;
        $mustComment      = !empty($_POST['must_comment']) ? 1 : 0;
        $clipStart        = sanitizeInput($_POST['clip_start_time'] ?? '');
        $clipEnd          = sanitizeInput($_POST['clip_end_time'] ?? '');
        $clipInstructions = sanitizeInput($_POST['clip_instructions'] ?? '');
        $endDate          = sanitizeInput($_POST['end_date'] ?? '');

        // Platform prizes
        $platformData = [];
        $totalPrize   = 0.0;
        foreach (['tiktok', 'instagram', 'facebook'] as $p) {
            if (!empty($_POST['enable_' . $p])) {
                $amt    = (float)($_POST[$p . '_prize'] ?? 0);
                $count  = min(10, max(1, (int)($_POST[$p . '_winners'] ?? 3)));
                if ($amt > 0) {
                    $platformData[$p] = ['amount' => $amt, 'winners' => $count];
                    $totalPrize += $amt;
                }
            }
        }

        // Validations
        if (empty($title))                         $errors[] = 'Contest title is required.';
        if (empty($youtubeUrl))                    $errors[] = 'YouTube URL is required.';
        if (!preg_match('/^https?:\/\/(www\.)?(youtube\.com|youtu\.be)\//i', $youtubeUrl)) $errors[] = 'Please enter a valid YouTube URL.';
        if (empty($platformData))                  $errors[] = 'Enable at least one platform with a prize amount.';
        if (empty($endDate))                       $errors[] = 'End date is required.';

        $minPrize = (float)(function_exists('getSetting') ? getSetting('min_contest_prize', '5000') : 5000);
        $maxDays  = (int)(function_exists('getSetting') ? getSetting('max_contest_days', '30') : 30);

        if ($totalPrize < $minPrize)               $errors[] = 'Minimum total prize pool is ₦' . number_format($minPrize, 0) . '.';

        $endTs = strtotime($endDate);
        if (!$endTs || $endTs <= time())           $errors[] = 'End date must be in the future.';
        elseif ($endTs > strtotime("+{$maxDays} days")) $errors[] = "End date cannot be more than {$maxDays} days from now.";

        if (empty($errors)) {
            try {
                $db = db();
                $feePercent  = (float)getSetting('platform_fee_percent', '10');
                $platformFee = round($totalPrize * $feePercent / 100, 2);
                $totalAmount = round($totalPrize + $platformFee, 2);

                $stmt = $db->prepare(
                    "INSERT INTO contests
                        (creator_id, title, description, youtube_url, youtube_video_id, youtube_title,
                         youtube_thumbnail, must_subscribe, must_like, must_comment,
                         clip_start_time, clip_end_time, clip_instructions,
                         prize_pool, platform_fee, total_amount, status, escrow_status, end_date)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft','unfunded',?)"
                );
                $stmt->execute([
                    $userId, $title, '', $youtubeUrl, $youtubeVideoId, $youtubeTitle,
                    $youtubeThumbnail, $mustSubscribe, $mustLike, $mustComment,
                    $clipStart ?: null, $clipEnd ?: null, $clipInstructions ?: null,
                    $totalPrize, $platformFee, $totalAmount,
                    $endDate,
                ]);
                $contestId = (int)$db->lastInsertId();

                foreach ($platformData as $platform => $pData) {
                    $db->prepare(
                        'INSERT INTO contest_platforms (contest_id, platform, prize_amount, winner_count) VALUES (?,?,?,?)'
                    )->execute([$contestId, $platform, $pData['amount'], $pData['winners']]);
                }

                redirect('/payment/fund-contest?contest_id=' . $contestId);
            } catch (Throwable $e) {
                $errors[] = 'Failed to create contest. Please try again.';
            }
        }
    }
}

$csrf     = generateCsrfToken();
$feePercent = function_exists('getSetting') ? (float)getSetting('platform_fee_percent', '10') : 10.0;
$minPrize = function_exists('getSetting') ? (float)getSetting('min_contest_prize', '5000') : 5000.0;
$maxDays  = function_exists('getSetting') ? (int)getSetting('max_contest_days', '30') : 30;
$minDate  = date('Y-m-d\TH:i', strtotime('+1 day'));
$maxDate  = date('Y-m-d\TH:i', strtotime("+{$maxDays} days"));

renderHead('Create Contest');
renderNav(true, ['username' => $username], 'creator');
?>

<div class="public-page">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="d-flex align-items-center gap-3 mb-4">
          <a href="/dashboard" class="text-muted text-decoration-none" style="font-size:0.85rem">← Back</a>
          <h3 class="fw-900 mb-0" style="letter-spacing:-0.5px">Create Contest</h3>
        </div>

        <?php foreach ($errors as $err): ?>
          <div class="alert-dark-danger mb-3"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="POST" id="createContestForm" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="youtube_video_id" id="youtubeVideoId">
          <input type="hidden" name="youtube_title" id="youtubeVideoTitle">
          <input type="hidden" name="youtube_thumbnail" id="youtubeVideoThumb">

          <!-- Section 1: YouTube Video -->
          <div class="card-dark p-4 mb-4">
            <h6 class="fw-700 mb-3">1. YouTube Video</h6>
            <div class="mb-3">
              <label class="form-label-dark">Contest Title *</label>
              <input type="text" name="title" class="form-control-dark" placeholder="e.g. Best clips of my latest video" maxlength="255" required>
            </div>
            <div class="mb-3">
              <label class="form-label-dark">YouTube URL *</label>
              <div class="d-flex gap-2">
                <input type="url" name="youtube_url" id="youtubeUrl" class="form-control-dark flex-grow-1" placeholder="https://www.youtube.com/watch?v=..." required>
                <button type="button" class="btn btn-outline-accent" id="fetchYtBtn" style="white-space:nowrap">Fetch Info</button>
              </div>
            </div>
            <div id="ytPreview" class="d-none">
              <div class="d-flex gap-3 align-items-center p-3" style="background:#0d0d0d;border:1px solid #222;border-radius:8px">
                <img id="ytThumb" src="" alt="" style="width:80px;height:56px;object-fit:cover;border-radius:6px">
                <div>
                  <div id="ytTitleText" class="fw-600" style="font-size:0.9rem"></div>
                  <div class="text-muted" style="font-size:0.78rem">Video found ✅</div>
                </div>
              </div>
            </div>
            <div id="ytError" class="text-danger" style="font-size:0.82rem;display:none"></div>
          </div>

          <!-- Section 2: Requirements -->
          <div class="card-dark p-4 mb-4">
            <h6 class="fw-700 mb-3">2. Viewer Requirements</h6>
            <p class="text-muted mb-3" style="font-size:0.85rem">Clippers must verify these before submitting.</p>
            <div class="d-flex flex-wrap gap-3">
              <?php foreach (['must_subscribe' => 'Must Subscribe', 'must_like' => 'Must Like', 'must_comment' => 'Must Comment'] as $name => $label): ?>
                <label class="d-flex align-items-center gap-2 cursor-pointer">
                  <input type="checkbox" name="<?= $name ?>" class="form-check-input" style="background:#111;border-color:#555;width:18px;height:18px">
                  <span style="font-size:0.9rem"><?= $label ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Section 3: Clip Instructions -->
          <div class="card-dark p-4 mb-4">
            <h6 class="fw-700 mb-3">3. Clip Instructions</h6>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label-dark">Start Time <span class="text-muted">(MM:SS)</span></label>
                <input type="text" name="clip_start_time" class="form-control-dark" placeholder="0:00" pattern="\d+:\d{2}">
              </div>
              <div class="col-md-6">
                <label class="form-label-dark">End Time <span class="text-muted">(MM:SS)</span></label>
                <input type="text" name="clip_end_time" class="form-control-dark" placeholder="5:00" pattern="\d+:\d{2}">
              </div>
              <div class="col-12">
                <label class="form-label-dark">Instructions for Clippers</label>
                <textarea name="clip_instructions" class="form-control-dark" rows="3" placeholder="Describe what makes a great clip for this contest…"></textarea>
              </div>
            </div>
          </div>

          <!-- Section 4: Prize Pool -->
          <div class="card-dark p-4 mb-4">
            <h6 class="fw-700 mb-3">4. Prize Pool</h6>
            <p class="text-muted mb-3" style="font-size:0.85rem">Enable platforms and set prize amounts. Minimum total: ₦<?= number_format($minPrize, 0) ?>.</p>

            <?php foreach (['tiktok' => ['🎵','TikTok'], 'instagram' => ['📸','Instagram'], 'facebook' => ['📘','Facebook']] as $pKey => [$icon, $label]): ?>
              <div class="mb-3 p-3" style="background:#0d0d0d;border:1px solid #1a1a1a;border-radius:8px" id="block_<?= $pKey ?>">
                <div class="d-flex align-items-center gap-2 mb-2">
                  <input type="checkbox" name="enable_<?= $pKey ?>" id="enable_<?= $pKey ?>"
                         class="form-check-input platform-toggle" data-platform="<?= $pKey ?>"
                         style="background:#111;border-color:#555;width:18px;height:18px">
                  <label for="enable_<?= $pKey ?>" class="fw-600" style="cursor:pointer"><?= $icon ?> <?= $label ?></label>
                </div>
                <div class="row g-2 platform-fields" id="fields_<?= $pKey ?>" style="display:none">
                  <div class="col-md-6">
                    <label class="form-label-dark" style="font-size:0.8rem">Prize Amount (₦)</label>
                    <input type="number" name="<?= $pKey ?>_prize" class="form-control-dark prize-input" data-platform="<?= $pKey ?>"
                           min="0" step="100" placeholder="0">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label-dark" style="font-size:0.8rem">Number of Winners</label>
                    <input type="number" name="<?= $pKey ?>_winners" class="form-control-dark"
                           min="1" max="10" value="3">
                  </div>
                </div>
              </div>
            <?php endforeach; ?>

            <div class="mt-3 p-3" style="background:var(--accent-dim);border:1px solid rgba(204,255,0,0.2);border-radius:8px">
              <div class="d-flex justify-content-between mb-1" style="font-size:0.88rem">
                <span class="text-muted">Total Prize Pool</span>
                <span class="fw-700" id="totalPrize">₦0</span>
              </div>
              <div class="d-flex justify-content-between mb-1" style="font-size:0.88rem">
                <span class="text-muted">Platform Fee (<?= $feePercent ?>%)</span>
                <span id="platformFee">₦0</span>
              </div>
              <div class="d-flex justify-content-between" style="font-size:0.95rem;font-weight:700;color:var(--accent)">
                <span>Total to Pay</span>
                <span id="totalToPay">₦0</span>
              </div>
            </div>
          </div>

          <!-- Section 5: End Date -->
          <div class="card-dark p-4 mb-4">
            <h6 class="fw-700 mb-3">5. Contest Duration</h6>
            <div class="mb-3">
              <label class="form-label-dark">End Date &amp; Time *</label>
              <input type="datetime-local" name="end_date" class="form-control-dark"
                     min="<?= e($minDate) ?>" max="<?= e($maxDate) ?>" required>
              <small class="text-muted" style="font-size:0.78rem">Contest runs 1–<?= $maxDays ?> days from now</small>
            </div>
          </div>

          <button type="submit" class="btn btn-accent w-100 py-3" style="font-size:1rem;font-weight:700">
            Continue to Payment →
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
const FEE_PCT = <?= $feePercent ?> / 100;

// Platform toggles
document.querySelectorAll('.platform-toggle').forEach(cb => {
  cb.addEventListener('change', function() {
    const fields = document.getElementById('fields_' + this.dataset.platform);
    fields.style.display = this.checked ? 'flex' : 'none';
    recalcPrize();
  });
});

// Prize calc
document.querySelectorAll('.prize-input').forEach(inp => {
  inp.addEventListener('input', recalcPrize);
});

function recalcPrize() {
  let total = 0;
  document.querySelectorAll('.platform-toggle:checked').forEach(cb => {
    const v = parseFloat(document.querySelector('[name="' + cb.dataset.platform + '_prize"]')?.value) || 0;
    total += v;
  });
  const fee = Math.round(total * FEE_PCT);
  const grand = total + fee;
  document.getElementById('totalPrize').textContent = '₦' + total.toLocaleString();
  document.getElementById('platformFee').textContent = '₦' + fee.toLocaleString();
  document.getElementById('totalToPay').textContent  = '₦' + grand.toLocaleString();
}

// YouTube fetch
document.getElementById('fetchYtBtn').addEventListener('click', async function() {
  const url = document.getElementById('youtubeUrl').value.trim();
  if (!url) return;
  this.disabled = true;
  this.textContent = 'Fetching…';
  document.getElementById('ytError').style.display = 'none';

  try {
    const r = await fetch('/ajax/contest_actions.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ action: 'fetch_youtube_info', youtube_url: url, csrf_token: <?= json_encode(generateCsrfToken()) ?> })
    });
    const d = await r.json();
    if (d.success) {
      document.getElementById('youtubeVideoId').value = d.video_id;
      document.getElementById('youtubeVideoTitle').value = d.title;
      document.getElementById('youtubeVideoThumb').value = d.thumbnail_url;
      document.getElementById('ytTitleText').textContent = d.title;
      document.getElementById('ytThumb').src = d.thumbnail_url;
      document.getElementById('ytPreview').classList.remove('d-none');
      if (!document.querySelector('[name="title"]').value) {
        document.querySelector('[name="title"]').value = d.title;
      }
    } else {
      document.getElementById('ytError').textContent = d.message || 'Could not fetch video info.';
      document.getElementById('ytError').style.display = 'block';
    }
  } catch {
    document.getElementById('ytError').textContent = 'Network error.';
    document.getElementById('ytError').style.display = 'block';
  }

  this.disabled = false;
  this.textContent = 'Fetch Info';
});
</script>

<?php renderFooter(); ?>
