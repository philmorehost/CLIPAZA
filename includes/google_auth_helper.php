<?php
declare(strict_types=1);

class GoogleAuthHelper {
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct() {
        $this->clientId     = getSetting('google_client_id', '');
        $this->clientSecret = getSetting('google_client_secret', '');

        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $this->redirectUri  = "$protocol://$host/auth/google-callback.php";
    }

    public function isConfigured(): bool {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    public function getAuthUrl(): string {
        $params = [
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'access_type'   => 'online',
            'prompt'        => 'select_account'
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public function fetchUser(string $code): ?array {
        // 1. Exchange code for access token
        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $tokenData = [
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code'
        ];

        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenData));
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) return null;

        $tokenResult = json_decode((string)$response, true);
        if (empty($tokenResult['access_token'])) return null;

        // 2. Fetch user profile
        $userUrl = 'https://www.googleapis.com/oauth2/v3/userinfo';
        $ch = curl_init($userUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $tokenResult['access_token']
        ]);
        $userResponse = curl_exec($ch);
        curl_close($ch);

        return json_decode((string)$userResponse, true) ?: null;
    }
}
