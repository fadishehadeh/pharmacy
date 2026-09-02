        </div><!-- .container-fluid -->
    </div><!-- #page-content-wrapper -->
</div><!-- #wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net@2.0.8/js/dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net-bs5@2.0.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<?php if (isset($extraScripts)) echo $extraScripts; ?>

<script>
// ── Offline detection + Service Worker ───────────────────────────────────────
(function () {
    var banner = document.getElementById('offlineBanner');
    var bannerText = document.getElementById('offlineBannerText');
    var syncingMsg  = <?= json_encode(tr('syncing')) ?>;
    var syncedMsg   = <?= json_encode(tr('synced')) ?>;

    function showBanner(msg) {
        if (!banner) return;
        if (msg) bannerText.textContent = msg;
        banner.classList.remove('d-none');
        banner.style.display = 'flex';
    }
    function hideBanner() {
        if (!banner) return;
        banner.classList.add('d-none');
        banner.style.display = 'none';
    }

    if (!navigator.onLine) showBanner();

    window.addEventListener('offline', function () { showBanner(); });
    window.addEventListener('online', function () {
        showBanner(syncingMsg);
        syncOfflineSales().then(function (count) {
            if (count > 0) {
                bannerText.textContent = syncedMsg;
                setTimeout(hideBanner, 3000);
            } else {
                hideBanner();
            }
        });
    });

    // Register service worker
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js', {scope: '<?= BASE_URL ?>/'})
            .catch(function (e) { console.warn('SW registration failed', e); });
    }
})();

// ── Offline sale queue (IndexedDB) ───────────────────────────────────────────
var PharmDB = (function () {
    var DB_NAME = 'pharma_offline', DB_VER = 1, _db = null;

    function open() {
        return new Promise(function (resolve, reject) {
            if (_db) return resolve(_db);
            var req = indexedDB.open(DB_NAME, DB_VER);
            req.onupgradeneeded = function (e) {
                var db = e.target.result;
                if (!db.objectStoreNames.contains('offline_sales'))
                    db.createObjectStore('offline_sales', {keyPath: 'id', autoIncrement: true});
                if (!db.objectStoreNames.contains('product_cache'))
                    db.createObjectStore('product_cache', {keyPath: 'cacheKey'});
            };
            req.onsuccess = function (e) { _db = e.target.result; resolve(_db); };
            req.onerror   = function (e) { reject(e); };
        });
    }

    return {
        queueSale: function (payload) {
            return open().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx = db.transaction('offline_sales', 'readwrite');
                    tx.objectStore('offline_sales').add({payload: payload, ts: Date.now()});
                    tx.oncomplete = resolve;
                    tx.onerror    = reject;
                });
            });
        },
        getQueuedSales: function () {
            return open().then(function (db) {
                return new Promise(function (resolve) {
                    var items = [];
                    db.transaction('offline_sales').objectStore('offline_sales')
                        .openCursor().onsuccess = function (e) {
                        var cur = e.target.result;
                        if (cur) { items.push(cur.value); cur.continue(); }
                        else resolve(items);
                    };
                });
            });
        },
        deleteSale: function (id) {
            return open().then(function (db) {
                return new Promise(function (resolve) {
                    var tx = db.transaction('offline_sales', 'readwrite');
                    tx.objectStore('offline_sales').delete(id);
                    tx.oncomplete = resolve;
                });
            });
        },
        cacheProducts: function (key, data) {
            return open().then(function (db) {
                return new Promise(function (resolve) {
                    var tx = db.transaction('product_cache', 'readwrite');
                    tx.objectStore('product_cache').put({cacheKey: key, data: data, ts: Date.now()});
                    tx.oncomplete = resolve;
                });
            });
        },
        getCachedProducts: function (key) {
            return open().then(function (db) {
                return new Promise(function (resolve) {
                    db.transaction('product_cache').objectStore('product_cache')
                        .get(key).onsuccess = function (e) { resolve(e.target.result || null); };
                });
            });
        }
    };
})();

function syncOfflineSales() {
    return PharmDB.getQueuedSales().then(function (sales) {
        var chain = Promise.resolve();
        sales.forEach(function (item) {
            chain = chain.then(function () {
                return fetch('<?= BASE_URL ?>/modules/pos/index.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(item.payload)
                }).then(function (r) { return r.json(); }).then(function (r) {
                    if (r.success || r.invoice_number) return PharmDB.deleteSale(item.id);
                });
            });
        });
        return chain.then(function () { return sales.length; });
    });
}
</script>
</body>
</html>
