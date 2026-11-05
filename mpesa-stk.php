<?php
/**
 * mpesa-stk.php
 * Receives the race-registration form's JSON payload and triggers a Daraja
 * STK Push. Requires mpesa-config.php (copy mpesa-config.example.php first
 * and fill in your real credentials — see that file for instructions).
 *
 * Once Safaricom returns a CheckoutRequestID, the registration is saved as
 * "pending". callback.php marks it "paid" once Safaricom confirms payment.
 *
 * UPDATED: optional team_id, server-side check of the entry fee.
 * (College & University teams do NOT pay here — they register through
 * institution-entry.php and pay by invoice / bank transfer.)
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
require __DIR__ . '/includes/registration-columns.php';

// ---- Entry fees ----
// The ONE place the server checks the amount. Keep in step with the
// data-price on each tier in index.html (the page only displays them; this
// is what is actually charged, so nobody can pay less by editing the page,
// and the public prize-pool totals can't be skewed).
$ENTRY_FEES = [
    'elite'   => 3000,
    'amateur' => 1500,
    'open'    => 500,
];

// ---- Read + validate input ----
$input = json_decode(file_get_contents('php://input'), true) ?: [];

$name        = trim($input['name']   ?? '');
$email       = trim($input['email']  ?? '');
$phone       = trim($input['phone']  ?? '');
$ticket      = strtolower(trim($input['ticket'] ?? ''));
$leg         = trim($input['leg']    ?? '');
$gender      = trim($input['gender'] ?? '');
$amount      = (int)($input['amount'] ?? 0);
$teamId      = (isset($input['team_id']) && $input['team_id'] !== '' && $input['team_id'] !== null) ? (int)$input['team_id'] : null;

function regFail(string $msg): void {
    http_response_code(400);
    echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => $msg]);
    exit;
}

if ($name === '' || $email === '' || $amount <= 0) {
    regFail('Missing required registration fields.');
}
if (!isset($ENTRY_FEES[$ticket])) {
    regFail('Unknown registration category.');
}
if ($amount !== $ENTRY_FEES[$ticket]) {
    regFail('The entry fee has changed — please refresh the page and try again.');
}

$msisdn = mpesaNormalisePhone($phone);
if ($msisdn === null) {
    regFail('Invalid phone number format.');
}

try {
    $result = mpesaStkPush(
        $msisdn,
        $amount,
        MPESA_ACCOUNT_REF_REGISTRATION,
        'Tour of Taita Taveta - ' . $leg . ' - ' . $ticket . ' (' . $gender . ')'
    );

    // Save as "pending" now that we have Safaricom's CheckoutRequestID —
    // callback.php will flip this row to "paid"/"failed".
    $checkoutRequestId = $result['CheckoutRequestID'] ?? null;
    if ($checkoutRequestId) {
        $db = Database::getInstance()->getConnection();
        ensureRegistrationColumns($db);

        // Optional team: keep it only if it is a real, approved team.
        if ($teamId !== null) {
            $t = $db->prepare("SELECT id FROM teams WHERE id = ? AND is_public = 1");
            $t->execute([$teamId]);
            if (!$t->fetch()) { $teamId = null; }
        }

        $stmt = $db->prepare("
            INSERT INTO registrations (checkout_request_id, name, email, phone, ticket, leg, gender, amount, status, team_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([
            $checkoutRequestId, $name, $email, $msisdn, $ticket, $leg, $gender, $amount,
            $teamId,
        ]);
    }

    echo json_encode($result);

} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'ResponseCode' => '1',
        'ResponseDescription' => $e->getMessage(),
    ]);
}
