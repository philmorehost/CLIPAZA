<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$action = sanitizeInput($_POST['action'] ?? $_GET['action'] ?? '');

switch ($action) {
    case 'get_packages':
        handleGetPackages();
        break;
    case 'submit_ad':
        handleSubmitAd();
        break;
    case 'init_ad_payment':
        handleInitAdPayment();
        break;
    case 'verify_ad_payment':
        handleVerifyAdPayment();
        break;
    case 'upload_deposit_proof':
        handleUploadDepositProof();
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
}

function adPaystackRequest(string $method, string $endpoint, array $data = []): array {
    $secretKey = getSetting('paystack_secret_key', '');
    if (defined('PAYSTACK_SECRET_KEY') && PAYSTACK_SECRET_KEY) {
        $secretKey = PAYSTACK_SECRET_KEY;
    }
    if (empty($secretKey)) {
        return ['error' => 'Paystack not configured.'];
    }
    $url  = 'https://api.paystack.co' . $endpoint;
    $ch   = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/json',
        'Cache-Control: no-cache',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);
    if ($error) return ['error' => $error];
    $decoded = json_decode($response, true);
    return $decoded ?: ['error' => 'Invalid response from Paystack.'];
}

function handleGetPackages(): never {
    try {
        $stmt = db()->prepare(
            "SELECT id, name, description, price, duration_days, features, placement_zones, max_ads
             FROM ad_packages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute();
        $packages = $stmt->fetchAll();
        foreach ($packages as &$pkg) {
            $pkg['features']        = json_decode($pkg['features'] ?? '[]', true) ?: [];
            $pkg['placement_zones'] = json_decode($pkg['placement_zones'] ?? '[]', true) ?: [];
        }
        unset($pkg);
        jsonResponse(['success' => true, 'packages' => $packages]);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Could not load packages.']);
    }
}

function validateAdUpload(string $inputName, string $destDir, int $maxBytes = 5242880): string {
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    $file = $_FILES[$inputName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error for ' . $inputName . ' (code ' . $file['error'] . ')');
    }
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('File ' . $inputName . ' exceeds 5 MB limit.');
    }
    $fi       = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($fi, $file['tmp_name']);
    finfo_close($fi);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new RuntimeException('Invalid file type for ' . $inputName . '. Only images are allowed.');
    }
    $ext      = match ($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        default      => 'jpg',
    };
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = rtrim($destDir, '/') . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException('Could not save uploaded file ' . $inputName . '.');
    }
    // Return web-accessible path
    $webRoot = dirname(__DIR__);
    return '/' . ltrim(str_replace($webRoot, '', $destPath), '/');
}

function handleSubmitAd(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
    }

    $userId        = (int)$_SESSION['user_id'];
    $packageId     = (int)($_POST['package_id'] ?? 0);
    $movieTitle    = sanitizeInput($_POST['movie_title'] ?? '');
    $tagline       = sanitizeInput($_POST['tagline'] ?? '');
    $description   = sanitizeInput($_POST['description'] ?? '');
    $genre         = sanitizeInput($_POST['genre'] ?? '');
    $releaseDate   = sanitizeInput($_POST['release_date'] ?? '');
    $trailerUrl    = sanitizeInput($_POST['trailer_url'] ?? '');
    $contactEmail  = sanitizeInput($_POST['contact_email'] ?? '');
    $contactPhone  = sanitizeInput($_POST['contact_phone'] ?? '');
    $websiteUrl    = sanitizeInput($_POST['website_url'] ?? '');
    $paymentMethod = sanitizeInput($_POST['payment_method'] ?? 'online');

    if (!$packageId) {
        jsonResponse(['success' => false, 'message' => 'Please select an ad package.']);
    }
    if ($movieTitle === '') {
        jsonResponse(['success' => false, 'message' => 'Movie title is required.']);
    }
    if (!in_array($paymentMethod, ['online', 'manual'], true)) {
        jsonResponse(['success' => false, 'message' => 'Invalid payment method.']);
    }

    try {
        $db   = db();
        $stmt = $db->prepare("SELECT id, price FROM ad_packages WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$packageId]);
        $package = $stmt->fetch();
        if (!$package) {
            jsonResponse(['success' => false, 'message' => 'Selected package is not available.']);
        }

        $root = dirname(__DIR__);

        $posterPath = '';
        $flyerPath  = '';

        if (!empty($_FILES['movie_poster']['name'])) {
            $posterPath = validateAdUpload('movie_poster', $root . '/uploads/movie-posters');
        }
        if (!empty($_FILES['movie_flyer']['name'])) {
            $flyerPath = validateAdUpload('movie_flyer', $root . '/uploads/movie-flyers');
        }

        $relDate = ($releaseDate !== '') ? $releaseDate : null;

        $db->prepare(
            "INSERT INTO movie_ads
             (user_id, package_id, movie_title, tagline, description, genre, release_date,
              trailer_url, poster_path, flyer_path, contact_email, contact_phone, website_url,
              payment_method, status, payment_status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $userId, $packageId, $movieTitle, $tagline ?: null, $description ?: null,
            $genre ?: null, $relDate, $trailerUrl ?: null,
            $posterPath ?: null, $flyerPath ?: null,
            $contactEmail ?: null, $contactPhone ?: null, $websiteUrl ?: null,
            $paymentMethod,
            $paymentMethod === 'manual' ? 'pending_review' : 'draft',
            $paymentMethod === 'manual' ? 'pending_verification' : 'unpaid',
        ]);

        $adId = (int)$db->lastInsertId();

        if ($paymentMethod === 'online') {
            $userEmail = $_SESSION['user_email'] ?? '';
            $amountKobo = (int)round((float)$package['price'] * 100);
            $reference  = 'ADMOV_' . $adId . '_' . time();
            $result = adPaystackRequest('POST', '/transaction/initialize', [
                'email'        => $userEmail,
                'amount'       => $amountKobo,
                'reference'    => $reference,
                'metadata'     => ['ad_id' => $adId, 'user_id' => $userId],
                'callback_url' => rtrim(getSetting('site_url', ''), '/') . '/my-ads',
            ]);
            if (!empty($result['error'])) {
                jsonResponse(['success' => false, 'message' => $result['error']]);
            }
            if (empty($result['status']) || !$result['status']) {
                jsonResponse(['success' => false, 'message' => $result['message'] ?? 'Paystack error.']);
            }
            $db->prepare("UPDATE movie_ads SET payment_reference = ? WHERE id = ?")
               ->execute([$reference, $adId]);
            jsonResponse([
                'success'           => true,
                'ad_id'             => $adId,
                'payment_method'    => 'online',
                'authorization_url' => $result['data']['authorization_url'] ?? '',
            ]);
        }

        jsonResponse([
            'success'        => true,
            'ad_id'          => $adId,
            'payment_method' => 'manual',
            'message'        => 'Ad submitted. Please upload your deposit proof to complete submission.',
        ]);
    } catch (RuntimeException $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()]);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Submission failed. Please try again.']);
    }
}

function handleInitAdPayment(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
    }

    $userId = (int)$_SESSION['user_id'];
    $adId   = (int)($_POST['ad_id'] ?? 0);
    if (!$adId) {
        jsonResponse(['success' => false, 'message' => 'Invalid ad ID.']);
    }

    try {
        $db   = db();
        $stmt = $db->prepare(
            "SELECT ma.*, ap.price FROM movie_ads ma
             LEFT JOIN ad_packages ap ON ap.id = ma.package_id
             WHERE ma.id = ? AND ma.user_id = ? LIMIT 1"
        );
        $stmt->execute([$adId, $userId]);
        $ad = $stmt->fetch();
        if (!$ad) {
            jsonResponse(['success' => false, 'message' => 'Ad not found.']);
        }
        if ($ad['payment_status'] === 'paid') {
            jsonResponse(['success' => false, 'message' => 'Ad is already paid.']);
        }

        $userEmail  = $_SESSION['user_email'] ?? '';
        $amountKobo = (int)round((float)$ad['price'] * 100);
        $reference  = 'ADMOV_' . $adId . '_' . time();
        $result = adPaystackRequest('POST', '/transaction/initialize', [
            'email'        => $userEmail,
            'amount'       => $amountKobo,
            'reference'    => $reference,
            'metadata'     => ['ad_id' => $adId, 'user_id' => $userId],
            'callback_url' => rtrim(getSetting('site_url', ''), '/') . '/my-ads',
        ]);
        if (!empty($result['error'])) {
            jsonResponse(['success' => false, 'message' => $result['error']]);
        }
        if (empty($result['status']) || !$result['status']) {
            jsonResponse(['success' => false, 'message' => $result['message'] ?? 'Paystack error.']);
        }
        $db->prepare("UPDATE movie_ads SET payment_reference = ? WHERE id = ?")
           ->execute([$reference, $adId]);
        jsonResponse([
            'success'           => true,
            'authorization_url' => $result['data']['authorization_url'] ?? '',
        ]);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Payment initialization failed.']);
    }
}

function handleVerifyAdPayment(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }

    $reference = sanitizeInput($_POST['reference'] ?? $_GET['reference'] ?? '');
    if (empty($reference)) {
        jsonResponse(['success' => false, 'message' => 'Payment reference is required.']);
    }

    $result = adPaystackRequest('GET', '/transaction/verify/' . urlencode($reference));
    if (!empty($result['error'])) {
        jsonResponse(['success' => false, 'message' => $result['error']]);
    }
    if (empty($result['data']['status']) || $result['data']['status'] !== 'success') {
        jsonResponse(['success' => false, 'message' => 'Payment not successful.', 'status' => $result['data']['status'] ?? 'unknown']);
    }

    try {
        $db   = db();
        $stmt = $db->prepare("SELECT * FROM movie_ads WHERE payment_reference = ? LIMIT 1");
        $stmt->execute([$reference]);
        $ad = $stmt->fetch();
        if (!$ad) {
            jsonResponse(['success' => false, 'message' => 'Ad not found for this reference.']);
        }
        if ($ad['payment_status'] === 'paid') {
            jsonResponse(['success' => true, 'message' => 'Already verified.', 'already_paid' => true]);
        }
        $db->prepare(
            "UPDATE movie_ads SET payment_status='paid', status='pending_review' WHERE id=?"
        )->execute([(int)$ad['id']]);
        jsonResponse(['success' => true, 'message' => 'Payment verified. Your ad is now under review.']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Database update failed.']);
    }
}

function handleUploadDepositProof(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
    }

    $userId        = (int)$_SESSION['user_id'];
    $adId          = (int)($_POST['ad_id'] ?? 0);
    $depositAmount = (float)($_POST['deposit_amount'] ?? 0);
    $depositNote   = sanitizeInput($_POST['deposit_note'] ?? '');

    if (!$adId) {
        jsonResponse(['success' => false, 'message' => 'Invalid ad ID.']);
    }

    try {
        $db   = db();
        $stmt = $db->prepare("SELECT id FROM movie_ads WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$adId, $userId]);
        if (!$stmt->fetch()) {
            jsonResponse(['success' => false, 'message' => 'Ad not found.']);
        }

        $root      = dirname(__DIR__);
        $proofPath = validateAdUpload('deposit_proof', $root . '/uploads/deposit-proofs');
        if ($proofPath === '') {
            jsonResponse(['success' => false, 'message' => 'Please upload a deposit proof image.']);
        }

        $db->prepare(
            "UPDATE movie_ads
             SET manual_deposit_proof=?, manual_deposit_amount=?, manual_deposit_note=?,
                 status='pending_review', payment_status='pending_verification'
             WHERE id=? AND user_id=?"
        )->execute([$proofPath, $depositAmount > 0 ? $depositAmount : null, $depositNote ?: null, $adId, $userId]);

        jsonResponse(['success' => true, 'message' => 'Deposit proof uploaded. Your ad is under review.']);
    } catch (RuntimeException $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()]);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Upload failed.']);
    }
}
