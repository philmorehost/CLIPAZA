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
    case 'claim_prize':
        handleClaimPrize();
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
}

function paystackGet(string $endpoint): array {
    $secretKey = getSetting('paystack_secret_key', '');
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

    jsonResponse([
        'success'      => true,
        'account_name' => $result['data']['account_name'] ?? '',
    ]);
}

function handleClaimPrize(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }

    $userId      = (int)$_SESSION['user_id'];
    $entryId     = (int)($_POST['entry_id'] ?? 0);
    $bankCode    = sanitizeInput($_POST['bank_code'] ?? '');
    $bankName    = sanitizeInput($_POST['bank_name'] ?? '');
    $acctNum     = sanitizeInput($_POST['account_number'] ?? '');
    $acctName    = sanitizeInput($_POST['account_name'] ?? '');

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

        // Check not already claimed
        $stmt = $db->prepare('SELECT id FROM payouts WHERE entry_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$entryId, $userId]);
        if ($stmt->fetch()) {
            jsonResponse(['success' => false, 'message' => 'Prize already claimed.']);
        }

        $prizeAmount = round((float)$cp['prize_amount'] / (int)$cp['winner_count'], 2);

        $db->prepare(
            "INSERT INTO payouts (contest_id, user_id, entry_id, amount, platform, rank_position,
              status, bank_name, bank_code, account_number, account_name, nuban_verified, claimed_at)
             VALUES (?,?,?,?,?,?,'claimed',?,?,?,?,1,NOW())"
        )->execute([
            $entry['contest_id'], $userId, $entryId, $prizeAmount,
            $entry['platform'], $rank, $bankName, $bankCode, $acctNum, $acctName,
        ]);

        // Update user total_earned
        $db->prepare('UPDATE user_profiles SET total_earned = total_earned + ? WHERE user_id = ?')
           ->execute([$prizeAmount, $userId]);

        jsonResponse(['success' => true, 'message' => 'Prize claimed! Transfer will be processed within 24 hours.']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Failed to process claim.']);
    }
}
