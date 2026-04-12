<?php
declare(strict_types=1);

$root = __DIR__;
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireUser();

$userId   = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$userMode = getUserMode();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$notifications = [];
$total         = 0;

try {
    $db   = db();
    $cnt  = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
    $cnt->execute([$userId]);
    $total = (int)$cnt->fetchColumn();
    $pag   = paginate($total, $perPage, $page);
    $stmt  = $db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $stmt->execute([$userId, $perPage, $pag['offset']]);
    $notifications = $stmt->fetchAll();

    // Mark all as read when visiting this page
    $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$userId]);
} catch (Throwable) {
    $pag = paginate(0, $perPage, 1);
}

$csrf = generateCsrfToken();
renderHead('Notifications');
renderNav(true, ['username' => $username], $userMode);
?>

<div class="public-page">
  <div class="container py-4">
    <div class="row justify-content-center">
      <div class="col-lg-7">

        <div class="d-flex align-items-center justify-content-between mb-4">
          <h3 class="fw-900 mb-0" style="letter-spacing:-0.5px">🔔 Notifications</h3>
          <?php if ($total > 0): ?>
          <button id="clearAllBtn" class="btn btn-sm btn-danger-custom" style="font-size:0.82rem">Clear All</button>
          <?php endif; ?>
        </div>

        <?php if (empty($notifications)): ?>
        <div class="card-dark p-5 text-center">
          <div style="font-size:3rem;margin-bottom:16px">🔔</div>
          <h5 class="fw-700 mb-2">All Caught Up!</h5>
          <p class="text-muted" style="font-size:0.9rem">You have no notifications.</p>
        </div>
        <?php else: ?>
        <div class="d-flex flex-column gap-2">
          <?php foreach ($notifications as $n): ?>
          <?php
            $iconMap = [
                'deposit'         => '💰',
                'payout'          => '💸',
                'payout_approved' => '✅',
                'payout_rejected' => '❌',
                'payout_cancelled'=> '⏸',
                'payout_restored' => '🔄',
                'kyc'             => '🪪',
                'appeal'          => '⚖',
                'wallet'          => '💳',
                'info'            => 'ℹ',
            ];
            $icon = $iconMap[$n['type']] ?? '🔔';
            $isUnread = !$n['is_read'];
          ?>
          <div class="card-dark p-4 d-flex align-items-start gap-3" style="<?= $isUnread ? 'border-color:rgba(204,255,0,0.2);' : '' ?>">
            <div style="font-size:1.6rem;flex-shrink:0;margin-top:2px"><?= $icon ?></div>
            <div class="flex-grow-1">
              <div class="d-flex align-items-center justify-content-between">
                <strong style="font-size:0.92rem;color:<?= $isUnread ? 'var(--accent)' : '#fff' ?>"><?= e($n['title']) ?></strong>
                <span style="font-size:0.75rem;color:var(--text-muted);white-space:nowrap;margin-left:12px"><?= e(timeAgo($n['created_at'])) ?></span>
              </div>
              <p style="font-size:0.85rem;color:var(--text-secondary);margin:4px 0 0"><?= e($n['message']) ?></p>
              <?php if (!empty($n['link'])): ?>
              <a href="<?= e($n['link']) ?>" style="font-size:0.8rem;color:var(--accent);margin-top:4px;display:inline-block">View →</a>
              <?php endif; ?>
            </div>
            <?php if ($isUnread): ?>
            <div style="width:8px;height:8px;background:var(--accent);border-radius:50%;flex-shrink:0;margin-top:8px"></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if ($pag['pages'] > 1): ?>
        <nav class="mt-4"><ul class="pagination pagination-dark justify-content-center">
            <?php for ($i = 1; $i <= $pag['pages']; $i++): ?>
                <li class="page-item <?= $i===$page?'active':'' ?>">
                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
        </ul></nav>
        <?php endif; ?>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<script>
const csrf = <?= json_encode($csrf) ?>;
document.getElementById('clearAllBtn')?.addEventListener('click', async function() {
  if (!confirm('Clear all notifications?')) return;
  this.disabled = true;
  try {
    const r = await fetch('/ajax/notifications_actions.php', {
      method: 'POST',
      body: new URLSearchParams({ action: 'clear_all', csrf_token: csrf })
    });
    const d = await r.json();
    if (d.success) location.reload();
    else alert(d.message || 'Error');
  } catch { alert('Network error.'); }
  this.disabled = false;
});
</script>

<?php renderFooter(); ?>
