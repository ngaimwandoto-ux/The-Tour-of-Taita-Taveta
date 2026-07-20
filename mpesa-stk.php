<?php
/**
 * mpesa-stk.php
 * Receives the race-registration form's JSON payload and triggers a Daraja
 * STK Push. Requires mpesa-config.php (copy mpesa-config.example.php first
 * and fill in your real credentials — see that file for instructions).
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
$email  = trim($input['email']  ?? '');
$phone  = trim($input['phone']  ?? '');
$ticket = trim($input['ticket'] ?? '');
$leg    = trim($input['leg']    ?? '');
$gender = trim($input['gender'] ?? '');
$amount = (int)($input['amount'] ?? 0);

if ($name === '' || $email === '' || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Missing required registration fields.']);
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
        'Tour of Taita Taveta - ' . $leg . ' - ' . $ticket . ' (' . $gender . ')'
    );

    // TODO: once you have a database, save $name/$email/$msisdn/$ticket/$leg here
    // (as "pending"), then mark it "paid" when callback.php confirms success.

    echo json_encode($result);

} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'ResponseCode' => '1',
        'ResponseDescription' => $e->getMessage(),
    ]);
}
