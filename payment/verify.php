<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$reference = sanitizeInput($_GET['reference'] ?? '');
if (empty($reference)) redirect('/dashboard');

$success = false;
$message = '';

// Verify via server-side call
try {
    $secretKey = getSetting('paystack_secret_key', '');
    if (defined('PAYSTACK_SECRET_KEY') && PAYSTACK_SECRET_KEY) {
        $secretKey = PAYSTACK_SECRET_KEY;
    }

    if (!empty($secretKey)) {
        $ch = curl_init('https://api.paystack.co/transaction/verify/' . urlencode($reference));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secretKey],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (!empty($data['data']['status']) && $data['data']['status'] === 'success') {
            $db   = db();
            $stmt = $db->prepare("SELECT * FROM contests WHERE paystack_reference = ? LIMIT 1");
            $stmt->execute([$reference]);
            $contest = $stmt->fetch();

            if ($contest && $contest['escrow_status'] !== 'funded') {
                $db->prepare(
                    "UPDATE contests SET status = 'active', escrow_status = 'funded', funded_at = NOW() WHERE id = ?"
                )->execute([$contest['id']]);

                $db->prepare(
                    'UPDATE user_profiles SET total_spent = total_spent + ? WHERE user_id = ?'
                )->execute([$contest['total_amount'], $contest['creator_id']]);

                $db->prepare(
                    "INSERT INTO transactions (user_id, amount, type, status, reference, description)
                     VALUES (?, ?, 'debit', 'completed', ?, ?)"
                )->execute([
                    $contest['creator_id'],
                    $contest['total_amount'],
                    $reference,
                    'Contest funding: ' . $contest['title'],
                ]);
            }
            $success = true;
            $message = 'Payment verified! Your contest is now live.';
        } else {
            $message = 'Payment could not be verified. Please contact support if funds were deducted.';
        }
    } else {
        // No Paystack key configured — mark as paid for dev/testing
        $db   = db();
        $stmt = $db->prepare("SELECT * FROM contests WHERE paystack_reference = ? LIMIT 1");
        $stmt->execute([$reference]);
        $contest = $stmt->fetch();
        if ($contest) {
            $db->prepare("UPDATE contests SET status = 'active', escrow_status = 'funded', funded_at = NOW() WHERE id = ?")
               ->execute([$contest['id']]);
            $success = true;
            $message = 'Contest activated (Paystack not configured).';
        }
    }
} catch (Throwable $e) {
    $message = 'Verification error. Please contact support.';
}

if ($success) {
    redirect('/dashboard?success=contest_funded');
}

$username = $_SESSION['username'] ?? '';
$userMode = getUserMode();
renderHead('Payment Verification');
renderNav(!empty($_SESSION['user_id']), ['username' => $username], $userMode);
?>

<div class="public-page d-flex align-items-center justify-content-center" style="min-height:70vh">
  <div class="text-center">
    <div style="font-size:3rem;margin-bottom:16px">⚠️</div>
    <h4 class="fw-700 mb-2">Payment Verification Failed</h4>
    <p class="text-muted mb-4" style="font-size:0.9rem;max-width:420px"><?= e($message) ?></p>
    <a href="/dashboard" class="btn btn-outline-accent">Go to Dashboard</a>
  </div>
</div>

<?php renderFooter(); ?>
