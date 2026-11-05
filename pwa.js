/* pwa.js — registers the service worker and offers an "Install app" button.
 * Add to any page with: <script src="pwa.js" defer></script>
 * (use ../pwa.js from pages inside Regions/, Teams/, pages/, info/).
 * The worker URL is worked out from this script's own location, so the
 * same file behaves correctly at any folder depth and on any host. */
(function () {

  // ---- Directory dropdown fix (phones) -------------------------------
  // On narrow screens the nav bar scrolls sideways (overflow-x:auto), and
  // that also clips anything hanging below it, so the Directory menu opened
  // but stayed invisible. Here the menu is lifted out of the nav on phones
  // (fixed, full width under the header), and tapping outside or on a link
  // closes it.
  (function () {
    var css = '@media (max-width:900px){' +
      '.nav-dropdown-menu{position:fixed !important;top:auto !important;left:12px !important;right:12px !important;' +
      'min-width:0 !important;max-height:60vh;overflow-y:auto;-webkit-overflow-scrolling:touch;' +
      'box-shadow:0 10px 30px rgba(0,0,0,.5);border:2px solid #F28C0F !important;}' +
      '.nav-dropdown-menu a{padding:0.7rem 1rem !important;font-size:0.95rem !important;}}';
    var st = document.createElement('style');
    st.textContent = css;
    document.head.appendChild(st);

    function closeAll(except) {
      var open = document.querySelectorAll('.nav-dropdown.open');
      for (var i = 0; i < open.length; i++) {
        if (open[i] === except) continue;
        open[i].classList.remove('open');
        var t = open[i].querySelector('.nav-dropdown-trigger');
        if (t) t.setAttribute('aria-expanded', 'false');
      }
    }
    document.addEventListener('click', function (e) {
      var dd = e.target.closest ? e.target.closest('.nav-dropdown') : null;
      if (dd) {
        var trig = dd.querySelector('.nav-dropdown-trigger');
        if (trig) trig.setAttribute('aria-expanded', dd.classList.contains('open') ? 'true' : 'false');
        if (dd.classList.contains('open') && window.matchMedia('(max-width:900px)').matches) {
          var hdr = document.querySelector('header'), menu = dd.querySelector('.nav-dropdown-menu');
          if (hdr && menu) menu.style.setProperty('top', hdr.getBoundingClientRect().bottom + 'px', 'important');  // sit under the whole header
        }
        if (e.target.closest('.nav-dropdown-menu a')) closeAll(null);   // picked an item
      } else {
        closeAll(null);                                                  // tapped elsewhere
      }
    });
  })();

  if (!('serviceWorker' in navigator)) return;

  var me = document.currentScript && document.currentScript.src;
  var root = me ? me.replace(/pwa\.js(\?.*)?$/, '') : './';

  window.addEventListener('load', function () {
    navigator.serviceWorker.register(root + 'sw.js').catch(function () {});
  });

  // ---- optional install button (Android / desktop Chrome & Edge) ----
  var deferred = null;
  try { if (localStorage.getItem('tttt-install-dismissed')) return; } catch (e) {}

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    if (document.getElementById('tttt-install')) return;

    var bar = document.createElement('div');
    bar.id = 'tttt-install';
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Install the Tour of Taita Taveta app');
    bar.style.cssText = 'position:fixed;left:12px;right:12px;bottom:12px;z-index:9999;max-width:420px;margin:0 auto;' +
      'display:flex;gap:10px;align-items:center;background:#13100C;color:#F0DBB8;border:2px solid #F28C0F;' +
      'padding:10px 12px;font-family:Barlow,system-ui,sans-serif;font-size:0.9rem;box-shadow:0 6px 24px rgba(0,0,0,.35);';
    bar.innerHTML = '<span style="flex:1">Install the Tour app for quick access to the legs and your registration.</span>' +
      '<button id="tttt-install-yes" style="background:#F28C0F;color:#13100C;border:0;padding:8px 14px;font-weight:700;cursor:pointer;text-transform:uppercase;font-family:inherit">Install</button>' +
      '<button id="tttt-install-no" aria-label="Dismiss" style="background:none;border:0;color:#F0DBB8;font-size:1.2rem;cursor:pointer;line-height:1">&times;</button>';
    document.body.appendChild(bar);

    document.getElementById('tttt-install-yes').onclick = function () {
      bar.remove();
      deferred.prompt();
      deferred.userChoice.finally(function () { deferred = null; });
    };
    document.getElementById('tttt-install-no').onclick = function () {
      bar.remove();
      try { localStorage.setItem('tttt-install-dismissed', '1'); } catch (e) {}
    };
  });

  window.addEventListener('appinstalled', function () {
    var b = document.getElementById('tttt-install'); if (b) b.remove();
  });
})();
