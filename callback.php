<?php
/**
 * callback.php
 * Safaricom posts the result of the STK push here. Checks
 * registrations, then visits, then donations, then bookings — by
 * CheckoutRequestID — and marks whichever one matches "paid" or
 * "failed".
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

    $tables = ['registrations', 'visits', 'donations', 'bookings'];
    foreach ($tables as $table) {
        $stmt = $db->prepare("
            UPDATE $table SET status = ?, mpesa_receipt = ?, updated_at = CURRENT_TIMESTAMP
            WHERE checkout_request_id = ?
        ");
        $stmt->execute([$status, $mpesaReceipt, $checkoutRequestId]);
        if ($stmt->rowCount() > 0) break;
    }

    // TODO once you're sending confirmations: look up the row's name/
    // phone here and send an SMS/email now that status is "paid" or "failed".
}

echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Callback received']);
