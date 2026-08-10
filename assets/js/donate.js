/**
 * assets/js/donate.js
 *
 * Renders a preset-amount chip widget into #donate-widget, reading
 * window.DONATE_SCOPE (set inline on the page, e.g. document.title)
 * to label what the donation backs. Posts to ../donate.php — same
 * STK push flow as visit-payment.php.
 *
 * Tier labels shown here are cosmetic only — the real tier is decided
 * server-side in donate.php so it can't be spoofed from the browser.
 */

(function () {
    const container = document.getElementById('donate-widget');
    if (!container) return;

    const scope = window.DONATE_SCOPE || document.title;

    // Cosmetic only — must match the thresholds in donate.php's
    // donationTierFor(). If you change one, change both.
    const CHIPS = [
        { amount: 1000, tier: 'Rhodolite' },
        { amount: 5000, tier: 'Spinel' },
        { amount: 10000, tier: 'Green Garnet' },
        { amount: 25000, tier: 'Ruby' },
        { amount: 50000, tier: 'Tsavorite' },
    ];

    let selectedAmount = null;
    let customMode = false;

    container.innerHTML = `
        <div class="donate-chips" id="donate-chips">
            ${CHIPS.map(c => `<div class="donate-chip" data-amount="${c.amount}">KES ${c.amount.toLocaleString()}<div class="donate-tier-label">${c.tier}</div></div>`).join('')}
            <div class="donate-chip" data-amount="custom">Custom</div>
        </div>
        <div class="field" id="donate-custom-field" style="display:none;">
            <label for="donate-custom-amount">Custom amount (KES)</label>
            <input type="number" id="donate-custom-amount" min="100" step="1">
        </div>
        <div class="field"><label for="donate-name">Full name</label><input type="text" id="donate-name" required></div>
        <div class="field"><label for="donate-phone">M-Pesa phone number</label><input type="tel" id="donate-phone" placeholder="07XXXXXXXX" pattern="^0[71][0-9]{8}$" required></div>
        <div class="amount-line"><span>Amount</span><span id="donate-amount-echo">—</span></div>
        <button type="button" class="submit" id="donate-submit-btn">Donate via M-Pesa</button>
        <div class="status-msg" id="donate-status-msg"></div>
    `;

    const chipsEl = container.querySelectorAll('.donate-chip');
    const customField = container.querySelector('#donate-custom-field');
    const customInput = container.querySelector('#donate-custom-amount');
    const amountEcho = container.querySelector('#donate-amount-echo');

    function selectChip(chip) {
        chipsEl.forEach(c => c.classList.remove('selected'));
        chip.classList.add('selected');

        if (chip.dataset.amount === 'custom') {
            customMode = true;
            customField.style.display = 'block';
            selectedAmount = Number(customInput.value) || null;
        } else {
            customMode = false;
            customField.style.display = 'none';
            selectedAmount = Number(chip.dataset.amount);
        }
        updateEcho();
    }

    function updateEcho() {
        amountEcho.textContent = selectedAmount ? 'KES ' + selectedAmount.toLocaleString() : '—';
    }

    chipsEl.forEach(chip => chip.addEventListener('click', () => selectChip(chip)));
    customInput.addEventListener('input', () => {
        selectedAmount = Number(customInput.value) || null;
        updateEcho();
    });

    const statusEl = container.querySelector('#donate-status-msg');
    const btn = container.querySelector('#donate-submit-btn');

    function showStatus(kind, text) {
        statusEl.className = 'status-msg show ' + kind;
        statusEl.textContent = text;
    }

    btn.addEventListener('click', async () => {
        const name = container.querySelector('#donate-name').value.trim();
        const phone = container.querySelector('#donate-phone').value.trim();

        if (!selectedAmount || selectedAmount < 100) {
            showStatus('error', 'Pick an amount (minimum KES 100) first.');
            return;
        }
        if (!name) {
            showStatus('error', 'Enter your name.');
            return;
        }
        if (!/^0[71][0-9]{8}$/.test(phone)) {
            showStatus('error', 'Enter a valid Safaricom number, e.g. 0712345678.');
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Sending M-Pesa prompt…';
        showStatus('info', 'Check your phone for the M-Pesa PIN prompt.');

        try {
            const res = await fetch('../donate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, phone, scope, amount: selectedAmount }),
            });
            if (!res.ok) throw new Error('Server returned ' + res.status);
            const data = await res.json();

            if (data.ResponseCode === '0' || data.ResponseCode === 0) {
                showStatus('success', `Prompt sent! You're donating at the ${data.tier || ''} tier — enter your M-Pesa PIN to complete.`);
            } else {
                showStatus('error', data.ResponseDescription || 'Payment could not be started.');
            }
        } catch (err) {
            showStatus('error', 'Could not reach the payment server — deploy donate.php on a live PHP host first.');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Donate via M-Pesa';
        }
    });
})();
