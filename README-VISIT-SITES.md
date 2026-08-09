# Visit-Sites Card Grid — Rollout Guide

## What changed

Each leg's "Visit & Support" section used to be a short static list —
fine for 1–2 entries, unworkable for 12–20. It's now a card grid driven
by a small JS array, so adding a place is copy-one-object, not writing
new HTML.

## Files here

```
assets/js/visit-sites.js            Shared renderer + payment logic — one copy, used by all 6 leg pages
style-additions-visit-cards.css     Append this to style.css (once — it's shared, not per-page)
Regions/sagalla.html                Full worked example — Sagalla's real 2 entries + a copy-paste template for more
```

## Steps

1. **Append `style-additions-visit-cards.css` to `style.css`** — once, at the end. Applies to every leg page automatically.
2. **Add `assets/js/visit-sites.js`** to the repo (new file, new folder if you don't already have `assets/js/` from the accounts build — if you do, it just goes alongside `account.js`/`messaging.js`).
3. **Replace `Regions/sagalla.html` entirely** with the version here — it's a full working example.
4. **For the other five leg pages** (`vuria-wusi.html`, `bura.html`, `kasighau.html`, `mbololo-ronge.html`, `mbale.html`), the pattern is the same three changes to each:
   - Replace the old `<section id="visit">...</section>` block (with the `.visit-wrap`/`.visit-list`/static `.visit-item` divs) with the new one from `sagalla.html` (the `<div id="visit-grid" class="site-grid"></div>` + form).
   - Replace that page's `<script>window.VISIT_SITES = [...]</script>` with its own real entries — carry over whatever that page already had (e.g. Vuria/Wusi's one entry, Kasighau's two) as your starting objects, then add more using the copy-paste template comment.
   - Delete the old inline `visitItems`/`vform` JS block from the bottom `<script>` tag (it's now in `visit-sites.js`) and add `<script src="../assets/js/visit-sites.js"></script>` right after the `VISIT_SITES` array — same as in `sagalla.html`.
   - Leave the ad-calculator `<script>` block at the bottom untouched — that part didn't change.

## Onboarding a new place later

Once this is live, adding place #13 to Sagalla or place #4 to Wesu is
just: open that page, find `VISIT_SITES`, paste in one new object with
a unique `id`, real `name`/`fee`/`description`, and 3 photo paths.
Nothing else needs to change — no new HTML, no touching the payment
logic, no touching `visit-payment.php`.

If real photos aren't ready yet, use any placeholder path (or even a
guessed future filename) — a missing/broken photo automatically shows
a "Photo pending" box instead of a broken image icon, so the place can
go live with just a name, fee, and description.
