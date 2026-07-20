# Tour of Taita Taveta — Deploy Guide

## What's in this folder

```
index.html                 Main site (About, Legs calendar, Register, Gallery)
style.css                  Shared stylesheet used by index.html AND every region page
logo-taita-taveta.jpg      Header logo
regions/
  sagalla.html
  vuria-wusi.html
  bura.html
  kasighau.html
  mbololo-ronge.html
  mbale.html
mpesa-config.example.php   Template — copy to mpesa-config.php and fill in
mpesa-common.php           Shared Daraja helper functions (token + STK push)
mpesa-stk.php              Backend for race registration payments
visit-payment.php          Backend for region-page visitation-fee payments
callback.php               Where Safaricom posts payment results
.gitignore                 Keeps mpesa-config.php and the callback log out of git
```

## 1. Host requirements

- PHP 7.4+ with cURL enabled (any standard shared host, e.g. cPanel, or a small VPS)
- **HTTPS is mandatory** — Safaricom will not call an `http://` callback URL, and
  STK push requests should also originate from HTTPS in production
- A public domain or subdomain (e.g. `tour.taitatavetacounty.example`)

## 2. Upload

Upload this entire folder to your web root, **keeping the folder structure intact**
— `regions/` must stay a subfolder next to `index.html`, since every region page
links back with relative paths (`../style.css`, `../index.html`, `../visit-payment.php`).

## 3. Configure M-Pesa (the part I can't do for you)

I don't have — and shouldn't have — your Safaricom credentials. Here's exactly
what to do:

1. Go to https://developer.safaricom.co.ke and log in (or create an account).
2. Create an app to get a **Consumer Key** and **Consumer Secret**.
3. For production, apply for a **Paybill/Till shortcode** and **Lipa Na M-Pesa
   Online Passkey** (sandbox testing can use the shared shortcode `174379`,
   already in the example file).
4. On the server, run:
   ```
   cp mpesa-config.example.php mpesa-config.php
   ```
5. Open `mpesa-config.php` and fill in the four `REPLACE_WITH_...` values, plus
   set `MPESA_CALLBACK_URL` to your real domain, e.g.
   `https://tour.taitatavetacounty.example/callback.php`.
6. Leave `MPESA_BASE_URL` on the sandbox URL until you've tested end-to-end,
   then switch it to `https://api.safaricom.co.ke` for real payments.
7. Never commit `mpesa-config.php` to git, paste it into chat, or screenshot it
   — treat those four values like a password. The included `.gitignore`
   already excludes it.

## 4. Test before going live

- Submit a test registration on `index.html` with the sandbox shortcode —
  confirm the STK prompt behavior end-to-end (Safaricom's sandbox uses test
  phone numbers, see their docs).
- Submit a test visitation-fee payment from one region page.
- Check that `callback.php` is reachable publicly (visit the URL directly —
  you should get a JSON response, not a 404).
- Only then switch `MPESA_BASE_URL` to production and re-test with a small
  real amount.

## 5. Content still to fill in

- Swap every `image/...jpg` placeholder block for real photos
- Replace region page tourism/culture/conservation copy — only Sagalla's is
  backed by verified research; the other five need local fact-checking
- Confirm exact weekend dates for Legs 5 and 6 once Kenya's 2027 holiday
  gazette is out
- Fill in real leaderboard results after each leg runs
