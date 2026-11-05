/**
 * assets/js/registration.js
 * Race registration, end to end:
 *   - picking a category (Elite / Amateur / Open / College & University)
 *   - individual categories -> optional team dropdown -> M-Pesa prompt
 *   - College & University -> a TEAM entry per institution: the M-Pesa form is
 *     replaced by the institution form (saved as "awaiting payment"; the
 *     institution is invoiced and pays by bank transfer)
 */
(function () {
  var form = document.getElementById('registration-form');
  var list = document.getElementById('tier-list');
  if (!form || !list) return;

  var src = document.currentScript && document.currentScript.src;
  var ROOT = src ? src.replace(/assets\/js\/registration\.js(\?.*)?$/, '') : '';

  var ticketInput = document.getElementById('ticket');
  var tierEcho = document.getElementById('tier-echo');
  var amountEcho = document.getElementById('amount-echo');
  var statusEl = document.getElementById('status-msg');
  var btn = document.getElementById('submit-btn');
  var teamSelect = document.getElementById('team');
  var instForm = document.getElementById('institution-form');

  var kes = function (n) { return 'KES ' + Number(n).toLocaleString('en-KE'); };
  function show(el, kind, text) { el.className = 'status-msg show ' + kind; el.textContent = text; }
  function $(id) { return document.getElementById(id); }

  // ---- category tiles ----
  var tiers = list.querySelectorAll('.tier');
  var current = null;
  function selectTier(el) {
    current = el;
    for (var i = 0; i < tiers.length; i++) tiers[i].classList.toggle('active', tiers[i] === el);
    var value = el.getAttribute('data-value');
    var isCollege = value === 'college';
    ticketInput.value = value;
    form.style.display = isCollege ? 'none' : '';
    if (instForm) instForm.style.display = isCollege ? '' : 'none';
    if (!isCollege) {
      tierEcho.textContent = el.getAttribute('data-label') || value;
      amountEcho.textContent = kes(el.getAttribute('data-price'));
    }
  }
  for (var i = 0; i < tiers.length; i++) {
    (function (el) { el.addEventListener('click', function () { selectTier(el); }); })(tiers[i]);
  }
  selectTier(list.querySelector('.tier.active') || tiers[0]);

  // ---- optional team dropdown (individual riders) ----
  if (teamSelect) {
    fetch(ROOT + 'teams-list.php')
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

  // ---- individual rider: submit -> M-Pesa ----
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var phone = $('phone').value.trim();
    if (!$('name').value.trim() || !$('email').value.trim()) { show(statusEl, 'error', 'Please enter your name and email.'); return; }
    if (!/^0[71][0-9]{8}$/.test(phone)) { show(statusEl, 'error', 'Enter a valid Safaricom number, e.g. 0712345678.'); return; }

    var payload = {
      name: $('name').value.trim(),
      email: $('email').value.trim(),
      phone: phone,
      gender: $('gender').value,
      leg: $('leg').value,
      ticket: ticketInput.value,
      amount: Number(current.getAttribute('data-price')),
      team_id: teamSelect && teamSelect.value ? Number(teamSelect.value) : null
    };

    btn.disabled = true;
    btn.textContent = 'Sending M-Pesa prompt…';
    show(statusEl, 'info', 'Check your phone for the M-Pesa PIN prompt.');

    fetch(ROOT + 'mpesa-stk.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ResponseCode === '0' || data.ResponseCode === 0) {
          show(statusEl, 'success', 'Prompt sent! Enter your M-Pesa PIN to complete your registration. You\'ll get a confirmation once payment clears.');
          form.reset();
          selectTier(list.querySelector('.tier.active') || tiers[0]);
        } else {
          show(statusEl, 'error', data.ResponseDescription || 'Payment could not be started.');
        }
      })
      .catch(function () { show(statusEl, 'error', 'Could not reach the payment server. Please try again.'); })
      .then(function () { btn.disabled = false; btn.textContent = 'Pay via M-Pesa'; });
  });

  // ---- College & University: institution team entry ----
  if (!instForm) return;
  var instStatus = $('institution-status');
  var instBtn = $('institution-submit');
  var legSelect = $('inst-leg');

  function fillLegs(d) {
    legSelect.innerHTML = '';
    var first = document.createElement('option');
    first.value = '';
    first.textContent = d && d.upcoming ? 'Next upcoming leg — ' + d.upcoming.label : 'Next upcoming leg (we will confirm which)';
    legSelect.appendChild(first);
    ((d && d.legs) || []).forEach(function (l) {
      var o = document.createElement('option');
      o.value = l.leg; o.textContent = l.label;
      legSelect.appendChild(o);
    });
  }
  fillLegs(null);
  fetch(ROOT + 'leg-schedule.php').then(function (r) { return r.json(); }).then(fillLegs).catch(function () {});

  instForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var payload = {
      institution: $('inst-name').value.trim(),
      team_name: $('inst-team').value.trim(),
      contact_name: $('inst-contact').value.trim(),
      email: $('inst-email').value.trim(),
      phone: $('inst-phone').value.trim(),
      riders: Number($('inst-riders').value),
      leg: legSelect.value,
      website: $('inst-website').value   // honeypot — stays empty for real people
    };
    if (!payload.institution || !payload.contact_name || !payload.email || !payload.phone) {
      show(instStatus, 'error', 'Please fill in the institution, contact person, email and phone.'); return;
    }
    if (!(payload.riders >= 1)) { show(instStatus, 'error', 'Enter how many riders are in the team.'); return; }

    instBtn.disabled = true;
    instBtn.textContent = 'Sending…';
    fetch(ROOT + 'institution-entry.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) {
          show(instStatus, 'success', 'Thank you — entry ' + d.reference + ' received for ' + d.leg +
            (d.leg_assumed ? ' (the next upcoming leg)' : '') +
            '. We will email an invoice with payment details to ' + payload.email + '. Your team is confirmed once payment is received.');
          instForm.reset();
          fillLegs(null);
          fetch(ROOT + 'leg-schedule.php').then(function (r) { return r.json(); }).then(fillLegs).catch(function () {});
        } else {
          show(instStatus, 'error', d.error || 'Could not save your entry.');
        }
      })
      .catch(function () { show(instStatus, 'error', 'Could not reach the server. Please try again.'); })
      .then(function () { instBtn.disabled = false; instBtn.textContent = 'Submit team entry'; });
  });
})();
