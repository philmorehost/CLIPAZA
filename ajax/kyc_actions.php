<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$action = sanitizeInput($_POST['action'] ?? '');

switch ($action) {
    case 'submit_kyc':
        handleSubmitKyc();
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
}

function handleSubmitKyc(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }

    $userId     = (int)$_SESSION['user_id'];
    $bankCode   = sanitizeInput($_POST['bank_code'] ?? '');
    $bankName   = sanitizeInput($_POST['bank_name'] ?? '');
    $acctNum    = sanitizeInput($_POST['account_number'] ?? '');
    $acctName   = sanitizeInput($_POST['account_name'] ?? '');
    $idType     = sanitizeInput($_POST['id_type'] ?? '');
    $idExpiry   = sanitizeInput($_POST['id_expiry'] ?? '');
    $snapshotData = $_POST['snapshot_data'] ?? '';

    // Validate required fields
    $validTypes = ['driver_license', 'international_passport', 'nin_slip'];
    if (!in_array($idType, $validTypes, true)) {
        jsonResponse(['success' => false, 'message' => 'Please select a valid ID type.']);
    }
    if (empty($bankCode) || empty($acctNum) || empty($acctName)) {
        jsonResponse(['success' => false, 'message' => 'Please verify your bank account first.']);
    }
    if (!preg_match('/^\d{10}$/', $acctNum)) {
        jsonResponse(['success' => false, 'message' => 'Invalid account number.']);
    }

    // Validate expiry for license/passport
    $requiresExpiry = in_array($idType, ['driver_license', 'international_passport'], true);
    if ($requiresExpiry) {
        if (empty($idExpiry) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $idExpiry)) {
            jsonResponse(['success' => false, 'message' => 'Expiry date is required for this ID type.']);
        }
        if (strtotime($idExpiry) < time()) {
            jsonResponse(['success' => false, 'message' => 'Your ID has expired. Please upload a valid, non-expired ID.']);
        }
    }

    $root = dirname(__DIR__);
    $uploadsBase = $root . '/uploads/kyc/' . $userId;
    if (!is_dir($uploadsBase)) {
        mkdir($uploadsBase, 0755, true);
    }

    // Upload ID document
    $idPath = '';
    if (isset($_FILES['id_document']) && $_FILES['id_document']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['id_document'];
        if ($file['size'] > 5 * 1024 * 1024) {
            jsonResponse(['success' => false, 'message' => 'ID document must be under 5MB.']);
        }
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true)) {
            jsonResponse(['success' => false, 'message' => 'Allowed file types: JPG, PNG, PDF.']);
        }
        // Validate MIME for images
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
                jsonResponse(['success' => false, 'message' => 'Invalid image file.']);
            }
        }
        $filename = 'id_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destPath = $uploadsBase . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            jsonResponse(['success' => false, 'message' => 'Failed to upload ID document.']);
        }
        $idPath = '/uploads/kyc/' . $userId . '/' . $filename;
    } else {
        jsonResponse(['success' => false, 'message' => 'Please upload your ID document.']);
    }

    // Handle snapshot (base64 or file upload)
    $snapshotPath = '';
    if (!empty($snapshotData) && str_starts_with($snapshotData, 'data:image')) {
        // Base64 from camera
        $data = substr($snapshotData, strpos($snapshotData, ',') + 1);
        $imgData = base64_decode($data);
        if (!$imgData || strlen($imgData) < 100) {
            jsonResponse(['success' => false, 'message' => 'Invalid snapshot data.']);
        }
        // Verify it's a valid JPEG
        $tmpFile = tempnam(sys_get_temp_dir(), 'snap');
        file_put_contents($tmpFile, $imgData);
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $tmpFile);
        finfo_close($finfo);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            @unlink($tmpFile);
            jsonResponse(['success' => false, 'message' => 'Invalid snapshot image.']);
        }
        $ext = ($mime === 'image/png') ? 'png' : 'jpg';
        $filename = 'snap_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destPath = $uploadsBase . '/' . $filename;
        if (!rename($tmpFile, $destPath)) {
            copy($tmpFile, $destPath);
            @unlink($tmpFile);
        }
        $snapshotPath = '/uploads/kyc/' . $userId . '/' . $filename;
    } elseif (isset($_FILES['snapshot_upload']) && $_FILES['snapshot_upload']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['snapshot_upload'];
        if ($file['size'] > 5 * 1024 * 1024) {
            jsonResponse(['success' => false, 'message' => 'Snapshot must be under 5MB.']);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            jsonResponse(['success' => false, 'message' => 'Snapshot must be a JPG or PNG image.']);
        }
        $ext = ($mime === 'image/png') ? 'png' : 'jpg';
        $filename = 'snap_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destPath = $uploadsBase . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            jsonResponse(['success' => false, 'message' => 'Failed to upload snapshot.']);
        }
        $snapshotPath = '/uploads/kyc/' . $userId . '/' . $filename;
    } else {
        jsonResponse(['success' => false, 'message' => 'Please provide a live selfie or upload a photo.']);
    }

    // Save to database
    try {
        $db = db();
        $db->prepare(
            "UPDATE user_profiles SET
                kyc_status = 'pending',
                kyc_id_type = ?,
                kyc_id_path = ?,
                kyc_snapshot_path = ?,
                kyc_id_expiry = ?,
                kyc_rejection_reason = NULL,
                bank_name = ?,
                bank_code = ?,
                account_number = ?,
                account_name = ?,
                updated_at = NOW()
             WHERE user_id = ?"
        )->execute([
            $idType,
            $idPath,
            $snapshotPath,
            ($requiresExpiry && $idExpiry) ? $idExpiry : null,
            $bankName,
            $bankCode,
            $acctNum,
            $acctName,
            $userId,
        ]);

        sendNotification($userId, 'kyc', 'KYC Submitted', 'Your KYC documents have been submitted and are pending review.', '/kyc');

        // Notify admins
        $admins = $db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll();
        foreach ($admins as $admin) {
            sendNotification((int)$admin['id'], 'kyc_admin', 'New KYC Submission', 'A user has submitted KYC documents for review.', '/admin/kyc.php');
        }

        jsonResponse(['success' => true, 'message' => 'KYC submitted successfully! Admin will review within 1–2 business days.']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Failed to save KYC data.']);
    }
}
