<?php
/**
 * reconcile.php
 * Recovers payments whose confirmation never reached the site.
 *
 * When someone pays by M-Pesa, Safaricom tells callback.php. If that message is
 * lost (site busy, host hiccup), the money is taken but the entry stays
 * "pending" forever. This script asks Safaricom about every payment that has
 * been pending for 3+ minutes (but under 2 days) and marks it paid or failed.
 * It is safe to run as often as you like: a payment is only changed while it is
 * still "pending", and a rider's receipt email goes out at most once.
 *
 * Run it either way:
 *   - on a schedule (recommended), as a cPanel Cron Job every 5 minutes:
 *       php /home/YOURUSER/public_html/reconcile.php
 *   - by hand: https://yourdomain/reconcile.php?key=YOUR_ADMIN_KEY
 *
 * Note: Safaricom's query does not return the M-Pesa receipt code, so a payment
 * recovered here has no receipt number stored. Find it on your paybill statement.
 */
$isCli = (PHP_SAPI === 'cli');
require_once __DIR__ . '/config.php';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!defined('ADMIN_KEY') || ADMIN_KEY === '' || !hash_equals((string) ADMIN_KEY, (string) ($_GET['key'] ?? ''))) {
        http_response_code(403);
        die("Not authorised. Add ?key=YOUR_ADMIN_KEY to the URL.\n");
    }
}

$configPath = __DIR__ . '/mpesa-config.php';
if (!file_exists($configPath)) die("mpesa-config.php is missing - nothing to check yet.\n");
require $configPath;
require __DIR__ . '/mpesa-common.php';
require __DIR__ . '/includes/database.php';
$hasMailer = is_file(__DIR__ . '/includes/mailer.php');   // emails are optional here
if ($hasMailer) require __DIR__ . '/includes/mailer.php';

@set_time_limit(120);
const RECONCILE_MAX_PER_RUN = 40;   // keeps each run short and well inside Safaricom's rate limits

// One run at a time (cron + a manual click must not overlap).
$lock = fopen(sys_get_temp_dir() . '/tttt-reconcile.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) die("Another reconcile run is in progress - try again in a minute.\n");

$db = Database::getInstance()->getConnection();
$tables = ['registrations', 'visits', 'donations', 'bookings'];
$budget = RECONCILE_MAX_PER_RUN;
$counts = ['checked' => 0, 'paid' => 0, 'failed' => 0, 'still_pending' => 0, 'errors' => 0];

foreach ($tables as $table) {
    if ($budget <= 0) break;
    $st = $db->prepare("SELECT * FROM $table
        WHERE status = 'pending' AND checkout_request_id IS NOT NULL AND checkout_request_id != ''
          AND created_at <= datetime('now', '-3 minutes') AND created_at >= datetime('now', '-2 days')
        ORDER BY created_at LIMIT ?");
    $st->execute([$budget]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $budget--;
        $counts['checked']++;
        try {
            $result = mpesaStkQuery($row['checkout_request_id']);
        } catch (Throwable $e) {
            $counts['errors']++;
            error_log('reconcile: ' . $e->getMessage());
            continue;
        }
        if ($result === 'pending') { $counts['still_pending']++; usleep(200000); continue; }

        $upd = $db->prepare("UPDATE $table SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'");
        $upd->execute([$result, $row['id']]);
        if ($upd->rowCount() > 0) {
            $counts[$result]++;
            echo date('c') . " $table #{$row['id']} -> $result\n";
            if ($result === 'paid') {
                if ($hasMailer && $table === 'registrations') mailRegistrationReceipt($row);
                elseif ($hasMailer && $table === 'bookings') mailAdminBookingPaid($row);
            }
        }
        usleep(200000);
    }
}

echo "Checked {$counts['checked']}: {$counts['paid']} recovered as paid, {$counts['failed']} marked failed, {$counts['still_pending']} still waiting, {$counts['errors']} could not be checked.\n";
if ($budget <= 0) echo "More payments are waiting - they will be picked up on the next run.\n";
flock($lock, LOCK_UN);
