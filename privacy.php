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
  <meta name="description" content="Read the ' . e($siteName) . ' Privacy Policy to understand how we collect, use, and protect your personal data.">
  <meta name="robots" content="noindex,follow">
  <link rel="canonical" href="' . $siteUrl . '/privacy">';
renderHead('Privacy Policy', $extraHead);
renderNav($isLoggedIn, ['username' => $username], $userMode);
?>

<?php
$dynamicPrivacy = getSetting('page_privacy', '');
if ($dynamicPrivacy !== ''):
?>
<div class="public-page">
  <div class="container py-5">
    <?= $dynamicPrivacy ?>
  </div>
</div>
<?php else: ?>
<div class="public-page">
  <div class="container py-5" style="max-width:820px">
    <h1 class="fw-900 mb-1" style="letter-spacing:-0.5px">Privacy Policy</h1>
    <p class="text-muted mb-5" style="font-size:0.88rem">Last updated: <?= date('F j, Y') ?></p>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">1. Who We Are</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        <?= e($siteName) ?> ("we", "us", "our") operates the <?= e($siteName) ?> platform, which enables YouTube creators to run fan clipping contests and enables fans to participate and win prizes. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our website.
      </p>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">2. Information We Collect</h2>
      <p class="text-muted mb-3" style="font-size:0.9rem;line-height:1.8">We collect information you provide directly to us:</p>
      <ul class="text-muted" style="font-size:0.9rem;line-height:2">
        <li><strong>Account information</strong> — username, email address, and password when you register.</li>
        <li><strong>Profile information</strong> — name, bank account details for payouts, and KYC documents when required.</li>
        <li><strong>Contest data</strong> — contest entries, clip URLs, view counts, and related metadata.</li>
        <li><strong>Payment information</strong> — transaction records and payout history (we do not store card details).</li>
        <li><strong>Usage data</strong> — IP address, browser type, pages visited, and interaction logs to operate and improve the service.</li>
      </ul>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">3. How We Use Your Information</h2>
      <ul class="text-muted" style="font-size:0.9rem;line-height:2">
        <li>To create and manage your account.</li>
        <li>To process contest entries and distribute prize payouts.</li>
        <li>To verify your identity and prevent fraud (KYC).</li>
        <li>To send transactional emails (registration, winnings, security alerts).</li>
        <li>To display public leaderboards showing usernames and clip statistics.</li>
        <li>To comply with legal obligations.</li>
      </ul>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">4. Sharing Your Information</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        We do not sell or rent your personal data. We may share it with:
      </p>
      <ul class="text-muted" style="font-size:0.9rem;line-height:2">
        <li><strong>Payment processors</strong> — to facilitate payouts and fund transfers.</li>
        <li><strong>Service providers</strong> — hosting, email, and analytics partners under confidentiality agreements.</li>
        <li><strong>Law enforcement</strong> — when required by law or to protect our rights.</li>
      </ul>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">5. Data Retention</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        We retain your account data for as long as your account is active or as needed to provide services. You may request deletion of your account and associated personal data by contacting us at any time, subject to legal retention requirements.
      </p>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">6. Cookies</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        We use essential session cookies to keep you logged in and to protect against CSRF attacks. We use <code>localStorage</code> to remember your theme preference. We do not use third-party advertising cookies without your consent.
      </p>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">7. Your Rights</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        Depending on your location, you may have the right to access, correct, or delete your personal data, or to object to certain processing. To exercise these rights, please contact us via our <a href="/contact" style="color:var(--accent)">Contact page</a>.
      </p>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">8. Security</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        We implement industry-standard security measures including HTTPS, hashed passwords (bcrypt), CSRF protection, and rate limiting. No method of transmission over the internet is 100% secure, however, and we cannot guarantee absolute security.
      </p>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">9. Changes to This Policy</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        We may update this Privacy Policy from time to time. We will notify you of significant changes by email or by displaying a notice on the platform. Continued use of <?= e($siteName) ?> after changes take effect constitutes acceptance of the updated policy.
      </p>
    </div>

    <div class="card-dark p-4 mb-4">
      <h2 class="fw-700 mb-3" style="font-size:1.1rem">10. Contact Us</h2>
      <p class="text-muted" style="font-size:0.9rem;line-height:1.8">
        Questions about this Privacy Policy? Reach us via our <a href="/contact" style="color:var(--accent)">Contact page</a>.
      </p>
    </div>

  </div>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
