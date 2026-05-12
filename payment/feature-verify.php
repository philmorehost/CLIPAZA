<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/payhub.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$reference = sanitizeInput($_GET['reference'] ?? '');
$gateway   = sanitizeInput($_GET['gateway'] ?? 'paystack');
if (!in_array($gateway, ['paystack', 'payhub'], true)) $gateway = 'paystack';

if (empty($reference)) redirect('/contests');

$success = false;
$message = '';

try {
    $db   = db();
    $stmt = $db->prepare("SELECT c.*, fcp.duration_days, fcp.name AS plan_name FROM contests c LEFT JOIN featured_contest_plans fcp ON fcp.id = c.featured_plan_id WHERE c.featured_payment_ref = ? LIMIT 1");
    $stmt->execute([$reference]);
    $row = $stmt->fetch();

    if (!$row) {
        $message = 'Payment reference not found.';
    } elseif ((int)$row['is_featured'] === 1 && !empty($row['featured_until']) && strtotime($row['featured_until']) > time()) {
        $success = true;
        $message = 'Already featured.';
    } else {
        $paid = false;
        if ($gateway === 'payhub') {
            $result = payhubVerifyPayment($reference);
            $paid = (!empty($result['status']) && $result['status'] === true && ($result['data']['status'] ?? '') === 'success');
        } else {
            $result = paystackGet('/transaction/verify/' . urlencode($reference));
            $paid = (!empty($result['data']['status']) && $result['data']['status'] === 'success');
        }

        if ($paid) {
            $durationDays  = max(1, (int)($row['duration_days'] ?? 7));
            $featuredUntil = date('Y-m-d H:i:s', strtotime("+{$durationDays} days"));
            $db->prepare("UPDATE contests SET is_featured = 1, featured_until = ? WHERE id = ?")->execute([$featuredUntil, $row['id']]);
            sendNotification((int)$row['creator_id'], 'featured', 'Contest Featured! ⭐', 'Your contest "' . $row['title'] . '" is now featured for ' . $durationDays . ' days!', '/contests');
            $success = true;
            $message = 'Your contest is now featured!';
        } else {
            $message = 'Payment could not be verified. If funds were deducted, contact support.';
        }
    }
} catch (Throwable) {
    $message = 'Verification error. Please contact support.';
}

if ($success) {
    redirect('/dashboard?success=contest_featured');
}

$username   = $_SESSION['username'] ?? '';
$isLoggedIn = !empty($_SESSION['user_id']);
$userMode   = function_exists('getUserMode') ? getUserMode() : '';
require_once $root . '/includes/layout.php';
renderHead('Feature Payment Verification');
renderNav($isLoggedIn, ['username' => $username], $userMode);
?>
<div class="public-page d-flex align-items-center justify-content-center" style="min-height:70vh">
  <div class="text-center">
    <div style="font-size:3rem;margin-bottom:16px">⚠️</div>
    <h4 class="fw-700 mb-2">Feature Payment Failed</h4>
    <p class="text-muted mb-4" style="font-size:0.9rem;max-width:420px"><?= e($message) ?></p>
    <a href="/dashboard" class="btn btn-outline-accent">Go to Dashboard</a>
  </div>
</div>
<?php renderFooter(); ?>
