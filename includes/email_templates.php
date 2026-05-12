<?php
declare(strict_types=1);

function emailBaseTemplate(string $content): string
{
    $year = date('Y');
    return <<<HTML
<div style="background:#000;padding:40px 16px;font-family:'Segoe UI',Arial,sans-serif;">
  <div style="max-width:600px;margin:0 auto;background:#0d0d0d;border:1px solid #1a1a1a;border-radius:16px;overflow:hidden;">
    <div style="background:#111;padding:28px 32px;border-bottom:1px solid #1a1a1a;">
      <div style="font-size:1.4rem;font-weight:900;color:#fff;letter-spacing:-0.5px;">
        Clipa<span style="color:#CCFF00;">za</span>
      </div>
    </div>
    <div style="padding:32px;">
      {$content}
    </div>
    <div style="padding:24px 32px;border-top:1px solid #1a1a1a;text-align:center;">
      <p style="color:#444;font-size:0.78rem;margin:0;">© {$year} Clipaza. This is an automated email.</p>
    </div>
  </div>
</div>
HTML;
}

function emailWelcome(string $username, string $email): string
{
    $u = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $content = <<<HTML
<h1 style="color:#fff;font-size:1.5rem;font-weight:900;margin:0 0 8px;">Welcome to Clipaza 🎬</h1>
<p style="color:#888;font-size:0.9rem;margin:0 0 24px;">Your account is ready</p>
<p style="color:#ccc;line-height:1.7;margin:0 0 16px;">
  Hey <strong style="color:#fff;">{$u}</strong>, welcome aboard! You're now part of the Clipaza community — where creators and clippers connect to grow together.
</p>
<p style="color:#ccc;line-height:1.7;margin:0 0 24px;">
  Start by browsing active contests and submitting your best clips to win prizes. Remember to read the participation disclaimer on your dashboard before joining any contest.
</p>
<a href="https://clipaza.com/dashboard" style="display:inline-block;background:#CCFF00;color:#000;font-weight:700;padding:12px 28px;border-radius:8px;text-decoration:none;">Go to Dashboard</a>
<p style="color:#555;font-size:0.78rem;margin:24px 0 0;">Your registered email: {$email}</p>
HTML;
    return emailBaseTemplate($content);
}

function emailContestSubmitted(string $username, string $contestTitle, string $platform, string $clipUrl): string
{
    $u  = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $ct = htmlspecialchars($contestTitle, ENT_QUOTES, 'UTF-8');
    $pl = htmlspecialchars(ucfirst($platform), ENT_QUOTES, 'UTF-8');
    $cu = htmlspecialchars($clipUrl, ENT_QUOTES, 'UTF-8');
    $content = <<<HTML
<h1 style="color:#fff;font-size:1.4rem;font-weight:900;margin:0 0 8px;">Clip Submitted ✅</h1>
<p style="color:#888;font-size:0.9rem;margin:0 0 24px;">Your entry has been received</p>
<p style="color:#ccc;line-height:1.7;margin:0 0 16px;">
  Hey <strong style="color:#fff;">{$u}</strong>, your clip has been submitted to <strong style="color:#CCFF00;">{$ct}</strong> on <strong style="color:#fff;">{$pl}</strong>. Good luck!
</p>
<div style="background:#111;border:1px solid #1a1a1a;border-radius:10px;padding:16px;margin:0 0 24px;">
  <div style="color:#666;font-size:0.78rem;margin-bottom:4px;">CLIP URL</div>
  <div style="color:#ccc;font-size:0.85rem;word-break:break-all;">{$cu}</div>
</div>
<p style="color:#888;font-size:0.85rem;line-height:1.6;margin:0 0 16px;">
  ⚠️ <strong style="color:#ffaa00;">Important reminder:</strong> If you win, you must submit a 2-minute analytics video proof and engagement screenshots within <strong>3 days</strong> of the contest ending.
</p>
<a href="https://clipaza.com/contests" style="display:inline-block;background:#CCFF00;color:#000;font-weight:700;padding:12px 28px;border-radius:8px;text-decoration:none;">View Contests</a>
HTML;
    return emailBaseTemplate($content);
}

function emailBotBanned(string $username): string
{
    $u = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $content = <<<HTML
<h1 style="color:#ff4444;font-size:1.4rem;font-weight:900;margin:0 0 8px;">Account Suspended 🚫</h1>
<p style="color:#888;font-size:0.9rem;margin:0 0 24px;">Automated activity detected</p>
<p style="color:#ccc;line-height:1.7;margin:0 0 16px;">
  Hi <strong style="color:#fff;">{$u}</strong>, your Clipaza account has been permanently suspended.
</p>
<div style="background:#1a0000;border:1px solid rgba(255,68,68,0.3);border-radius:10px;padding:16px;margin:0 0 24px;">
  <p style="color:#ff4444;font-weight:700;margin:0 0 8px;">Reason: Bot / Artificial Activity Detected</p>
  <p style="color:#aaa;font-size:0.85rem;margin:0;">Our automated systems detected the use of bots, automation scripts, or artificial engagement inflation in your recent contest submission. This is a zero-tolerance violation of our Terms of Service.</p>
</div>
<p style="color:#888;font-size:0.85rem;line-height:1.6;margin:0 0 16px;">
  Per our contest rules, this results in immediate permanent account suspension and forfeiture of any prizes. This decision is final.
</p>
<p style="color:#555;font-size:0.78rem;margin:0;">If you believe this is a genuine error, contact support at support@clipaza.com.</p>
HTML;
    return emailBaseTemplate($content);
}

function emailAdminBotAlert(string $username, string $email, string $contestTitle, string $clipUrl, array $flags): string
{
    $u  = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $em = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $ct = htmlspecialchars($contestTitle, ENT_QUOTES, 'UTF-8');
    $cu = htmlspecialchars($clipUrl, ENT_QUOTES, 'UTF-8');
    $flagList = '';
    foreach ($flags as $flag) {
        $f = htmlspecialchars((string)$flag, ENT_QUOTES, 'UTF-8');
        $flagList .= "<li style=\"margin-bottom:4px;color:#ffaa00;\">{$f}</li>";
    }
    if (!$flagList) {
        $flagList = '<li style="color:#888;">No specific flags</li>';
    }
    $content = <<<HTML
<h1 style="color:#ffaa00;font-size:1.4rem;font-weight:900;margin:0 0 8px;">⚠️ Bot Activity Detected</h1>
<p style="color:#888;font-size:0.9rem;margin:0 0 24px;">A user has been auto-banned</p>
<div style="background:#111;border:1px solid #1a1a1a;border-radius:10px;padding:16px;margin:0 0 16px;">
  <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
    <tr><td style="color:#666;padding:4px 0;width:120px;">User</td><td style="color:#fff;font-weight:700;">{$u}</td></tr>
    <tr><td style="color:#666;padding:4px 0;">Email</td><td style="color:#ccc;">{$em}</td></tr>
    <tr><td style="color:#666;padding:4px 0;">Contest</td><td style="color:#CCFF00;">{$ct}</td></tr>
    <tr><td style="color:#666;padding:4px 0;">Clip URL</td><td style="color:#ccc;word-break:break-all;">{$cu}</td></tr>
  </table>
</div>
<div style="background:#111;border:1px solid #1a1a1a;border-radius:10px;padding:16px;margin:0 0 24px;">
  <div style="color:#666;font-size:0.78rem;margin-bottom:8px;">DETECTION FLAGS</div>
  <ul style="margin:0;padding-left:18px;">{$flagList}</ul>
</div>
<p style="color:#888;font-size:0.85rem;margin:0 0 16px;">The account has been automatically suspended and the IP has been permanently blocked.</p>
<a href="https://clipaza.com/admin/contests.php" style="display:inline-block;background:#CCFF00;color:#000;font-weight:700;padding:12px 28px;border-radius:8px;text-decoration:none;">Review in Admin</a>
HTML;
    return emailBaseTemplate($content);
}

function emailContestWinner(string $username, string $contestTitle, string $platform, string $prize): string
{
    $u  = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $ct = htmlspecialchars($contestTitle, ENT_QUOTES, 'UTF-8');
    $pl = htmlspecialchars(ucfirst($platform), ENT_QUOTES, 'UTF-8');
    $pr = htmlspecialchars($prize, ENT_QUOTES, 'UTF-8');
    $content = <<<HTML
<h1 style="color:#CCFF00;font-size:1.6rem;font-weight:900;margin:0 0 8px;">🏆 You Won!</h1>
<p style="color:#888;font-size:0.9rem;margin:0 0 24px;">Congratulations on your victory</p>
<p style="color:#ccc;line-height:1.7;margin:0 0 16px;">
  Hey <strong style="color:#fff;">{$u}</strong>! Amazing news — you've won the <strong style="color:#CCFF00;">{$ct}</strong> contest on <strong style="color:#fff;">{$pl}</strong>!
</p>
<div style="background:#0a1a00;border:1px solid rgba(204,255,0,0.3);border-radius:10px;padding:16px;margin:0 0 24px;text-align:center;">
  <div style="color:#888;font-size:0.8rem;margin-bottom:4px;">YOUR PRIZE</div>
  <div style="color:#CCFF00;font-size:2rem;font-weight:900;">{$pr}</div>
</div>
<p style="color:#ffaa00;font-size:0.85rem;line-height:1.6;margin:0 0 8px;">
  ⚠️ <strong>Action required within 3 days:</strong>
</p>
<ol style="color:#ccc;font-size:0.85rem;line-height:1.7;margin:0 0 24px;padding-left:20px;">
  <li style="margin-bottom:6px;">Submit a <strong style="color:#fff;">2-minute screen-recorded video</strong> showing authentic video analytics (views, likes, comments).</li>
  <li style="margin-bottom:6px;">Provide screenshot proof of your comment and like on the creator's video.</li>
  <li>Ensure your payment details are saved in your profile.</li>
</ol>
<a href="https://clipaza.com/dashboard" style="display:inline-block;background:#CCFF00;color:#000;font-weight:700;padding:12px 28px;border-radius:8px;text-decoration:none;">Submit Proof Now</a>
HTML;
    return emailBaseTemplate($content);
}

function emailDisclaimerAccepted(string $username): string
{
    $u = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $content = <<<HTML
<h1 style="color:#fff;font-size:1.3rem;font-weight:900;margin:0 0 8px;">Disclaimer Acknowledged ✓</h1>
<p style="color:#888;font-size:0.9rem;margin:0 0 24px;">Thanks for confirming</p>
<p style="color:#ccc;line-height:1.7;margin:0 0 16px;">
  Hi <strong style="color:#fff;">{$u}</strong>, you've acknowledged the Clipaza contest participation disclaimer. You're all set to join contests!
</p>
<p style="color:#888;font-size:0.85rem;line-height:1.6;margin:0;">
  Remember: winning requires analytics video proof and engagement screenshots within 3 days of contest end. Fair play is enforced automatically.
</p>
HTML;
    return emailBaseTemplate($content);
}
