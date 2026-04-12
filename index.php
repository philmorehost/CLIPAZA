<?php
/**
 * PHP version gate.
 * This block uses only PHP 5.4-compatible syntax so it can be parsed and
 * executed on any PHP version, displaying a friendly error instead of a
 * cryptic internal server error when the server runs PHP < 8.0.
 * NOTE: declare(strict_types=1) is intentionally omitted from this entry-
 * point file because it must be the first statement, which would prevent
 * placing the version check first. Strict type checking is enforced in
 * all included library files (includes/*.php).
 */
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    header('Content-Type: text/html; charset=utf-8');
    header('HTTP/1.1 503 Service Unavailable');
    $required = htmlspecialchars('8.0.0', ENT_QUOTES, 'UTF-8');
    $current  = htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PHP Version Requirement Not Met</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f4f4;display:flex;
             align-items:center;justify-content:center;min-height:100vh;margin:0}
        .box{background:#fff;border-left:4px solid #e74c3c;border-radius:4px;
             padding:2rem 2.5rem;max-width:480px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h2{margin-top:0;color:#e74c3c}
        p{line-height:1.6;color:#888}
        code{background:#f0f0f0;padding:2px 6px;border-radius:3px;font-size:.9em}
    </style>
</head>
<body>
    <div class="box">
        <h2>&#9888; PHP Version Requirement Not Met</h2>
        <p>CLIPAZA requires <strong>PHP ' . $required . '</strong> or higher.</p>
        <p>Your server is running <strong>PHP <code>' . $current . '</code></strong>.</p>
        <p>Please upgrade PHP or contact your hosting provider for assistance.</p>
    </div>
</body>
</html>';
    exit;
}

$root = __DIR__;
$configFile = $root . '/config/config.php';
$lockFile   = $root . '/installer.lock';

// If not yet installed, redirect to the installer
if (!file_exists($lockFile) && !file_exists($configFile)) {
    header('Location: install/');
    exit;
}

$siteName    = 'Clipaza';
$siteTagline = 'Earn Money Clipping Videos';

// SEO / branding settings
$seoTitle       = '';
$seoDescription = 'Turn viral moments into real income. Clipaza rewards creators and clippers for finding and sharing the best video clips.';
$seoKeywords    = '';
$ogImageUrl     = '';
$customHeader   = '';
$adsenseCode    = '';
$siteFavicon    = '';
$siteLogo       = '';

if (file_exists($configFile)) {
    require_once $configFile;
    require_once $root . '/includes/db.php';
    require_once $root . '/includes/functions.php';
    $siteName       = getSetting('site_name', 'Clipaza');
    $seoTitle       = getSetting('seo_title', '');
    $seoDescription = getSetting('seo_description', $seoDescription);
    $seoKeywords    = getSetting('seo_keywords', '');
    $ogImageUrl     = getSetting('og_image_url', '');
    $customHeader   = getSetting('custom_header_code', '');
    $adsenseCode    = getSetting('adsense_code', '');
    $siteFavicon    = getSetting('site_favicon', '');
    $siteLogo       = getSetting('site_logo', '');
}

$pageTitle = $seoTitle !== '' ? $seoTitle : (htmlspecialchars($siteName) . ' — Earn Money Clipping Videos');

// Load active contests for trending section
$trendingContests = [];
if (file_exists($configFile)) {
    try {
        $db   = db();
        $stmt = $db->prepare(
            "SELECT c.*, GROUP_CONCAT(DISTINCT cp.platform ORDER BY cp.platform SEPARATOR ',') AS platforms
             FROM contests c
             LEFT JOIN contest_platforms cp ON cp.contest_id = c.id
             WHERE c.status = 'active' AND (c.end_date IS NULL OR c.end_date > NOW())
             GROUP BY c.id
             ORDER BY c.created_at DESC LIMIT 4"
        );
        $stmt->execute();
        $trendingContests = $stmt->fetchAll();
    } catch (Throwable) {}
}

// Live platform stats
$statUsers  = 0;
$statPrizes = 0.0;
$statClips  = 0;

// Live leaderboard data
$lbLive = [];

if (file_exists($configFile)) {
    try {
        $db         = db();
        $statUsers  = (int)$db->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn();
        $statPrizes = (float)$db->query("SELECT COALESCE(SUM(prize_pool),0) FROM contests")->fetchColumn();
        $statClips  = (int)$db->query("SELECT COUNT(*) FROM contest_entries WHERE status = 'approved'")->fetchColumn();

        $lbStmt = $db->query(
            "SELECT u.username, COUNT(ce.id) AS clip_count,
                    SUM(ce.view_count) AS total_views,
                    COALESCE(SUM(pr.amount),0) AS total_earned
             FROM contest_entries ce
             INNER JOIN users u ON u.id = ce.user_id
             LEFT JOIN payout_requests pr ON pr.user_id = ce.user_id AND pr.status = 'approved'
             WHERE ce.status = 'approved' AND ce.disqualified = 0
             GROUP BY ce.user_id
             ORDER BY total_views DESC
             LIMIT 5"
        );
        $lbLive = $lbStmt->fetchAll();
    } catch (Throwable) {}
}

// Format stat figures; fall back to marketing-tier labels when platform is new
function fmtStat(int|float $n, string $prefix = '', string $suffix = ''): string {
    if ($n <= 0) return '—';
    if ($n >= 1_000_000) return $prefix . number_format($n / 1_000_000, 1) . 'M+' . $suffix;
    if ($n >= 1_000)     return $prefix . number_format($n / 1_000, 0)     . 'K+' . $suffix;
    return $prefix . number_format((int)$n) . $suffix;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($seoDescription) ?>">
    <?php if ($seoKeywords !== ''): ?>
    <meta name="keywords" content="<?= htmlspecialchars($seoKeywords) ?>">
    <?php endif; ?>
    <?php if ($ogImageUrl !== ''): ?>
    <meta property="og:image" content="<?= htmlspecialchars($ogImageUrl) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($seoDescription) ?>">
    <meta property="og:type" content="website">
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <?php if ($siteFavicon !== ''): ?>
    <link rel="icon" href="<?= htmlspecialchars($siteFavicon) ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <?php if ($customHeader !== ''): ?>
    <?= $customHeader ?>
    <?php endif; ?>
</head>
<body>
<?php if ($adsenseCode !== ''): ?>
<?= $adsenseCode ?>
<?php endif; ?>

<!-- Navbar -->
<nav class="navbar-clipaza">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between">
            <a href="/" class="navbar-brand-clipaza">
                <?php if ($siteLogo !== ''): ?>
                <img src="<?= htmlspecialchars($siteLogo) ?>" alt="<?= htmlspecialchars($siteName) ?>" style="height:36px;vertical-align:middle;">
                <?php else: ?>
                Clipa<span>za</span>
                <?php endif; ?>
            </a>
            <div class="navbar-links d-none d-md-flex align-items-center gap-4">
                <a href="/contests" class="nav-text-link">Browse Contests</a>
                <a href="#features" class="nav-text-link">Features</a>
                <a href="#how-it-works" class="nav-text-link">How It Works</a>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <button class="btn-theme-toggle" id="themeToggleBtn" title="Toggle light/dark mode" aria-label="Toggle theme">🌙</button>
                <?php if (file_exists($configFile) && !empty($_SESSION['user_id'])): ?>
                    <a href="/dashboard" class="btn btn-sm btn-outline-accent" style="padding:8px 16px;font-size:0.85rem">Dashboard</a>
                <?php else: ?>
                    <a href="/auth/login" class="btn btn-sm" style="padding:8px 16px;font-size:0.85rem;background:transparent;color:var(--text-secondary);border:1px solid var(--border)">Login</a>
                    <a href="/auth/register" class="btn btn-accent" style="padding:10px 22px;font-size:0.875rem;">Sign Up Free</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<!-- Hero Section -->
<section class="lp-hero" id="hero">
    <div class="lp-hero-glow"></div>
    <div class="container">
        <div class="text-center animate-in">
            <div class="live-badge mb-4">🟢 Live Now — Start Earning Today</div>
            <h1 class="lp-hero-title">
                The Smartest Way to<br>
                Grow on YouTube<br>
                <span class="lp-hero-accent">While Getting Paid</span>
            </h1>
            <p class="lp-hero-sub">
                Creators fund contests. Clippers find viral moments.<br>
                Winners earn real money — paid directly to your bank.
            </p>

            <div class="d-flex gap-3 justify-content-center flex-wrap mb-5">
                <a href="/auth/register" class="btn btn-accent pulse-accent" style="padding:14px 32px;font-size:1rem;border-radius:10px">Start Earning Free →</a>
                <a href="/contests" class="btn btn-outline-accent" style="padding:14px 32px;font-size:1rem;border-radius:10px">Browse Contests</a>
            </div>

            <!-- Stats Row -->
            <div class="lp-stats-row">
                <div class="lp-stat">
                    <div class="lp-stat-val"><?= $statPrizes > 0 ? fmtStat($statPrizes, '₦') : '₦50M+' ?></div>
                    <div class="lp-stat-label">in Prizes</div>
                </div>
                <div class="lp-stat-divider"></div>
                <div class="lp-stat">
                    <div class="lp-stat-val"><?= $statUsers > 0 ? fmtStat($statUsers) : '10K+' ?></div>
                    <div class="lp-stat-label">Creators</div>
                </div>
                <div class="lp-stat-divider"></div>
                <div class="lp-stat">
                    <div class="lp-stat-val"><?= $statClips > 0 ? fmtStat($statClips) : '500K+' ?></div>
                    <div class="lp-stat-label">Clips</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Trending Contests Section -->
<section class="lp-section lp-section--alt" id="trending-contests">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <div class="lp-section-eyebrow">Live Now</div>
                <h2 class="lp-section-title" style="font-size:clamp(1.5rem,3vw,2rem);margin-bottom:0">Trending <span class="text-accent">Contests</span></h2>
            </div>
            <a href="/contests" class="btn btn-outline-accent" style="white-space:nowrap">View All →</a>
        </div>
        <div class="row g-4">
        <?php if (!empty($trendingContests)): ?>
            <?php foreach ($trendingContests as $tc):
                $tcPlatforms = array_filter(explode(',', $tc['platforms'] ?? ''));
                $platformIcons = implode('', array_map(fn($p) => match(trim($p)) {
                    'tiktok'=>'<span title="TikTok">🎵</span>',
                    'instagram'=>'<span title="Instagram">📸</span>',
                    'facebook'=>'<span title="Facebook">📘</span>',
                    default=>''
                }, $tcPlatforms));
                $timeLeft = '';
                if (!empty($tc['end_date'])) {
                    $secs = strtotime($tc['end_date']) - time();
                    if ($secs > 0) {
                        $d = floor($secs/86400); $h = floor(($secs%86400)/3600);
                        $timeLeft = $d > 0 ? "{$d}d {$h}h" : "{$h}h left";
                    } else { $timeLeft = 'Expiring'; }
                }
            ?>
            <div class="col-6 col-md-3">
                <a href="/contest?id=<?= (int)$tc['id'] ?>" class="text-decoration-none">
                    <div class="trend-card">
                        <?php if (!empty($tc['youtube_thumbnail'])): ?>
                            <img src="<?= htmlspecialchars($tc['youtube_thumbnail']) ?>" alt="" style="width:100%;height:110px;object-fit:cover">
                        <?php else: ?>
                            <div style="width:100%;height:110px;background:var(--input-bg);display:flex;align-items:center;justify-content:center;font-size:2rem">🎬</div>
                        <?php endif; ?>
                        <div class="p-3">
                            <div class="fw-700 mb-1" style="font-size:0.82rem;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($tc['title']) ?></div>
                            <div class="d-flex align-items-center justify-content-between">
                                <span style="color:var(--accent);font-weight:900;font-size:0.88rem">₦<?= number_format((float)$tc['prize_pool'], 0) ?></span>
                                <span style="font-size:0.85rem"><?= $platformIcons ?></span>
                            </div>
                            <?php if ($timeLeft): ?>
                                <div class="countdown-timer mt-1"><?= htmlspecialchars($timeLeft) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="col-12 text-center py-5">
                <div style="font-size:2.5rem;margin-bottom:16px">🎬</div>
                <p class="text-muted mb-3" style="font-size:0.95rem">No active contests right now — check back soon!</p>
                <a href="/auth/register" class="btn btn-accent" style="font-size:0.88rem;padding:10px 28px">Create the First Contest →</a>
            </div>
        <?php endif; ?>
        </div>
    </div>
</section>

<!-- How It Works -->
<section class="lp-section" id="how-it-works">
    <div class="container">
        <div class="text-center mb-5">
            <div class="lp-section-eyebrow">Simple Process</div>
            <h2 class="lp-section-title">How <span class="text-accent">Clipaza</span> Works</h2>
            <p class="lp-section-sub">Three steps from signing up to getting paid</p>
        </div>
        <div class="row g-4 justify-content-center">
            <div class="col-md-4">
                <div class="lp-step-card">
                    <div class="lp-step-num">01</div>
                    <div class="lp-step-icon">🏆</div>
                    <h3 class="lp-step-title">Creators Fund Contests</h3>
                    <p class="lp-step-desc">YouTube creators launch clipping contests with prize pools, defining what clips they want and how much they're willing to pay.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="lp-step-card lp-step-card--accent">
                    <div class="lp-step-num">02</div>
                    <div class="lp-step-icon">✂️</div>
                    <h3 class="lp-step-title">Clippers Create &amp; Share</h3>
                    <p class="lp-step-desc">Clippers find the best moments, edit them into viral short clips, and share them on social media to earn votes and views.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="lp-step-card">
                    <div class="lp-step-num">03</div>
                    <div class="lp-step-icon">💸</div>
                    <h3 class="lp-step-title">Winners Get Paid</h3>
                    <p class="lp-step-desc">Top clippers win their share of the prize pool — paid directly to their bank account via secure bank transfer.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Features Section -->
<section class="lp-section lp-section--alt" id="features">
    <div class="container">
        <div class="text-center mb-5">
            <div class="lp-section-eyebrow">Platform Features</div>
            <h2 class="lp-section-title">Why <span class="text-accent">Clipaza</span>?</h2>
            <p class="lp-section-sub">Everything you need to monetise your clipping skills</p>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">🎬</div>
                    <div class="feature-title">Clip &amp; Earn</div>
                    <div class="feature-desc">Turn long-form videos into viral short clips. Every clip you create earns you real revenue from contest prize pools.</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">🏆</div>
                    <div class="feature-title">Weekly Contests</div>
                    <div class="feature-desc">Compete in weekly clipping contests with cash prize pools. The best clips win — judged by views, votes, and engagement.</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">💸</div>
                    <div class="feature-title">Instant Payouts</div>
                    <div class="feature-desc">Withdraw your earnings anytime directly to your local bank account. No delays, no middlemen.</div>
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
                    <div class="feature-desc">Invite friends and earn a percentage of their contest winnings forever. Build a passive income stream.</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">🌍</div>
                    <div class="feature-title">Global Community</div>
                    <div class="feature-desc">Join creators and clippers from 100+ countries. Our platform supports local payment methods worldwide.</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- For Creators / For Clippers Split -->
<section class="lp-section" id="creators">
    <div class="container">
        <div class="text-center mb-5">
            <div class="lp-section-eyebrow">Two Sides, One Platform</div>
            <h2 class="lp-section-title">Built for <span class="text-accent">Everyone</span></h2>
        </div>
        <div class="row g-4">
            <div class="col-md-6" id="clippers">
                <div class="lp-split-card">
                    <div class="lp-split-badge">For Creators</div>
                    <h3 class="lp-split-title">Grow Your Channel<br><span class="text-accent">On Autopilot</span></h3>
                    <ul class="lp-split-list">
                        <li>🎯 Fund contests starting at any budget</li>
                        <li>📊 Get dozens of unique clips per video</li>
                        <li>🚀 Viral short-form content without editing</li>
                        <li>💡 Pay only for results you love</li>
                        <li>🔒 Full control over clip approval</li>
                    </ul>
                    <a href="/auth/register" class="btn btn-accent mt-3">Start a Contest →</a>
                </div>
            </div>
            <div class="col-md-6">
                <div class="lp-split-card lp-split-card--accent">
                    <div class="lp-split-badge lp-split-badge--dark">For Clippers</div>
                    <h3 class="lp-split-title">Turn Your Skills<br><span style="color:#000;">Into Cash</span></h3>
                    <ul class="lp-split-list lp-split-list--dark">
                        <li>✂️ Clip from top YouTube channels</li>
                        <li>💰 Enter contests with real prize money</li>
                        <li>🏅 Leaderboard rankings &amp; recognition</li>
                        <li>⚡ Fast payouts to your local bank</li>
                        <li>📱 Work from anywhere, any device</li>
                    </ul>
                    <a href="/auth/register" class="btn btn-accent" style="margin-top:12px;display:inline-block;">Join as Clipper →</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Leaderboard Preview -->
<section class="lp-section lp-section--alt">
    <div class="container">
        <div class="text-center mb-5">
            <div class="lp-section-eyebrow">Live Rankings</div>
            <h2 class="lp-section-title">Top Clippers <span class="text-accent">This Week</span></h2>
            <p class="lp-section-sub">Join the competition and see your name on the leaderboard</p>
        </div>
        <div class="lp-leaderboard">
            <div class="lp-lb-header">
                <span>Rank</span>
                <span>Clipper</span>
                <span>Clips</span>
                <span>Views</span>
                <span>Prize</span>
            </div>
            <?php
            $lbMedals = ['🥇','🥈','🥉','',''];
            $lbColors = ['#CCFF00','#aaaaaa','#cd7f32','#555','#555'];
            if (!empty($lbLive)):
                foreach ($lbLive as $idx => $row):
                    $medal = $lbMedals[$idx] ?? '';
                    $color = $lbColors[$idx] ?? '#555';
                    $views = (int)($row['total_views'] ?? 0);
                    $viewsFmt = $views >= 1_000_000 ? number_format($views/1_000_000,1).'M'
                              : ($views >= 1_000 ? number_format($views/1_000,0).'K' : (string)$views);
                    $prize = (float)($row['total_earned'] ?? 0);
                    $prizeFmt = $prize > 0 ? '₦'.number_format($prize, 0) : '—';
            ?>
            <div class="lp-lb-row">
                <span class="lp-lb-rank" style="color:<?= $color ?>;"><?= $medal !== '' ? $medal : '#'.($idx+1) ?></span>
                <span class="lp-lb-name"><?= htmlspecialchars($row['username']) ?></span>
                <span class="lp-lb-meta"><?= (int)$row['clip_count'] ?> clips</span>
                <span class="lp-lb-meta"><?= $viewsFmt ?> views</span>
                <span class="lp-lb-prize" style="color:var(--accent);"><?= $prizeFmt ?></span>
            </div>
            <?php endforeach; ?>
            <div class="lp-lb-footer">
                <a href="/contests" class="text-accent text-decoration-none" style="font-size:0.82rem">See all contestants →</a>
            </div>
            <?php else: ?>
            <div class="lp-lb-footer" style="padding:32px 24px">
                <div style="font-size:1.5rem;margin-bottom:12px">🏆</div>
                <p style="color:var(--text-muted);font-size:0.88rem;margin:0 0 12px">No ranked clippers yet — be the first to earn your spot!</p>
                <a href="/auth/register" class="btn btn-accent" style="font-size:0.85rem;padding:10px 24px">Join Now →</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="lp-cta-section">
    <div class="lp-cta-glow"></div>
    <div class="container text-center" style="position:relative;z-index:1;">
        <h2 class="lp-cta-title">Ready to Start <span class="text-accent">Earning?</span></h2>
        <p class="lp-cta-sub">Join creators and clippers already building real income on Clipaza.<br>Free to sign up — no credit card required.</p>

        <div class="d-flex gap-3 justify-content-center flex-wrap mb-4">
            <a href="/auth/register" class="btn btn-accent pulse-accent" style="padding:15px 40px;font-size:1.05rem;border-radius:10px">Sign Up Free →</a>
            <a href="/contests" class="btn btn-outline-accent" style="padding:15px 36px;font-size:1.05rem;border-radius:10px">Browse Active Contests</a>
        </div>
        <p class="lp-form-note">Already have an account? <a href="/auth/login" class="text-accent text-decoration-none">Log in here</a></p>
    </div>
</section>

<!-- Footer -->
<footer class="lp-footer">
    <div class="container">
        <div class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3">
            <div class="lp-footer-logo">
                <?php if ($siteLogo !== ''): ?>
                <img src="<?= htmlspecialchars($siteLogo) ?>" alt="<?= htmlspecialchars($siteName) ?>" style="height:28px;">
                <?php else: ?>
                Clipa<span>za</span>
                <?php endif; ?>
            </div>
            <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">© <?= date('Y') ?> <?= htmlspecialchars($siteName) ?>. All rights reserved.</p>
            <div class="d-flex gap-4 align-items-center flex-wrap justify-content-center">
                <a href="#" class="lp-footer-link">Privacy</a>
                <a href="#" class="lp-footer-link">Terms</a>
                <a href="#" class="lp-footer-link">Contact</a>
                <a href="#" class="lp-footer-link">About</a>
                <span class="lp-footer-socials">
                    <a href="#" title="Twitter/X" aria-label="Twitter">𝕏</a>
                    <a href="#" title="Instagram" aria-label="Instagram">📸</a>
                    <a href="#" title="TikTok" aria-label="TikTok">🎵</a>
                </span>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/main.js"></script>
</body>
</html>
