<?php
/**
 * callback.php
 * Safaricom posts the result of the STK push here (set as MPESA_CALLBACK_URL
 * in mpesa-config.php). This URL must be publicly reachable over HTTPS —
 * Safaricom's servers call it, not the browser.
 */

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Always log the raw payload somewhere you can inspect while testing.
file_put_contents(__DIR__ . '/mpesa-callback.log', date('c') . ' ' . $raw . PHP_EOL, FILE_APPEND);

$resultCode = $data['Body']['stkCallback']['ResultCode'] ?? null;

if ($resultCode === 0) {
    // Payment succeeded.
    // TODO: look up the pending registration by CheckoutRequestID and mark it "paid",
    // then send the confirmation SMS/email mentioned on the registration form.
} else {
    // Payment failed, was cancelled, or timed out.
    // TODO: mark the registration as "failed" so the rider can retry.
}

// Safaricom expects a 200 response acknowledging receipt.
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Callback received']);
