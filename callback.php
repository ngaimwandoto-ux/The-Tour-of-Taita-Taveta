<?php
/**
 * callback.php
 * Safaricom posts the result of the STK push here (set as MPESA_CALLBACK_URL
 * in mpesa-config.php). This URL must be publicly reachable over HTTPS —
 * Safaricom's servers call it, not the browser.
 *
 * UPDATED: this now looks up the matching "pending" row (in registrations,
 * then visits) by CheckoutRequestID and marks it "paid" or "failed" —
 * closing the loop that mpesa-stk.php / visit-payment.php opened.
 */

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Always log the raw payload somewhere you can inspect while testing.
file_put_contents(__DIR__ . '/mpesa-callback.log', date('c') . ' ' . $raw . PHP_EOL, FILE_APPEND);

$checkoutRequestId = $data['Body']['stkCallback']['CheckoutRequestID'] ?? null;
$resultCode = $data['Body']['stkCallback']['ResultCode'] ?? null;

if ($checkoutRequestId !== null && $resultCode !== null) {
    require __DIR__ . '/includes/database.php';
    $db = Database::getInstance()->getConnection();

    $mpesaReceipt = null;
    if ((int) $resultCode === 0) {
        // Extract the M-Pesa receipt number from CallbackMetadata on success.
        $items = $data['Body']['stkCallback']['CallbackMetadata']['Item'] ?? [];
        foreach ($items as $item) {
            if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
                $mpesaReceipt = $item['Value'] ?? null;
                break;
            }
        }
    }

    $status = ((int) $resultCode === 0) ? 'paid' : 'failed';

    // Try registrations first, then visits — whichever table has this
    // CheckoutRequestID is the one that gets updated.
    $stmt = $db->prepare("
        UPDATE registrations SET status = ?, mpesa_receipt = ?, updated_at = CURRENT_TIMESTAMP
        WHERE checkout_request_id = ?
    ");
    $stmt->execute([$status, $mpesaReceipt, $checkoutRequestId]);

    if ($stmt->rowCount() === 0) {
        $stmt = $db->prepare("
            UPDATE visits SET status = ?, mpesa_receipt = ?, updated_at = CURRENT_TIMESTAMP
            WHERE checkout_request_id = ?
        ");
        $stmt->execute([$status, $mpesaReceipt, $checkoutRequestId]);
    }

    // TODO once you're sending confirmations: look up the row's name/email/
    // phone here and send an SMS/email now that status is "paid" or "failed".
}

// Safaricom expects a 200 response acknowledging receipt.
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Callback received']);
