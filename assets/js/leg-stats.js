/**
 * assets/js/leg-stats.js
 * Fills the "This Leg / Elite / Amateur" prize section already on every
 * leg page (#leg-prize-total, #leg-elite-pool, #leg-elite-count,
 * #leg-amateur-pool, #leg-amateur-count, #leg-college-pool, #leg-college-count)
 * with live numbers from stats.php. (Only needed on pages that do not already have
 * their own stats script — in practice sagalla.html.)
 *
 * Nothing to configure: the leg is worked out from the page's file name
 * (Regions/sagalla.html -> Sagalla, vuria-wusi.html -> Vuria/Wusi, ...).
 * Refreshes every 30 seconds, like the homepage.
 */
(function () {
  var LEGS = {
    'sagalla': 'Sagalla', 'vuria-wusi': 'Vuria/Wusi', 'bura': 'Bura', 'kasighau': 'Kasighau',
    'mbololo-ronge': 'Mbololo/Ronge', 'mbale': 'Mbale', 'funju': 'Funju'
  };
  var file = location.pathname.split('/').pop().replace(/\.html?$/i, '').toLowerCase();
  var section = document.getElementById('leg-prize');
  var leg = (section && section.getAttribute('data-leg')) || LEGS[file];
  if (!section || !leg) return;

  var src = document.currentScript && document.currentScript.src;
  var url = src ? src.replace(/assets\/js\/leg-stats\.js(\?.*)?$/, 'stats.php') : '../stats.php';
  var kes = function (n) { return 'KES ' + Number(n || 0).toLocaleString('en-KE'); };
  function set(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }

  function load() {
    fetch(url, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.success) return;
        var l = null;
        (d.per_leg || []).forEach(function (x) { if (String(x.leg).toLowerCase() === leg.toLowerCase()) l = x; });
        if (!l) return;
        set('leg-prize-total', kes(l.leg_prize_pool));
        set('leg-elite-pool', kes(l.elite_pool));
        set('leg-elite-count', l.elite_count);
        set('leg-amateur-pool', kes(l.amateur_pool));
        set('leg-amateur-count', l.amateur_count);
        set('leg-college-pool', kes(l.college_pool));
        set('leg-college-count', l.college_count);
      })
      .catch(function () { /* leave the page's own KES 0 in place */ });
  }
  load();
  setInterval(load, 30000);
})();
