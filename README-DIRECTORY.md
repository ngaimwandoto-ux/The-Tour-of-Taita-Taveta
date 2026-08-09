# Homepage Directory (Teams/Sponsors/Supporters/Partners) — Rollout Guide

Turns the static placeholder tiles into a live list pulled from real
registered accounts. New listings are hidden until approved — see
"Approving listings" below.

## New/changed files

```
schema.sql              REPLACES existing — adds logo_path + is_public to users and teams
includes/auth.php       REPLACES existing — getUserById now also returns logo_path/is_public
includes/directory.php  NEW — logo upload handling + public listing queries
api/index.php           REPLACES existing — adds `directory` (public) and `upload-logo` (auth) endpoints
assets/js/directory.js  NEW — homepage-only script that renders live tiles
pages/dashboard.html    REPLACES existing — adds a logo upload card for team/sponsor/supporter/partner accounts
admin/approve.php       NEW — minimal key-protected approval screen (not a full admin panel)
config.example.php      REPLACES existing — adds ADMIN_KEY
uploads/logos/          NEW folder — where uploaded logos are stored
```

## 1. Config

In `config.php` (your real one, not the example), add a second random
string:
```
define('ADMIN_KEY', 'REPLACE_WITH_A_DIFFERENT_RANDOM_STRING');
```
Generate it the same way as `JWT_SECRET` — just don't reuse the same value.

## 2. .gitignore

Add this line so uploaded logos never get committed:
```
uploads/logos/*
!uploads/logos/.gitkeep
```
Create an empty `uploads/logos/.gitkeep` file so the folder itself
still exists in the repo (git doesn't track empty folders).

## 3. index.html changes

Four small edits — each `tile-grid` that should show real entries needs
an `id`, and its static placeholder tiles come out (the real ones now
render there via JS). Also update each section's `+ Add` link and
header "Register/Become a..." link to point at the real signup page.

**Teams section** — find:
```html
<div class="tile-grid">
  <a class="tile event-tile" href="Teams/voi-riders.html"><span class="leg-name">Voi Riders</span><span class="leg-date">Example team page</span></a>
  <a class="tile add-tile" href="#">+ Register your team</a>
</div>
```
Replace with:
```html
<div class="tile-grid" id="teams-tile-grid">
  <a class="tile event-tile" href="Teams/voi-riders.html"><span class="leg-name">Voi Riders</span><span class="leg-date">Example team page</span></a>
  <a class="tile add-tile" href="pages/register.html">+ Register your team</a>
</div>
```
(Voi Riders stays as your example page — it's a static HTML page, not
a real registered account, so the directory script won't touch it.)

**Sponsors section** — find:
```html
<div class="tile-grid">
  <div class="tile"><span>your-logo-1.png</span></div>
  <div class="tile"><span>your-logo-2.png</span></div>
  <div class="tile"><span>your-logo-3.png</span></div>
  <a class="tile add-tile" href="#">+ Add sponsor</a>
</div>
```
Replace with:
```html
<div class="tile-grid" id="sponsors-tile-grid">
  <a class="tile add-tile" href="pages/register.html">+ Add sponsor</a>
</div>
```

**Supporters section** — find:
```html
<div class="tile-grid">
  <div class="tile"><span>your-logo-1.png</span></div>
  <div class="tile"><span>your-logo-2.png</span></div>
  <a class="tile add-tile" href="#">+ Add supporter</a>
</div>
```
Replace with:
```html
<div class="tile-grid" id="supporters-tile-grid">
  <a class="tile add-tile" href="pages/register.html">+ Add supporter</a>
</div>
```

**Partners section** — find:
```html
<div class="tile-grid">
  <div class="tile"><span>your-logo-1.png</span></div>
  <a class="tile add-tile" href="#">+ Add partner</a>
</div>
```
Replace with:
```html
<div class="tile-grid" id="partners-tile-grid">
  <a class="tile add-tile" href="pages/register.html">+ Add partner</a>
</div>
```

**Add the script tag** — right before `</body>`, alongside any other
scripts already there:
```html
<script src="assets/js/directory.js"></script>
```

Also add a "Log In" nav link here too if you haven't already (same as
the other pages):
```html
<a href="pages/login.html">Log In</a>
```

## 4. How someone gets a tile on the homepage

1. They register at `pages/register.html`, choosing Team/Sponsor/Supporter/Partner.
2. They log in, go to `pages/dashboard.html`, and upload a logo.
3. Their listing is now sitting in the database with `is_public = 0` — not visible yet.
4. **You** visit `https://yourdomain/admin/approve.php?key=YOUR_ADMIN_KEY`, see it in the pending list, click Approve.
5. It now appears on the homepage automatically — no further action needed, `directory.js` picks it up on the next page load.

## Why the approval step exists

Without it, anyone could register as "sponsor," upload any logo and
name, and appear on your public homepage instantly — no review at
all. The approval screen is deliberately minimal (no login system of
its own, just a key in the URL) so this stays usable without building
a full admin panel, while keeping you as the actual gatekeeper for
what shows up publicly.

## What's NOT included

- No way to un-upload a logo from the dashboard (re-uploading replaces it; the old file is deleted automatically).
- `admin/approve.php`'s protection is a shared secret in a URL, not a real login — fine for one person (you) managing this alone, not meant to scale to multiple admins with different permissions.
- Team logos show only if that team was created via `pages/register.html` (account_type "team") — the static `Teams/voi-riders.html` example page is untouched and isn't wired to this system.
