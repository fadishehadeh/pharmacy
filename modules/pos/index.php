<?php
$pageTitle = 'Point of Sale';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
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
                        <input type="text" class="form-control" id="posSearch" placeholder="Search medicine by name or scan barcode..." autofocus>
                    </div>
                </div>
                <div class="col-auto">
                    <select class="form-select" id="posCategoryFilter">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= sanitize($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
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
                    <h6 class="mb-0"><i class="bi bi-cart3 me-2"></i>Current Sale</h6>
                    <button class="btn btn-sm btn-outline-danger" onclick="clearCart()"><i class="bi bi-x-lg"></i> Clear</button>
                </div>
                <div class="mt-2">
                    <select class="form-select form-select-sm" id="customerSelect">
                        <option value="">Walk-in Customer</option>
                        <?php foreach ($customers as $cust): ?>
                        <option value="<?= $cust['id'] ?>" data-insurance="<?= $cust['insurance_provider_id'] ?>"><?= sanitize($cust['name']) ?> <?= $cust['phone'] ? "({$cust['phone']})" : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="pos-cart-items p-2" id="cartItems">
                <div class="text-center text-muted py-5" id="cartEmpty">
                    <i class="bi bi-cart display-4"></i>
                    <p class="mt-2">Cart is empty</p>
                </div>
            </div>

            <div class="pos-cart-footer">
                <div class="d-flex justify-content-between mb-1">
                    <span>Subtotal:</span>
                    <strong id="cartSubtotal">$0.00</strong>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span>Discount:</span>
                    <div class="d-flex gap-1 align-items-center">
                        <input type="number" class="form-control form-control-sm" id="discountValue" value="0" min="0" style="width:70px" onchange="updateTotals()">
                        <select class="form-select form-select-sm" id="discountType" style="width:60px" onchange="updateTotals()">
                            <option value="fixed">$</option>
                            <option value="percentage">%</option>
                        </select>
                    </div>
                </div>
                <div class="d-flex justify-content-between mb-2 border-top pt-2">
                    <strong class="fs-5">Total:</strong>
                    <strong class="fs-5 text-primary" id="cartTotal">$0.00</strong>
                </div>
                <div class="d-flex justify-content-between mb-2 small text-muted">
                    <span>In LBP:</span>
                    <span id="cartTotalLBP">0 L.L.</span>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label small">Payment</label>
                        <select class="form-select form-select-sm" id="paymentMethod">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="credit">Credit</option>
                            <option value="insurance">Insurance</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Currency</label>
                        <select class="form-select form-select-sm" id="currency">
                            <option value="USD">USD ($)</option>
                            <option value="LBP">LBP (L.L.)</option>
                        </select>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Prescription #</label>
                    <input type="text" class="form-control form-control-sm" id="prescriptionNumber" placeholder="Optional">
                </div>

                <button class="btn btn-primary w-100 py-2" onclick="completeSale()" id="btnCompleteSale" disabled>
                    <i class="bi bi-check-circle me-2"></i>Complete Sale
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<SCRIPT
<script>
var cart = [];
var exchangeRate = {$exchangeRate};
var products = [];

function loadProducts(search, category) {
    search = search || '';
    category = category || '';
    $.getJSON('{$_SERVER['PHP_SELF']}', {ajax: 'products', search: search, category: category}, function(data) {
        products = data;
        renderProducts();
    });
}

function renderProducts() {
    var html = '';
    products.forEach(function(p) {
        var outClass = p.quantity_in_stock <= 0 ? 'out-of-stock' : '';
        var stockBadge = p.quantity_in_stock <= 0 ? 'bg-danger' : (p.quantity_in_stock <= p.min_stock_level ? 'bg-warning' : 'bg-success');
        html += '<div class="col-6 col-xl-4"><div class="card pos-product-card p-2 ' + outClass + '" onclick="addToCart(' + p.id + ')">';
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
            subsidy_percentage: parseFloat(product.subsidy_percentage || 0)
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

function completeSale() {
    if (cart.length === 0) return;
    if (!confirm('Complete this sale?')) return;

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

    $.post(window.location.href, JSON.stringify(data), function(resp) {
        if (resp.success) {
            cart = [];
            renderCart();
            loadProducts();
            window.open('receipt.php?id=' + resp.sale_id, '_blank', 'width=400,height=600');
            alert('Sale completed! Invoice: ' + resp.invoice_number);
        } else {
            alert('Error: ' + (resp.error || 'Unknown error'));
        }
    }, 'json').fail(function() { alert('Network error'); });
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
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
</script>
SCRIPT;

if (isset($_GET['ajax']) && $_GET['ajax'] === 'products') {
    header('Content-Type: application/json');
    $search = $_GET['search'] ?? '';
    $category = $_GET['category'] ?? '';
    $where = ['is_active = 1'];
    $params = [];
    if ($search) {
        $where[] = '(name LIKE ? OR barcode LIKE ? OR generic_name LIKE ?)';
        $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
    }
    if ($category) { $where[] = 'category_id = ?'; $params[] = $category; }
    $sql = "SELECT id, name, strength, form, sell_price, cost_price, quantity_in_stock, min_stock_level, barcode, is_subsidized, subsidy_percentage FROM medicines WHERE " . implode(' AND ', $where) . " ORDER BY name LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (isset($input['ajax']) && $input['ajax'] === 'complete_sale') {
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
            echo json_encode(['success' => true, 'sale_id' => $saleId, 'invoice_number' => $invoiceNumber]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

require_once __DIR__ . '/../../includes/footer.php';
?>
