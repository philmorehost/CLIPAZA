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
  <meta name="description" content="Read the ' . e($siteName) . ' Terms of Service to understand the rules, rights, and responsibilities that apply when you use our platform.">
  <meta name="robots" content="noindex,follow">
  <link rel="canonical" href="' . $siteUrl . '/terms">';
renderHead('Terms of Service', $extraHead);
renderNav($isLoggedIn, ['username' => $username], $userMode);
?>

<div class="public-page">
  <div class="container py-5" style="max-width:820px">
    <h1 class="fw-900 mb-1" style="letter-spacing:-0.5px">Terms of Service</h1>
    <p class="text-muted mb-5" style="font-size:0.88rem">Last updated: <?= date('F j, Y') ?></p>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">1. Acceptance of Terms</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        By creating an account or using <?= e($siteName) ?>, you agree to be bound by these Terms of Service. If you do not agree, please do not use the platform. We reserve the right to update these terms at any time, with notice provided via email or the platform.
      </p>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">2. Eligibility</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        You must be at least 18 years old to use <?= e($siteName) ?>. By registering, you represent that you meet this requirement and that the information you provide is accurate and complete.
      </p>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">3. Platform Overview</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        <?= e($siteName) ?> allows YouTube creators ("Creators") to fund clipping contests, and fans ("Clippers") to submit short clips of Creator content to TikTok or Instagram Reels for a chance to win cash prizes based on authentic view counts.
      </p>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">4. User Accounts</h2>
      <ul class="text-muted" style="font-size:0.9rem;line-height:2">
        <li>You are responsible for maintaining the confidentiality of your password.</li>
        <li>You are responsible for all activity that occurs under your account.</li>
        <li>You must notify us immediately of any unauthorised use of your account.</li>
        <li>We reserve the right to suspend or terminate accounts that violate these terms.</li>
      </ul>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">5. Contests — Creator Obligations</h2>
      <ul class="text-muted" style="font-size:0.9rem;line-height:2">
        <li>Creators must fund their contest prize pool before the contest goes live.</li>
        <li>Prize funds are held in escrow and released to winners upon contest closure.</li>
        <li>Creators may only use videos they own or have rights to for contest content.</li>
        <li>Creators may flag submissions that do not comply with their brand guidelines.</li>
        <li>Prize pools are non-refundable once a contest has received at least one valid submission.</li>
      </ul>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">6. Contests — Clipper Obligations</h2>
      <ul class="text-muted" style="font-size:0.9rem;line-height:2">
        <li>Submitted clips must be original work created by the submitting user.</li>
        <li>Clips must be publicly posted on the specified platform (TikTok or Reels).</li>
        <li>Purchasing views, using bots, or any form of view manipulation is strictly prohibited and will result in disqualification and account termination.</li>
        <li>Clips must not contain hate speech, explicit content, or material that infringes third-party rights.</li>
        <li>Only authentic views from real users count toward contest rankings.</li>
      </ul>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">7. Payouts</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        Winners are determined by authentic view count at contest close. Payouts are processed via bank transfer once KYC verification is complete. <?= e($siteName) ?> charges a platform fee on contest prize pools, as disclosed at contest creation. We are not responsible for delays caused by third-party payment processors or bank processing times.
      </p>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">8. Intellectual Property</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        You retain ownership of content you submit. By submitting a clip, you grant <?= e($siteName) ?> a non-exclusive, royalty-free licence to display and promote your submission within the platform. You warrant that you have the rights to any content you submit.
      </p>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">9. Prohibited Conduct</h2>
      <ul class="text-muted" style="font-size:0.9rem;line-height:2">
        <li>Creating fake accounts or impersonating other users.</li>
        <li>Manipulating contest results through artificial views or engagement.</li>
        <li>Reverse-engineering, scraping, or attempting to compromise platform security.</li>
        <li>Using the platform for money laundering or other illegal purposes.</li>
        <li>Harassing, abusing, or threatening other users.</li>
      </ul>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">10. Limitation of Liability</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        <?= e($siteName) ?> is provided "as is". To the maximum extent permitted by law, we disclaim all warranties and shall not be liable for any indirect, incidental, or consequential damages arising from your use of the platform.
      </p>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">11. Governing Law</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        These terms are governed by applicable law. Any disputes shall be resolved through binding arbitration or in the courts of the jurisdiction in which <?= e($siteName) ?> operates, as applicable.
      </p>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">12. Contact</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        Questions about these Terms? <a href="/contact" style="color:var(--accent)">Contact us here</a>.
      </p>
    </div>

  </div>
</div>

<?php renderFooter(); ?>
