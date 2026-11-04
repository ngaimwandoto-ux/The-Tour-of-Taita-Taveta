/**
 * assets/js/registration.js
 * Makes the race-registration form work end to end:
 *   - picking a category (Elite / Amateur / Open / College & University)
 *   - College & University: shows the institution box
 *   - optional "Riding for a team?" dropdown, filled from teams-list.php
 *   - submit -> mpesa-stk.php -> M-Pesa prompt on the rider's phone
 *
 * Needs the form markup already in index.html (#registration-form, #tier-list,
 * #ticket, #tier-echo, #amount-echo, #status-msg, #submit-btn) plus the new
 * #team select and #institution-field from registration-snippet.html.
 */
(function () {
  var form = document.getElementById('registration-form');
  var list = document.getElementById('tier-list');
  if (!form || !list) return;

  var ticketInput = document.getElementById('ticket');
  var tierEcho = document.getElementById('tier-echo');
  var amountEcho = document.getElementById('amount-echo');
  var statusEl = document.getElementById('status-msg');
  var btn = document.getElementById('submit-btn');
  var instField = document.getElementById('institution-field');
  var instInput = document.getElementById('institution');
  var teamSelect = document.getElementById('team');

  function base(file) {
    var s = document.currentScript && document.currentScript.src;
    return s ? s.replace(/assets\/js\/registration\.js(\?.*)?$/, file) : file;
  }
  var kes = function (n) { return 'KES ' + Number(n).toLocaleString('en-KE'); };
  function show(kind, text) { statusEl.className = 'status-msg show ' + kind; statusEl.textContent = text; }

  // ---- category tiles ----
  var tiers = list.querySelectorAll('.tier');
  var current = null;
  function selectTier(el) {
    current = el;
    for (var i = 0; i < tiers.length; i++) tiers[i].classList.toggle('active', tiers[i] === el);
    ticketInput.value = el.getAttribute('data-value');
    tierEcho.textContent = el.getAttribute('data-label') || el.getAttribute('data-value');
    amountEcho.textContent = kes(el.getAttribute('data-price'));
    if (instField) {
      var isCollege = el.getAttribute('data-value') === 'college';
      instField.style.display = isCollege ? '' : 'none';
      if (instInput) instInput.required = isCollege;
    }
  }
  for (var i = 0; i < tiers.length; i++) {
    (function (el) { el.addEventListener('click', function () { selectTier(el); }); })(tiers[i]);
  }
  selectTier(list.querySelector('.tier.active') || tiers[0]);

  // ---- optional team dropdown ----
  if (teamSelect) {
    fetch(base('teams-list.php'))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        (d.teams || []).forEach(function (t) {
          var o = document.createElement('option');
          o.value = t.id; o.textContent = t.name;
          teamSelect.appendChild(o);
        });
      })
      .catch(function () { /* optional field — fail quiet */ });
  }

  // ---- submit ----
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var phone = document.getElementById('phone').value.trim();
    if (!document.getElementById('name').value.trim() || !document.getElementById('email').value.trim()) { show('error', 'Please enter your name and email.'); return; }
    if (!/^0[71][0-9]{8}$/.test(phone)) { show('error', 'Enter a valid Safaricom number, e.g. 0712345678.'); return; }
    if (ticketInput.value === 'college' && !(instInput && instInput.value.trim())) {
      show('error', 'Enter your college or university name.'); return;
    }

    var payload = {
      name: document.getElementById('name').value.trim(),
      email: document.getElementById('email').value.trim(),
      phone: phone,
      gender: document.getElementById('gender').value,
      leg: document.getElementById('leg').value,
      ticket: ticketInput.value,
      amount: Number(current.getAttribute('data-price')),
      team_id: teamSelect && teamSelect.value ? Number(teamSelect.value) : null,
      institution: instInput ? instInput.value.trim() : ''
    };

    btn.disabled = true;
    btn.textContent = 'Sending M-Pesa prompt…';
    show('info', 'Check your phone for the M-Pesa PIN prompt.');

    fetch(base('mpesa-stk.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ResponseCode === '0' || data.ResponseCode === 0) {
          show('success', 'Prompt sent! Enter your M-Pesa PIN to complete your registration. You\'ll get a confirmation once payment clears.');
          form.reset();
          selectTier(list.querySelector('.tier.active') || tiers[0]);
        } else {
          show('error', data.ResponseDescription || 'Payment could not be started.');
        }
      })
      .catch(function () { show('error', 'Could not reach the payment server. Please try again.'); })
      .then(function () { btn.disabled = false; btn.textContent = 'Pay via M-Pesa'; });
  });
})();
