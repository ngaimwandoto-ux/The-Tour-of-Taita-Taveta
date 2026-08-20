<?php
/**
 * donate.php
 * Receives a "Support this leg" donation payload from any region page
 * and triggers a Daraja STK Push. Shares the same mpesa-config.php as
 * mpesa-stk.php / visit-payment.php — no separate setup needed.
 *
 * Guest checkout, same as race registration and visitation fees — this
 * is NOT tied to a Supporter account. Becoming a publicly-listed
 * Supporter (logo on the homepage) is still a separate step: register
 * a Supporter account, upload a logo, get approved. This endpoint only
 * handles the payment.
 *
 * Tier (Tsavorite/Ruby/Green Garnet/Spinel/Rhodolite) is derived here,
 * server-side, from the amount — never trust a tier sent by the client.
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

/**
 * Tier thresholds — PLACEHOLDER values pending final rates, same as the
 * ad-calculator prices elsewhere on the site. Adjust freely; nothing
 * else depends on these exact numbers.
 */
function donationTierFor(int $amount): string {
    if ($amount >= 50000) return 'Tsavorite';
    if ($amount >= 25000) return 'Ruby';
    if ($amount >= 10000) return 'Green Garnet';
    if ($amount >= 5000)  return 'Spinel';
    return 'Rhodolite';
}

$input = json_decode(file_get_contents('php://input'), true);

$name   = trim($input['name']  ?? '');
$phone  = trim($input['phone'] ?? '');
$scope  = trim($input['scope'] ?? ''); // e.g. "Sagalla Leg — Tour of Taita Taveta" or "Tour of Taita Taveta"
$amount = (int) ($input['amount'] ?? 0);

if ($name === '' || $scope === '' || $amount < 100) {
    http_response_code(400);
    echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Missing required fields, or amount is below the KES 100 minimum.']);
    exit;
}

$msisdn = mpesaNormalisePhone($phone);
if ($msisdn === null) {
    http_response_code(400);
    echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Invalid phone number format.']);
    exit;
}

$tier = donationTierFor($amount);

try {
    $result = mpesaStkPush(
        $msisdn,
        $amount,
        MPESA_ACCOUNT_REF_DONATION,
        'Support donation - ' . $scope . ' (' . $tier . ')'
    );

    $checkoutRequestId = $result['CheckoutRequestID'] ?? null;
    if ($checkoutRequestId) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO donations (checkout_request_id, name, phone, scope, tier, amount, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$checkoutRequestId, $name, $msisdn, $scope, $tier, $amount]);
    }

    // Let the frontend show the tier the donor landed in.
    $result['tier'] = $tier;
    echo json_encode($result);

} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'ResponseCode' => '1',
        'ResponseDescription' => $e->getMessage(),
    ]);
}
