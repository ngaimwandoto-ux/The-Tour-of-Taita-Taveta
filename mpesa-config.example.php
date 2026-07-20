<?php
/**
 * M-Pesa Daraja configuration — EXAMPLE FILE.
 *
 * 1. Copy this file to "mpesa-config.php" (do NOT commit that copy to git).
 * 2. Fill in your real values from https://developer.safaricom.co.ke
 * 3. Add "mpesa-config.php" to your .gitignore.
 *
 * Never paste real keys into chat, screenshots, or public repos —
 * treat them like a password.
 */

// Sandbox base URL while testing. Switch to the production URL when you go live.
define('MPESA_BASE_URL', 'https://sandbox.safaricom.co.ke');

define('MPESA_CONSUMER_KEY',    'REPLACE_WITH_YOUR_CONSUMER_KEY');
define('MPESA_CONSUMER_SECRET', 'REPLACE_WITH_YOUR_CONSUMER_SECRET');

// 174379 is Safaricom's shared sandbox shortcode/passkey — fine for testing,
// but you'll get your own shortcode + passkey for production.
define('MPESA_SHORTCODE', '174379');
define('MPESA_PASSKEY',   'REPLACE_WITH_YOUR_PASSKEY');

// Must be a publicly reachable HTTPS URL once deployed.
define('MPESA_CALLBACK_URL', 'https://yourdomain.co.ke/callback.php');

define('MPESA_ACCOUNT_REFERENCE', 'TourTaveta');
