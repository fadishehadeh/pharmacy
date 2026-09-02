var CACHE = 'pharma-v1';
var OFFLINE_ASSETS = [
    '/pharmacy/modules/pos/index.php',
    '/pharmacy/assets/css/style.css',
    '/pharmacy/assets/js/app.js',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js',
];

self.addEventListener('install', function (e) {
    e.waitUntil(
        caches.open(CACHE).then(function (cache) {
            return cache.addAll(OFFLINE_ASSETS);
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function (e) {
    var req = e.request;

    // Only handle GET requests — POST (sales) always go to the network
    if (req.method !== 'GET') return;

    // For navigation requests (page loads): network-first, cache fallback
    if (req.mode === 'navigate') {
        e.respondWith(
            fetch(req).then(function (res) {
                var clone = res.clone();
                caches.open(CACHE).then(function (c) { c.put(req, clone); });
                return res;
            }).catch(function () {
                return caches.match(req).then(function (cached) {
                    return cached || caches.match('/pharmacy/modules/pos/index.php');
                });
            })
        );
        return;
    }

    // For assets: cache-first
    e.respondWith(
        caches.match(req).then(function (cached) {
            if (cached) return cached;
            return fetch(req).then(function (res) {
                if (res && res.status === 200 && res.type === 'basic') {
                    var clone = res.clone();
                    caches.open(CACHE).then(function (c) { c.put(req, clone); });
                }
                return res;
            });
        })
    );
});
