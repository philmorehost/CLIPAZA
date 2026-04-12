<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/payhub.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$action = sanitizeInput($_POST['action'] ?? $_GET['action'] ?? '');

switch ($action) {
    case 'get_plans':      handleGetPlans();      break;
    case 'init_feature':   handleInitFeature();   break;
    case 'verify_feature': handleVerifyFeature(); break;
    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
}

function handleGetPlans(): never {
    try {
        $plans = db()->query("SELECT id, name, description, price, duration_days FROM featured_contest_plans WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
        jsonResponse(['success' => true, 'plans' => $plans]);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Failed to load plans.']);
    }
}

function handleInitFeature(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }

    $userId    = (int)$_SESSION['user_id'];
    $userEmail = $_SESSION['user_email'] ?? '';
    $contestId = (int)($_POST['contest_id'] ?? 0);
    $planId    = (int)($_POST['plan_id'] ?? 0);
    $gateway   = sanitizeInput($_POST['gateway'] ?? 'paystack');
    if (!in_array($gateway, ['paystack', 'payhub'], true)) $gateway = 'paystack';

    try {
        $db = db();
        $stmt = $db->prepare("SELECT * FROM contests WHERE id = ? AND creator_id = ? AND status = 'active' AND escrow_status = 'funded' LIMIT 1");
        $stmt->execute([$contestId, $userId]);
        $contest = $stmt->fetch();
        if (!$contest) {
            jsonResponse(['success' => false, 'message' => 'Contest not found or not eligible for featuring. Your contest must be active and funded.']);
        }

        $stmt = $db->prepare("SELECT * FROM featured_contest_plans WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();
        if (!$plan) {
            jsonResponse(['success' => false, 'message' => 'Plan not found.']);
        }

        $amount    = (float)$plan['price'];
        $reference = 'CLPZ_FEAT_' . $contestId . '_' . $planId . '_' . time() . '_' . bin2hex(random_bytes(4));

        if ($gateway === 'payhub') {
            if (!payhubEnabled()) {
                jsonResponse(['success' => false, 'message' => 'PayHub is not configured.']);
            }
            $callbackUrl = rtrim(getSetting('site_url', ''), '/') . '/payment/feature-verify.php?reference=' . urlencode($reference) . '&gateway=payhub';
            $result = payhubInitCheckout($userEmail, $amount, $reference, $callbackUrl, [
                'contest_id' => $contestId, 'plan_id' => $planId, 'user_id' => $userId, 'type' => 'feature',
            ]);
            if (!empty($result['error'])) {
                jsonResponse(['success' => false, 'message' => $result['error']]);
            }
            if (empty($result['status']) || !$result['status']) {
                jsonResponse(['success' => false, 'message' => $result['message'] ?? 'PayHub error.']);
            }
            $db->prepare("UPDATE contests SET featured_payment_ref = ?, featured_plan_id = ? WHERE id = ?")->execute([$reference, $planId, $contestId]);
            jsonResponse(['success' => true, 'checkout_url' => $result['data']['checkout_url'] ?? '', 'reference' => $reference]);
        } else {
            $amountKobo = (int)round($amount * 100);
            $callbackUrl = rtrim(getSetting('site_url', ''), '/') . '/payment/feature-verify.php?reference=' . urlencode($reference) . '&gateway=paystack';
            $result = paystackPost('/transaction/initialize', [
                'email'        => $userEmail,
                'amount'       => $amountKobo,
                'reference'    => $reference,
                'metadata'     => ['contest_id' => $contestId, 'plan_id' => $planId, 'user_id' => $userId, 'type' => 'feature'],
                'callback_url' => $callbackUrl,
            ]);
            if (!empty($result['error'])) {
                jsonResponse(['success' => false, 'message' => $result['error']]);
            }
            if (empty($result['status']) || !$result['status']) {
                jsonResponse(['success' => false, 'message' => $result['message'] ?? 'Paystack error.']);
            }
            $db->prepare("UPDATE contests SET featured_payment_ref = ?, featured_plan_id = ? WHERE id = ?")->execute([$reference, $planId, $contestId]);
            jsonResponse(['success' => true, 'authorization_url' => $result['data']['authorization_url'] ?? '', 'reference' => $reference]);
        }
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Payment initialization failed.']);
    }
}

function handleVerifyFeature(): never {
    $reference = sanitizeInput($_GET['reference'] ?? $_POST['reference'] ?? '');
    $gateway   = sanitizeInput($_GET['gateway'] ?? $_POST['gateway'] ?? 'paystack');
    if (empty($reference)) {
        jsonResponse(['success' => false, 'message' => 'Reference required.']);
    }
    activateFeature($reference, $gateway);
}

function activateFeature(string $reference, string $gateway): never {
    try {
        $db   = db();
        $stmt = $db->prepare("SELECT c.*, fcp.duration_days FROM contests c LEFT JOIN featured_contest_plans fcp ON fcp.id = c.featured_plan_id WHERE c.featured_payment_ref = ? LIMIT 1");
        $stmt->execute([$reference]);
        $row = $stmt->fetch();

        if (!$row) {
            jsonResponse(['success' => false, 'message' => 'Payment reference not found.']);
        }
        if ((int)$row['is_featured'] === 1 && !empty($row['featured_until']) && strtotime($row['featured_until']) > time()) {
            jsonResponse(['success' => true, 'message' => 'Already featured.']);
        }

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
            jsonResponse(['success' => true, 'message' => 'Contest is now featured!']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Payment could not be verified.']);
        }
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Verification error.']);
    }
}
