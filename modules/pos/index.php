<?php
require_once __DIR__ . '/../../config/app.php';

/* ── AJAX endpoints — handle before any HTML output ── */
if (isset($_GET['ajax'])) {
    requireLogin();
    $db = getDB();
    if ($_GET['ajax'] === 'products') {
        header('Content-Type: application/json');
        $search   = $_GET['search']   ?? '';
        $category = $_GET['category'] ?? '';
        $where  = ['is_active = 1'];
        $params = [];
        if ($search) {
            $where[]  = '(name LIKE ? OR barcode LIKE ? OR generic_name LIKE ?)';
            $params   = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
        }
        if ($category) { $where[] = 'category_id = ?'; $params[] = $category; }
        $sql  = "SELECT id, name, strength, form, sell_price, cost_price, quantity_in_stock, min_stock_level, barcode, is_subsidized, subsidy_percentage, image, is_controlled FROM medicines WHERE " . implode(' AND ', $where) . " ORDER BY name LIMIT 100";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    require_once __DIR__ . '/../../config/app.php';
    requireLogin();
    $db    = getDB();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (isset($input['ajax'])) {
        header('Content-Type: application/json');
        /* handled below after HTML section */
    }
}

require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$pageTitle = tr('pos_title', 'Point of Sale');
$db = getDB();

$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$customers = $db->query("SELECT * FROM customers ORDER BY name")->fetchAll();
$exchangeRate = getSetting('exchange_rate', 89500);
$vatRate = getSetting('vat_rate', 11);
?>

<div class="pos-container row g-3">
    <div class="col-lg-8">
        <div class="card p-3 mb-3">
            <div class="row g-2 align-items-center">
                <div class="col">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" id="posSearch" placeholder="<?= t('search_medicine') ?>" autofocus>
                    </div>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-outline-primary" id="btnPosScanner" title="<?= t('scan_barcode') ?>"><i class="bi bi-upc-scan"></i></button>
                </div>
                <div class="col-auto">
                    <select class="form-select" id="posCategoryFilter">
                        <option value=""><?= t('all_categories') ?></option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= sanitize($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div id="posScannerContainer" class="d-none mt-2" style="max-width:400px">
                <div class="position-relative">
                    <video id="posScannerVideo" style="width:100%;border-radius:8px;display:block"></video>
                    <canvas id="posScannerCanvas" style="display:none"></canvas>
                    <div style="position:absolute;top:50%;left:5%;right:5%;height:2px;background:red;opacity:0.7;pointer-events:none"></div>
                </div>
                <div class="d-flex gap-2 mt-1 flex-wrap">
                    <button type="button" class="btn btn-sm btn-warning" id="btnPosCapture"><i class="bi bi-camera me-1"></i>Capture</button>
                    <button type="button" class="btn btn-sm btn-danger" id="btnPosStopScanner"><?= t('stop') ?></button>
                </div>
                <div id="posScannerManual" class="mt-2 d-none">
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" id="posScannerManualInput" placeholder="Type barcode manually">
                        <button class="btn btn-primary" id="btnPosScannerManualOk">OK</button>
                    </div>
                    <small class="text-muted">BarcodeDetector not supported in this browser</small>
                </div>
            </div>
        </div>
        <div class="pos-products" id="posProducts" style="max-height:calc(100vh - 220px);overflow-y:auto">
            <div class="row g-2" id="productGrid"></div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="pos-cart">
            <div class="p-3 border-bottom">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-cart3 me-2"></i><?= t('current_sale') ?></h6>
                    <button class="btn btn-sm btn-outline-danger" onclick="clearCart()"><i class="bi bi-x-lg"></i> <?= t('clear') ?></button>
                </div>
                <div class="mt-2">
                    <select class="form-select form-select-sm" id="customerSelect">
                        <option value=""><?= t('walk_in_customer') ?></option>
                        <?php foreach ($customers as $cust): ?>
                        <option value="<?= $cust['id'] ?>" data-insurance="<?= $cust['insurance_provider_id'] ?>"><?= sanitize($cust['name']) ?> <?= $cust['phone'] ? "({$cust['phone']})" : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="pos-cart-items p-2" id="cartItems">
                <div class="text-center text-muted py-5" id="cartEmpty">
                    <i class="bi bi-cart display-4"></i>
                    <p class="mt-2"><?= t('cart_empty') ?></p>
                </div>
            </div>

            <div class="pos-cart-footer">
                <div class="d-flex justify-content-between mb-1">
                    <span><?= t('subtotal') ?>:</span>
                    <strong id="cartSubtotal">$0.00</strong>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span><?= t('discount') ?>:</span>
                    <div class="d-flex gap-1 align-items-center">
                        <input type="number" class="form-control form-control-sm" id="discountValue" value="0" min="0" style="width:70px" onchange="updateTotals()">
                        <select class="form-select form-select-sm" id="discountType" style="width:60px" onchange="updateTotals()">
                            <option value="fixed">$</option>
                            <option value="percentage">%</option>
                        </select>
                    </div>
                </div>
                <div class="d-flex justify-content-between mb-2 border-top pt-2">
                    <strong class="fs-5"><?= t('total') ?>:</strong>
                    <strong class="fs-5 text-primary" id="cartTotal">$0.00</strong>
                </div>
                <div class="d-flex justify-content-between mb-2 small text-muted align-items-center">
                    <span><?= t('in_lbp') ?>:</span>
                    <span id="cartTotalLBP">0 L.L.</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 small">
                    <span class="text-muted"><?= t('rate') ?>:</span>
                    <span id="rateDisplay" style="cursor:pointer" onclick="editRate()" title="Click to update rate">
                        <span class="badge bg-light text-secondary border" id="rateBadge">1 USD = <?= number_format($exchangeRate, 0) ?> L.L. <i class="bi bi-pencil ms-1" style="font-size:.65rem"></i></span>
                    </span>
                    <span id="rateEdit" class="d-none">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text" style="font-size:.75rem">1$=</span>
                            <input type="number" id="rateInput" class="form-control form-control-sm" style="width:90px;font-size:.8rem" step="100">
                            <button class="btn btn-sm btn-success" onclick="saveRate()" style="font-size:.75rem"><i class="bi bi-check-lg"></i></button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="cancelRate()" style="font-size:.75rem"><i class="bi bi-x"></i></button>
                        </div>
                    </span>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label small"><?= t('payment') ?></label>
                        <select class="form-select form-select-sm" id="paymentMethod">
                            <option value="cash"><?= t('cash') ?></option>
                            <option value="card"><?= t('card') ?></option>
                            <option value="credit"><?= t('credit') ?></option>
                            <option value="insurance"><?= t('insurance') ?></option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small"><?= t('currency') ?></label>
                        <select class="form-select form-select-sm" id="currency">
                            <option value="USD">USD ($)</option>
                            <option value="LBP">LBP (L.L.)</option>
                        </select>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small"><?= t('prescription_no') ?></label>
                    <input type="text" class="form-control form-control-sm" id="prescriptionNumber" placeholder="<?= t('optional') ?>">
                </div>

                <div class="d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1 py-2" onclick="completeSale()" id="btnCompleteSale" disabled>
                        <i class="bi bi-check-circle me-2"></i><?= t('complete_sale') ?>
                    </button>
                    <a id="btnWhatsApp" href="#" target="_blank" rel="noopener"
                       class="btn py-2 d-none"
                       style="background:#25D366;color:#fff;white-space:nowrap"
                       title="Send via WhatsApp">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M13.601 2.326A7.85 7.85 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.9 7.9 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.9 7.9 0 0 0 13.6 2.326zM7.994 14.521a6.6 6.6 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.56 6.56 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592m3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.065-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.73.73 0 0 0-.529.247c-.182.198-.691.677-.691 1.654s.71 1.916.81 2.049c.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.904.129 1.246.08.38-.058 1.171-.48 1.338-.943.164-.464.164-.86.114-.943-.049-.084-.182-.133-.38-.232"/>
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$_jsSelf     = $_SERVER['PHP_SELF'];
$_jsStrings  = json_encode([
    'cart_empty'    => tr('cart_empty',    'Cart is empty'),
    'confirm_sale'  => tr('confirm_sale',  'Complete this sale?'),
    'confirm_clear' => tr('confirm_clear', 'Clear the cart?'),
    'sale_complete' => tr('sale_complete', 'Sale completed!'),
    'out_of_stock'  => tr('out_of_stock',  'Out of stock'),
], JSON_UNESCAPED_UNICODE);
$baseUrl = BASE_URL;
$extraScripts = <<<SCRIPT
<script>
var cart = [];
var exchangeRate = {$exchangeRate};
var products = [];
var BASE_URL = '{$baseUrl}';
var LANG = {$_jsStrings};

function loadProducts(search, category) {
    search = search || '';
    category = category || '';
    var cacheKey = 'prod:' + search + ':' + category;
    if (!navigator.onLine && typeof PharmDB !== 'undefined') {
        PharmDB.getCachedProducts(cacheKey).then(function(r) {
            if (r && r.data) { products = r.data; renderProducts(); }
        });
        return;
    }
    $.ajax({
        url: '{$_jsSelf}',
        data: {ajax: 'products', search: search, category: category},
        dataType: 'json',
        success: function(data) {
            products = data;
            renderProducts();
            if (typeof PharmDB !== 'undefined') PharmDB.cacheProducts(cacheKey, data);
        },
        error: function(xhr) {
            $('#productGrid').html('<div class="col-12 text-center text-danger py-3"><i class="bi bi-exclamation-triangle me-2"></i>Search error (HTTP ' + xhr.status + '). <a href="' + BASE_URL + '/login.php">Re-login</a></div>');
        }
    });
}

function renderProducts() {
    var html = '';
    products.forEach(function(p) {
        var outClass = p.quantity_in_stock <= 0 ? 'out-of-stock' : '';
        var stockBadge = p.quantity_in_stock <= 0 ? 'bg-danger' : (p.quantity_in_stock <= p.min_stock_level ? 'bg-warning' : 'bg-success');
        html += '<div class="col-6 col-xl-4"><div class="card pos-product-card p-2 ' + outClass + '" onclick="addToCart(' + p.id + ')">';
        if (p.image) html += '<img src="' + BASE_URL + '/assets/uploads/' + p.image + '" style="height:40px;width:40px;object-fit:cover;border-radius:4px;float:right;margin-left:4px">';
        html += '<strong class="small">' + escHtml(p.name) + '</strong>';
        if (p.strength) html += '<small class="text-muted">' + escHtml(p.strength) + '</small>';
        html += '<div class="d-flex justify-content-between mt-1">';
        html += '<span class="text-primary fw-bold small">$' + parseFloat(p.sell_price).toFixed(2) + '</span>';
        html += '<span class="badge ' + stockBadge + '">' + p.quantity_in_stock + '</span>';
        html += '</div></div></div>';
    });
    if (!html) html = '<div class="col-12 text-center text-muted py-5">No medicines found</div>';
    $('#productGrid').html(html);
}

function addToCart(id) {
    var product = products.find(function(p) { return p.id == id; });
    if (!product || product.quantity_in_stock <= 0) return;

    var existing = cart.find(function(item) { return item.id == id; });
    if (existing) {
        if (existing.qty < product.quantity_in_stock) {
            existing.qty++;
        } else {
            alert('Not enough stock');
            return;
        }
    } else {
        cart.push({
            id: product.id,
            name: product.name,
            strength: product.strength,
            price: parseFloat(product.sell_price),
            cost: parseFloat(product.cost_price),
            qty: 1,
            max: product.quantity_in_stock,
            is_subsidized: product.is_subsidized,
            subsidy_percentage: parseFloat(product.subsidy_percentage || 0),
            is_controlled: product.is_controlled == 1 ? 1 : 0
        });
    }
    renderCart();
}

function removeFromCart(index) {
    cart.splice(index, 1);
    renderCart();
}

function updateQty(index, qty) {
    qty = parseInt(qty);
    if (qty <= 0) { removeFromCart(index); return; }
    if (qty > cart[index].max) { alert('Not enough stock'); return; }
    cart[index].qty = qty;
    renderCart();
}

function clearCart() {
    cart = [];
    renderCart();
}

function renderCart() {
    if (cart.length === 0) {
        $('#cartItems').html('<div class="text-center text-muted py-5" id="cartEmpty"><i class="bi bi-cart display-4"></i><p class="mt-2">Cart is empty</p></div>');
        $('#btnCompleteSale').prop('disabled', true);
    } else {
        var html = '';
        cart.forEach(function(item, i) {
            html += '<div class="border-bottom p-2">';
            html += '<div class="d-flex justify-content-between">';
            html += '<strong class="small">' + escHtml(item.name) + '</strong>';
            html += '<button class="btn btn-sm text-danger p-0" onclick="removeFromCart(' + i + ')"><i class="bi bi-x"></i></button>';
            html += '</div>';
            html += '<div class="d-flex justify-content-between align-items-center mt-1">';
            html += '<div class="input-group input-group-sm" style="width:100px">';
            html += '<button class="btn btn-outline-secondary" onclick="updateQty(' + i + ',' + (item.qty - 1) + ')">-</button>';
            html += '<input type="number" class="form-control text-center" value="' + item.qty + '" onchange="updateQty(' + i + ',this.value)" min="1" max="' + item.max + '">';
            html += '<button class="btn btn-outline-secondary" onclick="updateQty(' + i + ',' + (item.qty + 1) + ')">+</button>';
            html += '</div>';
            html += '<span class="fw-semibold">$' + (item.price * item.qty).toFixed(2) + '</span>';
            html += '</div></div>';
        });
        $('#cartItems').html(html);
        $('#btnCompleteSale').prop('disabled', false);
    }
    updateTotals();
}

function updateTotals() {
    var subtotal = 0;
    cart.forEach(function(item) { subtotal += item.price * item.qty; });

    var discountVal = parseFloat($('#discountValue').val()) || 0;
    var discountType = $('#discountType').val();
    var discount = discountType === 'percentage' ? (subtotal * discountVal / 100) : discountVal;
    if (discount > subtotal) discount = subtotal;

    var total = subtotal - discount;
    var totalLBP = total * exchangeRate;

    $('#cartSubtotal').text('$' + subtotal.toFixed(2));
    $('#cartTotal').text('$' + total.toFixed(2));
    $('#cartTotalLBP').text(formatNumber(totalLBP, 0) + ' L.L.');
}

function editRate() {
    $('#rateInput').val(exchangeRate);
    $('#rateDisplay').addClass('d-none');
    $('#rateEdit').removeClass('d-none');
    $('#rateInput').focus().select();
}
function cancelRate() {
    $('#rateDisplay').removeClass('d-none');
    $('#rateEdit').addClass('d-none');
}
function saveRate() {
    var newRate = parseInt($('#rateInput').val());
    if (!newRate || newRate < 1000) { alert('Invalid rate'); return; }
    $.post(window.location.href, {ajax: 'update_rate', rate: newRate}, function(r) {
        if (r.ok) {
            exchangeRate = newRate;
            $('#rateBadge').html('1 USD = ' + formatNumber(newRate, 0) + ' L.L. <i class="bi bi-pencil ms-1" style="font-size:.65rem"></i>');
            updateTotals();
            cancelRate();
        }
    }, 'json');
}
$('#rateInput').on('keydown', function(e) {
    if (e.key === 'Enter') saveRate();
    if (e.key === 'Escape') cancelRate();
});

function completeSale() {
    if (cart.length === 0) return;
    if (!confirm(LANG.confirm_sale)) return;

    var data = {
        ajax: 'complete_sale',
        items: cart,
        customer_id: $('#customerSelect').val() || null,
        discount_amount: parseFloat($('#discountValue').val()) || 0,
        discount_type: $('#discountType').val(),
        payment_method: $('#paymentMethod').val(),
        currency: $('#currency').val(),
        prescription_number: $('#prescriptionNumber').val(),
        exchange_rate: exchangeRate
    };

    if (!navigator.onLine) {
        // Queue locally and confirm to user
        var queueFn = typeof PharmDB !== 'undefined' ? PharmDB.queueSale(data) : Promise.resolve();
        queueFn.then(function() {
            cart = [];
            renderCart();
            alert(LANG.sale_complete + '\n(Offline — will sync when connected)');
        });
        return;
    }

    $.post(window.location.href, JSON.stringify(data), function(resp) {
        if (resp.success) {
            // Snapshot cart before clearing for WhatsApp message and controlled check
            var cartSnapshot = cart.slice();
            var completedSaleId = resp.sale_id;
            cart = [];
            renderCart();

            // Show WhatsApp share button with the snapshot
            showWaButton(resp, cartSnapshot);
            loadProducts();
            window.open('receipt.php?id=' + resp.sale_id, '_blank', 'width=400,height=600');

            // Check for controlled substances — show log modal before alerting
            var controlledItems = cartSnapshot.filter(function(i){ return i.is_controlled == 1; });
            if (controlledItems.length > 0) {
                showControlledModal(completedSaleId, controlledItems, resp.invoice_number);
            } else {
                alert(LANG.sale_complete + ' ' + resp.invoice_number);
            }
        } else {
            alert('Error: ' + (resp.error || 'Unknown error'));
        }
    }, 'json').fail(function() {
        // Network failed even though navigator.onLine was true — queue it
        if (typeof PharmDB !== 'undefined') {
            PharmDB.queueSale(data).then(function() {
                cart = [];
                renderCart();
                alert(LANG.sale_complete + '\n(Queued — will sync when connected)');
            });
        }
    });
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function showWaButton(resp, cartSnapshot) {
    // Build a compact plain-text receipt message for WhatsApp
    var pharmName = <?= json_encode(getSetting('pharmacy_name', 'My Pharmacy')) ?>;
    var lines = [];
    lines.push(pharmName);
    lines.push('Invoice: ' + (resp.invoice_number || ''));
    lines.push('Date: ' + new Date().toISOString().slice(0,16).replace('T',' '));
    lines.push('----------------------------');
    cartSnapshot.forEach(function(item) {
        var label = item.name + (item.strength ? ' ' + item.strength : '');
        lines.push(label);
        lines.push('  ' + item.qty + ' x $' + item.price.toFixed(2) + ' = $' + (item.price * item.qty).toFixed(2));
    });
    lines.push('----------------------------');
    var total = parseFloat(resp.total_amount || 0);
    var rate  = parseFloat(resp.exchange_rate || exchangeRate);
    lines.push('TOTAL: $' + total.toFixed(2));
    lines.push('LBP: ' + Math.round(total * rate).toLocaleString() + ' L.L.');
    var waText = lines.join('\n');
    var waUrl  = 'https://wa.me/?text=' + encodeURIComponent(waText);
    $('#btnWhatsApp').attr('href', waUrl).removeClass('d-none');
}

if (typeof formatNumber === 'undefined') {
    function formatNumber(n, decimals) {
        return parseFloat(n).toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }
}

var searchTimer;
$('#posSearch').on('input', function() {
    clearTimeout(searchTimer);
    var v = $(this).val();
    searchTimer = setTimeout(function() { loadProducts(v, $('#posCategoryFilter').val()); }, 300);
});

$('#posCategoryFilter').on('change', function() {
    loadProducts($('#posSearch').val(), $(this).val());
});

loadProducts();

// POS Barcode Scanner
var posScannerStream = null;
var posScannerIv = null;
var posScannerDetector = null;

$('#btnPosScanner').on('click', function() {
    var container = $('#posScannerContainer');
    if (!container.hasClass('d-none')) { stopPosScanner(); return; }
    container.removeClass('d-none');
    $('#posScannerManual').addClass('d-none');

    var video = document.getElementById('posScannerVideo');
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } } })
        .then(function(stream) {
            posScannerStream = stream;
            video.srcObject = stream;
            video.play();

            if (typeof BarcodeDetector !== 'undefined') {
                BarcodeDetector.getSupportedFormats().then(function(supported) {
                    var want = ['ean_13','ean_8','code_128','upc_a','upc_e','code_39','qr_code','data_matrix','itf'];
                    var formats = want.filter(function(f){ return supported.indexOf(f) !== -1; });
                    if (!formats.length) formats = supported;
                    posScannerDetector = new BarcodeDetector({ formats: formats });
                    startDetectionLoop(video);
                }).catch(function() {
                    posScannerDetector = new BarcodeDetector({ formats: ['ean_13','ean_8','code_128','upc_a'] });
                    startDetectionLoop(video);
                });
            } else {
                // BarcodeDetector not available - show manual fallback
                setTimeout(function() { $('#posScannerManual').removeClass('d-none'); }, 800);
            }
        })
        .catch(function(err) {
            alert('Camera access denied or unavailable: ' + err.message);
            stopPosScanner();
        });
});

function startDetectionLoop(video) {
    var missCount = 0;
    posScannerIv = setInterval(function() {
        if (!posScannerStream || !posScannerDetector) { clearInterval(posScannerIv); return; }
        if (video.readyState < 2) return; // not loaded yet
        posScannerDetector.detect(video).then(function(barcodes) {
            if (barcodes.length > 0) {
                clearInterval(posScannerIv);
                onPosBarcodeFound(barcodes[0].rawValue);
            } else {
                missCount++;
                if (missCount > 30) { // ~9 seconds with no result
                    $('#posScannerManual').removeClass('d-none');
                }
            }
        }).catch(function() {});
    }, 300);
}

$('#btnPosStopScanner').on('click', stopPosScanner);

$('#btnPosCapture').on('click', function() {
    var video = document.getElementById('posScannerVideo');
    var canvas = document.getElementById('posScannerCanvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);

    if (posScannerDetector) {
        posScannerDetector.detect(canvas).then(function(barcodes) {
            if (barcodes.length > 0) {
                onPosBarcodeFound(barcodes[0].rawValue);
            } else {
                $('#posScannerManual').removeClass('d-none');
                $('#posScannerManualInput').focus();
            }
        }).catch(function() {
            $('#posScannerManual').removeClass('d-none');
        });
    } else {
        $('#posScannerManual').removeClass('d-none');
        $('#posScannerManualInput').focus();
    }
});

$('#btnPosScannerManualOk').on('click', function() {
    var code = $('#posScannerManualInput').val().trim();
    if (code) onPosBarcodeFound(code);
});

$('#posScannerManualInput').on('keypress', function(e) {
    if (e.key === 'Enter') { var code = $(this).val().trim(); if (code) onPosBarcodeFound(code); }
});

function stopPosScanner() {
    if (posScannerIv) { clearInterval(posScannerIv); posScannerIv = null; }
    if (posScannerStream) { posScannerStream.getTracks().forEach(function(t){t.stop();}); posScannerStream = null; }
    $('#posScannerContainer').addClass('d-none');
    $('#posScannerManual').addClass('d-none');
    $('#posScannerManualInput').val('');
}

function onPosBarcodeFound(code) {
    stopPosScanner();
    $('#posSearch').val(code);
    loadProducts(code, $('#posCategoryFilter').val());
    setTimeout(function() {
        if (products.length === 1) addToCart(products[0].id);
    }, 500);
}

// Keyboard barcode scanner support (handles USB/BT scanners emitting rapid keystrokes)
var barcodeBuffer = '';
var barcodeTimer = null;
var barcodeLastTime = 0;
$(document).on('keypress', function(e) {
    if ($('input:focus, textarea:focus, select:focus').length > 0 && !$('#posSearch').is(':focus')) return;
    var now = Date.now();
    var gap = now - barcodeLastTime;
    barcodeLastTime = now;
    // Scanner sends chars very fast (< 50ms apart). Reset if gap is too long.
    if (gap > 80 && barcodeBuffer.length > 0) { barcodeBuffer = ''; }
    if (e.key === 'Enter' && barcodeBuffer.length >= 4) {
        e.preventDefault();
        var code = barcodeBuffer;
        barcodeBuffer = '';
        $('#posSearch').val(code);
        loadProducts(code, '');
        setTimeout(function() {
            if (products.length === 1) addToCart(products[0].id);
        }, 500);
        return;
    }
    if (e.key.length === 1) {
        barcodeBuffer += e.key;
        clearTimeout(barcodeTimer);
        barcodeTimer = setTimeout(function() { barcodeBuffer = ''; }, 200);
    }
});

// ── Controlled Substance Post-Sale Modal ─────────────────────────────────────
var _csCompletedSaleId = null;
var _csInvoiceNumber = '';
var _csControlledItems = [];

function showControlledModal(saleId, controlledItems, invoiceNumber) {
    _csCompletedSaleId = saleId;
    _csInvoiceNumber   = invoiceNumber || '';
    _csControlledItems = controlledItems;

    // Populate the medicine list in the modal
    var html = '';
    controlledItems.forEach(function(item) {
        html += '<div class="mb-2 p-2 border rounded bg-warning bg-opacity-10">' +
            '<strong><i class="bi bi-shield-lock text-danger me-1"></i>' + escHtml(item.name) + '</strong>' +
            (item.strength ? ' <small class="text-muted">(' + escHtml(item.strength) + ')</small>' : '') +
            ' &times; <strong>' + item.qty + '</strong>' +
            '</div>';
    });
    $('#csItemsList').html(html);
    $('#csModalInvoice').text(invoiceNumber || '');

    // Reset form fields
    $('#csForm')[0].reset();

    var modal = new bootstrap.Modal(document.getElementById('modalControlled'), { backdrop: 'static' });
    modal.show();
}

$('#csBtnSave').on('click', function() {
    var patientName = $('#csPatientName').val().trim();
    if (!patientName) { alert('Patient name is required.'); return; }

    var payload = {
        ajax: 'log_controlled',
        sale_id: _csCompletedSaleId,
        items: _csControlledItems.map(function(i){ return {id: i.id, qty: i.qty}; }),
        patient_name: patientName,
        patient_id_number: $('#csPatientId').val().trim(),
        patient_dob: $('#csPatientDob').val(),
        prescriber_name: $('#csPrescriber').val().trim(),
        prescriber_license: $('#csPrescriberLicense').val().trim(),
        prescription_number: $('#csPrescriptionNumber').val().trim(),
        notes: $('#csNotes').val().trim()
    };

    $.post(window.location.href, JSON.stringify(payload), function(r) {
        if (r.success) {
            bootstrap.Modal.getInstance(document.getElementById('modalControlled')).hide();
            alert('Sale complete & controlled substance register updated.\n' + _csInvoiceNumber);
        } else {
            alert('Controlled log error: ' + (r.error || 'Unknown'));
        }
    }, 'json').fail(function() {
        // Log failed — still dismiss modal so cashier isn't stuck
        bootstrap.Modal.getInstance(document.getElementById('modalControlled')).hide();
        alert('Sale complete but controlled log could not be saved. Add manually in MoPH > Controlled Register.\n' + _csInvoiceNumber);
    });
});

$('#csBtnSkip').on('click', function() {
    alert('Sale complete.\n' + _csInvoiceNumber + '\n\nReminder: Controlled substance log was skipped. Add manually if required.');
});
</script>
SCRIPT;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'update_rate') {
    ob_clean();
    header('Content-Type: application/json');
    requireLogin();
    $rate = intval($_POST['rate'] ?? 0);
    if ($rate >= 1000) {
        updateSetting('exchange_rate', $rate);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    // ── Controlled Substance Log endpoint ──────────────────────────────────
    if (isset($input['ajax']) && $input['ajax'] === 'log_controlled') {
        ob_clean();
        header('Content-Type: application/json');
        try {
            $saleId       = intval($input['sale_id'] ?? 0);
            $patientName  = trim($input['patient_name'] ?? '');
            $patientId    = trim($input['patient_id_number'] ?? '');
            $patientDob   = $input['patient_dob'] ?: null;
            $prescriber   = trim($input['prescriber_name'] ?? '');
            $prescrLic    = trim($input['prescriber_license'] ?? '');
            $rxNum        = trim($input['prescription_number'] ?? '');
            $notes        = trim($input['notes'] ?? '');
            $dispensedBy  = intval($_SESSION['user_id'] ?? 0);

            if (!$patientName) throw new Exception('Patient name is required');

            $stmt = $db->prepare("
                INSERT INTO controlled_substance_log
                    (medicine_id, sale_id, dispensed_qty, patient_name, patient_id_number,
                     patient_dob, prescriber_name, prescriber_license, prescription_number,
                     dispensed_by, dispensed_at, notes, transaction_type, quantity, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),?,'dispensed',?,?)
            ");

            foreach (($input['items'] ?? []) as $item) {
                $medId = intval($item['id']);
                $qty   = floatval($item['qty']);
                $stmt->execute([
                    $medId, $saleId, $qty, $patientName, $patientId ?: null,
                    $patientDob, $prescriber ?: null, $prescrLic ?: null, $rxNum ?: null,
                    $dispensedBy, $notes ?: null,
                    intval($qty), $dispensedBy
                ]);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if (isset($input['ajax']) && $input['ajax'] === 'complete_sale') {
        ob_clean();
        header('Content-Type: application/json');
        try {
            $db->beginTransaction();
            $invoiceNumber = generateInvoiceNumber();
            $items = $input['items'];
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += $item['price'] * $item['qty'];
            }
            $discountAmount = floatval($input['discount_amount'] ?? 0);
            $discountType = $input['discount_type'] ?? 'fixed';
            $actualDiscount = $discountType === 'percentage' ? ($subtotal * $discountAmount / 100) : $discountAmount;
            $total = $subtotal - $actualDiscount;

            $stmt = $db->prepare("INSERT INTO sales (invoice_number, customer_id, subtotal, discount_amount, discount_type, total_amount, amount_paid, payment_method, currency, exchange_rate, prescription_number, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $invoiceNumber,
                $input['customer_id'] ?: null,
                $subtotal,
                $actualDiscount,
                $discountType,
                $total,
                $total,
                $input['payment_method'] ?? 'cash',
                $input['currency'] ?? 'USD',
                $input['exchange_rate'] ?? 89500,
                $input['prescription_number'] ?: null,
                $_SESSION['user_id'] ?? null,
            ]);
            $saleId = $db->lastInsertId();

            $itemStmt = $db->prepare("INSERT INTO sale_items (sale_id, medicine_id, quantity, unit_price, cost_price, total_price, is_subsidized, subsidy_amount) VALUES (?,?,?,?,?,?,?,?)");
            foreach ($items as $item) {
                $itemTotal = $item['price'] * $item['qty'];
                $subsidyAmount = $item['is_subsidized'] ? ($itemTotal * $item['subsidy_percentage'] / 100) : 0;
                $itemStmt->execute([$saleId, $item['id'], $item['qty'], $item['price'], $item['cost'], $itemTotal, $item['is_subsidized'] ? 1 : 0, $subsidyAmount]);
                updateStock($item['id'], -$item['qty']);
                addStockMovement($item['id'], 'out', $item['qty'], "Sale: $invoiceNumber", 'sale', $saleId);
            }

            $db->commit();
            echo json_encode(['success' => true, 'sale_id' => $saleId, 'invoice_number' => $invoiceNumber, 'total_amount' => $total, 'exchange_rate' => $input['exchange_rate'] ?? 89500]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// ── Controlled Substance Register Modal HTML ──────────────────────────────────
?>
<!-- Controlled Substance Log Modal (shown after sale with controlled items) -->
<div class="modal fade" id="modalControlled" tabindex="-1" aria-labelledby="csModalLabel">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="csModalLabel">
                    <i class="bi bi-shield-lock me-2"></i>Controlled Substance Log Required
                </h5>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger py-2">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    Lebanese law requires patient &amp; prescriber details for every controlled substance dispensing.
                    Invoice: <strong id="csModalInvoice"></strong>
                </div>

                <div id="csItemsList" class="mb-3"></div>

                <form id="csForm">
                    <div class="row g-3">
                        <div class="col-12"><h6 class="text-secondary border-bottom pb-1">Patient Details</h6></div>
                        <div class="col-md-6">
                            <label class="form-label">Patient Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="csPatientName" placeholder="Full name" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">National ID / Passport</label>
                            <input type="text" class="form-control" id="csPatientId" placeholder="ID number">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" id="csPatientDob">
                        </div>

                        <div class="col-12"><h6 class="text-secondary border-bottom pb-1">Prescription Details</h6></div>
                        <div class="col-md-4">
                            <label class="form-label">Prescriber Name</label>
                            <input type="text" class="form-control" id="csPrescriber" placeholder="Dr. Name">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Prescriber License #</label>
                            <input type="text" class="form-control" id="csPrescriberLicense" placeholder="License #">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Prescription #</label>
                            <input type="text" class="form-control" id="csPrescriptionNumber" placeholder="Rx number">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" id="csNotes" rows="2" placeholder="Any relevant notes..."></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="csBtnSkip" data-bs-dismiss="modal">
                    Skip (log manually later)
                </button>
                <button type="button" class="btn btn-danger" id="csBtnSave">
                    <i class="bi bi-journal-check me-1"></i>Save to Register
                </button>
            </div>
        </div>
    </div>
</div>
<?php

require_once __DIR__ . '/../../includes/footer.php';
?>
