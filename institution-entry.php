<?php
/**
 * institution-entry.php
 * Receives a College & University team entry. Saves it as "pending" —
 * no money is taken here. You send the institution an invoice with the
 * bank details, and when the transfer arrives you mark it paid in
 * admin/institutions.php. Only then does it count toward the prize pool.
 */
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only.']);
    exit;
}

require __DIR__ . '/includes/database.php';
require __DIR__ . '/includes/institutions.php';
require __DIR__ . '/includes/leg-schedule.php';
require __DIR__ . '/includes/mailer.php';

function instFail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];

// Honeypot: real people never fill this hidden field. Pretend success to bots.
if (!empty($in['website'])) {
    echo json_encode(['success' => true, 'reference' => 'INST-0000', 'leg' => '']);
    exit;
}

$institution = mb_substr(trim($in['institution'] ?? ''), 0, 120);
$teamName    = mb_substr(trim($in['team_name'] ?? ''), 0, 120);
$contact     = mb_substr(trim($in['contact_name'] ?? ''), 0, 100);
$email       = trim($in['email'] ?? '');
$phone       = mb_substr(trim($in['phone'] ?? ''), 0, 30);
$riders      = (int) ($in['riders'] ?? 0);
$legIn       = trim($in['leg'] ?? '');

if ($institution === '' || $contact === '' || $phone === '') instFail('Please fill in the institution, contact person and phone number.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) instFail('Please enter a valid email address — your invoice is sent there.');
if (!preg_match('/^\+?[0-9 ()-]{7,30}$/', $phone)) instFail('Please enter a valid phone number.');
if ($riders < 1 || $riders > 100) instFail('Enter how many riders are in the team (1–100).');
if ($teamName === '') $teamName = $institution;

// Leg: a chosen leg must be real and not already over; blank = the next upcoming leg.
$assumed = 0;
if ($legIn === '') {
    $up = upcomingLeg();
    $leg = $up ? $up['leg'] : 'To be confirmed';
    $assumed = 1;
} else {
    $leg = null;
    foreach (legSchedulePublic() as $l) {
        if (strcasecmp($l['leg'], $legIn) === 0) {
            if ($l['past']) instFail('That leg has already taken place — please pick an upcoming leg.');
            $leg = $l['leg'];
        }
    }
    if ($leg === null) instFail('Unknown leg.');
}

try {
    $db = Database::getInstance()->getConnection();
    ensureInstitutionTable($db);

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $recent = $db->prepare("SELECT COUNT(*) c FROM institution_entries WHERE ip = ? AND created_at > datetime('now','-1 hour')");
    $recent->execute([$ip]);
    $recentCount = (int) $recent->fetch()['c'];
    $recent->closeCursor();   // finish the read before writing below
    if ($recentCount >= 5) instFail('Too many entries from this connection — please try again later.', 429);

    $st = $db->prepare("
        INSERT INTO institution_entries (institution, team_name, contact_name, email, phone, riders, leg, leg_assumed, ip)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $st->execute([$institution, $teamName, $contact, $email, $phone, $riders, $leg, $assumed, $ip]);
    $id = (int) $db->lastInsertId();

    $reply = json_encode([
        'success' => true,
        'reference' => 'INST-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT),
        'leg' => $leg,
        'leg_assumed' => $assumed === 1,
    ]);

    // Reply to the visitor first, then send the emails (they never wait on the mail server).
    $row = ['id' => $id, 'institution' => $institution, 'team_name' => $teamName, 'contact_name' => $contact,
            'email' => $email, 'phone' => $phone, 'riders' => $riders, 'leg' => $leg, 'leg_assumed' => $assumed];
    respondThenRun($reply, function () use ($row) {
        mailInstitutionReceived($row);
        mailAdminInstitutionEntry($row);
    });
} catch (Throwable $e) {
    error_log('institution-entry: ' . $e->getMessage());
    instFail('Could not save your entry right now — please try again.', 500);
}
