/**
 * assets/js/site-grids.js
 * Shared renderer for site grids — tourism, conservation, flora & fauna.
 * Uses the same photo-card style as visit-sites.js.
 */

(function () {
    function photoCard(entry) {
        // Use name or title (whichever exists)
        const name = entry.name || entry.title || 'Unnamed site';
        const desc = entry.description || entry.desc || '';
        const details = entry.details || entry.extra || '';
        
        // Generate image HTML from photos array (or placeholder)
        const images = (entry.photos || []).map(function(src) {
            return `<img src="${src}" alt="${name}" loading="lazy" onerror="this.parentElement.outerHTML='<div class=&quot;ph&quot;><span>Photo pending</span></div>'">`;
        }).join('');

        // Build details line (if present)
        const detailsHtml = details 
            ? `<p style="font-size:0.85rem;opacity:0.7;margin-top:0.25rem;"><em>${details}</em></p>` 
            : '';

        // Build description (if present and not the same as details)
        const descHtml = desc && desc !== details 
            ? `<p>${desc}</p>` 
            : '';

        return `
            <figure>
                <div class="ph">${images || '<span>Photo pending</span>'}</div>
                <figcaption>${name}</figcaption>
                ${descHtml}
                ${detailsHtml}
            </figure>`;
    }

    // Grid configurations
    const grids = [
        { id: 'tourism-grid', data: window.TOURISM_SITES || [] },
        { id: 'conservation-grid', data: window.CONSERVATION_SITES || [] },
        { id: 'flora-fauna-grid', data: window.FLORA_FAUNA_SITES || [] }
    ];

    const emptyMessages = {
        'tourism-grid': 'Tourism sites will appear here as the region develops.',
        'conservation-grid': 'Conservation areas will appear here as more data is collected.',
        'flora-fauna-grid': 'Species and plants will appear here as research continues.'
    };

    grids.forEach(function(grid) {
        const el = document.getElementById(grid.id);
        if (!el) return;

        if (grid.data.length > 0) {
            el.innerHTML = grid.data.map(photoCard).join('');
        } else {
            el.innerHTML = `<p style="color:rgba(20,20,20,0.5);font-family:'Barlow',sans-serif;text-align:center;grid-column:1/-1;padding:1rem 0;">${emptyMessages[grid.id] || 'Information coming soon.'}</p>`;
        }
    });
})();
