// Service worker: makes the CRM installable and shows a friendly page when offline.
// Only static files are cached. Pages with client data are never stored on the device.
const CACHE = 'crm-v1';
const STATIC = ['/offline.html', '/assets/app.css', '/assets/app.js', '/assets/bootstrap/bootstrap.min.css', '/assets/app/icon-192.png', '/assets/app/icon-512.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(STATIC)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('/offline.html')));   // pages: always from the network
    return;
  }
  if (url.pathname.startsWith('/assets/')) {
    // static files: network first (a new deploy shows up right away), cache as the offline fallback
    e.respondWith(fetch(req).then((res) => { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(req, copy)); return res; }).catch(() => caches.match(req)));
  }
});
