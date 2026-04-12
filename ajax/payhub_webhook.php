<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/payhub.php';

// Read raw body before any output
$rawBody = (string)file_get_contents('php://input');

// Validate HMAC-SHA256 signature
$apiKey    = getSetting('payhub_api_key', '');
$signature = $_SERVER['HTTP_X_PAYHUB_SIGNATURE'] ?? '';

if (empty($apiKey) || empty($signature)) {
    http_response_code(401);
    exit;
}

$expectedSig = hash_hmac('sha256', $rawBody, $apiKey);
if (!hash_equals($expectedSig, strtolower($signature))) {
    http_response_code(401);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

$event     = $payload['event']     ?? '';
$reference = $payload['reference'] ?? ($payload['data']['reference'] ?? '');
$status    = $payload['data']['status'] ?? '';

try {
    $db = db();

    if ($event === 'payment.success' || ($event === '' && $status === 'success')) {
        // Try contest funding
        $stmt = $db->prepare("SELECT * FROM contests WHERE payhub_reference = ? LIMIT 1");
        $stmt->execute([$reference]);
        $contest = $stmt->fetch();

        if ($contest && $contest['escrow_status'] !== 'funded') {
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
                'Contest funding (PayHub webhook): ' . $contest['title'],
            ]);

            $db->commit();
            http_response_code(200);
            exit;
        }

        // Try wallet deposit
        $stmt = $db->prepare("SELECT * FROM transactions WHERE reference = ? AND type = 'credit' LIMIT 1");
        $stmt->execute([$reference]);
        $tx = $stmt->fetch();

        if ($tx && $tx['status'] !== 'completed') {
            $db->beginTransaction();
            $db->prepare("UPDATE transactions SET status = 'completed' WHERE reference = ?")->execute([$reference]);
            $db->prepare("UPDATE user_profiles SET wallet_balance = wallet_balance + ? WHERE user_id = ?")->execute([$tx['amount'], $tx['user_id']]);
            $db->commit();

            sendNotification((int)$tx['user_id'], 'deposit', 'Deposit Successful',
                '₦' . number_format((float)$tx['amount'], 0) . ' has been added to your wallet.', '/wallet');
        }
    }
} catch (Throwable) {
    try { $db->rollBack(); } catch (Throwable) {}
    http_response_code(500);
    exit;
}

http_response_code(200);
