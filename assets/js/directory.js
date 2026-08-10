/**
 * assets/js/directory.js
 * Include on index.html only. Fetches the public, approved list of
 * teams/sponsors/supporters/partners/media and renders a tile per
 * entry into the existing tile-grid containers — the "+Add X" tile
 * stays, real entries get inserted before it.
 *
 * Requires each target container to have an id, added to index.html:
 *   #teams-tile-grid, #sponsors-tile-grid, #supporters-tile-grid,
 *   #partners-tile-grid, #media-tile-grid
 * — see README-DIRECTORY.md for the exact markup change.
 */

(function () {
    const API_BASE = '/api/index.php';

    function tileFor(entry, linkHref) {
        // This script only runs on index.html at the repo root, so
        // logo_path (e.g. "uploads/logos/x.jpg") needs no "../" prefix.
        const logo = entry.logo_path
            ? `<img src="${entry.logo_path}" alt="${entry.display_name}" style="max-width:80%;max-height:70%;object-fit:contain;">`
            : `<span>${entry.display_name}</span>`;
        return linkHref
            ? `<a class="tile" href="${linkHref}" style="flex-direction:column;gap:0.4rem;">${logo}</a>`
            : `<div class="tile" style="flex-direction:column;gap:0.4rem;">${logo}</div>`;
    }

    async function loadDirectory() {
        let data;
        try {
            const res = await fetch(`${API_BASE}?endpoint=directory`);
            data = await res.json();
        } catch (e) {
            return; // Backend not deployed yet — homepage just keeps its static placeholder tiles.
        }
        if (!data.success) return;

        const { teams, sponsors, supporters, partners, media } = data.listings;

        renderInto('teams-tile-grid', teams, t => tileFor(t, `Teams/team.html?slug=${encodeURIComponent(t.slug)}`));
        renderInto('sponsors-tile-grid', sponsors, s => tileFor(s));
        renderInto('supporters-tile-grid', supporters, s => tileFor(s));
        renderInto('partners-tile-grid', partners, p => tileFor(p));
        renderInto('media-tile-grid', media, m => tileFor(m));
    }

    function renderInto(containerId, entries, tileBuilder) {
        const container = document.getElementById(containerId);
        if (!container || !entries || !entries.length) return;

        const addTile = container.querySelector('.add-tile');
        entries.forEach(entry => {
            const wrapper = document.createElement('div');
            wrapper.innerHTML = tileBuilder(entry);
            const node = wrapper.firstElementChild;
            if (addTile) {
                container.insertBefore(node, addTile);
            } else {
                container.appendChild(node);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', loadDirectory);
})();
<script>
  // Replace the hardcoded "128" stats with the real live count from stats.php.
  (async function () {
    try {
      const res = await fetch('stats.php');
      const data = await res.json();
      if (typeof data.paid_riders === 'number') {
        const statEl = document.getElementById('riders-stat');
        const counterEl = document.getElementById('ticket-counter');
        if (statEl) statEl.textContent = data.paid_riders;
        if (counterEl) counterEl.textContent = data.paid_riders;
      }
    } catch (e) {
      // Backend not deployed yet — the page just keeps showing the
      // placeholder number until stats.php is live.
    }
  })();
</script>
