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
        'save_code'    => handleSaveCode(),
        'save_ads'     => handleSaveAds(),
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

/**
 * Validate and move an uploaded file.
 * Returns the web-accessible path on success or throws RuntimeException on failure.
 */
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

    $origName = (string)$file['name'];
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExts, true)) {
        throw new \RuntimeException('Invalid file type. Allowed: ' . implode(', ', $allowedExts));
    }

    // Validate MIME via finfo for images (not for .ico which lacks a reliable MIME)
    if (in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mime     = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $okMimes  = ['image/png', 'image/jpeg'];
        if (!in_array($mime, $okMimes, true)) {
            throw new \RuntimeException('File content does not match a valid image type (PNG or JPEG required).');
        }
    }

    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    $destDir  = rtrim($destDir, '/\\');
    $fullDest = $destDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $fullDest)) {
        throw new \RuntimeException('Failed to move uploaded file.');
    }

    // Return web path relative to document root, using forward slashes for URLs
    $webRoot = str_replace('\\', '/', rtrim(dirname(__DIR__, 2), '/\\'));
    $webPath = str_replace('\\', '/', $fullDest);
    if (str_starts_with($webPath, $webRoot)) {
        return substr($webPath, strlen($webRoot));
    }
    // Fallback: derive path from destDir name relative to uploads/
    $uploadsPos = strrpos($webPath, '/uploads/');
    return $uploadsPos !== false ? substr($webPath, $uploadsPos) : '/' . $filename;
}

/**
 * Remove a previously stored file from disk (silent fail).
 */
function deleteOldFile(string $webPath, string $webRoot): void {
    if ($webPath === '') return;
    $normalized = str_replace('\\', '/', $webRoot);
    $fullPath   = rtrim($normalized, '/') . '/' . ltrim($webPath, '/');
    if (file_exists($fullPath) && is_file($fullPath)) {
        @unlink($fullPath);
    }
}

// ---------------------------------------------------------------------------
// Action handlers
// ---------------------------------------------------------------------------

function handleSaveGeneral(): never {
    $root = dirname(__DIR__, 2);

    $siteName = sanitizeInput($_POST['site_name'] ?? '');
    if ($siteName === '') {
        jsonResponse(['success' => false, 'message' => 'Site name cannot be empty.']);
    }

    saveSiteSetting('site_name', $siteName);

    // Logo upload
    try {
        $logoPath = processUpload('site_logo', $root . '/uploads/logo', ['png', 'jpg', 'jpeg']);
        if ($logoPath !== '') {
            // Clean up old logo file
            deleteOldFile(getSetting('site_logo', ''), $root);
            saveSiteSetting('site_logo', $logoPath);
        }
    } catch (\RuntimeException $e) {
        jsonResponse(['success' => false, 'message' => 'Logo: ' . $e->getMessage()]);
    }

    // Favicon upload
    try {
        $faviconPath = processUpload('site_favicon', $root . '/uploads/favicon', ['png', 'jpg', 'jpeg', 'ico']);
        if ($faviconPath !== '') {
            // Clean up old favicon file
            deleteOldFile(getSetting('site_favicon', ''), $root);
            saveSiteSetting('site_favicon', $faviconPath);
        }
    } catch (\RuntimeException $e) {
        jsonResponse(['success' => false, 'message' => 'Favicon: ' . $e->getMessage()]);
    }

    jsonResponse(['success' => true, 'message' => 'General settings saved.']);
}

function handleSaveSeo(): never {
    $fields = [
        'seo_title'       => 200,
        'seo_description' => 500,
        'seo_keywords'    => 500,
        'og_image_url'    => 500,
    ];

    foreach ($fields as $key => $maxLen) {
        $value = substr(trim($_POST[$key] ?? ''), 0, $maxLen);
        // og_image_url: only allow empty or valid URL
        if ($key === 'og_image_url' && $value !== '') {
            if (!filter_var($value, FILTER_VALIDATE_URL)) {
                jsonResponse(['success' => false, 'message' => 'OG Image URL is not a valid URL.']);
            }
        }
        saveSiteSetting($key, $value);
    }

    jsonResponse(['success' => true, 'message' => 'SEO settings saved.']);
}

function handleSaveCode(): never {
    // WARNING: These values are intentionally stored and output as raw HTML/JS.
    // They are injected into every public page. Only trusted admins should edit these.
    // If an admin account is compromised, these fields are a potential XSS vector.
    $headerCode  = $_POST['custom_header_code'] ?? '';
    $adsenseCode = $_POST['adsense_code'] ?? '';

    saveSiteSetting('custom_header_code', $headerCode);
    saveSiteSetting('adsense_code', $adsenseCode);

    jsonResponse(['success' => true, 'message' => 'Code injection settings saved.']);
}

function handleSaveAds(): never {
    $root = dirname(__DIR__, 2);

    // If a file was uploaded, use its contents; otherwise use the textarea
    if (isset($_FILES['ads_txt_file']) && $_FILES['ads_txt_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['ads_txt_file'];

        if ($file['size'] > 2097152) {
            jsonResponse(['success' => false, 'message' => 'ads.txt file too large (max 2 MB).']);
        }

        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'txt') {
            jsonResponse(['success' => false, 'message' => 'Only .txt files are allowed for ads.txt.']);
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            jsonResponse(['success' => false, 'message' => 'Could not read uploaded file.']);
        }
        saveSiteSetting('ads_txt_content', $content);
    } else {
        $content = $_POST['ads_txt_content'] ?? '';
        saveSiteSetting('ads_txt_content', $content);
    }

    jsonResponse(['success' => true, 'message' => 'Ads settings saved.']);
}
