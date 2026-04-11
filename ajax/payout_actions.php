<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$action = sanitizeInput($_REQUEST['action'] ?? ');

switch ($action) {
    case 'get_banks':
        handleGetBanks();
        break;
    case 'verify_account':
        handleVerifyAccount();
        break;
    case 'claim_prize':
        handleClaimPrize();
        break;
    case 'request_wallet_payout':
        handleWalletPayoutRequest();
        break;
    case 'submit_payout_appeal':
        handlePayoutAppeal();
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
}

function paystackGet(string $endpoint): array {
    $secretKey = getSetting('paystack_secret_key', ');
    if (defined('PAYSTACK_SECRET_KEY') && PAYSTACK_SECRET_KEY) {
        $secretKey = PAYSTACK_SECRET_KEY;
    }
    if (empty($secretKey)) return ['error' => 'Paystack not configured.'];

    $ch = curl_init('https://api.paystack.co' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secretKey],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => $err];
    return json_decode($response, true) ?: ['error' => 'Invalid response.'];
}

function handleGetBanks(): never {
    // Try Paystack /bank endpoint first
    $result = paystackGet('/bank?country=nigeria&perPage=100&use_cursor=false');
    if (!empty($result['data']) && is_array($result['data'])) {
        $banks = array_map(fn($b) => ['code' => $b['code'], 'name' => $b['name']], $result['data']);
        jsonResponse(['success' => true, 'banks' => $banks]);
    }

    // Hardcoded fallback: major Nigerian banks
    $banks = [
        ['code' => '044', 'name' => 'Access Bank'],
        ['code' => '063', 'name' => 'Access Bank (Diamond)'],
        ['code' => '035A', 'name' => 'ALAT by Wema'],
        ['code' => '401', 'name' => 'ASO Savings and Loans'],
        ['code' => '023', 'name' => 'Citibank Nigeria'],
        ['code' => '050', 'name' => 'Ecobank Nigeria'],
        ['code' => '562', 'name' => 'Ekondo Microfinance Bank'],
        ['code' => '070', 'name' => 'Fidelity Bank'],
        ['code' => '011', 'name' => 'First Bank of Nigeria'],
        ['code' => '214', 'name' => 'First City Monument Bank'],
        ['code' => '058', 'name' => 'Guaranty Trust Bank'],
        ['code' => '030', 'name' => 'Heritage Bank'],
        ['code' => '301', 'name' => 'Jaiz Bank'],
        ['code' => '082', 'name' => 'Keystone Bank'],
        ['code' => '526', 'name' => 'Moniepoint MFB'],
        ['code' => '076', 'name' => 'Polaris Bank'],
        ['code' => '101', 'name' => 'Providus Bank'],
        ['code' => '221', 'name' => 'Stanbic IBTC Bank'],
        ['code' => '068', 'name' => 'Standard Chartered Bank'],
        ['code' => '232', 'name' => 'Sterling Bank'],
        ['code' => '100', 'name' => 'SunTrust Bank'],
        ['code' => '032', 'name' => 'Union Bank of Nigeria'],
        ['code' => '033', 'name' => 'United Bank for Africa'],
        ['code' => '215', 'name' => 'Unity Bank'],
        ['code' => '035', 'name' => 'Wema Bank'],
        ['code' => '057', 'name' => 'Zenith Bank'],
        ['code' => '999992', 'name' => 'Opay (OPay Digital Services)'],
        ['code' => '999991', 'name' => 'PalmPay'],
        ['code' => '999993', 'name' => 'Kuda Bank'],
    ];
    jsonResponse(['success' => true, 'banks' => $banks]);
}

function handleVerifyAccount(): never {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? ')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }

    $accountNumber = sanitizeInput($_POST['account_number'] ?? ');
    $bankCode      = sanitizeInput($_POST['bank_code'] ?? ');

    if (!preg_match('/^\d{10}$/', $accountNumber)) {
        jsonResponse(['success' => false, 'message' => 'Account number must be 10 digits.']);
    }
    if (empty($bankCode)) {
        jsonResponse(['success' => false, 'message' => 'Bank code is required.']);
    }

    $result = paystackGet('/bank/resolve?account_number=' . urlencode($accountNumber) . '&bank_code=' . urlencode($bankCode));

    if (!empty($result['error'])) {
        jsonResponse(['success' => false, 'message' => $result['error']]);
    }
    if (empty($result['status']) || !$result['status']) {
        jsonResponse(['success' => false, 'message' => $result['message'] ?? 'Could not verify account.']);
    }

    jsonResponse([
        'success'      => true,
        'account_name' => $result['data']['account_name'] ?? ',
    ]);
}

function handleWalletPayoutRequest(): never {
    if (empty($_SESSION['user_id'])) jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
    if (!verifyCsrfToken($_POST['csrf_token'] ?? ')) jsonResponse(['success' => false, 'message' => 'Invalid CSRF'], 403);

    $userId = (int)$_SESSION['user_id'];
    $amount = (float)($_POST['amount'] ?? 0);

    if ($amount < 1000) jsonResponse(['success' => false, 'message' => 'Minimum withdrawal is ₦1,000.']);

    try {
        $db = db();
        $stmt = $db->prepare('SELECT wallet_balance, bank_name, bank_code, account_number, account_name, kyc_status FROM user_profiles WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();

        if (!$profile || $profile['kyc_status'] !== 'approved') {
            jsonResponse(['success' => false, 'message' => 'KYC verification required.']);
        }

        if ($profile['wallet_balance'] < $amount) {
            jsonResponse(['success' => false, 'message' => 'Insufficient balance.']);
        }

        $db->beginTransaction();
        // Deduct from wallet
        $db->prepare('UPDATE user_profiles SET wallet_balance = wallet_balance - ? WHERE user_id = ?')->execute([$amount, $userId]);

        // Create payout record
        $db->prepare("
            INSERT INTO payouts (user_id, amount, status, bank_name, bank_code, account_number, account_name, nuban_verified, created_at)
            VALUES (?, ?, 'claimed', ?, ?, ?, ?, 1, NOW())
        ")->execute([
            $userId, $amount, $profile['bank_name'], $profile['bank_code'], $profile['account_number'], $profile['account_name']
        ]);

        $db->commit();
        jsonResponse(['success' => true, 'message' => 'Withdrawal request submitted!']);
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        jsonResponse(['success' => false, 'message' => 'Failed to submit request: ' . $e->getMessage()]);
    }
}

function handlePayoutAppeal(): never {
    if (empty($_SESSION['user_id'])) jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
    if (!verifyCsrfToken($_POST['csrf_token'] ?? ')) jsonResponse(['success' => false, 'message' => 'Invalid CSRF'], 403);

    $userId = (int)$_SESSION['user_id'];
    $payoutId = (int)($_POST['payout_id'] ?? 0);
    $message = sanitizeInput($_POST['message'] ?? ');

    if (empty($message)) jsonResponse(['success' => false, 'message' => 'Appeal message is required.']);

    try {
        $db = db();
        $db->prepare("UPDATE payouts SET appeal_message = ? WHERE id = ? AND user_id = ? AND status IN ('rejected', 'cancelled')")
           ->execute([$message, $payoutId, $userId]);
        jsonResponse(['success' => true, 'message' => 'Appeal submitted.']);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to submit appeal.']);
    }
}

function handleClaimPrize(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? ')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }

    $userId      = (int)$_SESSION['user_id'];
    $entryId     = (int)($_POST['entry_id'] ?? 0);
    $bankCode    = sanitizeInput($_POST['bank_code'] ?? ');
    $bankName    = sanitizeInput($_POST['bank_name'] ?? ');
    $acctNum     = sanitizeInput($_POST['account_number'] ?? ');
    $acctName    = sanitizeInput($_POST['account_name'] ?? ');

    if (!preg_match('/^\d{10}$/', $acctNum)) {
        jsonResponse(['success' => false, 'message' => 'Invalid account number.']);
    }
    if (empty($acctName)) {
        jsonResponse(['success' => false, 'message' => 'Please verify your account first.']);
    }
    if (empty($bankCode)) {
        jsonResponse(['success' => false, 'message' => 'Please select a bank.']);
    }

    try {
        $db   = db();
        $stmt = $db->prepare('SELECT ce.*, c.id AS c_id FROM contest_entries ce INNER JOIN contests c ON c.id = ce.contest_id WHERE ce.id = ? AND ce.user_id = ? LIMIT 1');
        $stmt->execute([$entryId, $userId]);
        $entry = $stmt->fetch();

        if (!$entry) {
            jsonResponse(['success' => false, 'message' => 'Entry not found.']);
        }

        // Verify they are a winner
        $stmt = $db->prepare(
            "SELECT cp.prize_amount, cp.winner_count FROM contest_platforms cp
             WHERE cp.contest_id = ? AND cp.platform = ? LIMIT 1"
        );
        $stmt->execute([$entry['contest_id'], $entry['platform']]);
        $cp = $stmt->fetch();
        if (!$cp) {
            jsonResponse(['success' => false, 'message' => 'Platform not found for this contest.']);
        }

        // Calculate rank
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM contest_entries
             WHERE contest_id = ? AND platform = ? AND disqualified = 0
             AND view_count > (SELECT view_count FROM contest_entries WHERE id = ?)"
        );
        $stmt->execute([$entry['contest_id'], $entry['platform'], $entryId]);
        $rank = (int)$stmt->fetchColumn() + 1;

        if ($rank > (int)$cp['winner_count']) {
            jsonResponse(['success' => false, 'message' => 'Your rank does not qualify for a prize.']);
        }

        // Check for existing pending payout record
        $stmt = $db->prepare('SELECT * FROM payouts WHERE entry_id = ? AND user_id = ? AND status = \'pending\' LIMIT 1');
        $stmt->execute([$entryId, $userId]);
        $existingPayout = $stmt->fetch();

        if (!$existingPayout) {
            // Check if it was already claimed/processed
            $stmt = $db->prepare('SELECT id FROM payouts WHERE entry_id = ? AND user_id = ? AND status IN (\'claimed\', \'processing\', \'completed\') LIMIT 1');
            $stmt->execute([$entryId, $userId]);
            if ($stmt->fetch()) {
                jsonResponse(['success' => false, 'message' => 'Prize already claimed or being processed.']);
            }
            jsonResponse(['success' => false, 'message' => 'No eligible payout record found.']);
        }

        $prizeAmount = (float)$existingPayout['amount'];

        // Paystack Payout Implementation
        $secretKey = getSetting('paystack_secret_key', ');
        if (defined('PAYSTACK_SECRET_KEY') && PAYSTACK_SECRET_KEY) {
            $secretKey = PAYSTACK_SECRET_KEY;
        }

        if (empty($secretKey)) {
            jsonResponse(['success' => false, 'message' => 'Payout system is temporarily unavailable (not configured).']);
        }

        // 1. Create Transfer Recipient
        $recipientResponse = paystackPost('/transferrecipient', [
            'type' => 'nuban',
            'name' => $acctName,
            'account_number' => $acctNum,
            'bank_code' => $bankCode,
            'currency' => 'NGN'
        ]);

        if (empty($recipientResponse['status']) || !$recipientResponse['status']) {
            jsonResponse(['success' => false, 'message' => 'Failed to create transfer recipient: ' . ($recipientResponse['message'] ?? 'Unknown error')]);
        }

        $recipientCode = $recipientResponse['data']['recipient_code'];

        // 2. Initiate Transfer
        $transferResponse = paystackPost('/transfer', [
            'source' => 'balance',
            'amount' => (int)round($prizeAmount * 100), // in kobo
            'recipient' => $recipientCode,
            'reason' => "Clipaza Contest Prize: " . $entry['platform']
        ]);

        if (empty($transferResponse['status']) || !$transferResponse['status']) {
            // If transfer fails, update status to failed
            $db->prepare(
                "UPDATE payouts SET status = 'failed', bank_name = ?, bank_code = ?, account_number = ?, account_name = ?, nuban_verified = 1, claimed_at = NOW() WHERE id = ?"
            )->execute([$bankName, $bankCode, $acctNum, $acctName, $existingPayout['id']]);

            jsonResponse(['success' => false, 'message' => 'Transfer initiation failed: ' . ($transferResponse['message'] ?? 'Unknown error')]);
        }

        $transferCode = $transferResponse['data']['transfer_code'];
        $reference = $transferResponse['data']['reference'];

        $db->prepare(
            "UPDATE payouts SET status = 'processing', bank_name = ?, bank_code = ?, account_number = ?, account_name = ?, nuban_verified = 1, paystack_transfer_code = ?, paystack_reference = ?, claimed_at = NOW() WHERE id = ?"
        )->execute([
            $bankName, $bankCode, $acctNum, $acctName,
            $transferCode, $reference,
            $existingPayout['id']
        ]);

        // Update user total_earned
        $db->prepare('UPDATE user_profiles SET total_earned = total_earned + ? WHERE user_id = ?')
           ->execute([$prizeAmount, $userId]);

        jsonResponse(['success' => true, 'message' => 'Prize claimed! Your transfer is being processed. Reference: ' . $reference]);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to process claim: ' . $e->getMessage()]);
    }
}

function paystackPost(string $endpoint, array $data): array {
    $secretKey = getSetting('paystack_secret_key', ');
    if (defined('PAYSTACK_SECRET_KEY') && PAYSTACK_SECRET_KEY) {
        $secretKey = PAYSTACK_SECRET_KEY;
    }
    if (empty($secretKey)) return ['error' => 'Paystack not configured.'];

    $ch = curl_init('https://api.paystack.co' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => $err];
    return json_decode($response, true) ?: ['error' => 'Invalid response.'];
}
