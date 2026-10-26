/**
 * Staff app service worker. Only the app's own files: the page opens with no
 * signal. The API is never cached — jobs, trips and fixes always go to the
 * server, and the app keeps anything unsent on the phone itself.
 */
const CACHE = 'staff-v8';
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

// The 18:00 attendance reminder (modules/operations/includes/ops_attendance_reminder.php).
self.addEventListener('push', (event) => {
  let msg = {};
  try { msg = event.data ? event.data.json() : {}; } catch (e) { msg = { body: event.data ? event.data.text() : '' }; }
  event.waitUntil(self.registration.showNotification(msg.title || 'Staff', {
    body: msg.body || '',
    icon: 'icon-192.png',
    badge: 'icon-192.png',
    tag: msg.tag || 'attendance-reminder',
    renotify: true,
    data: { url: new URL(msg.url || './', self.registration.scope).href },
  }));
});

// Tap: bring the app forward (or open it) on the home screen, where Check in / Check out is.
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || self.registration.scope;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      const open = list.find((c) => c.url.startsWith(self.registration.scope));
      if (open) {
        open.postMessage({ type: 'open-attendance' });
        return open.focus();
      }
      return self.clients.openWindow(url);
    })
  );
});
