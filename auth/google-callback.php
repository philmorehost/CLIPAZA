<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';

if (empty($code) || $state !== ($_SESSION['google_oauth_state'] ?? '')) {
    die('Invalid request.');
}

unset($_SESSION['google_oauth_state']);

$clientId = getSetting('google_client_id', '');
$clientSecret = getSetting('google_client_secret', '');
$redirectUri = rtrim(getSetting('site_url', 'http://localhost'), '/') . '/auth/google-callback.php';

// Exchange code for token
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code'
]));

$response = curl_exec($ch);
$data = json_decode($response, true);
curl_close($ch);

if (empty($data['access_token'])) {
    die('Failed to obtain access token.');
}

$accessToken = $data['access_token'];
$refreshToken = $data['refresh_token'] ?? '';

// Get user info
$ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
$userInfoResponse = curl_exec($ch);
$userInfo = json_decode($userInfoResponse, true);
curl_close($ch);

if (empty($userInfo['email'])) {
    die('Failed to get user info.');
}

$email = $userInfo['email'];
$name = $userInfo['name'] ?? '';
$googleId = $userInfo['id'] ?? '';

try {
    $db = db();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Create new user
        $username = explode('@', $email)[0] . '_' . bin2hex(random_bytes(2));
        $password = hashPassword(bin2hex(random_bytes(16)));

        $stmt = $db->prepare('INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, \'user\', \'active\')');
        $stmt->execute([$username, $email, $password]);
        $userId = (int)$db->lastInsertId();

        $db->prepare('INSERT INTO user_profiles (user_id, display_name, active_mode) VALUES (?, ?, \'clipper\')')
           ->execute([$userId, $name]);

        $user = [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
            'role' => 'user'
        ];
    } else {
        $userId = (int)$user['id'];
    }

    // Log user in
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['google_access_token'] = $accessToken;
    $_SESSION['logged_in'] = true;

    // Update/Get user mode
    $ps = $db->prepare('SELECT active_mode FROM user_profiles WHERE user_id = ? LIMIT 1');
    $ps->execute([$userId]);
    $profile = $ps->fetch();
    $_SESSION['user_mode'] = $profile ? $profile['active_mode'] : 'clipper';

    // Log login
    logLoginEvent($userId, $user['username'], getClientIp(), 'google_login', 'Logged in via Google');

    redirect('/dashboard');

} catch (Throwable $e) {
    die('Error during authentication: ' . $e->getMessage());
}
