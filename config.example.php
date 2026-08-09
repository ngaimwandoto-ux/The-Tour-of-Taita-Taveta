<?php
/**
 * config.example.php
 *
 * Site-wide configuration for accounts, auth, and the SQLite database —
 * EXAMPLE FILE. Copy this to "config.php" and fill in real values.
 *
 * 1. Copy this file to "config.php" (do NOT commit that copy to git).
 * 2. Add "config.php" to your .gitignore, right alongside mpesa-config.php.
 * 3. Generate real secrets — do not ship the placeholders below.
 *    On the command line: php -r "echo bin2hex(random_bytes(32));"
 *
 * Never paste real values into chat, screenshots, or public repos —
 * treat them like a password.
 */

// 'local' relaxes cookie "secure" flag so auth works over plain HTTP
// during local testing. Switch to 'production' once you're live on HTTPS.
define('APP_ENV', 'local');

// Where the SQLite database file lives. Keep it OUTSIDE any web-served
// "public"/"htdocs" root if your host has one, or protect the /data
// folder with a .htaccess deny-all — see README-BACKEND.md.
define('DB_PATH', __DIR__ . '/data/tttt.sqlite');

// Generate with: php -r "echo bin2hex(random_bytes(32));"
define('JWT_SECRET', 'REPLACE_WITH_A_REAL_RANDOM_64_CHAR_HEX_STRING');

// A second, separate random string — protects admin/approve.php.
// Generate the same way, but use a DIFFERENT value than JWT_SECRET.
define('ADMIN_KEY', 'REPLACE_WITH_A_DIFFERENT_RANDOM_STRING');

// Your real deployed frontend origin(s), comma-separated, no trailing slash.
// e.g. 'https://tour-of-taita-taveta.vercel.app,https://touroftaitataveta.co.ke'
define('CORS_ALLOWED_ORIGINS', 'https://tour-of-taita-taveta.vercel.app');

// Login rate limiting
define('RATE_LIMIT_LOGIN_MAX', 5);       // attempts
define('RATE_LIMIT_LOGIN_WINDOW', 900);  // seconds (15 minutes)

// Auth cookie lifetime
define('TOKEN_EXPIRY_SECONDS', 60 * 60 * 24); // 24 hours
