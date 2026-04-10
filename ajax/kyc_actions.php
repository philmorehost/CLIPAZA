<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) jsonResponse(['success' => false, 'message' => 'Invalid CSRF'], 403);

$userId = (int)$_SESSION['user_id'];

// 1. Process Bank Details
$bankName = sanitizeInput($_POST['bank_name'] ?? '');
$bankCode = sanitizeInput($_POST['bank_code'] ?? '');
$acctNum = sanitizeInput($_POST['account_number'] ?? '');
$acctName = sanitizeInput($_POST['account_name'] ?? '');

if (empty($acctName)) jsonResponse(['success' => false, 'message' => 'Please verify your bank account first.']);

// 2. Process ID Upload
if (!isset($_FILES['kyc_id_file']) || $_FILES['kyc_id_file']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['success' => false, 'message' => 'Please upload a valid ID document.']);
}

$idType = sanitizeInput($_POST['kyc_id_type'] ?? 'nin');
$expiry = sanitizeInput($_POST['kyc_id_expiry'] ?? '');

$idFile = $_FILES['kyc_id_file'];
$idExt = strtolower(pathinfo($idFile['name'], PATHINFO_EXTENSION));
$idName = 'id_' . $userId . '_' . time() . '.' . $idExt;
$idPath = 'uploads/kyc/' . $idName;

if (!move_uploaded_file($idFile['tmp_name'], $root . '/' . $idPath)) {
    jsonResponse(['success' => false, 'message' => 'Failed to save ID document.']);
}

// 3. Process Snapshot (Base64)
$snapshotData = $_POST['kyc_snapshot'] ?? '';
if (empty($snapshotData)) jsonResponse(['success' => false, 'message' => 'Please capture a live snapshot.']);

$snapshotData = str_replace('data:image/jpeg;base64,', '', $snapshotData);
$snapshotData = base64_decode($snapshotData);
$snapshotName = 'snap_' . $userId . '_' . time() . '.jpg';
$snapshotPath = 'uploads/kyc/' . $snapshotName;

if (!file_put_contents($root . '/' . $snapshotPath, $snapshotData)) {
    jsonResponse(['success' => false, 'message' => 'Failed to save snapshot.']);
}

try {
    $db = db();
    $db->prepare("
        UPDATE user_profiles SET
            kyc_status = 'pending',
            kyc_id_type = ?,
            kyc_id_path = ?,
            kyc_snapshot_path = ?,
            kyc_id_expiry = ?,
            bank_name = ?,
            bank_code = ?,
            account_number = ?,
            account_name = ?
        WHERE user_id = ?
    ")->execute([
        $idType, $idPath, $snapshotPath, $expiry ?: null,
        $bankName, $bankCode, $acctNum, $acctName,
        $userId
    ]);

    jsonResponse(['success' => true, 'message' => 'Verification submitted!']);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
