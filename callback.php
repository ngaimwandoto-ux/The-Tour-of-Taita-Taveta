<?php
/**
 * callback.php
 * Safaricom posts the result of the STK push here (set as MPESA_CALLBACK_URL
 * in mpesa-config.php). This URL must be publicly reachable over HTTPS —
 * Safaricom's servers call it, not the browser.
 *
 * Looks up the matching "pending" row — checking registrations, then
 * visits, then donations — by CheckoutRequestID, and marks it "paid" or
 * "failed". Whichever table actually has that CheckoutRequestID is the
 * one that gets updated; the other two UPDATEs simply affect zero rows.
 */

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

file_put_contents(__DIR__ . '/mpesa-callback.log', date('c') . ' ' . $raw . PHP_EOL, FILE_APPEND);

$checkoutRequestId = $data['Body']['stkCallback']['CheckoutRequestID'] ?? null;
$resultCode = $data['Body']['stkCallback']['ResultCode'] ?? null;

if ($checkoutRequestId !== null && $resultCode !== null) {
    require __DIR__ . '/includes/database.php';
    $db = Database::getInstance()->getConnection();

    $mpesaReceipt = null;
    if ((int) $resultCode === 0) {
        $items = $data['Body']['stkCallback']['CallbackMetadata']['Item'] ?? [];
        foreach ($items as $item) {
            if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
                $mpesaReceipt = $item['Value'] ?? null;
                break;
            }
        }
    }

    $status = ((int) $resultCode === 0) ? 'paid' : 'failed';

    $tables = ['registrations', 'visits', 'donations'];
    foreach ($tables as $table) {
        $stmt = $db->prepare("
            UPDATE $table SET status = ?, mpesa_receipt = ?, updated_at = CURRENT_TIMESTAMP
            WHERE checkout_request_id = ?
        ");
        $stmt->execute([$status, $mpesaReceipt, $checkoutRequestId]);
        if ($stmt->rowCount() > 0) break; // found and updated the right table — stop here
    }

    // TODO once you're sending confirmations: look up the row's name/
    // phone here and send an SMS/email now that status is "paid" or "failed".
}

echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Callback received']);
