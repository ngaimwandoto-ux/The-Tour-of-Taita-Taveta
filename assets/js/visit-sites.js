/**
 * assets/js/visit-sites.js
 *
 * Renders a card grid from window.VISIT_SITES (defined inline on each
 * region page, right before this script tag) and wires up the payment
 * form — same STK push flow as before, just now works for any number
 * of sites instead of 1-2 hardcoded ones.
 *
 * To onboard a new place on any leg page: open that page, find its
 * VISIT_SITES array, copy one object, fill it in. Nothing else to touch.
 *
 * Photos: give each site 3 paths. Any that don't exist yet (or fail to
 * load) automatically show a "Photo pending" placeholder instead of a
 * broken image — so you can list a place before its photos are ready.
 */

(function () {
    const sites = window.VISIT_SITES || [];
    const grid = document.getElementById('visit-grid');
    if (!grid) return;

    let selected = sites[0] || null;

    function photoCell(src, isMain) {
        if (!src) {
            return `<div class="${isMain ? 'main-photo ' : ''}ph-fallback"><span>Photo pending</span></div>`;
        }
        // onerror swaps a broken/missing image for the same placeholder look.
        return `<div class="${isMain ? 'main-photo' : ''}">` +
            `<img src="${src}" alt="" loading="lazy" ` +
            `onerror="this.parentElement.outerHTML='<div class=&quot;${isMain ? 'main-photo ' : ''}ph-fallback&quot;><span>Photo pending</span></div>'">` +
            `</div>`;
    }

    function render() {
        grid.innerHTML = sites.map(site => {
            const photos = (site.photos || []).slice(0, 3);
            while (photos.length < 3) photos.push(null);

            const isSelected = selected && selected.id === site.id;

            return `
                <div class="site-card ${isSelected ? 'selected' : ''}" data-site-id="${site.id}">
                    <div class="site-card-photos">
                        ${photoCell(photos[0], true)}
                        ${photoCell(photos[1], false)}
                        ${photoCell(photos[2], false)}
                    </div>
                    <div class="site-card-body">
                        <div class="site-card-name">${site.name}</div>
                        <div class="site-card-desc">${site.description || ''}</div>
                        <div class="site-card-footer">
                            <span class="site-card-fee">KES ${Number(site.fee).toLocaleString()}</span>
                            <span class="site-card-select">${isSelected ? 'Selected ✓' : 'Select →'}</span>
                        </div>
                    </div>
                </div>`;
        }).join('');

        grid.querySelectorAll('.site-card').forEach(card => {
            card.addEventListener('click', () => {
                selected = sites.find(s => String(s.id) === card.dataset.siteId) || selected;
                updateEcho();
                render();
            });
        });
    }

    function updateEcho() {
        const siteEcho = document.getElementById('visit-site-echo');
        const feeEcho = document.getElementById('visit-fee-echo');
        if (selected) {
            if (siteEcho) siteEcho.textContent = selected.name;
            if (feeEcho) feeEcho.textContent = 'KES ' + Number(selected.fee).toLocaleString();
        }
    }

    render();
    updateEcho();

    // ---- Payment form — identical flow to before, just reads `selected` now ----
    const vform = document.getElementById('visit-form');
    if (!vform) return;
    const vstatus = document.getElementById('visit-status-msg');
    const vbtn = document.getElementById('visit-submit-btn');

    function showVStatus(kind, text) {
        vstatus.className = 'status-msg show ' + kind;
        vstatus.textContent = text;
    }

    vform.addEventListener('submit', async function (e) {
        e.preventDefault();

        if (!selected) {
            showVStatus('error', 'Pick a place to visit first.');
            return;
        }

        const payload = {
            name: vform.vname.value.trim(),
            email: '',
            phone: vform.vphone.value.trim(),
            ticket: selected.name,
            leg: document.title,
            amount: Number(selected.fee),
        };

        if (!/^0[71][0-9]{8}$/.test(payload.phone)) {
            showVStatus('error', 'Enter a valid Safaricom number, e.g. 0712345678.');
            return;
        }

        vbtn.disabled = true;
        vbtn.textContent = 'Sending M-Pesa prompt…';
        showVStatus('info', 'Check your phone for the M-Pesa PIN prompt.');

        try {
            const res = await fetch('../visit-payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            if (!res.ok) throw new Error('Server returned ' + res.status);
            const data = await res.json();

            if (data.ResponseCode === '0' || data.ResponseCode === 0) {
                showVStatus('success', 'Prompt sent! Enter your M-Pesa PIN to complete payment.');
                vform.reset();
            } else {
                showVStatus('error', data.ResponseDescription || 'Payment could not be started.');
            }
        } catch (err) {
            showVStatus('error', 'Could not reach the payment server — deploy visit-payment.php on a live PHP host first.');
        } finally {
            vbtn.disabled = false;
            vbtn.textContent = 'Pay visitation fee';
        }
    });
})();
