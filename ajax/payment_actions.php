<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$action = sanitizeInput($_POST['action'] ?? ');

switch ($action) {
    case 'init_payment':
        handleInitPayment();
        break;
    case 'verify_payment':
        handleVerifyPayment();
        break;
    case 'init_deposit':
        handleInitDeposit();
        break;
    case 'verify_deposit':
        handleVerifyDeposit();
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
}

function paystackRequest(string $method, string $endpoint, array $data = []): array {
    $secretKey = getSetting('paystack_secret_key', ');
    if (defined('PAYSTACK_SECRET_KEY') && PAYSTACK_SECRET_KEY) {
        $secretKey = PAYSTACK_SECRET_KEY;
    }
    if (empty($secretKey)) {
        return ['error' => 'Paystack not configured.'];
    }

    $url = 'https://api.paystack.co' . $endpoint;
    $ch  = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/json',
        'Cache-Control: no-cache',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) return ['error' => $error];
    $decoded = json_decode($response, true);
    return $decoded ?: ['error' => 'Invalid response from Paystack.'];
}

function handleInitPayment(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? ')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }

    $userId    = (int)$_SESSION['user_id'];
    $userEmail = $_SESSION['user_email'] ?? ';
    $contestId = (int)($_POST['contest_id'] ?? 0);

    try {
        $db   = db();
        $stmt = $db->prepare(
            "SELECT * FROM contests WHERE id = ? AND creator_id = ? AND status = 'draft' AND escrow_status = 'unfunded' LIMIT 1"
        );
        $stmt->execute([$contestId, $userId]);
        $contest = $stmt->fetch();
        if (!$contest) {
            jsonResponse(['success' => false, 'message' => 'Contest not found or already funded.']);
        }

        $amountNaira = (float)$contest['total_amount'];
        $amountKobo  = (int)round($amountNaira * 100);
        $reference   = 'CLPZ_' . $contestId . '_' . time() . '_' . bin2hex(random_bytes(4));

        $result = paystackRequest('POST', '/transaction/initialize', [
            'email'     => $userEmail,
            'amount'    => $amountKobo,
            'reference' => $reference,
            'metadata'  => [
                'contest_id' => $contestId,
                'user_id'    => $userId,
            ],
            'callback_url' => rtrim(getSetting('site_url', '), '/') . '/payment/verify?reference=' . urlencode($reference),
        ]);

        if (!empty($result['error'])) {
            jsonResponse(['success' => false, 'message' => $result['error']]);
        }
        if (empty($result['status']) || !$result['status']) {
            jsonResponse(['success' => false, 'message' => $result['message'] ?? 'Paystack error.']);
        }

        // Store reference
        $db->prepare('UPDATE contests SET paystack_reference = ? WHERE id = ?')
           ->execute([$reference, $contestId]);

        jsonResponse([
            'success'           => true,
            'authorization_url' => $result['data']['authorization_url'] ?? ',
            'reference'         => $reference,
        ]);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Payment initialization failed.']);
    }
}

function handleInitDeposit(): never {
    if (empty($_SESSION['user_id'])) jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
    if (!verifyCsrfToken($_POST['csrf_token'] ?? ')) jsonResponse(['success' => false, 'message' => 'Invalid CSRF'], 403);

    $userId = (int)$_SESSION['user_id'];
    $email = $_SESSION['user_email'] ?? ';
    $amount = (float)($_POST['amount'] ?? 0);

    if ($amount < 500) jsonResponse(['success' => false, 'message' => 'Minimum deposit is ₦500.']);

    $amountKobo = (int)round($amount * 100);
    $reference = 'DEP_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4));

    $result = paystackRequest('POST', '/transaction/initialize', [
        'email' => $email,
        'amount' => $amountKobo,
        'reference' => $reference,
        'metadata' => ['type' => 'deposit', 'user_id' => $userId],
        'callback_url' => rtrim(getSetting('site_url', '), '/') . '/payment/verify-deposit?reference=' . urlencode($reference),
    ]);

    if (!empty($result['error'])) jsonResponse(['success' => false, 'message' => $result['error']]);
    if (empty($result['status'])) jsonResponse(['success' => false, 'message' => $result['message'] ?? 'Paystack error.']);

    // Record pending transaction
    try {
        db()->prepare("INSERT INTO transactions (user_id, amount, type, status, reference, description) VALUES (?, ?, 'credit', 'pending', ?, 'Wallet Deposit')")
           ->execute([$userId, $amount, $reference]);
    } catch (Throwable $e) {}

    jsonResponse(['success' => true, 'authorization_url' => $result['data']['authorization_url']]);
}

function handleVerifyDeposit(): never {
    $reference = sanitizeInput($_REQUEST['reference'] ?? ');
    if (empty($reference)) jsonResponse(['success' => false, 'message' => 'No reference']);

    $result = paystackRequest('GET', '/transaction/verify/' . urlencode($reference));
    if (!empty($result['error'])) jsonResponse(['success' => false, 'message' => $result['error']]);

    if (empty($result['data']['status']) || $result['data']['status'] !== 'success') {
        jsonResponse(['success' => false, 'message' => 'Payment failed']);
    }

    try {
        $db = db();
        $stmt = $db->prepare("SELECT * FROM transactions WHERE reference = ? AND type = 'credit' LIMIT 1");
        $stmt->execute([$reference]);
        $tx = $stmt->fetch();

        if (!$tx) jsonResponse(['success' => false, 'message' => 'Transaction not found']);
        if ($tx['status'] === 'completed') jsonResponse(['success' => true, 'message' => 'Already processed']);

        $db->beginTransaction();
        $db->prepare("UPDATE transactions SET status = 'completed' WHERE id = ?")->execute([$tx['id']]);
        $db->prepare("UPDATE user_profiles SET wallet_balance = wallet_balance + ? WHERE user_id = ?")->execute([$tx['amount'], $tx['user_id']]);

        // Ensure wallet_balance was updated
        $stmt = $db->prepare('SELECT wallet_balance FROM user_profiles WHERE user_id = ?');
        $stmt->execute([$tx['user_id']]);
        $newBalance = $stmt->fetchColumn();

        $db->commit();

        jsonResponse(['success' => true, 'message' => 'Wallet funded!']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonResponse(['success' => false, 'message' => 'Update failed']);
    }
}

function handleVerifyPayment(): never {
    $reference = sanitizeInput($_POST['reference'] ?? $_GET['reference'] ?? ');
    if (empty($reference)) {
        jsonResponse(['success' => false, 'message' => 'Payment reference is required.']);
    }

    $result = paystackRequest('GET', '/transaction/verify/' . urlencode($reference));

    if (!empty($result['error'])) {
        jsonResponse(['success' => false, 'message' => $result['error']]);
    }

    if (empty($result['data']['status']) || $result['data']['status'] !== 'success') {
        jsonResponse(['success' => false, 'message' => 'Payment not successful.', 'status' => $result['data']['status'] ?? 'unknown']);
    }

    try {
        $db   = db();
        $stmt = $db->prepare("SELECT * FROM contests WHERE paystack_reference = ? LIMIT 1");
        $stmt->execute([$reference]);
        $contest = $stmt->fetch();

        if (!$contest) {
            jsonResponse(['success' => false, 'message' => 'Contest not found for this reference.']);
        }

        if ($contest['escrow_status'] === 'funded') {
            jsonResponse(['success' => true, 'message' => 'Already funded.', 'already_funded' => true]);
        }

        $db->prepare(
            "UPDATE contests SET status = 'active', escrow_status = 'funded', funded_at = NOW() WHERE id = ?"
        )->execute([$contest['id']]);

        // Update creator total_spent
        $db->prepare(
            'UPDATE user_profiles SET total_spent = total_spent + ? WHERE user_id = ?'
        )->execute([$contest['total_amount'], $contest['creator_id']]);

        // Record transaction
        $db->prepare(
            "INSERT INTO transactions (user_id, amount, type, status, reference, description)
             VALUES (?, ?, 'debit', 'completed', ?, ?)"
        )->execute([
            $contest['creator_id'],
            $contest['total_amount'],
            $reference,
            'Contest funding: ' . $contest['title'],
        ]);

        jsonResponse(['success' => true, 'message' => 'Payment verified. Contest is now live!']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Database update failed.']);
    }
}
