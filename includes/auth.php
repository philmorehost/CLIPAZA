<?php
declare(strict_types=1);

require_once __DIR__ . '/db';
require_once __DIR__ . '/functions';
require_once __DIR__ . '/security';
require_once __DIR__ . '/mailer';

function requireAuth(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
        redirect('../admin/login');
    }
}

function requireAdmin(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? ') !== 'admin') {
        redirect(defined('ADMIN_DIR') ? ADMIN_DIR . '/login' : '/admin/login');
    }
}

function isLoggedIn(): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return !empty($_SESSION['user_id']);
}

function login(string $username, string $password): array {
    $ip = getClientIp();

    if (BruteForceProtection::isIpWhitelisted($ip) === false
        && BruteForceProtection::isIpBlocked($ip)) {
        return ['success' => false, 'message' => 'Your IP address has been temporarily blocked due to too many failed attempts.'];
    }

    if (BruteForceProtection::isAccountLocked($username)) {
        return ['success' => false, 'message' => 'This account is temporarily locked. Please try again later.'];
    }

    try {
        $db   = db();
        $stmt = $db->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            BruteForceProtection::recordAttempt($username, $ip, false);
            logLoginEvent($user['id'] ?? null, $username, $ip, 'failed_login', 'Invalid credentials');
            return ['success' => false, 'message' => 'Invalid username or password.'];
        }

        if ($user['status'] !== 'active') {
            return ['success' => false, 'message' => 'Your account is not active.'];
        }

        BruteForceProtection::recordAttempt($username, $ip, true);

        if (session_status() === PHP_SESSION_NONE) session_start();
        session_regenerate_id(true);

        $_SESSION['user_id']    = $user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['user_role']  = $user['role'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['logged_in']  = true;

        // Load user mode from profile
        try {
            $ps = $db->prepare('SELECT active_mode FROM user_profiles WHERE user_id = ? LIMIT 1');
            $ps->execute([$user['id']]);
            $profile = $ps->fetch();
            $_SESSION['user_mode'] = $profile ? $profile['active_mode'] : 'clipper';
        } catch (Throwable) {
            $_SESSION['user_mode'] = 'clipper';
        }

        logLoginEvent($user['id'], $username, $ip, 'login_success', 'Successful login');

        updateIpWhitelist((int)$user['id'], $ip);

        if ($user['login_notifications'] ?? false) {
            $mailer = new Mailer();
            $mailer->sendLoginNotification($user['email'], $user['username'], $ip);
        }

        return ['success' => true, 'user' => $user];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'An error occurred. Please try again.'];
    }
}

function logout(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $username = $_SESSION['username'] ?? ';
    $ip       = getClientIp();
    if ($username) logLoginEvent($_SESSION['user_id'] ?? null, $username, $ip, 'logout', ');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), ', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function logLoginEvent(?int $userId, string $username, string $ip, string $action, string $details): void {
    try {
        $db = db();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? ';
        $stmt = $db->prepare(
            'INSERT INTO login_history (user_id, username, ip_address, user_agent, action, details)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $username, $ip, $ua, $action, $details]);
    } catch (Throwable) {}
}

function updateIpWhitelist(int $userId, string $ip): void {
    try {
        $db = db();
        $stmt = $db->prepare(
            'INSERT INTO ip_whitelist (ip_address, user_id, successful_logins, last_seen)
             VALUES (?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE
               successful_logins = successful_logins + 1,
               last_seen = NOW()'
        );
        $stmt->execute([$ip, $userId]);
    } catch (Throwable) {}
}

function getCurrentUser(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) return null;
    try {
        $db = db();
        $stmt = $db->prepare('SELECT id, username, email, role, status FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    } catch (Throwable) {
        return null;
    }
}

function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function requireUser(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) {
        redirect('/auth/login');
    }
}

function requireCreatorMode(): void {
    requireUser();
    if (($_SESSION['user_mode'] ?? 'clipper') !== 'creator') {
        redirect('/dashboard?error=creator_required');
    }
}

function getUserMode(): string {
    return $_SESSION['user_mode'] ?? 'clipper';
}
