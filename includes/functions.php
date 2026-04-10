<?php
/**
 * CLIPAZA – shared helper functions
 */

declare(strict_types=1);

/**
 * Return the client's real IP address.
 *
 * X-Forwarded-For and similar headers are only trusted when REMOTE_ADDR
 * is listed in the TRUSTED_PROXIES constant (defined in config/config.php).
 * This prevents IP spoofing when the application is not behind a proxy.
 */
function getClientIp(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    $trustedProxies = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : [];

    if (in_array($remoteAddr, $trustedProxies, true)) {
        // Trust forwarded headers only from known proxies.
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
        ];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                // X-Forwarded-For can be a comma-separated list; take the first.
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    }

    return $remoteAddr;
}

/**
 * Escape a string for safe HTML output.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Return a PDO connection using the constants defined in config.php.
 */
function getDb(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
    }

    return $pdo;
}
