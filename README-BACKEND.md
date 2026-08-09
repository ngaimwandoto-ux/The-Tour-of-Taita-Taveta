# Accounts + Persistence — Deploy Guide

Adds a real database to what you already have. No Composer, no MySQL,
no external services — plain PHP + SQLite, same spirit as your existing
`mpesa-config.php` setup.

## What's in this folder

```
config.example.php   Template — copy to config.php and fill in (like mpesa-config.example.php)
schema.sql            Database structure — applied automatically on first run
includes/
  database.php         PDO/SQLite connection singleton
  auth.php             Register, login, logout, rate limiting, tokens
api/
  index.php            /api/index.php?endpoint=register|login|logout|profile
mpesa-stk.php          UPDATED — now saves a "pending" registration row
visit-payment.php      UPDATED — now saves a "pending" visit row
callback.php           UPDATED — now marks rows "paid"/"failed" by CheckoutRequestID
stats.php              Public read-only endpoint — live rider counts
data/                  SQLite database file lives here (auto-created)
```

## 1. Host requirements

- PHP 7.4+ with the `pdo_sqlite` extension. This is enabled by default on
  almost every host (cPanel included) — nothing extra to install.
- No MySQL, no Composer, no `vendor/` folder needed.
- HTTPS still mandatory for the M-Pesa callback URL, same as before.

## 2. Upload

Upload this whole folder's contents into your existing site root,
alongside `index.html`, `Regions/`, `mpesa-common.php`, etc. — keep the
folder structure intact (`includes/`, `api/`, `data/` all next to
`index.html`).

## 3. Configure

```
cp config.example.php config.php
```

Then edit `config.php`:
- Generate a real secret: `php -r "echo bin2hex(random_bytes(32));"`
  Paste it into `JWT_SECRET`.
- Set `CORS_ALLOWED_ORIGINS` to your real deployed frontend URL(s).
- Set `APP_ENV` to `'production'` once you're serving over HTTPS (this
  makes auth cookies require HTTPS — leave as `'local'` while testing
  over plain HTTP).

**Never commit `config.php` to git** — add it to `.gitignore` next to
`mpesa-config.php` (see below).

## 4. Protect the database file

The SQLite file at `data/tttt.sqlite` should never be directly
web-accessible. If your host serves everything under this folder
publicly, add a `data/.htaccess` containing:

```
Deny from all
```

(Apache) — or move `DB_PATH` in `config.php` to a folder one level
above your web root, if your host allows that.

## 5. First run

The database and its tables are created automatically the first time
any script touches `includes/database.php` — no manual SQL import step.
If you want to trigger it explicitly, just visit `/stats.php` once
after deploying.

## 6. Test before going live

- Submit a test registration → check `data/tttt.sqlite` (or query via
  `stats.php`) for a new `pending` row.
- Simulate/wait for the M-Pesa callback → confirm the row flips to
  `paid` and gets an `mpesa_receipt` value.
- Hit `/api/index.php?endpoint=register` with a test account → confirm
  a row appears in `users`.
- Hit `/stats.php` → confirm it returns real counts, not an error.

## 7. Wire the homepage to real numbers

Replace the hardcoded `128` in `index.html`'s stat bar and the
`ticket-counter` span with a fetch to `/stats.php` — see the comment
at the top of `stats.php` for the exact snippet.

## Still TODO after this

- Email sending (verification, payment confirmation) — deliberately
  left out. `mail()` is unreliable on shared hosting; decide on a
  provider (or your host's SMTP relay) once you actually need it.
- `.gitignore` — add `config.php` and `data/*.sqlite` (see below).
- Team/rider dashboard pages that read from `api/index.php?endpoint=profile`
  — the API supports it, but no frontend pages call it yet.
