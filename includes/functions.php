<?php
declare(strict_types=1);

function e(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function generateToken(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

function generateCsrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken(32);
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $stored = $_SESSION['csrf_token'] ?? '';
    return hash_equals($stored, $token);
}

function redirect(string $url, int $code = 302): never {
    header('Location: ' . $url, true, $code);
    exit;
}

function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function getSetting(string $key, string $default = ''): string {
    try {
        $db = db();
        $stmt = $db->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $val = $row ? (string)$row['setting_value'] : '';
        return ($val !== '') ? $val : $default;
    } catch (Throwable) {
        return $default;
    }
}

function getSecuritySetting(string $key, string $default = ''): string {
    try {
        $db = db();
        $stmt = $db->prepare('SELECT setting_value FROM security_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    } catch (Throwable) {
        return $default;
    }
}

function saveSecuritySetting(string $key, string $value): bool {
    try {
        $db = db();
        $stmt = $db->prepare(
            'INSERT INTO security_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        return $stmt->execute([$key, $value]);
    } catch (Throwable) {
        return false;
    }
}

function sanitizeInput(string $input): string {
    return trim(strip_tags($input));
}

/**
 * Formats site name with a lemon-colored span starting from the second capital letter.
 * Useful for "ClipZaza" -> "Clip<span...>Zaza</span>"
 */
/**
 * Renders a platform icon image.
 */
function getPlatformIcon(string $platform, string $size = '1.2rem'): string {
    $src = match(strtolower($platform)) {
        'tiktok'    => '/assets/img/tiktok.png',
        'instagram' => '/assets/img/instagram.png',
        'facebook'  => '/assets/img/facebook.png',
        default     => ''
    };
    if (!$src) return '';
    return sprintf(
        '<img src="%s" alt="%s" class="platform-img-icon" style="width:%s; height:%s; vertical-align:middle; object-fit:contain; margin-top:-2px" />',
        $src,
        ucfirst($platform),
        $size,
        $size
    );
}

function formatSiteName(string $siteName): string {
    $len = mb_strlen($siteName);
    $capCount = 0;
    $breakIndex = -1;

    for ($i = 0; $i < $len; $i++) {
        $char = mb_substr($siteName, $i, 1);
        if ($char >= 'A' && $char <= 'Z') {
            $capCount++;
            if ($capCount === 2) {
                $breakIndex = $i;
                break;
            }
        }
    }

    if ($breakIndex !== -1) {
        return e(mb_substr($siteName, 0, $breakIndex)) . '<span style="color:var(--accent)">' . e(mb_substr($siteName, $breakIndex)) . '</span>';
    }
    return e($siteName);
}

function isValidEmail(string $email): bool {
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function isValidIp(string $ip): bool {
    return (bool)filter_var($ip, FILTER_VALIDATE_IP);
}

function getClientIp(): string {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Only trust proxy headers if REMOTE_ADDR is a known/configured trusted proxy.
    // Without a trusted-proxy allowlist, always use REMOTE_ADDR to prevent IP spoofing.
    $trustedProxies = defined('TRUSTED_PROXIES') ? (array)TRUSTED_PROXIES : [];
    if (!empty($trustedProxies) && in_array($remoteAddr, $trustedProxies, true)) {
        $proxyHeaders = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];
        foreach ($proxyHeaders as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
    }

    return $remoteAddr;
}

function formatDate(string $date, string $format = 'M j, Y g:i A'): string {
    try {
        return (new DateTime($date))->format($format);
    } catch (Throwable) {
        return $date;
    }
}

function timeAgo(string $date): string {
    try {
        $ts = (new DateTime($date))->getTimestamp();
        $diff = time() - $ts;
        return match(true) {
            $diff < 60     => 'just now',
            $diff < 3600   => floor($diff / 60) . 'm ago',
            $diff < 86400  => floor($diff / 3600) . 'h ago',
            $diff < 604800 => floor($diff / 86400) . 'd ago',
            default        => (new DateTime($date))->format('M j, Y'),
        };
    } catch (Throwable) {
        return $date;
    }
}

function paginate(int $total, int $perPage, int $current): array {
    $pages = (int)ceil($total / $perPage);
    return [
        'total'    => $total,
        'perPage'  => $perPage,
        'current'  => max(1, min($current, max(1, $pages))),
        'pages'    => $pages,
        'offset'   => ($current - 1) * $perPage,
        'hasPrev'  => $current > 1,
        'hasNext'  => $current < $pages,
    ];
}

function sendNotification(int $userId, string $type, string $title, string $message, string $link = ''): void {
    try {
        $db = db();
        $db->prepare(
            'INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $type, $title, $message, $link ?: null]);
    } catch (Throwable) {}
}

function getUnreadNotificationCount(int $userId): int {
    try {
        $db   = db();
        $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function paystackPost(string $endpoint, array $data): array {
    $secretKey = getSetting('paystack_secret_key', '');
    if (defined('PAYSTACK_SECRET_KEY') && PAYSTACK_SECRET_KEY) {
        $secretKey = PAYSTACK_SECRET_KEY;
    }
    if (empty($secretKey)) {
        return ['error' => 'Paystack not configured.'];
    }
    $ch = curl_init('https://api.paystack.co' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/json',
            'Cache-Control: no-cache',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => $err];
    return json_decode($response, true) ?: ['error' => 'Invalid response.'];
}

function paystackGet(string $endpoint): array {
    $secretKey = getSetting('paystack_secret_key', '');
    if (defined('PAYSTACK_SECRET_KEY') && PAYSTACK_SECRET_KEY) {
        $secretKey = PAYSTACK_SECRET_KEY;
    }
    if (empty($secretKey)) {
        return ['error' => 'Paystack not configured.'];
    }
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

function getPreferredPayoutGateway(): string {
    return getSetting('preferred_payout_gateway', 'paystack');
}

/**
 * Checks if KYC is mandatory based on the active payout system.
 * Mandatory if any automated gateway is active.
 */
function isKycRequired(): bool {
    return getPreferredPayoutGateway() !== 'manual';
}

function autoArchiveContests(): int {
    try {
        $db   = db();
        $stmt = $db->prepare(
            "UPDATE contests SET status = 'ended'
             WHERE status = 'active' AND end_date IS NOT NULL AND end_date <= NOW()"
        );
        $stmt->execute();
        return $stmt->rowCount();
    } catch (Throwable) {
        return 0;
    }
}

function sendEmail(string $to, string $subject, string $html): bool
{
    try {
        require_once __DIR__ . '/mailer.php';
        return (new Mailer())->send($to, $subject, $html);
    } catch (Throwable) {
        return false;
    }
}
