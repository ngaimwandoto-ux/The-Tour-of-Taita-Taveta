/**
 * assets/js/site-grids.js
 * Shared renderer for site grids — tourism, conservation, flora & fauna.
 * Used by all leg pages.
 * 
 * Each grid expects a window variable:
 *   - TOURISM_SITES
 *   - CONSERVATION_SITES
 *   - FLORA_FAUNA_SITES
 */

(function() {
    // Grid element IDs
    const GRID_IDS = {
        tourism: 'tourism-grid',
        conservation: 'conservation-grid',
        fauna: 'flora-fauna-grid'
    };

    // Data sources
    const DATA_SOURCES = {
        tourism: window.TOURISM_SITES || [],
        conservation: window.CONSERVATION_SITES || [],
        fauna: window.FLORA_FAUNA_SITES || []
    };

    // Card renderers
    function renderCard(item, type) {
        const title = item.name || item.title || 'Unnamed site';
        const desc = item.description || item.desc || '';
        const extra = item.details || item.extra || '';
        
        return `
            <div class="info-card">
                <h3>${title}</h3>
                <p>${desc}</p>
                ${extra ? `<p style="font-size:0.85rem;opacity:0.7;margin-top:0.25rem;"><em>${extra}</em></p>` : ''}
            </div>
        `;
    }

    function renderEmptyGrid(type) {
        const messages = {
            tourism: 'Tourism sites will appear here as the region develops.',
            conservation: 'Conservation areas will appear here as more data is collected.',
            fauna: 'Species and plants will appear here as research continues.'
        };
        return `<p style="color:rgba(20,20,20,0.5);font-family:'Barlow',sans-serif;text-align:center;grid-column:1/-1;padding:1rem 0;">${messages[type] || 'Information coming soon.'}</p>`;
    }

    // Render each grid
    Object.keys(GRID_IDS).forEach(function(type) {
        const grid = document.getElementById(GRID_IDS[type]);
        if (!grid) return;

        const entries = DATA_SOURCES[type];
        if (entries && entries.length > 0) {
            grid.innerHTML = entries.map(function(item) {
                return renderCard(item, type);
            }).join('');
        } else {
            grid.innerHTML = renderEmptyGrid(type);
        }
    });
})();
