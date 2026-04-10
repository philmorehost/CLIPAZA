<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireCreatorMode();

$userId    = (int)$_SESSION['user_id'];
$username  = $_SESSION['username'] ?? '';
$contestId = (int)($_GET['contest_id'] ?? 0);

if ($contestId <= 0) redirect('/dashboard');

$contest = null;
try {
    $db   = db();
    $stmt = $db->prepare("SELECT * FROM contests WHERE id = ? AND creator_id = ? LIMIT 1");
    $stmt->execute([$contestId, $userId]);
    $contest = $stmt->fetch();
} catch (Throwable) {}

if (!$contest) redirect('/dashboard');
if ($contest['escrow_status'] === 'funded') redirect('/dashboard?success=contest_funded');

$platforms = [];
try {
    $db   = db();
    $stmt = $db->prepare('SELECT * FROM contest_platforms WHERE contest_id = ?');
    $stmt->execute([$contestId]);
    $platforms = $stmt->fetchAll();
} catch (Throwable) {}

$csrf = generateCsrfToken();
renderHead('Fund Contest');
renderNav(true, ['username' => $username], 'creator');
?>

<div class="public-page">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-lg-6">
        <div class="text-center mb-4">
          <div style="font-size:2.5rem;margin-bottom:8px">💳</div>
          <h3 class="fw-900 mb-1" style="letter-spacing:-0.5px">Fund Your Contest</h3>
          <p class="text-muted" style="font-size:0.9rem">Your contest will go live as soon as payment is confirmed.</p>
        </div>

        <!-- Contest summary -->
        <div class="card-dark p-4 mb-4">
          <?php if (!empty($contest['youtube_thumbnail'])): ?>
            <img src="<?= e($contest['youtube_thumbnail']) ?>" alt="" class="rounded mb-3 w-100" style="height:160px;object-fit:cover">
          <?php endif; ?>
          <h5 class="fw-700 mb-2"><?= e($contest['title']) ?></h5>
          <?php if (!empty($contest['end_date'])): ?>
            <p class="text-muted mb-3" style="font-size:0.85rem">Ends: <?= formatDate($contest['end_date']) ?></p>
          <?php endif; ?>

          <!-- Platform breakdown -->
          <?php if (!empty($platforms)): ?>
            <div class="mb-3">
              <?php foreach ($platforms as $p): ?>
                <?php $pIcon = match($p['platform']) { 'tiktok'=>'🎵','instagram'=>'📸','facebook'=>'📘',default=>'🎬' }; ?>
                <div class="d-flex justify-content-between py-2" style="border-bottom:1px solid #1a1a1a;font-size:0.88rem">
                  <span><?= $pIcon ?> <?= ucfirst(e($p['platform'])) ?> (<?= (int)$p['winner_count'] ?> winners)</span>
                  <span class="fw-600">₦<?= number_format((float)$p['prize_amount'], 0) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <!-- Payment breakdown -->
          <div class="p-3" style="background:#0d0d0d;border-radius:8px">
            <div class="d-flex justify-content-between mb-1" style="font-size:0.88rem">
              <span class="text-muted">Prize Pool</span>
              <span>₦<?= number_format((float)$contest['prize_pool'], 0) ?></span>
            </div>
            <div class="d-flex justify-content-between mb-2" style="font-size:0.88rem">
              <span class="text-muted">Platform Fee</span>
              <span>₦<?= number_format((float)$contest['platform_fee'], 0) ?></span>
            </div>
            <div class="d-flex justify-content-between" style="font-size:1.05rem;font-weight:700;color:var(--accent)">
              <span>Total to Pay</span>
              <span>₦<?= number_format((float)$contest['total_amount'], 0) ?></span>
            </div>
          </div>
        </div>

        <div id="payFeedback" class="mb-3"></div>

        <button class="btn btn-accent w-100 py-3" id="payBtn" style="font-size:1rem;font-weight:700">
          Pay ₦<?= number_format((float)$contest['total_amount'], 0) ?> to Activate Contest
        </button>
        <p class="text-center text-muted mt-3" style="font-size:0.78rem">
          Secured via Paystack. Your funds are held in escrow until the contest ends.
        </p>
        <div class="text-center mt-2">
          <a href="/dashboard" class="text-muted text-decoration-none" style="font-size:0.82rem">← Back to Dashboard</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('payBtn').addEventListener('click', async function() {
  const btn = this;
  const fb  = document.getElementById('payFeedback');
  btn.disabled = true;
  btn.textContent = 'Initializing payment…';
  fb.innerHTML = '';

  try {
    const r = await fetch('/ajax/payment_actions.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        action: 'init_payment',
        contest_id: <?= $contestId ?>,
        csrf_token: <?= json_encode(generateCsrfToken()) ?>
      })
    });
    const d = await r.json();
    if (d.success && d.authorization_url) {
      window.location.href = d.authorization_url;
    } else {
      fb.innerHTML = '<div class="alert-dark-danger">' + (d.message || 'Payment initialization failed.') + '</div>';
      btn.disabled = false;
      btn.textContent = 'Pay ₦<?= number_format((float)$contest['total_amount'], 0) ?> to Activate Contest';
    }
  } catch {
    fb.innerHTML = '<div class="alert-dark-danger">Network error. Please try again.</div>';
    btn.disabled = false;
    btn.textContent = 'Pay ₦<?= number_format((float)$contest['total_amount'], 0) ?> to Activate Contest';
  }
});
</script>

<?php renderFooter(); ?>
