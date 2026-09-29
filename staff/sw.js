/**
 * Staff app service worker. Only the app's own files: the page opens with no
 * signal. The API is never cached — jobs, trips and fixes always go to the
 * server, and the app keeps anything unsent on the phone itself.
 */
const CACHE = 'staff-v6';
const SHELL = ['index.php', 'app.css', 'js/core.js', 'js/driver.js', 'js/cleaning.js', 'js/app.js', 'manifest.json', 'icon-192.png', 'icon-512.png', 'apple-touch-icon.png', 'audio/check/texts.json'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// Network first, so a new upload shows up at once; the cached copy when offline.
self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;
  if (!url.pathname.startsWith(new URL('./', self.registration.scope).pathname)) return;

  event.respondWith(
    fetch(req)
      .then((res) => {
        if (res.ok) {
          const copy = res.clone();
          const key = req.mode === 'navigate' ? 'index.php' : req;
          caches.open(CACHE).then((c) => c.put(key, copy));
        }
        return res;
      })
      .catch(() =>
        caches.match(req.mode === 'navigate' ? 'index.php' : req, { ignoreSearch: true })
          .then((hit) => hit || caches.match('index.php'))
      )
  );
});
