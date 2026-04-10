<?php
declare(strict_types=1);

$root = __DIR__;
$configFile = $root . '/config/config.php';
$lockFile   = $root . '/installer.lock';

// If not yet installed, redirect to the installer
if (!file_exists($lockFile) && !file_exists($configFile)) {
    header('Location: install/');
    exit;
}

$siteName = 'Clipaza';
$siteTagline = 'Earn Money Clipping Videos';

if (file_exists($configFile)) {
    require_once $configFile;
    require_once $root . '/includes/db.php';
    require_once $root . '/includes/functions.php';
    $siteName = getSetting('site_name', 'Clipaza');
}

$waitlistSuccess = false;
$waitlistError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['waitlist_email'])) {
    $email = filter_var(trim($_POST['waitlist_email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if ($email && file_exists($configFile)) {
        try {
            $db   = db();
            $stmt = $db->prepare('SELECT id FROM waitlist WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $waitlistError = "You're already on the waitlist!";
            } else {
                $db->prepare('INSERT INTO waitlist (email) VALUES (?)')->execute([$email]);
                $waitlistSuccess = true;
            }
        } catch (Throwable) {
            $waitlistError = 'Something went wrong. Please try again.';
        }
    } elseif (!$email) {
        $waitlistError = 'Please enter a valid email address.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($siteName) ?> — Earn Money Clipping Videos</title>
    <meta name="description" content="Turn viral moments into real income. Clipaza rewards you for finding and sharing the best video clips.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- Navbar -->
<nav class="navbar-clipaza">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between">
            <a href="/" class="navbar-brand-clipaza">Clipa<span>za</span></a>
            <div class="d-flex gap-3 align-items-center">
                <a href="/admin/login.php" class="btn-outline-accent btn" style="padding:8px 18px;font-size:0.875rem;">Admin</a>
            </div>
        </div>
    </div>
</nav>

<!-- Hero -->
<section class="hero-section text-center">
    <div class="container">
        <div class="animate-in">
            <div class="coming-soon-badge">Coming Soon</div>
            <h1 class="hero-title">Earn Money<br><span class="highlight">Clipping Videos</span></h1>
            <p class="hero-subtitle">Turn viral moments into real income. Find, clip, and share the best video moments — and get paid for every view.</p>

            <?php if ($waitlistSuccess): ?>
            <div class="alert-dark-success d-inline-block px-4 py-3 mb-3" style="border-radius:8px;">
                🎉 You're on the waitlist! We'll notify you at launch.
            </div>
            <?php elseif ($waitlistError): ?>
            <div class="alert-dark-warning d-inline-block px-4 py-3 mb-3" style="border-radius:8px;">
                <?= htmlspecialchars($waitlistError) ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="email-form">
                <input type="email" name="waitlist_email" placeholder="Enter your email address" required
                       value="<?= isset($_POST['waitlist_email']) && !$waitlistSuccess ? htmlspecialchars($_POST['waitlist_email']) : '' ?>">
                <button type="submit" class="btn btn-accent pulse-accent">Join Waitlist</button>
            </form>
            <p style="font-size:0.8rem;color:#555;margin-top:12px;">No spam. Unsubscribe anytime. 🔒</p>
        </div>
    </div>
</section>

<!-- Features -->
<section style="padding:80px 0;" id="features">
    <div class="container">
        <div class="text-center mb-5">
            <h2 style="font-size:2rem;font-weight:800;letter-spacing:-1px;">Why <span style="color:#CCFF00;">Clipaza</span>?</h2>
            <p style="color:#888;margin-top:8px;">Everything you need to start earning from video content</p>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">🎬</div>
                    <div class="feature-title">Clip & Earn</div>
                    <div class="feature-desc">Find the best moments in long-form videos and turn them into viral short clips. Every clip you create earns you revenue.</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">🏆</div>
                    <div class="feature-title">Weekly Contests</div>
                    <div class="feature-desc">Compete in weekly clipping contests with prize pools. The best clips win cash rewards distributed directly to your wallet.</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">💸</div>
                    <div class="feature-title">Instant Payouts</div>
                    <div class="feature-desc">Withdraw your earnings at any time. We support multiple payout methods including PayPal, Stripe, and crypto.</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">📈</div>
                    <div class="feature-title">Performance Analytics</div>
                    <div class="feature-desc">Track your clips' views, engagement, and earnings in real time. Know exactly what content performs best.</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">🤝</div>
                    <div class="feature-title">Referral Program</div>
                    <div class="feature-desc">Invite friends and earn a percentage of their earnings forever. Build a passive income stream by growing the community.</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">🌍</div>
                    <div class="feature-title">Global Community</div>
                    <div class="feature-desc">Join creators from 100+ countries. Our platform supports multiple languages and local payment methods.</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section style="padding:80px 0;border-top:1px solid #222;">
    <div class="container text-center">
        <h2 style="font-size:2rem;font-weight:800;letter-spacing:-1px;margin-bottom:16px;">Ready to Start Earning?</h2>
        <p style="color:#888;margin-bottom:32px;font-size:1.05rem;">Join thousands of creators already on the waitlist.</p>
        <form method="POST" class="email-form">
            <input type="email" name="waitlist_email" placeholder="Your email address" required>
            <button type="submit" class="btn btn-accent">Get Early Access</button>
        </form>
    </div>
</section>

<!-- Footer -->
<footer style="border-top:1px solid #222;padding:32px 0;">
    <div class="container">
        <div class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3">
            <div style="font-weight:900;font-size:1.2rem;letter-spacing:-0.5px;">Clipa<span style="color:#CCFF00;">za</span></div>
            <p style="font-size:0.8rem;color:#555;margin:0;">© <?= date('Y') ?> Clipaza. All rights reserved.</p>
            <div class="d-flex gap-3">
                <a href="#" style="color:#555;font-size:0.8rem;">Privacy</a>
                <a href="#" style="color:#555;font-size:0.8rem;">Terms</a>
                <a href="#" style="color:#555;font-size:0.8rem;">Contact</a>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/main.js"></script>
</body>
</html>