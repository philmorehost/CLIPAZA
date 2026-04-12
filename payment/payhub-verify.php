<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';
require_once $root . '/includes/payhub.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$reference = sanitizeInput($_GET['reference'] ?? '');
$type      = sanitizeInput($_GET['type'] ?? '');

if (empty($reference) || !in_array($type, ['contest', 'deposit'], true)) {
    redirect('/dashboard');
}

$success = false;
$message = '';

try {
    $result = payhubVerifyPayment($reference);

    if (!empty($result['error'])) {
        $message = $result['error'];
    } elseif (empty($result['data']['status']) || $result['data']['status'] !== 'success') {
        $message = 'Payment not confirmed by PayHub. Status: ' . ($result['data']['status'] ?? 'unknown');
    } else {
        $db = db();

        if ($type === 'contest') {
            $stmt = $db->prepare("SELECT * FROM contests WHERE payhub_reference = ? LIMIT 1");
            $stmt->execute([$reference]);
            $contest = $stmt->fetch();

            if (!$contest) {
                $message = 'Contest not found for this payment reference.';
            } elseif ($contest['escrow_status'] === 'funded') {
                $success = true;
                $message = 'Contest already funded.';
            } else {
                $db->beginTransaction();

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
                    'Contest funding (PayHub): ' . $contest['title'],
                ]);

                $db->commit();
                $success = true;
                $message = 'Payment verified! Your contest is now live.';
            }

        } elseif ($type === 'deposit') {
            $stmt = $db->prepare("SELECT * FROM transactions WHERE reference = ? AND type = 'credit' LIMIT 1");
            $stmt->execute([$reference]);
            $tx = $stmt->fetch();

            if (!$tx) {
                $message = 'Transaction not found.';
            } elseif ($tx['status'] === 'completed') {
                $success = true;
                $message = 'Deposit already credited.';
            } else {
                $db->beginTransaction();
                $db->prepare("UPDATE transactions SET status = 'completed' WHERE reference = ?")->execute([$reference]);
                $db->prepare("UPDATE user_profiles SET wallet_balance = wallet_balance + ? WHERE user_id = ?")->execute([$tx['amount'], $tx['user_id']]);
                $db->commit();

                sendNotification((int)$tx['user_id'], 'deposit', 'Deposit Successful',
                    '₦' . number_format((float)$tx['amount'], 0) . ' has been added to your wallet.', '/wallet');

                $success = true;
                $message = 'Deposit successful!';
            }
        }
    }
} catch (Throwable) {
    try { $db->rollBack(); } catch (Throwable) {}
    $message = 'Verification error. Please contact support.';
}

if ($success) {
    if ($type === 'contest') {
        redirect('/dashboard?success=contest_funded');
    } else {
        redirect('/wallet?deposit=success');
    }
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
    <?php if ($type === 'deposit'): ?>
      <a href="/wallet?deposit=failed" class="btn btn-outline-accent">Back to Wallet</a>
    <?php else: ?>
      <a href="/dashboard" class="btn btn-outline-accent">Go to Dashboard</a>
    <?php endif; ?>
  </div>
</div>

<?php renderFooter(); ?>
