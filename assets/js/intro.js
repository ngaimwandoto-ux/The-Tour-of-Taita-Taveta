/**
 * assets/js/intro.js — the short sponsor intro screen shown when the installed
 * app opens. Everything you might want to change is in the SETTINGS block.
 *
 *  - Shows only when the app is opened from the home screen (not in a normal
 *    browser tab), and only ONCE per app session.
 *  - Tap anywhere to skip.
 *  - To preview it in a browser, open the homepage with  ?intro=1  on the end.
 *  - To switch it off, set ENABLED to false.
 */
(function () {
  // ===================== SETTINGS =====================
  var ENABLED = true;
  var SPONSOR_PREFIX = 'Powered by';
  var SPONSOR_NAME   = 'XYZ Bank PLC';          // <- the sponsor's name
  var SPONSOR_LOGO   = '';                      // <- optional, e.g. 'logos/sponsors/xyz-bank.png' (shown instead of the name)
  var SHOW_MS        = 7000;                    // how long it stays up (2000–3000 feels right)
  var MAX_WAIT_MS    = 6000;                    // never longer than this, even on a slow connection
  // =====================================================

  if (!ENABLED) return;

  var q = location.search || '';
  var forced = /[?&]intro=1(&|$)/.test(q);
  var standalone = false;
  try { standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true; } catch (e) {}
  var fromApp = standalone || /[?&]source=pwa(&|$)/.test(q);
  if (!forced && !fromApp) return;

  try { if (!forced && sessionStorage.getItem('tttt-intro-shown')) return; } catch (e) {}
  try { sessionStorage.setItem('tttt-intro-shown', '1'); } catch (e) {}

  var src = document.currentScript && document.currentScript.src;
  var ROOT = src ? src.replace(/assets\/js\/intro\.js(\?.*)?$/, '') : '';

  var css =
    '#tttt-intro{position:fixed;inset:0;z-index:2147483000;background:#13100C;color:#F0DBB8;display:flex;flex-direction:column;align-items:center;justify-content:center;' +
      'opacity:1;transition:opacity .45s ease;font-family:"Barlow Condensed","Arial Narrow",Arial,sans-serif;cursor:pointer;-webkit-tap-highlight-color:transparent}' +
    '#tttt-intro.out{opacity:0;pointer-events:none}' +
    '#tttt-intro .logo{width:min(46vw,200px);height:auto;animation:ttttIn .7s ease both}' +
    '#tttt-intro .tag{margin-top:1.1rem;font-size:.8rem;letter-spacing:.34em;text-transform:uppercase;color:#F28C0F;animation:ttttIn .7s .15s ease both}' +
    '#tttt-intro .sponsor{position:absolute;left:0;right:0;bottom:calc(2.2rem + env(safe-area-inset-bottom,0px));text-align:center;animation:ttttIn .8s .5s ease both}' +
    '#tttt-intro .sponsor small{display:block;font-family:"IBM Plex Mono",ui-monospace,monospace;font-size:.62rem;letter-spacing:.22em;text-transform:uppercase;color:rgba(240,219,184,.6)}' +
    '#tttt-intro .sponsor b{display:block;margin-top:.35rem;font-weight:700;font-size:1.05rem;letter-spacing:.14em;text-transform:uppercase;color:#F0DBB8}' +
    '#tttt-intro .sponsor img{display:block;margin:.5rem auto 0;max-height:34px;max-width:55vw;width:auto}' +
    '@keyframes ttttIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}' +
    '@media (prefers-reduced-motion:reduce){#tttt-intro,#tttt-intro *{animation:none!important;transition:none!important}}';

  var st = document.createElement('style'); st.textContent = css;
  var el = document.createElement('div'); el.id = 'tttt-intro'; el.setAttribute('aria-hidden', 'true');

  var logo = document.createElement('img'); logo.className = 'logo'; logo.alt = ''; logo.src = ROOT + 'logos/taita-taveta.png';
  var tag = document.createElement('div'); tag.className = 'tag'; tag.textContent = 'Attitude Meets Altitude';
  el.appendChild(logo); el.appendChild(tag);

  if (SPONSOR_NAME || SPONSOR_LOGO) {
    var sp = document.createElement('div'); sp.className = 'sponsor';
    var small = document.createElement('small'); small.textContent = SPONSOR_PREFIX; sp.appendChild(small);
    if (SPONSOR_LOGO) {
      var img = document.createElement('img'); img.alt = SPONSOR_NAME || ''; img.src = ROOT + SPONSOR_LOGO;
      img.onerror = function () { img.remove(); if (SPONSOR_NAME) { var b = document.createElement('b'); b.textContent = SPONSOR_NAME; sp.appendChild(b); } };
      sp.appendChild(img);
    } else {
      var b = document.createElement('b'); b.textContent = SPONSOR_NAME; sp.appendChild(b);
    }
    el.appendChild(sp);
  }

  // Added straight onto <html> so it covers the page from the very first paint.
  document.documentElement.appendChild(st);
  document.documentElement.appendChild(el);

  var closed = false;
  function close() {
    if (closed) return; closed = true;
    el.classList.add('out');
    setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); if (st.parentNode) st.parentNode.removeChild(st); }, 600);
  }
  el.addEventListener('click', close);

  var started = Date.now();
  setTimeout(function () {
    if (document.readyState === 'complete') { close(); return; }
    // page still loading: wait for it, but never past MAX_WAIT_MS
    window.addEventListener('load', close);
    setTimeout(close, Math.max(0, MAX_WAIT_MS - (Date.now() - started)));
  }, SHOW_MS);
})();
