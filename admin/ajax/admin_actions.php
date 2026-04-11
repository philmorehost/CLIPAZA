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
    case 'approve':
    case 'reject':
    case 'cancel':
    case 'restore':
        handlePayoutRequestAction($action);
        break;
    case 'login_as_user':
        handleLoginAsUser();
        break;
    case 'edit_user':
        handleEditUser();
        break;
    case 'approve_kyc':
    case 'reject_kyc':
        handleKycAction($action);
        break;
    case 'update_admin_profile':
        handleUpdateAdminProfile();
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
        if (empty($secretKey)) {
            jsonResponse(['success' => false, 'message' => 'Paystack not configured.']);
        }

        // Create transfer recipient
        $recpData = [
            'type'           => 'nuban',
            'name'           => $payout['account_name'],
            'account_number' => $payout['account_number'],
            'bank_code'      => '', // Bank code not stored; log for manual
            'currency'       => 'NGN',
        ];

        $db->prepare("UPDATE payouts SET status = 'processing' WHERE id = ?")->execute([$payoutId]);
        jsonResponse(['success' => true, 'message' => 'Payout marked as processing. Complete via Paystack dashboard.']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Failed to process payout.']);
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

function handlePayoutRequestAction(string $action): never {
    $requestId = (int)($_POST['payout_request_id'] ?? 0);
    $reason    = sanitizeInput($_POST['reason'] ?? '');
    $adminNote = sanitizeInput($_POST['admin_note'] ?? '');
    $adminId   = (int)($_SESSION['user_id'] ?? 0);

    if (!$requestId) {
        jsonResponse(['success' => false, 'message' => 'Invalid request ID.']);
    }

    try {
        $db   = db();
        $stmt = $db->prepare("SELECT * FROM payout_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();

        if (!$req) {
            jsonResponse(['success' => false, 'message' => 'Payout request not found.']);
        }

        $userId = (int)$req['user_id'];
        $amount = (float)$req['amount'];

        if ($action === 'approve') {
            if (!in_array($req['status'], ['pending', 'on_hold'], true)) {
                jsonResponse(['success' => false, 'message' => 'Only pending or on-hold requests can be approved.']);
            }

            // Attempt Paystack transfer
            $transferResult = initiatePaystackTransfer($req);
            $transferCode   = $transferResult['transfer_code'] ?? null;
            $reference      = $transferResult['reference'] ?? null;

            // Warn admin when Paystack is not configured or transfer failed
            $paystackNote = '';
            if (($transferResult['status'] ?? '') === 'skipped') {
                $paystackNote = ' (Paystack not configured — manual bank transfer required)';
            } elseif (in_array($transferResult['status'] ?? '', ['recipient_error', 'transfer_error'], true)) {
                $paystackNote = ' (Paystack error: ' . ($transferResult['error'] ?? 'unknown') . ' — manual transfer required)';
            }

            $db->beginTransaction();
            $db->prepare(
                "UPDATE payout_requests SET status = 'approved', processed_by = ?, processed_at = NOW(),
                 admin_note = ?, paystack_transfer_code = ?, paystack_reference = ?, updated_at = NOW()
                 WHERE id = ?"
            )->execute([$adminId, $adminNote ?: null, $transferCode, $reference, $requestId]);

            // Update matching pending withdrawal transaction to completed
            $db->prepare(
                "UPDATE transactions SET status = 'completed' WHERE user_id = ? AND type = 'withdrawal' AND status = 'pending' AND description = 'Payout request' ORDER BY created_at DESC LIMIT 1"
            )->execute([$userId]);

            $db->commit();

            sendNotification($userId, 'payout_approved', 'Payout Approved! 🎉',
                '₦' . number_format($amount, 0) . ' has been approved and transfer initiated to your bank account.', '/wallet');
            sendNotification($adminId, 'admin_note', 'Payout Approved',
                'Payout of ₦' . number_format($amount, 0) . ' approved for user #' . $userId . $paystackNote . '.', '/admin/payouts.php');

            jsonResponse(['success' => true, 'message' => 'Payout approved and transfer initiated.' . $paystackNote]);

        } elseif ($action === 'reject') {
            if (empty($reason)) {
                jsonResponse(['success' => false, 'message' => 'Rejection reason is required.']);
            }
            if (!in_array($req['status'], ['pending', 'on_hold'], true)) {
                jsonResponse(['success' => false, 'message' => 'Only pending or on-hold requests can be rejected.']);
            }

            $db->beginTransaction();
            $db->prepare(
                "UPDATE payout_requests SET status = 'rejected', rejection_reason = ?, admin_note = ?,
                 processed_by = ?, processed_at = NOW(), updated_at = NOW() WHERE id = ?"
            )->execute([$reason, $adminNote ?: null, $adminId, $requestId]);

            // Reverse amount to wallet
            $db->prepare("UPDATE user_profiles SET wallet_balance = wallet_balance + ? WHERE user_id = ?")->execute([$amount, $userId]);

            // Update matching pending withdrawal transaction to failed
            $db->prepare(
                "UPDATE transactions SET status = 'failed' WHERE user_id = ? AND type = 'withdrawal' AND status = 'pending' AND description = 'Payout request' ORDER BY created_at DESC LIMIT 1"
            )->execute([$userId]);

            // Credit refund transaction
            $db->prepare(
                "INSERT INTO transactions (user_id, amount, type, status, description) VALUES (?, ?, 'refund', 'completed', 'Payout rejected — refunded to wallet')"
            )->execute([$userId, $amount]);

            $db->commit();

            sendNotification($userId, 'payout_rejected', 'Payout Rejected',
                '₦' . number_format($amount, 0) . ' has been returned to your wallet. Reason: ' . $reason, '/wallet');
            sendNotification($adminId, 'admin_note', 'Payout Rejected',
                'Payout of ₦' . number_format($amount, 0) . ' rejected for user #' . $userId . '.', '/admin/payouts.php');

            jsonResponse(['success' => true, 'message' => 'Payout rejected and amount refunded to user wallet.']);

        } elseif ($action === 'cancel') {
            if (empty($reason)) {
                jsonResponse(['success' => false, 'message' => 'Cancellation reason is required.']);
            }
            if ($req['status'] !== 'pending') {
                jsonResponse(['success' => false, 'message' => 'Only pending requests can be cancelled.']);
            }

            $db->prepare(
                "UPDATE payout_requests SET status = 'cancelled', cancel_reason = ?, admin_note = ?,
                 processed_by = ?, processed_at = NOW(), updated_at = NOW() WHERE id = ?"
            )->execute([$reason, $adminNote ?: null, $adminId, $requestId]);

            sendNotification($userId, 'payout_cancelled', 'Payout On Hold',
                'Your payout of ₦' . number_format($amount, 0) . ' has been placed on hold. Reason: ' . $reason . '. You may submit an appeal.', '/wallet');

            jsonResponse(['success' => true, 'message' => 'Payout cancelled. User notified.']);

        } elseif ($action === 'restore') {
            if ($req['status'] !== 'on_hold') {
                jsonResponse(['success' => false, 'message' => 'Only on-hold requests can be restored to pending.']);
            }

            $db->prepare(
                "UPDATE payout_requests SET status = 'pending', admin_note = ?, updated_at = NOW() WHERE id = ?"
            )->execute([$adminNote ?: null, $requestId]);

            sendNotification($userId, 'payout_restored', 'Payout Under Review',
                'Your payout request has been restored to pending status and is being reviewed.', '/wallet');

            jsonResponse(['success' => true, 'message' => 'Request restored to pending.']);
        }

        jsonResponse(['success' => false, 'message' => 'Unknown action.']);
    } catch (Throwable $e) {
        try { $db->rollBack(); } catch (Throwable) {}
        jsonResponse(['success' => false, 'message' => 'Action failed: ' . $e->getMessage()]);
    }
}

function initiatePaystackTransfer(array $req): array {
    $secretKey = getSetting('paystack_secret_key', '');
    if (defined('PAYSTACK_SECRET_KEY') && PAYSTACK_SECRET_KEY) {
        $secretKey = PAYSTACK_SECRET_KEY;
    }
    if (empty($secretKey)) {
        return ['status' => 'skipped', 'error' => 'Paystack not configured'];
    }

    // 1. Create transfer recipient
    $recipientResult = paystackPost('/transferrecipient', [
        'type'           => 'nuban',
        'name'           => $req['account_name'],
        'account_number' => $req['account_number'],
        'bank_code'      => $req['bank_code'],
        'currency'       => 'NGN',
    ]);

    if (empty($recipientResult['data']['recipient_code'])) {
        return ['status' => 'recipient_error', 'error' => $recipientResult['message'] ?? 'Could not create recipient'];
    }

    $recipientCode = $recipientResult['data']['recipient_code'];
    $reference     = 'CLPZ_PAY_' . $req['id'] . '_' . time();

    // 2. Initiate transfer
    $transferResult = paystackPost('/transfer', [
        'source'    => 'balance',
        'amount'    => (int)round((float)$req['amount'] * 100),
        'recipient' => $recipientCode,
        'reason'    => 'Clipaza wallet withdrawal',
        'reference' => $reference,
    ]);

    if (!empty($transferResult['data']['transfer_code'])) {
        return [
            'status'        => 'initiated',
            'transfer_code' => $transferResult['data']['transfer_code'],
            'reference'     => $reference,
        ];
    }

    return ['status' => 'transfer_error', 'error' => $transferResult['message'] ?? 'Transfer failed', 'reference' => $reference];
}

function handleLoginAsUser(): never {
    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
    if (!$targetUserId) {
        jsonResponse(['success' => false, 'message' => 'Invalid user ID.']);
    }
    try {
        $db   = db();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND role != 'admin' LIMIT 1");
        $stmt->execute([$targetUserId]);
        $user = $stmt->fetch();
        if (!$user) {
            jsonResponse(['success' => false, 'message' => 'User not found or cannot impersonate admin.']);
        }

        // Store admin session for return
        $_SESSION['admin_impersonating'] = true;
        $_SESSION['original_admin_id']   = $_SESSION['user_id'];
        $_SESSION['original_admin_name'] = $_SESSION['username'];

        // Switch to user session
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['user_role']  = $user['role'];
        $_SESSION['user_email'] = $user['email'];

        // Load user mode
        try {
            $ps = $db->prepare('SELECT active_mode FROM user_profiles WHERE user_id = ? LIMIT 1');
            $ps->execute([$user['id']]);
            $profile = $ps->fetch();
            $_SESSION['user_mode'] = $profile ? $profile['active_mode'] : 'clipper';
        } catch (Throwable) {
            $_SESSION['user_mode'] = 'clipper';
        }

        jsonResponse(['success' => true, 'redirect' => '/dashboard']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Failed to switch user.']);
    }
}

function handleEditUser(): never {
    $userId      = (int)($_POST['edit_user_id'] ?? 0);
    $email       = sanitizeInput($_POST['email'] ?? '');
    $username    = sanitizeInput($_POST['username'] ?? '');
    $role        = sanitizeInput($_POST['role'] ?? '');
    $status      = sanitizeInput($_POST['status'] ?? '');
    $displayName = sanitizeInput($_POST['display_name'] ?? '');
    $walletAdjust = (float)($_POST['wallet_adjust'] ?? 0);
    $newPassword = $_POST['new_password'] ?? '';

    if (!$userId) {
        jsonResponse(['success' => false, 'message' => 'Invalid user ID.']);
    }
    if (!isValidEmail($email)) {
        jsonResponse(['success' => false, 'message' => 'Invalid email address.']);
    }
    if (!in_array($role, ['admin', 'user', 'moderator'], true)) {
        jsonResponse(['success' => false, 'message' => 'Invalid role.']);
    }
    if (!in_array($status, ['active', 'inactive', 'banned', 'pending'], true)) {
        jsonResponse(['success' => false, 'message' => 'Invalid status.']);
    }

    try {
        $db = db();

        // Check email uniqueness
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) {
            jsonResponse(['success' => false, 'message' => 'Email already in use by another account.']);
        }

        $updateFields = 'email = ?, role = ?, status = ?';
        $params = [$email, $role, $status];
        if (!empty($newPassword)) {
            if (strlen($newPassword) < 8) {
                jsonResponse(['success' => false, 'message' => 'Password must be at least 8 characters.']);
            }
            $updateFields .= ', password = ?';
            $params[] = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        }
        $params[] = $userId;
        $db->prepare("UPDATE users SET {$updateFields} WHERE id = ?")->execute($params);

        // Update profile
        $db->prepare(
            "UPDATE user_profiles SET display_name = ? WHERE user_id = ?"
        )->execute([$displayName ?: null, $userId]);

        // Wallet adjustment
        if ($walletAdjust != 0) {
            if ($walletAdjust < 0) {
                // Ensure balance doesn't go negative
                $checkStmt = $db->prepare("SELECT wallet_balance FROM user_profiles WHERE user_id = ? LIMIT 1");
                $checkStmt->execute([$userId]);
                $currentBalance = (float)($checkStmt->fetchColumn() ?: 0);
                if ($currentBalance + $walletAdjust < 0) {
                    jsonResponse(['success' => false, 'message' => 'Deduction would make wallet balance negative. Current balance: ₦' . number_format($currentBalance, 2)]);
                }
            }
            $db->prepare("UPDATE user_profiles SET wallet_balance = wallet_balance + ? WHERE user_id = ?")->execute([$walletAdjust, $userId]);
            $txType = $walletAdjust > 0 ? 'credit' : 'debit';
            $db->prepare(
                "INSERT INTO transactions (user_id, amount, type, status, description) VALUES (?, ?, ?, 'completed', 'Admin wallet adjustment')"
            )->execute([$userId, abs($walletAdjust), $txType]);
            sendNotification($userId, 'wallet', 'Wallet Adjusted',
                'Your wallet has been adjusted by ₦' . number_format(abs($walletAdjust), 0) . ' by an administrator.', '/wallet');
        }

        jsonResponse(['success' => true, 'message' => 'User updated successfully.']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Update failed.']);
    }
}

function handleKycAction(string $action): never {
    $userId = (int)($_POST['kyc_user_id'] ?? 0);
    $reason = sanitizeInput($_POST['reason'] ?? '');

    if (!$userId) {
        jsonResponse(['success' => false, 'message' => 'Invalid user ID.']);
    }

    try {
        $db = db();
        if ($action === 'approve_kyc') {
            $db->prepare("UPDATE user_profiles SET kyc_status = 'approved', kyc_rejection_reason = NULL WHERE user_id = ?")->execute([$userId]);
            sendNotification($userId, 'kyc', 'KYC Approved ✅', 'Your identity verification has been approved. You can now request payouts.', '/wallet');
            jsonResponse(['success' => true, 'message' => 'KYC approved.']);
        } else {
            if (empty($reason)) {
                jsonResponse(['success' => false, 'message' => 'Rejection reason is required.']);
            }
            $db->prepare("UPDATE user_profiles SET kyc_status = 'rejected', kyc_rejection_reason = ? WHERE user_id = ?")->execute([$reason, $userId]);
            sendNotification($userId, 'kyc', 'KYC Rejected', 'Your KYC was rejected. Reason: ' . $reason . '. Please re-submit with valid documents.', '/kyc');
            jsonResponse(['success' => true, 'message' => 'KYC rejected.']);
        }
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Action failed.']);
    }
}

function handleUpdateAdminProfile(): never {
    $adminId  = (int)($_SESSION['user_id'] ?? 0);
    $field    = sanitizeInput($_POST['field'] ?? '');

    try {
        $db = db();

        if ($field === 'info') {
            $username = sanitizeInput($_POST['username'] ?? '');
            $email    = sanitizeInput($_POST['email'] ?? '');

            if ($username === '' || $email === '') {
                jsonResponse(['success' => false, 'message' => 'Username and email are required.']);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(['success' => false, 'message' => 'Invalid email address.']);
            }

            // Check uniqueness (exclude current admin)
            $chk = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
            $chk->execute([$username, $adminId]);
            if ($chk->fetch()) {
                jsonResponse(['success' => false, 'message' => 'Username is already taken.']);
            }
            $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $chk->execute([$email, $adminId]);
            if ($chk->fetch()) {
                jsonResponse(['success' => false, 'message' => 'Email is already in use.']);
            }

            $db->prepare("UPDATE users SET username = ?, email = ?, updated_at = NOW() WHERE id = ? AND role = 'admin'")
               ->execute([$username, $email, $adminId]);

            // Refresh session username
            $_SESSION['username'] = $username;

            jsonResponse(['success' => true, 'message' => 'Profile updated successfully.']);

        } elseif ($field === 'password') {
            $currentPw  = $_POST['current_password'] ?? '';
            $newPw      = $_POST['new_password'] ?? '';
            $confirmPw  = $_POST['confirm_password'] ?? '';

            if ($currentPw === '' || $newPw === '' || $confirmPw === '') {
                jsonResponse(['success' => false, 'message' => 'All password fields are required.']);
            }
            if ($newPw !== $confirmPw) {
                jsonResponse(['success' => false, 'message' => 'New passwords do not match.']);
            }
            if (strlen($newPw) < 8) {
                jsonResponse(['success' => false, 'message' => 'New password must be at least 8 characters.']);
            }

            $stmt = $db->prepare("SELECT password FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
            $stmt->execute([$adminId]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($currentPw, $row['password'])) {
                jsonResponse(['success' => false, 'message' => 'Current password is incorrect.']);
            }

            $hash = password_hash($newPw, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?")->execute([$hash, $adminId]);

            jsonResponse(['success' => true, 'message' => 'Password changed successfully.']);

        } else {
            jsonResponse(['success' => false, 'message' => 'Unknown profile field.']);
        }
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Profile update failed.']);
    }
}
