<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__, 2);

if (!file_exists($root . '/config/config.php')) {
    echo json_encode(['success' => false, 'message' => 'Application not configured.']);
    exit;
}

require_once $root . '/config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/security.php';
require_once $root . '/includes/auth.php';

// Must be admin
if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

// CSRF check
$token = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    match ($action) {
        'save_security_settings' => handleSaveSecuritySettings(),
        'save_country_rule'      => handleSaveCountryRule(),
        'block_ip'               => handleBlockIp(),
        'unblock_ip'             => handleUnblockIp(),
        'unlock_account'         => handleUnlockAccount(),
        default                  => jsonResponse(['success' => false, 'message' => 'Unknown action.']),
    };
} catch (\UnhandledMatchError $e) {
    jsonResponse(['success' => false, 'message' => 'Unknown action.']);
}

function handleSaveSecuritySettings(): never {
    $allowed = [
        'ip_protection_enabled', 'ip_period_minutes', 'ip_max_failures',
        'username_protection_enabled', 'username_period_minutes', 'username_max_failures',
        'block_duration_minutes', 'notify_on_block', 'notify_on_lock', 'log_retention_days',
        'two_factor_enabled', 'captcha_enabled', 'captcha_threshold',
        'protect_local_only', 'allow_lock_admin', 'ip_block_duration_option',
        'notify_admin_login_unknown_ip', 'notify_brute_force_with_username',
    ];

    $checkboxes = [
        'ip_protection_enabled', 'username_protection_enabled', 'notify_on_block', 'notify_on_lock',
        'two_factor_enabled', 'captcha_enabled',
        'protect_local_only', 'allow_lock_admin', 'notify_admin_login_unknown_ip', 'notify_brute_force_with_username',
    ];

    $saved = 0;
    $validIpBlockOptions = ['1day', '1week', '1month', '1year'];
    foreach ($allowed as $key) {
        if (in_array($key, $checkboxes)) {
            $value = isset($_POST[$key]) && $_POST[$key] === '1' ? '1' : '0';
        } elseif ($key === 'ip_block_duration_option') {
            $raw   = trim($_POST[$key] ?? '1day');
            $value = in_array($raw, $validIpBlockOptions, true) ? $raw : '1day';
        } else {
            $value = trim($_POST[$key] ?? '');
        }
        if (saveSecuritySetting($key, $value)) $saved++;
    }

    logAdminAction('save_security_settings', "Updated {$saved} security settings");
    jsonResponse(['success' => true, 'message' => "Security settings saved ({$saved} updated)."]);
}

function handleSaveCountryRule(): never {
    $code   = strtoupper(trim($_POST['country_code'] ?? ''));
    $status = $_POST['status'] ?? '';

    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        jsonResponse(['success' => false, 'message' => 'Invalid country code.']);
    }

    $validStatuses = ['not_specified', 'whitelist', 'blacklist'];
    if (!in_array($status, $validStatuses, true)) {
        jsonResponse(['success' => false, 'message' => 'Invalid status.']);
    }

    try {
        $db = db();
        $stmt = $db->prepare(
            'UPDATE country_rules SET status = ?, updated_at = NOW() WHERE country_code = ?'
        );
        $stmt->execute([$status, $code]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => false, 'message' => 'Country not found.']);
        }

        logAdminAction('save_country_rule', "Set {$code} to {$status}");
        jsonResponse(['success' => true, 'message' => "Country rule updated."]);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Database error.']);
    }
}

function handleBlockIp(): never {
    $ip       = trim($_POST['ip'] ?? '');
    $type     = $_POST['block_type'] ?? 'temporary';
    $duration = max(1, (int)($_POST['duration'] ?? 60));
    $reason   = trim($_POST['reason'] ?? 'Manual block by admin');

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        jsonResponse(['success' => false, 'message' => 'Invalid IP address.']);
    }

    if (!in_array($type, ['temporary', 'permanent'], true)) {
        $type = 'temporary';
    }

    $adminId = (int)($_SESSION['user_id'] ?? 0);
    $success = BruteForceProtection::blockIp($ip, $type, $duration, $reason, $adminId);

    if ($success) {
        logAdminAction('block_ip', "Blocked IP {$ip} ({$type})");
        jsonResponse(['success' => true, 'message' => "IP {$ip} has been blocked."]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Failed to block IP.']);
    }
}

function handleUnblockIp(): never {
    $ip = trim($_POST['ip'] ?? '');

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        jsonResponse(['success' => false, 'message' => 'Invalid IP address.']);
    }

    $success = BruteForceProtection::unblockIp($ip);

    if ($success) {
        logAdminAction('unblock_ip', "Unblocked IP {$ip}");
        jsonResponse(['success' => true, 'message' => "IP {$ip} has been unblocked."]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Failed to unblock IP.']);
    }
}

function handleUnlockAccount(): never {
    $username = trim($_POST['username'] ?? '');

    if (empty($username)) {
        jsonResponse(['success' => false, 'message' => 'Username is required.']);
    }

    $success = BruteForceProtection::unlockAccount($username);

    if ($success) {
        logAdminAction('unlock_account', "Unlocked account: {$username}");
        jsonResponse(['success' => true, 'message' => "Account '{$username}' has been unlocked."]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Failed to unlock account.']);
    }
}

function logAdminAction(string $action, string $details): void {
    try {
        $db = db();
        $stmt = $db->prepare(
            'INSERT INTO login_history (user_id, username, ip_address, user_agent, action, details)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $_SESSION['username'] ?? '',
            getClientIp(),
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            'admin_' . $action,
            $details,
        ]);
    } catch (Throwable) {}
}
