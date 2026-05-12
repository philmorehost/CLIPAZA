<?php
declare(strict_types=1);

/**
 * PayHub payment gateway helper functions.
 */

function payhubRequest(string $method, string $endpoint, array $data = []): array {
    $baseUrl  = rtrim(getSetting('payhub_base_url', 'https://payhub.datagifting.com.ng'), '/');
    $apiKey   = getSetting('payhub_api_key', '');

    if (empty($apiKey)) {
        return ['error' => 'PayHub not configured.'];
    }

    $url = $baseUrl . $endpoint;
    $ch  = curl_init($url);

    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ];

    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($data);
    }

    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => $err];
    }

    $decoded = json_decode($response, true);
    return $decoded ?: ['error' => 'Invalid response from PayHub.'];
}

function payhubInitCheckout(string $email, float $amount, string $reference, string $callbackUrl, array $metadata = []): array {
    $merchantId = getSetting('payhub_merchant_id', '');

    return payhubRequest('POST', '/api/payment/initialize', [
        'merchant_id'  => $merchantId,
        'amount'       => $amount,
        'email'        => $email,
        'reference'    => $reference,
        'callback_url' => $callbackUrl,
        'metadata'     => $metadata,
    ]);
}

function payhubVerifyPayment(string $reference): array {
    return payhubRequest('GET', '/api/payment/verify/' . urlencode($reference));
}

function payhubCreateVirtualAccount(string $email, string $name): array {
    $merchantId = getSetting('payhub_merchant_id', '');

    return payhubRequest('POST', '/api/virtual-account/create', [
        'merchant_id'    => $merchantId,
        'customer_email' => $email,
        'customer_name'  => $name,
        'bvn_optional'   => true,
    ]);
}

function payhubInitPayout(float $amount, string $bankCode, string $accountNumber, string $accountName, string $reference, string $narration = ''): array {
    $merchantId = getSetting('payhub_merchant_id', '');

    return payhubRequest('POST', '/api/payout/send', [
        'merchant_id'    => $merchantId,
        'amount'         => $amount,
        'bank_code'      => $bankCode,
        'account_number' => $accountNumber,
        'account_name'   => $accountName,
        'reference'      => $reference,
        'narration'      => $narration ?: 'Clipaza withdrawal',
    ]);
}

function payhubEnabled(): bool {
    $apiKey     = getSetting('payhub_api_key', '');
    $merchantId = getSetting('payhub_merchant_id', '');
    return !empty($apiKey) && !empty($merchantId);
}
