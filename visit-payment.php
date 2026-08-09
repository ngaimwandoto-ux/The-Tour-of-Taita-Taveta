<?php
/**
 * visit-payment.php
 * Receives a site-visitation-fee payload from any region page (regions/*.html)
 * and triggers a Daraja STK Push. Shares the same mpesa-config.php as
 * mpesa-stk.php — no separate setup needed.
 *
 * UPDATED: once Safaricom returns a CheckoutRequestID, this now saves the
 * visit payment as "pending" in the database. callback.php marks it "paid"
 * once Safaricom confirms the payment went through.
 */

header('Content-Type: application/json');

$configPath = __DIR__ . '/mpesa-config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode([
        'ResponseCode' => '1',
        'ResponseDescription' => 'Server is not configured yet: copy mpesa-config.example.php to mpesa-config.php and fill in your Daraja credentials.',
    ]);
    exit;
}
require $configPath;
require __DIR__ . '/mpesa-common.php';
require __DIR__ . '/includes/database.php';

// ---- Read + validate input ----
$input = json_decode(file_get_contents('php://input'), true);

$name   = trim($input['name']   ?? '');
$phone  = trim($input['phone']  ?? '');
$site   = trim($input['ticket'] ?? ''); // the site/attraction name, reusing the registration payload's field name
$leg    = trim($input['leg']    ?? ''); // document.title from the region page, e.g. "Sagalla Leg — Tour of Taita Taveta"
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

    $checkoutRequestId = $result['CheckoutRequestID'] ?? null;
    if ($checkoutRequestId) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO visits (checkout_request_id, name, phone, site, leg, amount, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$checkoutRequestId, $name, $msisdn, $site, $leg, $amount]);
    }

    echo json_encode($result);

} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'ResponseCode' => '1',
        'ResponseDescription' => $e->getMessage(),
    ]);
}
