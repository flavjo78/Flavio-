/* Service worker della Regia Predizione.
   Serve per due cose: rendere il pannello installabile sul telefono
   e farlo aprire anche senza rete (offline). Mette in cache i file
   del pannello alla prima apertura e li ripropone quando serve. */

const CACHE = 'regia-predizione-v1';
const ASSETS = [
  './regia-predizione.html',
  './manifest.json',
  './icons/icon-192.png',
  './icons/icon-512.png',
  './icons/apple-touch-icon.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  // "network first" così, quando c'e' rete, prendi sempre la versione aggiornata;
  // se sei offline usi la copia in cache.
  event.respondWith(
    fetch(req)
      .then((res) => {
        const copy = res.clone();
        caches.open(CACHE).then((cache) => cache.put(req, copy)).catch(() => {});
        return res;
      })
      .catch(() => caches.match(req).then((hit) => hit || caches.match('./regia-predizione.html')))
  );
});
