<?php
/**
 * visit-payment.php
 * Receives a site-visitation-fee payload from any region page
 * (regions/*.html) and triggers a Daraja STK Push. Shares the same
 * mpesa-config.php as mpesa-stk.php — no separate setup needed.
 */

header('Content-Type: application/json');

$configPath = __DIR__ . '/mpesa-config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode([
        'ResponseCode' => '1',
        'ResponseDescription' => 'Server is not configured yet: copy mpesa-config.example.php to mpesa-config.php and fill in your Daraja credentials.'
    ]);
    exit;
}
require $configPath;
require __DIR__ . '/mpesa-common.php';

// ---- Read + validate input ----
$input = json_decode(file_get_contents('php://input'), true);

$name   = trim($input['name']   ?? '');
$phone  = trim($input['phone']  ?? '');
$site   = trim($input['ticket'] ?? '');  // the site/attraction name, reusing the same field name as the registration payload
$leg    = trim($input['leg']    ?? '');  // document.title from the region page, e.g. "Sagalla Leg — Tour of Taita Taveta"
$amount = (int)($input['amount'] ?? 0);

if ($name === '' || $site === '' || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Missing required visitation payment fields.']);
    exit;
}

$msisdn = mpesaNormalisePhone($phone);
if ($msisdn === null) {
    http_response_code(400);
    echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Invalid phone number format.']);
    exit;
}

try {
    $result = mpesaStkPush(
        $msisdn,
        $amount,
        MPESA_ACCOUNT_REFERENCE,
        'Visitation fee - ' . $site . ' (' . $leg . ')'
    );

    // TODO: once you have a database, record $name/$msisdn/$site/$leg/$amount here
    // as "pending", then mark it "paid" when callback.php confirms success.
    // Consider splitting this revenue out to the relevant conservancy/site
    // rather than pooling it with race registration income.

    echo json_encode($result);

} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'ResponseCode' => '1',
        'ResponseDescription' => $e->getMessage(),
    ]);
}
