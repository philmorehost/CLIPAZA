<?php
declare(strict_types=1);

$root = __DIR__;
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$isLoggedIn = !empty($_SESSION['user_id']);
$username   = $_SESSION['username'] ?? '';
$userMode   = getUserMode();
$siteName   = getSetting('site_name', 'Clipaza');
$siteUrl    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'clipaza.com');

$extraHead = '
  <meta name="description" content="Learn about ' . e($siteName) . ' — the platform that connects YouTube creators with their most passionate fans through clipping contests and real cash prizes.">
  <link rel="canonical" href="' . $siteUrl . '/about">';
renderHead('About', $extraHead);
renderNav($isLoggedIn, ['username' => $username], $userMode);
?>

<?php
$dynamicAbout = getSetting('page_about', '');
if ($dynamicAbout !== ''):
?>
<div class="public-page">
  <div class="container py-5">
    <?= $dynamicAbout ?>
  </div>
</div>
<?php else: ?>
<div class="public-page">
  <div class="container py-5" style="max-width:820px">
    <h1 class="fw-900 mb-2" style="letter-spacing:-0.5px">About <?= e($siteName) ?></h1>
    <p class="text-muted mb-5" style="font-size:1rem;line-height:1.8">Where creators reward their biggest fans.</p>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">The Idea</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        <?= e($siteName) ?> started with a simple observation: fans already know a creator's content better than anyone. They know which moment will make someone stop scrolling. They know which clip will spread. Why not give them a contest to enter — and a prize to chase — while the creator's best content reaches TikTok and Instagram Reels at the same time?
      </p>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        That's <?= e($siteName) ?>. A contest platform built for creators who want organic reach and for fans who want to turn a good eye into real money.
      </p>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">How It Works</h2>
      <div class="d-flex flex-column gap-3">
        <div class="d-flex gap-3 align-items-start">
          <div style="font-size:1.5rem;min-width:2rem;line-height:1">🎬</div>
          <div>
            <div class="fw-700 mb-1" style="font-size:0.95rem">Creator launches a contest</div>
            <p class="text-muted mb-0" style="font-size:0.88rem;line-height:1.7">A YouTube creator picks a video, sets a prize pool, and opens it to their fanbase. The contest goes live on <?= e($siteName) ?> and anyone can join.</p>
          </div>
        </div>
        <div class="d-flex gap-3 align-items-start">
          <div style="font-size:1.5rem;min-width:2rem;line-height:1">✂️</div>
          <div>
            <div class="fw-700 mb-1" style="font-size:0.95rem">Fans clip and post</div>
            <p class="text-muted mb-0" style="font-size:0.88rem;line-height:1.7">Fans find the moment that's going to stop people mid-scroll, cut it, post it to TikTok or Reels, then submit the link.</p>
          </div>
        </div>
        <div class="d-flex gap-3 align-items-start">
          <div style="font-size:1.5rem;min-width:2rem;line-height:1">🏆</div>
          <div>
            <div class="fw-700 mb-1" style="font-size:0.95rem">Views decide the winner</div>
            <p class="text-muted mb-0" style="font-size:0.88rem;line-height:1.7">Every clip sits on a live leaderboard. Only authentic views from real users count. No bots, no purchased traffic. When the contest closes, the honest number at the top wins.</p>
          </div>
        </div>
      </div>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">Our Values</h2>
      <ul class="text-muted" style="font-size:0.9rem;line-height:2">
        <li><strong>Authenticity first</strong> — only real views win. We actively detect and disqualify artificial engagement.</li>
        <li><strong>Fair play</strong> — no judges, no panels, no back-room decisions. The leaderboard is the judge.</li>
        <li><strong>Transparent payouts</strong> — winners receive their prize via bank transfer. We publish our fee structure upfront.</li>
        <li><strong>Creator control</strong> — creators can flag clips that don't fit their brand at any point during the contest.</li>
      </ul>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">Get Involved</h2>
      <p class="text-muted mb-4" style="font-size:0.9rem;line-height:1.8">
        Whether you're a creator looking for organic reach across three platforms at once, or a fan who wants to turn a good eye and a phone that works into cash — there's a spot for you here.
      </p>
      <div class="d-flex gap-3 flex-wrap">
        <a href="/auth/register" class="btn btn-accent">Start a Contest →</a>
        <a href="/contests" class="btn btn-outline-accent">Browse Contests →</a>
      </div>
    </div>

  </div>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
