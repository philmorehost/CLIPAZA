<?php
declare(strict_types=1);

function renderHead(string $title, string $extraHead = ''): void {
    $siteName = function_exists('getSetting') ? getSetting('site_name', 'Clipaza') : 'Clipaza';
    $favicon  = function_exists('getSetting') ? getSetting('site_favicon', '') : '';
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
HTML;
    if ($favicon) {
        echo '  <link rel="icon" href="' . e($favicon) . '">' . "\n";
    }
    if ($extraHead) echo $extraHead . "\n";
    echo "</head>\n<body>\n";
}

function renderNav(bool $isLoggedIn, array $user = [], string $activeMode = ''): void {
    $siteName = function_exists('getSetting') ? getSetting('site_name', 'Clipaza') : 'Clipaza';
    $siteLogo = function_exists('getSetting') ? getSetting('site_logo', '') : '';
    $logoHtml = $siteLogo
        ? '<img src="' . e($siteLogo) . '" alt="' . e($siteName) . '" style="height:32px">'
        : '<span class="fw-900" style="font-size:1.25rem;letter-spacing:-0.5px">' . e($siteName) . '<span style="color:var(--accent)">.</span></span>';

    echo '<nav class="navbar navbar-expand-lg" style="background:#000;border-bottom:1px solid #1a1a1a;position:sticky;top:0;z-index:1000">';
    echo '<div class="container">';
    echo '<a class="navbar-brand text-white text-decoration-none" href="/">' . $logoHtml . '</a>';
    echo '<button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" style="color:#fff"><span>&#9776;</span></button>';
    echo '<div class="collapse navbar-collapse" id="mainNav">';
    echo '<ul class="navbar-nav me-auto">';
    echo '<li class="nav-item"><a class="nav-link text-muted" href="/contests" style="font-size:0.9rem">Browse Contests</a></li>';
    echo '<li class="nav-item"><a class="nav-link text-muted" href="/#how-it-works" style="font-size:0.9rem">How It Works</a></li>';
    echo '</ul>';
    echo '<div class="d-flex align-items-center gap-2">';
    if ($isLoggedIn) {
        $username = e($user['username'] ?? 'User');
        $mode     = e($activeMode ?: ($user['mode'] ?? 'clipper'));
        $modeLabel = ucfirst($mode);
        echo '<span class="badge" style="background:var(--accent-dim);color:var(--accent);font-size:0.72rem;border:1px solid rgba(204,255,0,0.3)">' . $modeLabel . ' Mode</span>';
        echo '<a href="/dashboard" class="btn btn-sm btn-outline-accent">Dashboard</a>';
        echo '<div class="dropdown">';
        echo '<button class="btn btn-sm" style="background:#111;color:#fff;border:1px solid #222" data-bs-toggle="dropdown">@' . $username . ' &#9660;</button>';
        echo '<ul class="dropdown-menu dropdown-menu-end" style="background:#111;border:1px solid #222">';
        echo '<li><a class="dropdown-item text-white" href="/profile" style="font-size:0.85rem">Profile</a></li>';
        echo '<li><a class="dropdown-item text-white" href="/payout" style="font-size:0.85rem">Payouts</a></li>';
        echo '<li><hr class="dropdown-divider" style="border-color:#222"></li>';
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
        <a href="/terms" class="lp-footer-link text-decoration-none">Rules</a>
        <a href="/auth/register" class="lp-footer-link text-decoration-none">Sign Up</a>
      </div>
      <div style="font-size:0.8rem;color:#555">&copy; {$year} {$siteName}. All rights reserved.</div>
    </div>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/main.js"></script>
</body>
</html>
HTML;
}
