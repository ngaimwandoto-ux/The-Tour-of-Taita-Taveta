<?php
/**
 * mpesa-common.php
 * Shared Daraja helper functions used by mpesa-stk.php (race registration)
 * and visit-payment.php (site visitation fees). Requires mpesa-config.php
 * to already be loaded (defines MPESA_* constants) before these are called.
 */

function mpesaGetAccessToken(): string {
    $ch = curl_init(MPESA_BASE_URL . '/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET);
    $result = curl_exec($ch);
    if ($result === false) {
        throw new RuntimeException('Could not reach Safaricom auth endpoint: ' . curl_error($ch));
    }
    curl_close($ch);

    $decoded = json_decode($result, true);
    if (empty($decoded['access_token'])) {
        throw new RuntimeException('Failed to obtain access token from Safaricom.');
    }
    return $decoded['access_token'];
}

/**
 * Normalises a Kenyan number like 07XXXXXXXX or 01XXXXXXXX to 2547XXXXXXXX / 2541XXXXXXXX.
 * Returns null if the input doesn't match the expected format.
 */
function mpesaNormalisePhone(string $phone): ?string {
    if (!preg_match('/^0[71][0-9]{8}$/', $phone)) {
        return null;
    }
    return '254' . substr($phone, 1);
}

/**
 * Triggers an STK push for the given MSISDN/amount and returns Safaricom's
 * raw decoded response array (contains ResponseCode / ResponseDescription /
 * CheckoutRequestID on success).
 */
function mpesaStkPush(string $msisdn, int $amount, string $accountReference, string $transactionDesc): array {
    $token = mpesaGetAccessToken();

    $timestamp = date('YmdHis');
    $password  = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);

    $body = [
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password'          => $password,
        'Timestamp'         => $timestamp,
        'TransactionType'   => 'CustomerPayBillOnline',
        'Amount'            => $amount,
        'PartyA'            => $msisdn,
        'PartyB'            => MPESA_SHORTCODE,
        'PhoneNumber'       => $msisdn,
        'CallBackURL'       => MPESA_CALLBACK_URL,
        'AccountReference'  => $accountReference,
        'TransactionDesc'   => $transactionDesc,
    ];

    $ch = curl_init(MPESA_BASE_URL . '/mpesa/stkpush/v1/processrequest');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $response = curl_exec($ch);

    if ($response === false) {
        throw new RuntimeException('STK push request failed: ' . curl_error($ch));
    }
    curl_close($ch);

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : ['ResponseCode' => '1', 'ResponseDescription' => 'Unrecognised response from Safaricom.'];
}
