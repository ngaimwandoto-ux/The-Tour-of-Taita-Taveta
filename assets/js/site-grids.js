/**
 * assets/js/site-grids.js
 * Shared renderer for informational site grids: Conservation, Flora &
 * Fauna, Gastronomy, Traditional Medicine. Same photo-card style as
 * visit-sites.js, minus the payment form — these four are showcase
 * only, no M-Pesa involved.
 *
 * Define these inline on each leg page, before this script tag:
 *   window.CONSERVATION         -> #conservation-grid
 *   window.FLORA_FAUNA          -> #flora-fauna-grid
 *   window.GASTRONOMY           -> #gastronomy-grid
 *   window.TRADITIONAL_MEDICINE -> #traditional-medicine-grid
 */

(function () {
    function photoCard(entry) {
        const name = entry.name || entry.title || 'Unnamed site';
        const desc = entry.description || entry.desc || '';
        const details = entry.details || entry.extra || '';

        const images = (entry.photos || []).map(function (src) {
            return `<img src="${src}" alt="${name}" loading="lazy" onerror="this.parentElement.outerHTML='<div class=&quot;ph&quot;><span>Photo pending</span></div>'">`;
        }).join('');

        const detailsHtml = details
            ? `<p style="font-size:0.85rem;opacity:0.7;margin-top:0.25rem;"><em>${details}</em></p>`
            : '';

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

    const grids = [
        { id: 'conservation-grid', data: window.CONSERVATION || [], emptyMsg: 'Conservation areas will appear here as more data is collected.' },
        { id: 'flora-fauna-grid', data: window.FLORA_FAUNA || [], emptyMsg: 'Species and plants will appear here as research continues.' },
        { id: 'gastronomy-grid', data: window.GASTRONOMY || [], emptyMsg: 'Food and culinary experiences will appear here as they\'re developed.' },
        { id: 'traditional-medicine-grid', data: window.TRADITIONAL_MEDICINE || [], emptyMsg: 'Traditional medicine practices will appear here as research continues.' },
    ];

    grids.forEach(function (grid) {
        const el = document.getElementById(grid.id);
        if (!el) return;

        if (grid.data.length > 0) {
            el.innerHTML = grid.data.map(photoCard).join('');
        } else {
            el.innerHTML = `<p style="color:rgba(20,20,20,0.5);font-family:'Barlow',sans-serif;text-align:center;grid-column:1/-1;padding:1rem 0;">${grid.emptyMsg}</p>`;
        }
    });
})();

