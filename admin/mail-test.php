<?php
/**
 * admin/mail-test.php
 * Key-protected. Shows the email settings (never the password) and sends a
 * test email to YOUR admin address (MAIL_ADMIN_TO) — never anywhere else.
 *
 * Usage: https://yourdomain/admin/mail-test.php?key=YOUR_ADMIN_KEY
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/mailer.php';

if (!defined('ADMIN_KEY') || ADMIN_KEY === '' || ($_GET['key'] ?? '') !== ADMIN_KEY) {
    http_response_code(403);
    die('Not authorised. Add ?key=YOUR_ADMIN_KEY to the URL.');
}

$result = null;
$to = (string) mailSetting('MAIL_ADMIN_TO', '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $logFile = dirname(DB_PATH) . '/mail-outbox.log';
    $before = is_file($logFile) ? filesize($logFile) : 0;
    $ok = $to !== '' && sendMailToMany($to, 'Test email — Tour of Taita Taveta',
        mailLayout('Email is working', '<p>If you can read this, the site can send email. Sent ' . mailE(gmdate('Y-m-d H:i')) . ' UTC.</p>'),
        'If you can read this, the site can send email. Sent ' . gmdate('Y-m-d H:i') . " UTC.\n");
    $detail = '';
    if (is_file($logFile)) {
        $new = (string) file_get_contents($logFile, false, null, $before);
        if (preg_match('/(FAILED[^\n]*)/', $new, $m)) $detail = $m[1];
    }
    $result = [$ok, $detail];
}
$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$rows = [
    'Email switched on'  => mailEnabled() ? 'Yes' : 'No — emails are only written to data/mail-outbox.log',
    'Config file found'  => is_file(__DIR__ . '/../mail-config.php') ? 'Yes' : 'No — copy mail-config.example.php to mail-config.php',
    'Method'             => (string) mailSetting('MAIL_TRANSPORT', '—'),
    'Sends from'         => (string) mailSetting('MAIL_FROM_EMAIL', '—'),
    'Your alerts go to'  => $to !== '' ? $to : '—',
    'SMTP server'        => mailSetting('SMTP_HOST') ? mailSetting('SMTP_HOST') . ':' . mailSetting('SMTP_PORT') . ' (' . mailSetting('SMTP_SECURE') . ')' : '—',
    'SMTP password set'  => (mailSetting('SMTP_PASS') && mailSetting('SMTP_PASS') !== 'REPLACE_WITH_THE_MAILBOX_PASSWORD') ? 'Yes' : 'No',
];
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email settings</title>
<style>
  body { font-family: sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; background: #FAFAF7; color: #171712; }
  h1 { font-size: 1.4rem; } table { border-collapse: collapse; width: 100%; background: #fff; }
  td { padding: .5rem .7rem; border: 1px solid #ddd; font-size: .88rem; } td:first-child { color: #666; width: 40%; }
  button { padding: .55rem 1rem; border: none; background: #3FA34D; color: #fff; cursor: pointer; margin-top: 1rem; }
  .ok { background: #E4F4E6; padding: .6rem .8rem; } .bad { background: #FBE0DC; padding: .6rem .8rem; } .off { background: #FBE9CF; padding: .6rem .8rem; }
</style></head><body>
<h1>Email settings</h1>
<table><?php foreach ($rows as $k => $v): ?><tr><td><?= $h($k) ?></td><td><?= $h($v) ?></td></tr><?php endforeach; ?></table>
<?php if ($result): [$ok, $detail] = $result; ?>
  <?php if ($ok): ?><p class="ok">Test email sent to <?= $h($to) ?>. Check the inbox (and the spam folder).</p>
  <?php elseif (!mailEnabled()): ?><p class="off">Email is switched off, so nothing was sent. The preview is in data/mail-outbox.log.</p>
  <?php else: ?><p class="bad">The test email FAILED.<?= $detail ? ' ' . $h($detail) : '' ?></p><?php endif; ?>
<?php endif; ?>
<form method="post"><button type="submit">Send a test email to me</button></form>
<p style="font-size:.8rem;color:#777;">Back to <a href="institutions.php?key=<?= urlencode(ADMIN_KEY) ?>">College &amp; University entries</a></p>
</body></html>
