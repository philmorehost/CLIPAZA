<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$clientId = getSetting('google_client_id', '');
$redirectUri = rtrim(getSetting('site_url', 'http://localhost'), '/') . '/auth/google-callback.php';

if (empty($clientId)) {
    die('Google OAuth is not configured. Please contact the administrator.');
}

$params = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/youtube.readonly',
    'access_type' => 'offline',
    'prompt' => 'consent',
    'state' => generateToken(16)
];

$_SESSION['google_oauth_state'] = $params['state'];

$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
header('Location: ' . $url);
exit;
