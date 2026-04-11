<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$action = sanitizeInput($_REQUEST['action'] ?? '');

switch ($action) {
    case 'get_banks':
        handleGetBanks();
        break;
    case 'verify_account':
        handleVerifyAccount();
        break;
    case 'init_deposit':
        handleInitDeposit();
        break;
    case 'verify_deposit':
        handleVerifyDeposit();
        break;
    case 'request_payout':
        handleRequestPayout();
        break;
    case 'submit_appeal':
        handleSubmitAppeal();
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
}

function handleGetBanks(): never {
    $result = paystackGet('/bank?country=nigeria&perPage=100&use_cursor=false');
    if (!empty($result['data']) && is_array($result['data'])) {
        $banks = array_map(fn($b) => ['code' => $b['code'], 'name' => $b['name']], $result['data']);
        jsonResponse(['success' => true, 'banks' => $banks]);
    }
    // Hardcoded fallback
    $banks = [
        ['code' => '044', 'name' => 'Access Bank'],
        ['code' => '063', 'name' => 'Access Bank (Diamond)'],
        ['code' => '035A', 'name' => 'ALAT by Wema'],
        ['code' => '023', 'name' => 'Citibank Nigeria'],
        ['code' => '050', 'name' => 'Ecobank Nigeria'],
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
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }
    $accountNumber = sanitizeInput($_POST['account_number'] ?? '');
    $bankCode      = sanitizeInput($_POST['bank_code'] ?? '');
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
    jsonResponse(['success' => true, 'account_name' => $result['data']['account_name'] ?? '']);
}

function handleInitDeposit(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }
    $userId    = (int)$_SESSION['user_id'];
    $userEmail = $_SESSION['user_email'] ?? '';
    $amount    = (float)($_POST['amount'] ?? 0);

    if ($amount < 100) {
        jsonResponse(['success' => false, 'message' => 'Minimum deposit is ₦100.']);
    }

    $amountKobo = (int)round($amount * 100);
    $reference  = 'CLPZ_DEP_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4));

    $result = paystackPost('/transaction/initialize', [
        'email'     => $userEmail,
        'amount'    => $amountKobo,
        'reference' => $reference,
        'metadata'  => ['user_id' => $userId, 'type' => 'wallet_deposit'],
        'callback_url' => rtrim(getSetting('site_url', ''), '/') . '/ajax/wallet_actions.php?action=verify_deposit&reference=' . urlencode($reference),
    ]);

    if (!empty($result['error'])) {
        jsonResponse(['success' => false, 'message' => $result['error']]);
    }
    if (empty($result['status']) || !$result['status']) {
        jsonResponse(['success' => false, 'message' => $result['message'] ?? 'Paystack error.']);
    }

    // Store pending transaction
    try {
        $db = db();
        $db->prepare(
            "INSERT INTO transactions (user_id, amount, type, status, reference, description) VALUES (?, ?, 'credit', 'pending', ?, ?)"
        )->execute([$userId, $amount, $reference, 'Wallet deposit']);
    } catch (Throwable) {}

    jsonResponse([
        'success'           => true,
        'authorization_url' => $result['data']['authorization_url'] ?? '',
        'reference'         => $reference,
    ]);
}

function handleVerifyDeposit(): never {
    $reference = sanitizeInput($_REQUEST['reference'] ?? '');
    if (empty($reference)) {
        // Redirect to wallet on missing reference
        header('Location: /wallet');
        exit;
    }

    $result = paystackGet('/transaction/verify/' . urlencode($reference));

    if (!empty($result['error']) || empty($result['data']['status']) || $result['data']['status'] !== 'success') {
        header('Location: /wallet?deposit=failed');
        exit;
    }

    try {
        $db   = db();
        $stmt = $db->prepare("SELECT * FROM transactions WHERE reference = ? AND type = 'credit' LIMIT 1");
        $stmt->execute([$reference]);
        $tx = $stmt->fetch();

        if (!$tx) {
            header('Location: /wallet?deposit=not_found');
            exit;
        }

        if ($tx['status'] === 'completed') {
            header('Location: /wallet?deposit=already');
            exit;
        }

        $db->beginTransaction();
        $db->prepare("UPDATE transactions SET status = 'completed' WHERE reference = ?")->execute([$reference]);
        $db->prepare("UPDATE user_profiles SET wallet_balance = wallet_balance + ? WHERE user_id = ?")->execute([$tx['amount'], $tx['user_id']]);
        $db->commit();

        sendNotification((int)$tx['user_id'], 'deposit', 'Deposit Successful', '₦' . number_format((float)$tx['amount'], 0) . ' has been added to your wallet.', '/wallet');
        header('Location: /wallet?deposit=success');
        exit;
    } catch (Throwable) {
        try { $db->rollBack(); } catch (Throwable) {}
        header('Location: /wallet?deposit=error');
        exit;
    }
}

function handleRequestPayout(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }

    $userId     = (int)$_SESSION['user_id'];
    $amount     = (float)($_POST['amount'] ?? 0);
    $bankCode   = sanitizeInput($_POST['bank_code'] ?? '');
    $bankName   = sanitizeInput($_POST['bank_name'] ?? '');
    $acctNum    = sanitizeInput($_POST['account_number'] ?? '');
    $acctName   = sanitizeInput($_POST['account_name'] ?? '');

    $minW = (float)getSetting('min_withdrawal_amount', '1000');
    $maxW = (float)getSetting('max_withdrawal_amount', '500000');

    if ($amount < $minW) {
        jsonResponse(['success' => false, 'message' => 'Minimum withdrawal is ₦' . number_format($minW, 0) . '.']);
    }
    if ($amount > $maxW) {
        jsonResponse(['success' => false, 'message' => 'Maximum withdrawal is ₦' . number_format($maxW, 0) . '.']);
    }
    if (empty($bankCode) || empty($acctNum) || empty($acctName)) {
        jsonResponse(['success' => false, 'message' => 'Please verify your bank account first.']);
    }
    if (!preg_match('/^\d{10}$/', $acctNum)) {
        jsonResponse(['success' => false, 'message' => 'Invalid account number.']);
    }

    try {
        $db   = db();

        // Check KYC
        $stmt = $db->prepare("SELECT kyc_status, wallet_balance FROM user_profiles WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();

        if (!$profile || ($profile['kyc_status'] ?? 'none') !== 'approved') {
            jsonResponse(['success' => false, 'message' => 'KYC verification required before requesting payouts.']);
        }

        $balance = (float)($profile['wallet_balance'] ?? 0);
        if ($amount > $balance) {
            jsonResponse(['success' => false, 'message' => 'Insufficient wallet balance.']);
        }

        // Check for existing pending or on_hold request
        $stmt = $db->prepare("SELECT id FROM payout_requests WHERE user_id = ? AND status IN ('pending', 'on_hold') LIMIT 1");
        $stmt->execute([$userId]);
        if ($stmt->fetch()) {
            jsonResponse(['success' => false, 'message' => 'You already have a pending or on-hold payout request.']);
        }

        $db->beginTransaction();

        // Deduct from wallet
        $db->prepare("UPDATE user_profiles SET wallet_balance = wallet_balance - ? WHERE user_id = ?")->execute([$amount, $userId]);

        // Record transaction
        $db->prepare(
            "INSERT INTO transactions (user_id, amount, type, status, description) VALUES (?, ?, 'withdrawal', 'pending', 'Payout request')"
        )->execute([$userId, $amount]);

        // Create payout request
        $db->prepare(
            "INSERT INTO payout_requests (user_id, amount, status, bank_name, bank_code, account_number, account_name)
             VALUES (?, ?, 'pending', ?, ?, ?, ?)"
        )->execute([$userId, $amount, $bankName, $bankCode, $acctNum, $acctName]);

        $db->commit();

        // Notify user
        sendNotification($userId, 'payout', 'Payout Request Submitted', 'Your payout request for ₦' . number_format($amount, 0) . ' is pending admin review.', '/wallet');

        // Notify admin(s)
        try {
            $admins = $db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll();
            foreach ($admins as $admin) {
                sendNotification((int)$admin['id'], 'payout_admin', 'New Payout Request', 'User has requested a payout of ₦' . number_format($amount, 0) . '.', '/admin/payouts.php');
            }
        } catch (Throwable) {}

        jsonResponse(['success' => true, 'message' => 'Payout request submitted! Admin will review within 24 hours.']);
    } catch (Throwable) {
        try { $db->rollBack(); } catch (Throwable) {}
        jsonResponse(['success' => false, 'message' => 'Failed to submit request. Please try again.']);
    }
}

function handleSubmitAppeal(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }

    $userId    = (int)$_SESSION['user_id'];
    $requestId = (int)($_POST['payout_request_id'] ?? 0);
    $message   = sanitizeInput($_POST['appeal_message'] ?? '');

    if (empty($message)) {
        jsonResponse(['success' => false, 'message' => 'Appeal message is required.']);
    }

    try {
        $db   = db();
        $stmt = $db->prepare("SELECT * FROM payout_requests WHERE id = ? AND user_id = ? AND status = 'cancelled' LIMIT 1");
        $stmt->execute([$requestId, $userId]);
        $req = $stmt->fetch();

        if (!$req) {
            jsonResponse(['success' => false, 'message' => 'Request not found or not eligible for appeal.']);
        }

        if (!empty($req['appeal_message'])) {
            jsonResponse(['success' => false, 'message' => 'Appeal already submitted for this request.']);
        }

        $db->prepare("UPDATE payout_requests SET appeal_message = ?, status = 'on_hold', updated_at = NOW() WHERE id = ?")->execute([$message, $requestId]);

        sendNotification($userId, 'appeal', 'Appeal Submitted', 'Your appeal has been submitted and is under review.', '/wallet');

        // Notify admins
        $admins = $db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll();
        foreach ($admins as $admin) {
            sendNotification((int)$admin['id'], 'appeal_admin', 'Payout Appeal Submitted', 'A user has appealed a cancelled payout request.', '/admin/payouts.php');
        }

        jsonResponse(['success' => true, 'message' => 'Appeal submitted successfully.']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Failed to submit appeal.']);
    }
}
