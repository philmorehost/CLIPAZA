<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
}
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
}

$action = sanitizeInput($_POST['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

switch ($action) {
    case 'switch_mode':
        handleSwitchMode($userId);
        break;
    case 'update_profile':
        handleUpdateProfile($userId);
        break;
    case 'toggle_notifications':
        handleToggleNotifications($userId);
        break;
    case 'save_paystack_settings':
        handleSavePaystackSettings();
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
}

function handleSwitchMode(int $userId): never {
    try {
        $db   = db();
        $stmt = $db->prepare('SELECT active_mode FROM user_profiles WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();

        $newMode = ($profile['active_mode'] ?? 'clipper') === 'clipper' ? 'creator' : 'clipper';

        // Use INSERT ... ON DUPLICATE KEY UPDATE to ensure the profile record exists.
        $db->prepare(
            'INSERT INTO user_profiles (user_id, active_mode) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE active_mode = VALUES(active_mode)'
        )->execute([$userId, $newMode]);

        $_SESSION['user_mode'] = $newMode;
        jsonResponse(['success' => true, 'new_mode' => $newMode]);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to switch mode: ' . $e->getMessage()]);
    }
}

function handleUpdateProfile(int $userId): never {
    $displayName     = sanitizeInput($_POST['display_name'] ?? '');
    $bio             = sanitizeInput($_POST['bio'] ?? '');
    $brandDesc       = sanitizeInput($_POST['brand_description'] ?? '');
    $youtubeHandle   = sanitizeInput($_POST['youtube_handle'] ?? '');
    $tiktokHandle    = sanitizeInput($_POST['tiktok_handle'] ?? '');
    $instagramHandle = sanitizeInput($_POST['instagram_handle'] ?? '');
    $facebookHandle  = sanitizeInput($_POST['facebook_handle'] ?? '');

    try {
        $db = db();
        $db->prepare(
            "UPDATE user_profiles SET
               display_name = ?,
               bio = ?,
               brand_description = ?,
               youtube_handle = ?,
               tiktok_handle = ?,
               instagram_handle = ?,
               facebook_handle = ?
             WHERE user_id = ?"
        )->execute([
            $displayName ?: null,
            $bio ?: null,
            $brandDesc ?: null,
            $youtubeHandle ?: null,
            $tiktokHandle ?: null,
            $instagramHandle ?: null,
            $facebookHandle ?: null,
            $userId,
        ]);
        jsonResponse(['success' => true, 'message' => 'Profile updated successfully.']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Failed to update profile.']);
    }
}

function handleToggleNotifications(int $userId): never {
    $value = (int)($_POST['value'] ?? 0);
    $value = $value ? 1 : 0;
    try {
        $db = db();
        $db->prepare('UPDATE users SET login_notifications = ? WHERE id = ?')->execute([$value, $userId]);
        jsonResponse(['success' => true, 'value' => $value]);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Update failed.']);
    }
}

function handleSavePaystackSettings(): never {
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Admin access required.'], 403);
    }
    $pubKey    = sanitizeInput($_POST['paystack_public_key'] ?? '');
    $secretKey = sanitizeInput($_POST['paystack_secret_key'] ?? '');
    try {
        $db = db();
        foreach ([['paystack_public_key', $pubKey], ['paystack_secret_key', $secretKey]] as [$k, $v]) {
            $db->prepare(
                'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            )->execute([$k, $v]);
        }
        jsonResponse(['success' => true, 'message' => 'Paystack settings saved.']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Failed to save settings.']);
    }
}
