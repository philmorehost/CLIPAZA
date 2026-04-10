<?php
declare(strict_types=1);
session_start();

$root = dirname(dirname(__DIR__));
require_once $root . '/config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/security.php';
require_once $root . '/includes/auth.php';

requireAdmin();
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
}

$action = sanitizeInput($_POST['action'] ?? '');

switch ($action) {
    case 'update_user_status':
        handleUpdateUserStatus();
        break;
    case 'update_contest_status':
        handleUpdateContestStatus();
        break;
    case 'process_payout':
        handleProcessPayout();
        break;
    case 'update_views':
        handleUpdateViews();
        break;
    case 'disqualify_entry':
        handleDisqualifyEntry();
        break;
    case 'update_payout_status':
        handleUpdatePayoutStatus();
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
}

function handleUpdateUserStatus(): never {
    $userId = (int)($_POST['user_id'] ?? 0);
    $status = sanitizeInput($_POST['status'] ?? '');
    if (!in_array($status, ['active', 'inactive', 'banned', 'pending'], true)) {
        jsonResponse(['success' => false, 'message' => 'Invalid status.']);
    }
    try {
        db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $userId]);
        jsonResponse(['success' => true, 'message' => 'User status updated.']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Update failed.']);
    }
}

function handleUpdateContestStatus(): never {
    $contestId = (int)($_POST['contest_id'] ?? 0);
    $status    = sanitizeInput($_POST['status'] ?? '');
    if (!in_array($status, ['draft', 'active', 'expired', 'cancelled'], true)) {
        jsonResponse(['success' => false, 'message' => 'Invalid status.']);
    }
    try {
        db()->prepare('UPDATE contests SET status = ? WHERE id = ?')->execute([$status, $contestId]);
        jsonResponse(['success' => true, 'message' => 'Contest status updated.']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Update failed.']);
    }
}

function handleProcessPayout(): never {
    $payoutId = (int)($_POST['payout_id'] ?? 0);
    try {
        $db   = db();
        $stmt = $db->prepare("SELECT * FROM payouts WHERE id = ? AND status = 'claimed' LIMIT 1");
        $stmt->execute([$payoutId]);
        $payout = $stmt->fetch();
        if (!$payout) jsonResponse(['success' => false, 'message' => 'Payout not found or not claimable.']);

        $secretKey = getSetting('paystack_secret_key', '');
        if (defined('PAYSTACK_SECRET_KEY') && PAYSTACK_SECRET_KEY) {
            $secretKey = PAYSTACK_SECRET_KEY;
        }

        if (empty($secretKey)) {
            jsonResponse(['success' => false, 'message' => 'Paystack not configured.']);
        }

        // 1. Create Transfer Recipient
        $ch = curl_init('https://api.paystack.co/transferrecipient');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'type' => 'nuban',
            'name' => $payout['account_name'],
            'account_number' => $payout['account_number'],
            'bank_code' => $payout['bank_code'],
            'currency' => 'NGN'
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/json'
        ]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!$resp['status']) {
            jsonResponse(['success' => false, 'message' => 'Failed to create recipient: ' . ($resp['message'] ?? 'Unknown error')]);
        }

        $recipientCode = $resp['data']['recipient_code'];

        // 2. Initiate Transfer
        $ch = curl_init('https://api.paystack.co/transfer');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'source' => 'balance',
            'amount' => (int)round((float)$payout['amount'] * 100),
            'recipient' => $recipientCode,
            'reason' => 'Clipaza Payout'
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/json'
        ]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!$resp['status']) {
            jsonResponse(['success' => false, 'message' => 'Failed to initiate transfer: ' . ($resp['message'] ?? 'Unknown error')]);
        }

        $db->prepare("UPDATE payouts SET status = 'processing', paystack_transfer_code = ?, paystack_reference = ? WHERE id = ?")
           ->execute([$resp['data']['transfer_code'], $resp['data']['reference'], $payoutId]);

        jsonResponse(['success' => true, 'message' => 'Payout initiated successfully. Status: ' . $resp['data']['status']]);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to process payout: ' . $e->getMessage()]);
    }
}

function handleDisqualifyEntry(): never {
    $entryId = (int)($_POST['entry_id'] ?? 0);
    $reason  = sanitizeInput($_POST['reason'] ?? 'Disqualified by admin');
    try {
        db()->prepare('UPDATE contest_entries SET disqualified = 1, disqualify_reason = ? WHERE id = ?')
           ->execute([$reason, $entryId]);
        jsonResponse(['success' => true, 'message' => 'Entry disqualified.']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Update failed.']);
    }
}

function handleUpdatePayoutStatus(): never {
    $payoutId = (int)($_POST['payout_id'] ?? 0);
    $status   = sanitizeInput($_POST['status'] ?? '');
    if (!in_array($status, ['pending', 'claimed', 'processing', 'completed', 'failed'], true)) {
        jsonResponse(['success' => false, 'message' => 'Invalid status.']);
    }
    try {
        db()->prepare('UPDATE payouts SET status = ? WHERE id = ?')->execute([$status, $payoutId]);
        jsonResponse(['success' => true, 'message' => 'Payout status updated.']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Update failed.']);
    }
}

function handleUpdateViews(): never {
    $entryId   = (int)($_POST['entry_id'] ?? 0);
    $views     = max(0, (int)($_POST['view_count'] ?? 0));
    $likes     = max(0, (int)($_POST['like_count'] ?? 0));
    $comments  = max(0, (int)($_POST['comment_count'] ?? 0));
    try {
        db()->prepare(
            'UPDATE contest_entries SET view_count = ?, like_count = ?, comment_count = ? WHERE id = ?'
        )->execute([$views, $likes, $comments, $entryId]);
        jsonResponse(['success' => true, 'message' => 'Entry stats updated.']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Update failed.']);
    }
}
