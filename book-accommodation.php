<?php
/**
 * book-accommodation.php
 * Guest pays the FULL nightly total to TTTT's own paybill — Safaricom's
 * STK push can't route to a third party's number directly. The 20%/80%
 * split is computed and stored for tracking; the host is paid out
 * manually (see admin/bookings.php) until B2C automation exists.
 */

header('Content-Type: application/json');

$configPath = __DIR__ . '/mpesa-config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Server is not configured yet.']);
    exit;
}
require $configPath;
require __DIR__ . '/mpesa-common.php';
require __DIR__ . '/includes/database.php';
require __DIR__ . '/includes/accommodation.php';

$input = json_decode(file_get_contents('php://input'), true);

$propertyId = (int) ($input['property_id'] ?? 0);
$guestName  = trim($input['guest_name'] ?? '');
$guestPhone = trim($input['guest_phone'] ?? '');
$checkIn    = trim($input['check_in'] ?? '');
$checkOut   = trim($input['check_out'] ?? '');

if ($propertyId <= 0 || $guestName === '' || $checkIn === '' || $checkOut === '') {
    http_response_code(400);
    echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Missing required booking details.']);
    exit;
}

$checkInDate = DateTime::createFromFormat('Y-m-d', $checkIn);
$checkOutDate = DateTime::createFromFormat('Y-m-d', $checkOut);
if (!$checkInDate || !$checkOutDate || $checkOutDate <= $checkInDate) {
    http_response_code(400);
    echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Invalid date range.']);
    exit;
}
$nights = (int) $checkInDate->diff($checkOutDate)->days;

$accommodation = new Accommodation();
$property = $accommodation->getProperty($propertyId);
if (!$property || !$property['is_public'] || !$property['is_active']) {
    http_response_code(404);
    echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Property not available.']);
    exit;
}

$totalAmount = $nights * (int) $property['price_per_night'];

$msisdn = mpesaNormalisePhone($guestPhone);
if ($msisdn === null) {
    http_response_code(400);
    echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Invalid phone number format.']);
    exit;
}

try {
    $result = mpesaStkPush(
        $msisdn,
        $totalAmount,
        MPESA_ACCOUNT_REFERENCE,
        'Booking - ' . $property['name'] . ' (' . $nights . ' nights)'
    );

    $checkoutRequestId = $result['CheckoutRequestID'] ?? null;
    if ($checkoutRequestId) {
        $accommodation->createPendingBooking(
            $checkoutRequestId, $propertyId, $guestName, $msisdn,
            $checkIn, $checkOut, $nights, $totalAmount
        );
    }

    echo json_encode($result);

} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => $e->getMessage()]);
}
