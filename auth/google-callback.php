<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/google_auth_helper.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$code = $_GET['code'] ?? '';
if (empty($code)) {
    redirect('/auth/login?error=google_failed');
}

$helper = new GoogleAuthHelper();
$googleUser = $helper->fetchUser($code);

if (!$googleUser || empty($googleUser['sub']) || empty($googleUser['email'])) {
    redirect('/auth/login?error=google_failed');
}

$googleId = $googleUser['sub'];
$email    = $googleUser['email'];
$name     = $googleUser['name'] ?? explode('@', $email)[0];

try {
    $db = db();

    // 1. Try to find user by google_id
    $stmt = $db->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
    $stmt->execute([$googleId]);
    $user = $stmt->fetch();

    if (!$user) {
        // 2. Try to find user by email
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Link existing account
            $db->prepare('UPDATE users SET google_id = ? WHERE id = ?')->execute([$googleId, $user['id']]);
        } else {
            // 3. Create new user
            $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]));
            $username = $baseUsername;
            $counter = 1;
            while (true) {
                $check = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                $check->execute([$username]);
                if (!$check->fetch()) break;
                $username = $baseUsername . $counter++;
            }

            $db->prepare(
                'INSERT INTO users (username, email, password, role, status, google_id) VALUES (?, ?, ?, "user", "active", ?)'
            )->execute([$username, $email, bin2hex(random_bytes(16)), $googleId]);
            $userId = (int)$db->lastInsertId();

            // Create profile
            $db->prepare(
                'INSERT INTO user_profiles (user_id, display_name, active_mode) VALUES (?, ?, "clipper")'
            )->execute([$userId, $name]);

            $stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
        }
    }

    if ($user['status'] !== 'active') {
        redirect('/auth/login?error=account_inactive');
    }

    // Initiate session
    if (session_status() === PHP_SESSION_NONE) session_start();
    session_regenerate_id(true);

    $_SESSION['user_id']    = $user['id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['logged_in']  = true;

    // Load user mode
    try {
        $ps = $db->prepare('SELECT active_mode FROM user_profiles WHERE user_id = ? LIMIT 1');
        $ps->execute([$user['id']]);
        $profile = $ps->fetch();
        $_SESSION['user_mode'] = $profile ? $profile['active_mode'] : 'clipper';
    } catch (Throwable) {
        $_SESSION['user_mode'] = 'clipper';
    }

    logLoginEvent($user['id'], $user['username'], getClientIp(), 'google_login_success', 'Successful login via Google');
    updateIpWhitelist((int)$user['id'], getClientIp());

    redirect('/dashboard');

} catch (Throwable $e) {
    redirect('/auth/login?error=google_failed');
}
