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
        return $row ? (string)$row['setting_value'] : $default;
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
