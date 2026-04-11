<?php
declare(strict_types=1);

$root = __DIR__;
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$isLoggedIn = isLoggedIn();
$username   = $_SESSION['username'] ?? ';
$userMode   = getUserMode();

renderHead('Rules & Compliance');
renderNav($isLoggedIn, ['username' => $username], $userMode);
?>

<div class="public-page">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-lg-8">
        <h1 class="fw-900 mb-4" style="letter-spacing:-1px">Rules & <span class="text-accent">Compliance</span></h1>

        <div class="card-dark p-4 mb-4">
          <h4 class="fw-700 mb-3 text-accent">1. For Video Owners (Creators)</h4>
          <ul class="lp-split-list">
            <li><strong>Escrow Guarantee:</strong> Once a contest is funded and live, funds are held in escrow. They cannot be withdrawn by the creator unless no contestants join the contest by the expiration date.</li>
            <li><strong>Transparent Payouts:</strong> The platform automatically identifies winners based on engagement metrics. Creators do not manually select winners to ensure fairness.</li>
            <li><strong>Content Ownership:</strong> Creators retain the right to use clips generated from their videos for promotional purposes.</li>
          </ul>
        </div>

        <div class="card-dark p-4 mb-4">
          <h4 class="fw-700 mb-3 text-accent">2. For Contestants (Clippers)</h4>
          <ul class="lp-split-list">
            <li><strong>No Bot Policy:</strong> Use of view-bots, fake engagement, or any automated systems to inflate stats will result in an immediate and permanent lifetime ban from Clipaza.</li>
            <li><strong>Clip Persistence:</strong> Deleting a submitted clip before the contest officially ends and winners are announced results in automatic disqualification.</li>
            <li><strong>Authentic Engagement:</strong> All required actions (Subscribe, Like, Comment) must remain active for the entire duration of the contest. Reversing these actions before the contest ends will lead to disqualification.</li>
            <li><strong>Platform Rules:</strong> All clips must comply with the terms of service of the platform they are posted on (TikTok, Instagram, Facebook).</li>
          </ul>
        </div>

        <div class="card-dark p-4 mb-4">
          <h4 class="fw-700 mb-3 text-accent">3. Payout & Verification</h4>
          <ul class="lp-split-list">
            <li><strong>Verification Engine:</strong> Our system pings the YouTube API to verify that the user's connected YouTube handle has performed the mandatory requirements.</li>
            <li><strong>Leaderboard Integrity:</strong> Rankings are updated periodically via official social media APIs or manual verification by administrators.</li>
            <li><strong>NUBAN Verification:</strong> To prevent fraud, bank account details are resolved to the account holder's name before disbursements are made.</li>
          </ul>
        </div>

        <div class="text-center mt-5">
          <p class="text-muted" style="font-size:0.9rem">By using Clipaza, you agree to these rules and our full Terms of Service.</p>
          <a href="auth/register" class="btn btn-accent">Start Earning Now</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php renderFooter(); ?>
