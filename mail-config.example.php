<?php
/**
 * mail-config.example.php
 *
 * Email settings — EXAMPLE FILE.
 *
 * 1. Copy this file to "mail-config.php" (do NOT commit that copy).
 * 2. Add "mail-config.php" to .gitignore, next to config.php and mpesa-config.php.
 * 3. Fill in the values below once you have hosting and a mailbox.
 * 4. Open admin/mail-test.php?key=YOUR_ADMIN_KEY to send yourself a test email.
 *
 * While MAIL_ENABLED is false (or this file doesn't exist) NOTHING is sent:
 * every email is written to data/mail-outbox.log instead, so you can see
 * exactly what would have gone out. Nothing on the site breaks either way.
 */

define('MAIL_ENABLED', false);          // switch to true when the settings below are real

// 'smtp' = log in to a mailbox on your host (recommended: best delivery).
// 'mail' = PHP's built-in mail() (works on some hosts with no login, but more often lands in spam).
define('MAIL_TRANSPORT', 'smtp');

// Who the email appears to come from. Use an address on YOUR domain,
// e.g. entries@touroftaitataveta.co.ke — never a gmail.com address,
// or many inboxes will reject it.
define('MAIL_FROM_EMAIL', 'entries@your-domain.example');
define('MAIL_FROM_NAME',  'Tour of Taita Taveta');
define('MAIL_REPLY_TO',   'info@your-domain.example');

// Where YOUR alerts go (new team entries, paid bookings). Comma-separate for several.
define('MAIL_ADMIN_TO', 'you@example.com');

// SMTP login — your host's cPanel / Host Pinnacle "Email Accounts" page shows these.
define('SMTP_HOST',   'mail.your-domain.example');
define('SMTP_PORT',   587);             // 587 with 'tls', or 465 with 'ssl'
define('SMTP_SECURE', 'tls');           // 'tls' (STARTTLS), 'ssl', or 'none' (testing only)
define('SMTP_USER',   'entries@your-domain.example');
define('SMTP_PASS',   'REPLACE_WITH_THE_MAILBOX_PASSWORD');

// Public address of the site, no trailing slash. Used for links inside emails.
define('SITE_URL', 'https://your-domain.example');
