<?php
/**
 * includes/mailer.php
 * Sends the site's emails. No Composer, no libraries.
 *
 *  - Settings live in mail-config.php (copy of mail-config.example.php).
 *  - While MAIL_ENABLED is false, or the config file is missing, nothing is
 *    sent: each email is written to data/mail-outbox.log as a preview.
 *  - Sending NEVER breaks a page. Every public function catches its own
 *    errors, logs them, and returns true/false.
 *
 * Emails: registration receipt (rider), team entry received / payment
 * confirmed (institution), and admin alerts (new team entry, paid booking).
 */
require_once __DIR__ . '/../config.php';

/* ------------------------------------------------------------------ */
/* Settings + logging                                                  */
/* ------------------------------------------------------------------ */

function mailSetting(string $name, $default = null) {
    static $loaded = null;
    if ($loaded === null) {
        $p = __DIR__ . '/../mail-config.php';
        $loaded = is_file($p);
        if ($loaded) require_once $p;
    }
    return ($loaded && defined($name)) ? constant($name) : $default;
}

function mailEnabled(): bool { return (bool) mailSetting('MAIL_ENABLED', false); }

function mailLog(string $line, ?string $body = null): void {
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $entry = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $line . "\n";
    if ($body !== null) $entry .= $body . "\n" . str_repeat('-', 60) . "\n";
    @file_put_contents($dir . '/mail-outbox.log', $entry, FILE_APPEND | LOCK_EX);
}

/** Send the HTTP reply now, THEN run slow work (email) so visitors never wait on it. */
function respondThenRun(string $body, ?callable $after): void {
    if (function_exists('ignore_user_abort')) ignore_user_abort(true);
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;
    while (ob_get_level() > 0) @ob_end_flush();
    @flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    if ($after) {
        try { $after(); } catch (Throwable $e) { error_log('mail after-response: ' . $e->getMessage()); }
    }
}

/* ------------------------------------------------------------------ */
/* Sending                                                             */
/* ------------------------------------------------------------------ */

function mailCrlf(string $s): string { return str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $s)); }

function mailClean(string $s): string { return trim(preg_replace('/[\r\n]+/', ' ', $s)); }

function mailEncodeHeader(string $s): string {
    $s = mailClean($s);
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/** Send one email. Returns true if it was handed to the mail server. */
function sendMail(string $to, string $subject, string $html, string $text): bool {
    try {
        $to = mailClean($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { mailLog("SKIPPED (bad address) to=\"$to\" subject=\"$subject\""); return false; }

        if (!mailEnabled()) {
            mailLog("PREVIEW (mail is off) to=$to subject=\"$subject\"", $text);
            return false;
        }

        $fromEmail = mailClean((string) mailSetting('MAIL_FROM_EMAIL', ''));
        $fromName  = mailClean((string) mailSetting('MAIL_FROM_NAME', 'Tour of Taita Taveta'));
        $replyTo   = mailClean((string) mailSetting('MAIL_REPLY_TO', ''));
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) { mailLog("FAILED to=$to — MAIL_FROM_EMAIL is not a valid address"); return false; }

        $boundary = 'b' . bin2hex(random_bytes(12));
        $domain = substr(strrchr($fromEmail, '@'), 1);
        $headers = [
            'From: ' . mailEncodeHeader($fromName) . ' <' . $fromEmail . '>',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: ' . $replyTo;

        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
              . quoted_printable_encode(mailCrlf($text)) . "\r\n"
              . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
              . quoted_printable_encode(mailCrlf($html)) . "\r\n"
              . "--$boundary--\r\n";

        $subj = mailEncodeHeader($subject);
        $transport = (string) mailSetting('MAIL_TRANSPORT', 'smtp');

        if ($transport === 'mail') {
            $ok = @mail($to, $subj, $body, implode("\r\n", $headers), '-f' . $fromEmail);
            mailLog(($ok ? 'SENT' : 'FAILED') . " (mail()) to=$to subject=\"$subject\"");
            return (bool) $ok;
        }

        $data = 'To: ' . $to . "\r\nSubject: " . $subj . "\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $err = smtpDeliver($fromEmail, $to, $data);
        mailLog(($err === null ? 'SENT' : 'FAILED') . " (smtp) to=$to subject=\"$subject\"" . ($err === null ? '' : " — $err"));
        return $err === null;
    } catch (Throwable $e) {
        error_log('sendMail: ' . $e->getMessage());
        mailLog('FAILED (exception) ' . $e->getMessage());
        return false;
    }
}

/** Send to every address in a comma-separated list. True if at least one went out. */
function sendMailToMany(string $list, string $subject, string $html, string $text): bool {
    $any = false;
    foreach (array_filter(array_map('trim', explode(',', $list))) as $addr) {
        if (sendMail($addr, $subject, $html, $text)) $any = true;
    }
    return $any;
}

/** Minimal SMTP client: STARTTLS / SSL, AUTH LOGIN. Returns null on success, else an error string. */
function smtpDeliver(string $from, string $to, string $data): ?string {
    $host   = (string) mailSetting('SMTP_HOST', '');
    $port   = (int) mailSetting('SMTP_PORT', 587);
    $secure = (string) mailSetting('SMTP_SECURE', 'tls');
    $user   = (string) mailSetting('SMTP_USER', '');
    $pass   = (string) mailSetting('SMTP_PASS', '');
    if ($host === '') return 'SMTP_HOST is empty';

    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $en, $es, 10);
    if (!$fp) return "cannot connect to $host:$port ($es)";
    stream_set_timeout($fp, 10);

    $read = function () use ($fp): array {
        $full = ''; $code = 0;
        while (($line = fgets($fp, 1024)) !== false) {
            $full .= $line;
            $code = (int) substr($line, 0, 3);
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return [$code, trim($full)];
    };
    $cmd = function (string $c, array $ok) use ($fp, $read): ?string {
        fwrite($fp, $c . "\r\n");
        [$code, $msg] = $read();
        return in_array($code, $ok, true) ? null : "unexpected reply to " . strtok($c, ' ') . ": $msg";
    };

    try {
        [$code, $msg] = $read();
        if ($code !== 220) return "server greeting: $msg";
        $helo = parse_url((string) mailSetting('SITE_URL', ''), PHP_URL_HOST) ?: 'localhost';
        if ($e = $cmd("EHLO $helo", [250])) return $e;
        if ($secure === 'tls') {
            if ($e = $cmd('STARTTLS', [220])) return $e;
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return 'TLS handshake failed';
            if ($e = $cmd("EHLO $helo", [250])) return $e;
        }
        if ($user !== '') {
            if ($e = $cmd('AUTH LOGIN', [334])) return $e;
            if ($e = $cmd(base64_encode($user), [334])) return $e;
            if ($e = $cmd(base64_encode($pass), [235])) return 'login refused (wrong SMTP_USER / SMTP_PASS?)';
        }
        if ($e = $cmd("MAIL FROM:<$from>", [250])) return $e;
        if ($e = $cmd("RCPT TO:<$to>", [250, 251])) return $e;
        if ($e = $cmd('DATA', [354])) return $e;
        $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $data));
        fwrite($fp, str_replace("\n", "\r\n", $data) . "\r\n.\r\n");
        [$code, $msg] = $read();
        if ($code !== 250) return "message rejected: $msg";
        @fwrite($fp, "QUIT\r\n");
        return null;
    } finally {
        @fclose($fp);
    }
}

/* ------------------------------------------------------------------ */
/* Layout + helpers                                                    */
/* ------------------------------------------------------------------ */

function mailE($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function mailKes($n): string { return 'KES ' . number_format((int) $n); }
function mailInstRef($id): string { return 'INST-' . str_pad((string) (int) $id, 4, '0', STR_PAD_LEFT); }

/** $rows = [[label, value], ...] → [html table, plain-text lines] */
function mailDetails(array $rows): array {
    $h = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:14px 0;">';
    $t = '';
    foreach ($rows as [$k, $v]) {
        if ($v === null || $v === '') continue;
        $h .= '<tr><td style="padding:7px 10px;border-bottom:1px solid #eee;color:#777;font-size:13px;width:38%;">' . mailE($k)
            . '</td><td style="padding:7px 10px;border-bottom:1px solid #eee;font-size:14px;font-weight:bold;">' . mailE($v) . '</td></tr>';
        $t .= $k . ': ' . $v . "\n";
    }
    return [$h . '</table>', $t];
}

function mailLayout(string $heading, string $innerHtml): string {
    return '<!DOCTYPE html><html><body style="margin:0;background:#f4f1ea;font-family:Arial,Helvetica,sans-serif;color:#171712;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:20px 10px;">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#fff;">'
        . '<tr><td style="background:#121008;padding:18px 24px;color:#F28C0F;font-size:13px;letter-spacing:3px;text-transform:uppercase;">Tour of Taita Taveta</td></tr>'
        . '<tr><td style="padding:24px;font-size:15px;line-height:1.55;"><h1 style="margin:0 0 12px;font-size:20px;">' . mailE($heading) . '</h1>'
        . $innerHtml
        . '</td></tr><tr><td style="padding:14px 24px;background:#f4f1ea;color:#888;font-size:12px;">Attitude meets altitude · This is an automatic message about your entry.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/* ------------------------------------------------------------------ */
/* The emails                                                          */
/* ------------------------------------------------------------------ */

/** Rider: payment confirmed. $r = a `registrations` row (with mpesa_receipt filled in). */
function mailRegistrationReceipt(array $r): bool {
    if (empty($r['email'])) return false;
    $ticket = ucfirst((string) ($r['ticket'] ?? ''));
    [$dh, $dt] = mailDetails([
        ['Name', $r['name'] ?? ''], ['Category', $ticket], ['Leg', $r['leg'] ?? ''],
        ['Amount paid', mailKes($r['amount'] ?? 0)], ['M-Pesa receipt', $r['mpesa_receipt'] ?? ''],
    ]);
    $name = (string) ($r['name'] ?? 'rider');
    $html = mailLayout("You're in, " . strtok($name, ' ') . '!',
        '<p>We have received your payment and your entry is confirmed.</p>' . $dh
        . '<p>Keep this email and your M-Pesa message as proof of payment. We will be in touch with race-day details closer to the date.</p>');
    $text = "You're in, " . strtok($name, ' ') . "!\n\nWe have received your payment and your entry is confirmed.\n\n$dt\nKeep this email and your M-Pesa message as proof of payment. We will be in touch with race-day details closer to the date.\n\nTour of Taita Taveta — Attitude meets altitude";
    return sendMail($r['email'], 'Entry confirmed — Tour of Taita Taveta', $html, $text);
}

/** Institution: entry received, invoice to follow. $r = institution_entries row. */
function mailInstitutionReceived(array $r): bool {
    $ref = mailInstRef($r['id']);
    [$dh, $dt] = mailDetails([
        ['Reference', $ref], ['Institution', $r['institution']], ['Team', $r['team_name']],
        ['Riders', (string) $r['riders']], ['Leg', $r['leg']],
    ]);
    $assumed = !empty($r['leg_assumed']);
    $legNote = $assumed ? 'You did not choose a leg, so we have entered the team for the next upcoming leg. Reply to this email if you want a different one.' : '';
    $html = mailLayout('We have your team entry',
        '<p>Hello ' . mailE($r['contact_name']) . ',</p><p>Thank you — we have received the entry for <strong>' . mailE($r['institution']) . '</strong>.</p>' . $dh
        . ($legNote ? '<p>' . mailE($legNote) . '</p>' : '')
        . '<p><strong>Next step:</strong> we will send an invoice with the payment details. The team is confirmed once the payment reaches us. Quote the reference <strong>' . mailE($ref) . '</strong> when you pay.</p>');
    $text = "Hello {$r['contact_name']},\n\nThank you — we have received the entry for {$r['institution']}.\n\n$dt" . ($legNote ? "\n$legNote\n" : '')
        . "\nNext step: we will send an invoice with the payment details. The team is confirmed once the payment reaches us. Quote the reference $ref when you pay.\n\nTour of Taita Taveta";
    return sendMail($r['email'], "Team entry received ($ref) — Tour of Taita Taveta", $html, $text);
}

/** Institution: payment confirmed by you in admin. */
function mailInstitutionPaid(array $r): bool {
    $ref = mailInstRef($r['id']);
    [$dh, $dt] = mailDetails([
        ['Reference', $ref], ['Institution', $r['institution']], ['Team', $r['team_name']], ['Leg', $r['leg']],
        ['Amount received', mailKes($r['amount'] ?? 0)], ['Bank reference', $r['payment_ref'] ?? ''],
    ]);
    $html = mailLayout('Payment received — your team is confirmed',
        '<p>Hello ' . mailE($r['contact_name']) . ',</p><p>We have received the payment for <strong>' . mailE($r['institution']) . '</strong>. Your team is confirmed.</p>' . $dh
        . '<p>Keep this email as your receipt. We will be in touch with race details closer to the date.</p>');
    $text = "Hello {$r['contact_name']},\n\nWe have received the payment for {$r['institution']}. Your team is confirmed.\n\n$dt\nKeep this email as your receipt. We will be in touch with race details closer to the date.\n\nTour of Taita Taveta";
    return sendMail($r['email'], "Payment received — team confirmed ($ref)", $html, $text);
}

/** You: a new team entry arrived. */
function mailAdminInstitutionEntry(array $r): bool {
    $to = (string) mailSetting('MAIL_ADMIN_TO', '');
    if ($to === '') return false;
    $ref = mailInstRef($r['id']);
    [$dh, $dt] = mailDetails([
        ['Reference', $ref], ['Institution', $r['institution']], ['Team', $r['team_name']], ['Riders', (string) $r['riders']],
        ['Leg', $r['leg'] . (!empty($r['leg_assumed']) ? ' (assumed — confirm with them)' : '')],
        ['Contact', $r['contact_name']], ['Email', $r['email']], ['Phone', $r['phone']],
    ]);
    $link = mailSetting('SITE_URL', '') ? rtrim((string) mailSetting('SITE_URL'), '/') . '/admin/institutions.php' : '';
    $html = mailLayout('New College & University entry',
        '<p>A team has entered and is waiting for an invoice.</p>' . $dh
        . ($link ? '<p>Open the admin page (add your key): <br>' . mailE($link) . '</p>' : ''));
    $text = "A team has entered and is waiting for an invoice.\n\n$dt" . ($link ? "\nAdmin page (add ?key=YOUR_KEY): $link\n" : '');
    return sendMailToMany($to, "New team entry: {$r['institution']} ($ref)", $html, $text);
}

/** You: a booking was paid — host payout is now owed. $b = bookings row. */
function mailAdminBookingPaid(array $b): bool {
    $to = (string) mailSetting('MAIL_ADMIN_TO', '');
    if ($to === '') return false;
    [$dh, $dt] = mailDetails([
        ['Guest', $b['guest_name'] ?? ''], ['Phone', $b['guest_phone'] ?? ''],
        ['Check-in', $b['check_in'] ?? ''], ['Check-out', $b['check_out'] ?? ''],
        ['Total paid', mailKes($b['total_amount'] ?? 0)], ['Host payout owed', mailKes($b['host_payout_amount'] ?? 0)],
        ['M-Pesa receipt', $b['mpesa_receipt'] ?? ''],
    ]);
    $html = mailLayout('Booking paid — host payout owed', '<p>A guest has paid for accommodation. Send the host their share and mark it paid out in the bookings admin page.</p>' . $dh);
    $text = "A guest has paid for accommodation. Send the host their share and mark it paid out in the bookings admin page.\n\n$dt";
    return sendMailToMany($to, 'Booking paid — host payout owed', $html, $text);
}
