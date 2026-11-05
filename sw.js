/* Tour of Taita Taveta — service worker.
 * Lives at the site root so its scope covers every page.
 * Bump VERSION whenever you want every phone to drop its old cache.
 *
 * NEVER cached (always straight to the network): *.php, /api/, /admin/,
 * and the account pages (/pages/). That keeps M-Pesa, login, messaging,
 * bookings and admin always live — a stale copy of those would be wrong
 * or unsafe. */
const VERSION = 'tttt-v4';
const SHELL_CACHE = VERSION + '-shell';
const RUNTIME_CACHE = VERSION + '-runtime';
const MAX_RUNTIME_ENTRIES = 80;

const BASE = new URL('./', self.location).href;           // works on GitHub Pages subpath and Vercel root
const OFFLINE_URL = BASE + 'offline.html';
const PRECACHE = [
  BASE,
  BASE + 'index.html',
  BASE + 'offline.html',
  BASE + 'style.css',
  BASE + 'icons/icon-192.png',
  BASE + 'icons/icon-512.png'
];

const NEVER_CACHE = /(\.php(\?|$)|\/api\/|\/admin\/|\/pages\/)/i;

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(SHELL_CACHE).then(function (cache) {
      // add one by one so a single missing file can't abort the install
      return Promise.all(PRECACHE.map(function (url) {
        return cache.add(url).catch(function () {});
      }));
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) {
        return k.indexOf('tttt-') === 0 && k !== SHELL_CACHE && k !== RUNTIME_CACHE;
      }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

function trim(cacheName, max) {
  caches.open(cacheName).then(function (cache) {
    cache.keys().then(function (keys) {
      if (keys.length > max) cache.delete(keys[0]).then(function () { trim(cacheName, max); });
    });
  });
}

// Network first, but give up after a few seconds so slow mobile data
// falls back to the cached copy instead of hanging.
function networkFirst(request, timeoutMs) {
  return new Promise(function (resolve, reject) {
    var settled = false;
    var timer = setTimeout(function () {
      caches.match(request).then(function (hit) {
        if (hit && !settled) { settled = true; resolve(hit); }
      });
    }, timeoutMs);
    fetch(request).then(function (res) {
      clearTimeout(timer);
      if (res && res.ok) {
        var copy = res.clone();
        caches.open(RUNTIME_CACHE).then(function (c) { c.put(request, copy); trim(RUNTIME_CACHE, MAX_RUNTIME_ENTRIES); });
      }
      if (!settled) { settled = true; resolve(res); }
    }).catch(function (err) {
      clearTimeout(timer);
      caches.match(request).then(function (hit) {
        if (settled) return;
        settled = true;
        if (hit) resolve(hit); else reject(err);
      });
    });
  });
}

// Serve cached immediately, refresh in the background.
function staleWhileRevalidate(request) {
  return caches.open(RUNTIME_CACHE).then(function (cache) {
    return cache.match(request).then(function (hit) {
      var refresh = fetch(request).then(function (res) {
        if (res && (res.ok || res.type === 'opaque')) {
          cache.put(request, res.clone());
          trim(RUNTIME_CACHE, MAX_RUNTIME_ENTRIES);
        }
        return res;
      }).catch(function () { return hit; });
      return hit || refresh;
    });
  });
}

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return;                       // POSTs (payments, forms) pass straight through

  var url = new URL(req.url);
  var sameOrigin = url.origin === self.location.origin;
  var isFont = url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com';

  if (!sameOrigin && !isFont) return;                     // leave every other third party alone
  if (sameOrigin && NEVER_CACHE.test(url.pathname + url.search)) {
    if (req.mode === 'navigate') {
      // account/admin pages: network only, friendly page if offline
      event.respondWith(fetch(req).catch(function () { return caches.match(OFFLINE_URL); }));
    }
    return;
  }

  if (isFont) {
    event.respondWith(staleWhileRevalidate(req));
    return;
  }

  if (req.mode === 'navigate') {
    event.respondWith(
      networkFirst(req, 4000).catch(function () {
        return caches.match(OFFLINE_URL);
      })
    );
    return;
  }

  event.respondWith(staleWhileRevalidate(req));
});
