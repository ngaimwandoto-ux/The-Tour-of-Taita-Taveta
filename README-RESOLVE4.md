# Resolving 4 Open Items — Rollout Guide

Covers: donation chips for leg-level Support, Media + Advertiser account
types, riders joining a team at registration, and dynamic team pages.

## Files — new or replaced

```
schema.sql                   REPLACES existing — media/advertiser types + their profile tables, donations table
includes/auth.php            REPLACES existing — media/advertiser registration, rider team_id handling
includes/directory.php       REPLACES existing — media added to public listing; listTeams(), getTeamByCaptain(), getTeamBySlugWithRoster()
api/index.php                REPLACES existing — adds list-teams, my-team, team endpoints
callback.php                 REPLACES existing — now also reconciles the donations table
donate.php                   NEW — root level, sibling to mpesa-stk.php/visit-payment.php
assets/js/donate.js          NEW — donation chip widget
assets/js/directory.js       REPLACES existing — adds media-tile-grid, team links point at Teams/team.html?slug=
style-additions-donate.css   NEW — append to style.css
pages/register.html          REPLACES existing — Media/Advertiser options, team picker for riders
pages/dashboard.html         REPLACES existing — media added to logo section, "view your team page" link for captains
Teams/team.html              NEW — dynamic team page (roster, logo, captain)
```

## 1. Append `style-additions-donate.css` to `style.css`

Once, at the end.

## 2. Add the new files

`donate.php`, `assets/js/donate.js`, `Teams/team.html` — new files,
same as you've been doing throughout.

## 3. Replace the files marked "REPLACES existing"

Same process as always: open, edit, select all, paste, commit.

## 4. index.html — two more edits

**Media section** — find:
```html
<div class="tile-grid">
  <div class="tile"><span>your-brand-1.png</span></div>
  <div class="tile"><span>your-brand-2.png</span></div>
  <a class="tile add-tile" href="#">+ Register as media</a>
</div>
```
Replace with:
```html
<div class="tile-grid" id="media-tile-grid">
  <a class="tile add-tile" href="pages/register.html">+ Register as media</a>
</div>
```

That's the only change needed on `index.html` this round — the
donation widget is leg-page-only (see below), and `directory.js` was
already wired up in the previous round.

## 5. Each of the six leg pages — the Support section

I'm giving you a find/replace snippet rather than a full-file rewrite
this time, since you've since made your own manual edits to these
pages (hero logos, elevation profiles) that a full overwrite could
clobber.

Find (the leg name will differ per page — Bura, Kasighau, etc.):
```html
<div class="eco-group">
      <div class="eco-group-head"><h3>Support Sagalla</h3></div>
      <p class="eco-group-note">No conditions — back this leg because you believe in it. Same gemstone tiers as the Tour: Tsavorite, Ruby, Green Garnet, Spinel, Rhodolite.</p>
      <div class="tile-grid">
        <a class="tile add-tile" href="#">+ Support this leg</a>
      </div>
    </div>
```
Replace with:
```html
<div class="eco-group">
      <div class="eco-group-head"><h3>Support Sagalla</h3></div>
      <p class="eco-group-note">No conditions — back this leg because you believe in it. Pick an amount below — your tier is set automatically. Amounts are placeholders pending final tier thresholds.</p>
      <div id="donate-widget"></div>
    </div>
```

Then, in that same page's bottom `<script>` area (anywhere after the
`VISIT_SITES` script, before `</body>`), add:
```html
<script>window.DONATE_SCOPE = document.title;</script>
<script src="../assets/js/donate.js"></script>
```

Repeat for all six leg pages, swapping "Sagalla" for that page's own
name in the `<h3>` (leave the `<h3>` itself untouched — only the
`<p class="eco-group-note">` and the tile-grid change).

## 6. Config — nothing new required

`donate.php` reuses the same `mpesa-config.php` as everything else —
no new credentials, no new setup.

## How each piece works now

**Donations**: guest checkout, same as race registration/visitation
fees. Tier (Tsavorite through Rhodolite) is decided server-side from
the amount — the client-side labels are cosmetic only. A donation
does NOT automatically create or update a Supporter account or
homepage logo — that's still a separate, deliberate step (register →
upload logo → get approved), same distinction as race registration
vs. rider accounts.

**Media / Advertiser accounts**: Media gets a homepage logo listing,
same review flow as Sponsors/Supporters/Partners. Advertiser does
NOT — on the live site, advertisers buy inventory through the ad
calculator/enquiry flow, not a logo wall, so there's no public listing
for that type. They can still register, log in, and message people.

**Rider + team at registration**: the team dropdown on
`pages/register.html` is populated from every team that exists
(not just approved/public ones — a team pending homepage review is
still a real, joinable team). Picking "No team" is the default and
always available.

**Dynamic team pages**: `Teams/team.html?slug=voi-riders` (for
example) now fetches real data — name, logo, captain, and every rider
whose account has `team_id` set to that team. `Teams/voi-riders.html`
(the original static example) is untouched and still exists
separately — the homepage's team tiles now link to the new dynamic
page instead.

## What's still open after this

- Leaderboard/results — team pages still show "No results yet," since
  timing integration hasn't been built.
- Team-level fundraising (a team's own Support/Sponsor/Partner/Advertise
  tiles) — still static placeholders on the dynamic team page, same as
  before. This needs its own payment-routing decision (a team's own
  paybill vs. pooling into the Tour's) — flagged earlier as part of the
  multi-paybill question you're deferring to the hosting stage.
- No way to leave/change a team after registering — `team_id` is set
  once, at registration, with no edit UI yet.
