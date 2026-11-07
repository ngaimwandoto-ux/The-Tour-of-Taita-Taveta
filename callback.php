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

    $notify = null;   // emails to send AFTER we've answered Safaricom
    $tables = ['registrations', 'visits', 'donations', 'bookings'];

    // On a busy moment SQLite can answer "database is locked" for a split second.
    // Try again a few times instead of giving up; if it still fails the raw message is
    // already in mpesa-callback.log and reconcile.php will recover the payment.
    $retry = function (callable $fn) {
        for ($i = 1; ; $i++) {
            try { return $fn(); }
            catch (PDOException $e) {
                $busy = stripos($e->getMessage(), 'locked') !== false || stripos($e->getMessage(), 'busy') !== false;
                if (!$busy || $i >= 8) throw $e;
                usleep(random_int(40000, 160000));
            }
        }
    };

    try {
        foreach ($tables as $table) {
            // Remember the row as it was, so a retried callback never sends a second email.
            $row = $retry(function () use ($db, $table, $checkoutRequestId) {
                $before = $db->prepare("SELECT * FROM $table WHERE checkout_request_id = ?");
                $before->execute([$checkoutRequestId]);
                $found = $before->fetch(PDO::FETCH_ASSOC);
                $before->closeCursor();   // finish the read BEFORE we write (an open read blocks the write)
                return $found;
            });

            $changed = $retry(function () use ($db, $table, $status, $mpesaReceipt, $checkoutRequestId) {
                $stmt = $db->prepare("
                    UPDATE $table SET status = ?, mpesa_receipt = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE checkout_request_id = ?
                ");
                $stmt->execute([$status, $mpesaReceipt, $checkoutRequestId]);
                return $stmt->rowCount();
            });

            if ($changed > 0) {
                if ($row && $row['status'] !== 'paid' && $status === 'paid') {
                    $row['mpesa_receipt'] = $mpesaReceipt;
                    if ($table === 'registrations') {
                        $notify = function () use ($row) { if (function_exists('mailRegistrationReceipt')) mailRegistrationReceipt($row); };
                    } elseif ($table === 'bookings') {
                        $notify = function () use ($row) { if (function_exists('mailAdminBookingPaid')) mailAdminBookingPaid($row); };
                    }
                }
                break;
            }
        }
    } catch (Throwable $e) {
        error_log('callback: could not save ' . $checkoutRequestId . ': ' . $e->getMessage());
    }
}

$reply = json_encode(['ResultCode' => 0, 'ResultDesc' => 'Callback received']);
if (!empty($notify) && is_file(__DIR__ . '/includes/mailer.php')) {
    require __DIR__ . '/includes/mailer.php';
    respondThenRun($reply, $notify);
} else {
    echo $reply;
}
