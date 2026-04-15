<?php
declare(strict_types=1);

function renderHead(string $title, string $extraHead = ''): void {
    $siteName     = function_exists('getSetting') ? getSetting('site_name', 'Clipaza') : 'Clipaza';
    $favicon      = function_exists('getSetting') ? getSetting('site_favicon', '') : '';
    $adminDefault = function_exists('getSetting') ? getSetting('default_theme', 'dark') : 'dark';
    // Sanitise: only allow known values
    if (!in_array($adminDefault, ['dark', 'light'], true)) $adminDefault = 'dark';
    $fullTitle = e($title) . ' — ' . e($siteName);
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$fullTitle}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/style.css">
  <script>
    (function() {
      var stored = localStorage.getItem('clipaza_theme');
      var theme = (stored === 'light' || stored === 'dark') ? stored : '{$adminDefault}';
      document.documentElement.dataset.theme = theme;
    })();
  </script>
HTML;
    if ($favicon) {
        echo '  <link rel="icon" href="' . e($favicon) . '">' . "\n";
    }
    if ($extraHead !== '') {
        echo $extraHead . "\n";
    }
    echo "</head>\n<body>\n";
}

function renderNav(bool $isLoggedIn, array $user = [], string $activeMode = ''): void {
    $siteName = function_exists('getSetting') ? getSetting('site_name', 'Clipaza') : 'Clipaza';
    $siteLogo = function_exists('getSetting') ? getSetting('site_logo', '') : '';
    $logoHtml = $siteLogo
        ? '<img src="' . e($siteLogo) . '" alt="' . e($siteName) . '" style="height:32px">'
        : '<span class="fw-900" style="font-size:1.25rem;letter-spacing:-0.5px">' . formatSiteName($siteName) . '</span>';

    echo '<nav class="navbar navbar-expand-lg navbar-themed" style="background:var(--nav-bg);border-bottom:1px solid var(--nav-border);position:sticky;top:0;z-index:1000">';
    echo '<div class="container">';
    echo '<a class="navbar-brand text-decoration-none" href="/" style="color:var(--text)">' . $logoHtml . '</a>';
    echo '<button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" style="color:var(--text)"><span>&#9776;</span></button>';
    echo '<div class="collapse navbar-collapse" id="mainNav">';
    echo '<ul class="navbar-nav me-auto">';
    echo '<li class="nav-item"><a class="nav-link" href="/contests" style="font-size:0.9rem;color:var(--text-muted)">Browse Contests</a></li>';
    echo '<li class="nav-item"><a class="nav-link" href="/ads" style="font-size:0.9rem;color:var(--text-muted)">🎬 Movies</a></li>';
    echo '<li class="nav-item"><a class="nav-link" href="/#how-it-works" style="font-size:0.9rem;color:var(--text-muted)">How It Works</a></li>';
    echo '</ul>';
    echo '<div class="d-flex align-items-center gap-2">';
    // Theme toggle
    echo '<button class="btn-theme-toggle" id="themeToggleBtn" title="Toggle light/dark mode" aria-label="Toggle theme">🌙</button>';
    if ($isLoggedIn) {
        $username = e($user['username'] ?? 'User');
        $mode     = e($activeMode ?: ($user['mode'] ?? 'clipper'));
        $modeLabel = ucfirst($mode);
        // Impersonation banner
        if (!empty($_SESSION['admin_impersonating'])) {
            $origName = e($_SESSION['original_admin_name'] ?? 'Admin');
            echo '<a href="/auth/return-admin" class="btn btn-sm" style="background:rgba(255,68,68,0.15);color:var(--danger);border:1px solid rgba(255,68,68,0.3);font-size:0.75rem">← Return to Admin (' . $origName . ')</a>';
        }
        echo '<span class="badge" style="background:var(--accent-dim);color:var(--accent);font-size:0.72rem;border:1px solid rgba(204,255,0,0.3)">' . $modeLabel . ' Mode</span>';
        echo '<a href="/dashboard" class="btn btn-sm btn-outline-accent">Dashboard</a>';
        echo '<div class="dropdown">';
        echo '<button class="btn btn-sm" style="background:var(--card-bg);color:var(--text);border:1px solid var(--border)" data-bs-toggle="dropdown">@' . $username . ' &#9660;</button>';
        echo '<ul class="dropdown-menu dropdown-menu-end dropdown-menu-themed" style="background:var(--dropdown-bg);border-color:var(--dropdown-border)">';
        echo '<li><a class="dropdown-item" href="/profile" style="font-size:0.85rem;color:var(--dropdown-item)">Profile</a></li>';
        echo '<li><a class="dropdown-item" href="/wallet" style="font-size:0.85rem;color:var(--dropdown-item)">💳 Wallet</a></li>';
        echo '<li><a class="dropdown-item" href="/kyc" style="font-size:0.85rem;color:var(--dropdown-item)">🪪 KYC</a></li>';
        echo '<li><a class="dropdown-item" href="/notifications" style="font-size:0.85rem;color:var(--dropdown-item)">🔔 Notifications' . (function() {
            if (session_status() === PHP_SESSION_NONE) session_start();
            if (!empty($_SESSION['user_id'])) {
                $cnt = getUnreadNotificationCount((int)$_SESSION['user_id']);
                return $cnt > 0 ? ' <span style="background:var(--danger);color:#fff;font-size:0.65rem;padding:1px 5px;border-radius:10px">' . $cnt . '</span>' : '';
            }
            return '';
        })() . '</a></li>';
        echo '<li><a class="dropdown-item" href="/payout" style="font-size:0.85rem;color:var(--dropdown-item)">Payouts</a></li>';
        echo '<li><a class="dropdown-item" href="/advertise" style="font-size:0.85rem;color:var(--dropdown-item)">🎬 Advertise Movie</a></li>';
        echo '<li><a class="dropdown-item" href="/my-ads" style="font-size:0.85rem;color:var(--dropdown-item)">📋 My Ads</a></li>';
        echo '<li><hr class="dropdown-divider" style="border-color:var(--border)"></li>';
        echo '<li><a class="dropdown-item text-danger" href="/auth/logout" style="font-size:0.85rem">Logout</a></li>';
        echo '</ul></div>';
    } else {
        echo '<a href="/auth/login" class="btn btn-sm btn-outline-accent">Login</a>';
        echo '<a href="/auth/register" class="btn btn-sm btn-accent">Sign Up Free</a>';
    }
    echo '</div></div></div></nav>';
}

function renderFooter(): void {
    $siteName = function_exists('getSetting') ? getSetting('site_name', 'Clipaza') : 'Clipaza';
    $year = date('Y');
    echo <<<HTML
<footer class="lp-footer mt-auto">
  <div class="container">
    <div class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3">
      <div class="lp-footer-logo">{$siteName}<span>.</span></div>
      <div class="d-flex gap-4">
        <a href="/contests" class="lp-footer-link text-decoration-none">Browse</a>
        <a href="/#how-it-works" class="lp-footer-link text-decoration-none">How It Works</a>
        <a href="/about" class="lp-footer-link text-decoration-none">About</a>
        <a href="/privacy" class="lp-footer-link text-decoration-none">Privacy</a>
        <a href="/terms" class="lp-footer-link text-decoration-none">Terms</a>
        <a href="/contact" class="lp-footer-link text-decoration-none">Contact</a>
      </div>
      <div style="font-size:0.8rem;color:var(--text-muted)">&copy; {$year} {$siteName}. All rights reserved.</div>
    </div>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/main.js"></script>
<script>
(function() {
  var btn = document.getElementById('themeToggleBtn');
  if (!btn) return;
  function current() {
    var t = document.documentElement.dataset.theme;
    if (t) return t;
    return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  }
  function setIcon() { btn.textContent = current() === 'dark' ? '☀️' : '🌙'; }
  setIcon();
  btn.addEventListener('click', function() {
    var next = current() === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    localStorage.setItem('clipaza_theme', next);
    setIcon();
  });
})();
</script>
</body>
</html>
HTML;
}
