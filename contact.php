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

// Fetch admin email from the config constant (set during installation)
$supportEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '';

$extraHead = '
  <meta name="description" content="Get in touch with the ' . e($siteName) . ' team. We\'re here to help with contests, payouts, KYC, and everything else.">
  <link rel="canonical" href="' . $siteUrl . '/contact">';
renderHead('Contact', $extraHead);
renderNav($isLoggedIn, ['username' => $username], $userMode);
?>

<div class="public-page">
  <div class="container py-5" style="max-width:720px">
    <h1 class="fw-900 mb-2" style="letter-spacing:-0.5px">Contact Us</h1>
    <p class="text-muted mb-5" style="font-size:0.95rem;line-height:1.7">Have a question, spotted an issue, or just want to say hello? We're here.</p>

    <?php if ($supportEmail !== ''): ?>
    <!-- Email card -->
    <div class="card-dark p-4 mb-4">
      <div class="d-flex align-items-start gap-3">
        <div style="font-size:1.6rem;min-width:2.2rem;line-height:1.2">✉️</div>
        <div>
          <div class="fw-700 mb-1" style="font-size:1rem">Email Support</div>
          <p class="text-muted mb-2" style="font-size:0.88rem;line-height:1.7">
            For account issues, payout queries, KYC questions, and anything else — drop us an email and we'll get back to you as quickly as we can.
          </p>
          <a href="mailto:<?= e($supportEmail) ?>" style="color:var(--accent);font-weight:600;font-size:0.95rem;word-break:break-all"><?= e($supportEmail) ?></a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Common topics -->
    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1rem">Common Topics</h2>
      <div class="d-flex flex-column gap-3">
        <div class="d-flex gap-3 align-items-start">
          <div style="font-size:1.2rem;min-width:1.8rem">💸</div>
          <div>
            <div class="fw-700 mb-1" style="font-size:0.9rem">Payout Issues</div>
            <p class="text-muted mb-0" style="font-size:0.85rem;line-height:1.6">Check that your bank details are saved in your profile and your KYC is approved. If your payout is still pending after 5 business days, email us with your username and payout amount.</p>
          </div>
        </div>
        <div class="d-flex gap-3 align-items-start">
          <div style="font-size:1.2rem;min-width:1.8rem">🪪</div>
          <div>
            <div class="fw-700 mb-1" style="font-size:0.9rem">KYC Verification</div>
            <p class="text-muted mb-0" style="font-size:0.85rem;line-height:1.6">KYC is required before your first payout. Submit your documents from your <a href="/kyc" style="color:var(--accent)">KYC page</a>. Review typically takes 1–2 business days.</p>
          </div>
        </div>
        <div class="d-flex gap-3 align-items-start">
          <div style="font-size:1.2rem;min-width:1.8rem">🏆</div>
          <div>
            <div class="fw-700 mb-1" style="font-size:0.9rem">Contest Questions</div>
            <p class="text-muted mb-0" style="font-size:0.85rem;line-height:1.6">Questions about rules, eligibility, or results? Start at the specific contest page — all rules are listed there. If you still need help, get in touch.</p>
          </div>
        </div>
        <div class="d-flex gap-3 align-items-start">
          <div style="font-size:1.2rem;min-width:1.8rem">🛡</div>
          <div>
            <div class="fw-700 mb-1" style="font-size:0.9rem">Report Abuse or Fraud</div>
            <p class="text-muted mb-0" style="font-size:0.85rem;line-height:1.6">If you've spotted bot activity, fake accounts, or any other abuse, please report it to us immediately. We take platform integrity seriously.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Social / quick links -->
    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1rem">Quick Links</h2>
      <div class="d-flex gap-3 flex-wrap">
        <a href="/privacy" class="btn btn-sm btn-outline-accent">Privacy Policy</a>
        <a href="/terms" class="btn btn-sm btn-outline-accent">Terms of Service</a>
        <a href="/about" class="btn btn-sm btn-outline-accent">About <?= e($siteName) ?></a>
        <?php if ($isLoggedIn): ?>
        <a href="/kyc" class="btn btn-sm btn-outline-accent">KYC Verification</a>
        <a href="/payout" class="btn btn-sm btn-outline-accent">Payout Request</a>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php renderFooter(); ?>
