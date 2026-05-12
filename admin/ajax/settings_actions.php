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
        'save_general' => handleSaveGeneral(),
        'save_seo'     => handleSaveSeo(),
        'save_payment' => handleSavePayment(),
        'save_code'    => handleSaveCode(),
        'save_ads'     => handleSaveAds(),
        'save_landing' => handleSaveLanding(),
        'update_default_theme' => handleUpdateDefaultTheme(),
        default        => jsonResponse(['success' => false, 'message' => 'Unknown action.']),
    };
} catch (\UnhandledMatchError $e) {
    jsonResponse(['success' => false, 'message' => 'Unknown action.']);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function saveSiteSetting(string $key, string $value): bool {
    try {
        $db   = db();
        $stmt = $db->prepare(
            'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        return $stmt->execute([$key, $value]);
    } catch (Throwable) {
        return false;
    }
}

function processUpload(string $inputName, string $destDir, array $allowedExts, int $maxBytes = 2097152): string {
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    $file = $_FILES[$inputName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new \RuntimeException('Upload error code ' . $file['error']);
    }
    if ($file['size'] > $maxBytes) {
        throw new \RuntimeException('File too large (max 2 MB).');
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        throw new \RuntimeException('Invalid file type. Allowed: ' . implode(', ', $allowedExts));
    }
    if (in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            throw new \RuntimeException('File content does not match a valid image type.');
        }
    }
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    $fullDest = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $fullDest)) {
        throw new \RuntimeException('Failed to move uploaded file.');
    }
    $webRoot = str_replace('\\', '/', rtrim(dirname(__DIR__, 2), '/\\'));
    $webPath = str_replace('\\', '/', $fullDest);
    if (str_starts_with($webPath, $webRoot)) {
        return substr($webPath, strlen($webRoot));
    }
    $uploadsPos = strrpos($webPath, '/uploads/');
    return $uploadsPos !== false ? substr($webPath, $uploadsPos) : '/' . $filename;
}

function deleteOldFile(string $webPath, string $webRoot): void {
    if ($webPath === '') return;
    $fullPath = rtrim(str_replace('\\', '/', $webRoot), '/') . '/' . ltrim($webPath, '/');
    if (file_exists($fullPath) && is_file($fullPath)) @unlink($fullPath);
}

// ---------------------------------------------------------------------------
// Action handlers
// ---------------------------------------------------------------------------

function handleSaveGeneral(): never {
    $root = dirname(__DIR__, 2);
    $siteName = sanitizeInput($_POST['site_name'] ?? '');
    if ($siteName === '') jsonResponse(['success' => false, 'message' => 'Site name cannot be empty.']);
    saveSiteSetting('site_name', $siteName);
    $theme = $_POST['default_theme'] ?? 'dark';
    saveSiteSetting('default_theme', in_array($theme, ['dark', 'light'], true) ? $theme : 'dark');

    saveSiteSetting('google_client_id', sanitizeInput($_POST['google_client_id'] ?? ''));
    saveSiteSetting('google_client_secret', sanitizeInput($_POST['google_client_secret'] ?? ''));

    try {
        $logoPath = processUpload('site_logo', $root . '/uploads/logo', ['png', 'jpg', 'jpeg']);
        if ($logoPath !== '') {
            deleteOldFile(getSetting('site_logo', ''), $root);
            saveSiteSetting('site_logo', $logoPath);
        }
    } catch (\RuntimeException $e) { jsonResponse(['success' => false, 'message' => 'Logo: ' . $e->getMessage()]); }
    try {
        $faviconPath = processUpload('site_favicon', $root . '/uploads/favicon', ['png', 'jpg', 'jpeg', 'ico']);
        if ($faviconPath !== '') {
            deleteOldFile(getSetting('site_favicon', ''), $root);
            saveSiteSetting('site_favicon', $faviconPath);
        }
    } catch (\RuntimeException $e) { jsonResponse(['success' => false, 'message' => 'Favicon: ' . $e->getMessage()]); }
    jsonResponse(['success' => true, 'message' => 'General settings saved.']);
}

function handleSavePayment(): never {
    $fields = [
        'paystack_public_key'      => 200,
        'paystack_secret_key'      => 200,
        'payhub_base_url'          => 500,
        'payhub_api_key'           => 200,
        'payhub_merchant_id'       => 200,
        'preferred_payout_gateway' => 20,
        'min_withdrawal_amount'    => 20,
        'max_withdrawal_amount'    => 20,
        'platform_fee_percent'     => 20,
        'min_contest_prize'        => 20,
        'max_contest_days'         => 20,
        'withdrawal_fee_percent'   => 20,
        'withdrawal_fee_flat'      => 20,
        'ad_bank_name'             => 200,
        'ad_bank_account'          => 200,
        'ad_bank_number'           => 20,
    ];
    foreach ($fields as $key => $maxLen) {
        saveSiteSetting($key, substr(trim((string)($_POST[$key] ?? '')), 0, $maxLen));
    }
    jsonResponse(['success' => true, 'message' => 'Payment settings saved.']);
}

function handleSaveSeo(): never {
    $fields = ['seo_title'=>200, 'seo_description'=>500, 'seo_keywords'=>500, 'og_image_url'=>500];
    foreach ($fields as $key => $maxLen) {
        $val = substr(trim((string)($_POST[$key] ?? '')), 0, $maxLen);
        if ($key === 'og_image_url' && $val !== '' && !filter_var($val, FILTER_VALIDATE_URL)) {
            jsonResponse(['success' => false, 'message' => 'Invalid OG Image URL.']);
        }
        saveSiteSetting($key, $val);
    }
    jsonResponse(['success' => true, 'message' => 'SEO settings saved.']);
}

function handleSaveCode(): never {
    saveSiteSetting('custom_header_code', $_POST['custom_header_code'] ?? '');
    saveSiteSetting('adsense_code', $_POST['adsense_code'] ?? '');
    jsonResponse(['success' => true, 'message' => 'Code injection settings saved.']);
}

function handleUpdateDefaultTheme(): never {
    $theme = $_POST['default_theme'] ?? 'dark';
    if (!in_array($theme, ['dark', 'light'], true)) jsonResponse(['success' => false, 'message' => 'Invalid theme.']);
    if (saveSiteSetting('default_theme', $theme)) jsonResponse(['success' => true, 'message' => 'Default theme updated to ' . $theme]);
    jsonResponse(['success' => false, 'message' => 'Failed to update default theme.']);
}

function handleSaveLanding(): never {
    $fields = [
        'lp_hero_title'=>200, 'lp_hero_accent'=>200, 'lp_hero_sub'=>1000,
        'lp_hero_btn_creator'=>50, 'lp_hero_btn_fan'=>50,
        'lp_hiw_title'=>200, 'lp_trending_title_accent'=>200,
        'lp_brands_title'=>200, 'lp_brands_sub'=>1000, 'lp_brands_content'=>1500,
        'lp_lb_title_accent'=>200, 'lp_lb_text'=>1500,
        'lp_step1_title'=>200, 'lp_step1_desc'=>500,
        'lp_step2_title'=>200, 'lp_step2_desc'=>500,
        'lp_step3_title'=>200, 'lp_step3_desc'=>500,
        'lp_features_title'=>200, 'lp_features_sub'=>500, 'lp_hide_features'=>1,
        'lp_cta_title'=>200, 'lp_cta_sub'=>500,
        'lp_creators_title'=>200, 'lp_creators_sub'=>1000, 'lp_creators_extra'=>500,
        'lp_creators_p1'=>200, 'lp_creators_p2'=>200, 'lp_creators_p3'=>200, 'lp_creators_p4'=>200,
        'lp_fans_title'=>200, 'lp_fans_sub'=>1000, 'lp_fans_extra'=>500,
        'lp_fans_p1'=>200, 'lp_fans_p2'=>200, 'lp_fans_p3'=>200, 'lp_fans_p4'=>200,
    ];
    for ($i = 1; $i <= 6; $i++) { $fields["lp_f{$i}_title"] = 200; $fields["lp_f{$i}_desc"] = 500; }
    foreach ($fields as $key => $maxLen) {
        $val = substr(trim((string)($_POST[$key] ?? '')), 0, $maxLen);
        if ($key === 'lp_hide_features') $val = !empty($_POST[$key]) ? '1' : '0';
        saveSiteSetting($key, $val);
    }
    jsonResponse(['success' => true, 'message' => 'Landing page settings saved.']);
}

function handleSaveAds(): never {
    if (isset($_FILES['ads_txt_file']) && $_FILES['ads_txt_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['ads_txt_file'];
        if ($file['size'] > 2097152) jsonResponse(['success' => false, 'message' => 'File too large.']);
        if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'txt') jsonResponse(['success' => false, 'message' => 'TXT files only.']);
        $content = file_get_contents($file['tmp_name']);
        if ($content !== false) saveSiteSetting('ads_txt_content', $content);
    } else {
        saveSiteSetting('ads_txt_content', $_POST['ads_txt_content'] ?? '');
    }
    jsonResponse(['success' => true, 'message' => 'Ads settings saved.']);
}
