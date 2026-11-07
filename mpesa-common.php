<?php
/**
 * mpesa-common.php
 * Shared Daraja helper functions used by mpesa-stk.php (race registration),
 * visit-payment.php, donate.php, book-accommodation.php and reconcile.php.
 * Requires mpesa-config.php to already be loaded (defines MPESA_* constants)
 * before these are called.
 *
 * Busy-day behaviour:
 *  - the Safaricom access token is fetched once and reused for most of its
 *    hour of life (it used to be fetched again for every single payment);
 *  - every call to Safaricom has a time limit, so a slow reply can never tie
 *    up a PHP process for long.
 */

if (!defined('MPESA_CONNECT_TIMEOUT')) define('MPESA_CONNECT_TIMEOUT', 8);   // seconds to reach Safaricom
if (!defined('MPESA_TOTAL_TIMEOUT'))   define('MPESA_TOTAL_TIMEOUT', 25);     // seconds for the whole call

function mpesaTokenCacheKey(): string {
    return 'tttt_mpesa_token_' . md5(MPESA_BASE_URL . '|' . MPESA_CONSUMER_KEY);
}

/** Cached token, or null. Uses APCu when the host has it, else a private temp file. */
function mpesaTokenFromCache(): ?string {
    $key = mpesaTokenCacheKey();
    if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
        $v = apcu_fetch($key, $ok);
        if ($ok && is_string($v) && $v !== '') return $v;
    }
    $file = sys_get_temp_dir() . '/' . $key . '.json';
    if (is_file($file)) {
        $d = json_decode((string) @file_get_contents($file), true);
        if (is_array($d) && !empty($d['token']) && ($d['expires_at'] ?? 0) > time()) return $d['token'];
    }
    return null;
}

function mpesaTokenToCache(string $token, int $ttl): void {
    $key = mpesaTokenCacheKey();
    if (function_exists('apcu_store') && ini_get('apc.enabled')) apcu_store($key, $token, $ttl);
    $file = sys_get_temp_dir() . '/' . $key . '.json';
    $tmp = $file . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, json_encode(['token' => $token, 'expires_at' => time() + $ttl])) !== false) {
        @chmod($tmp, 0600);
        @rename($tmp, $file);   // atomic: a reader never sees half a file
    }
}

function mpesaForgetToken(): void {
    $key = mpesaTokenCacheKey();
    if (function_exists('apcu_delete')) @apcu_delete($key);
    @unlink(sys_get_temp_dir() . '/' . $key . '.json');
}

function mpesaGetAccessToken(bool $forceFresh = false): string {
    if (!$forceFresh && ($cached = mpesaTokenFromCache()) !== null) return $cached;

    $ch = curl_init(MPESA_BASE_URL . '/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, MPESA_CONNECT_TIMEOUT);
    curl_setopt($ch, CURLOPT_TIMEOUT, MPESA_TOTAL_TIMEOUT);
    $result = curl_exec($ch);
    if ($result === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Could not reach Safaricom auth endpoint: ' . $err);
    }
    curl_close($ch);

    $decoded = json_decode($result, true);
    if (empty($decoded['access_token'])) {
        throw new RuntimeException('Failed to obtain access token from Safaricom.');
    }
    // Safaricom says tokens last about an hour; keep ours for 5 minutes less than they say.
    $ttl = max(60, (int) ($decoded['expires_in'] ?? 3599) - 300);
    mpesaTokenToCache($decoded['access_token'], $ttl);
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
 * POST a JSON body to a Daraja path with the cached token. If Safaricom says the token
 * is no good (it was revoked or expired early) the token is thrown away and the call is
 * retried ONCE with a fresh one. Returns [httpStatus, decodedArray|null].
 */
function mpesaPost(string $path, array $body): array {
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $token = mpesaGetAccessToken($attempt > 0);
        $ch = curl_init(MPESA_BASE_URL . $path);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $token]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, MPESA_CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, MPESA_TOTAL_TIMEOUT);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Request to Safaricom failed: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status === 401 && $attempt === 0) { mpesaForgetToken(); continue; }
        $decoded = json_decode($response, true);
        return [$status, is_array($decoded) ? $decoded : null];
    }
    return [0, null];
}

/**
 * Triggers an STK push for the given MSISDN/amount and returns Safaricom's
 * raw decoded response array (contains ResponseCode / ResponseDescription /
 * CheckoutRequestID on success).
 */
function mpesaStkPush(string $msisdn, int $amount, string $accountReference, string $transactionDesc): array {
    $timestamp = date('YmdHis');
    $password  = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);

    [, $decoded] = mpesaPost('/mpesa/stkpush/v1/processrequest', [
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
    ]);
    return $decoded ?? ['ResponseCode' => '1', 'ResponseDescription' => 'Unrecognised response from Safaricom.'];
}

/**
 * Asks Safaricom what happened to an earlier STK push. Used to recover payments whose
 * confirmation (callback) never reached us. Returns:
 *   'paid'    – the customer paid
 *   'failed'  – cancelled, wrong PIN, timed out, insufficient funds, etc.
 *   'pending' – Safaricom is still processing, or could not say — try again later
 */
function mpesaStkQuery(string $checkoutRequestId): string {
    $timestamp = date('YmdHis');
    [, $d] = mpesaPost('/mpesa/stkpushquery/v1/query', [
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password'          => base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp),
        'Timestamp'         => $timestamp,
        'CheckoutRequestID' => $checkoutRequestId,
    ]);
    if (is_array($d) && isset($d['ResultCode']) && $d['ResultCode'] !== '') {
        return ((string) $d['ResultCode'] === '0') ? 'paid' : 'failed';
    }
    return 'pending';
}
