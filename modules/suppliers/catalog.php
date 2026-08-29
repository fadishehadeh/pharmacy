<?php
$pageTitle = 'Supplier Catalog';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) {
    flashMessage('Access denied', 'error');
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));

// Filters
$supplierId = intval($_GET['supplier'] ?? 0);
$categoryId = intval($_GET['category'] ?? 0);
$search = $_GET['search'] ?? '';
$view = $_GET['view'] ?? 'catalog';

// Load suppliers
$suppliers = $db->query("SELECT s.*,
    (SELECT COUNT(DISTINCT m.id) FROM medicines m WHERE m.supplier_id = s.id AND m.is_active = 1) as product_count,
    (SELECT COALESCE(AVG(m.cost_price), 0) FROM medicines m WHERE m.supplier_id = s.id AND m.is_active = 1) as avg_cost,
    (SELECT COALESCE(SUM(po.total), 0) FROM purchase_orders po WHERE po.supplier_id = s.id AND po.status != 'cancelled') as total_business
    FROM suppliers s WHERE s.is_active = 1 ORDER BY s.name")->fetchAll();

// Load categories
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

// Build product query
$where = ['m.is_active = 1'];
$params = [];

if ($supplierId) {
    $where[] = 'm.supplier_id = ?';
    $params[] = $supplierId;
}
if ($categoryId) {
    $where[] = 'm.category_id = ?';
    $params[] = $categoryId;
}
if ($search) {
    $where[] = '(m.name LIKE ? OR m.generic_name LIKE ? OR m.barcode LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = implode(' AND ', $where);

$products = $db->prepare("SELECT m.*,
    s.name as supplier_name,
    c.name as category_name,
    (SELECT MAX(po.order_date) FROM purchase_orders po
     JOIN purchase_order_items poi ON po.id = poi.purchase_order_id
     WHERE poi.medicine_id = m.id AND po.status != 'cancelled') as last_ordered,
    CASE WHEN m.sell_price > 0 AND m.cost_price > 0
         THEN ROUND((m.sell_price - m.cost_price) / m.sell_price * 100, 1)
         ELSE 0 END as margin_pct
    FROM medicines m
    LEFT JOIN suppliers s ON m.supplier_id = s.id
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE {$whereClause}
    ORDER BY s.name, m.name");
$products->execute($params);
$products = $products->fetchAll();

// Price comparison view: find medicines available from multiple suppliers
$comparisonData = [];
if ($view === 'compare') {
    $compStmt = $db->prepare("SELECT m.name as medicine_name, m.id as medicine_id,
        m.cost_price, m.sell_price, m.quantity_in_stock,
        s.name as supplier_name, s.id as supplier_id,
        CASE WHEN m.sell_price > 0 AND m.cost_price > 0
             THEN ROUND((m.sell_price - m.cost_price) / m.sell_price * 100, 1)
             ELSE 0 END as margin_pct
        FROM medicines m
        LEFT JOIN suppliers s ON m.supplier_id = s.id
        WHERE m.is_active = 1 AND m.name IN (
            SELECT m2.name FROM medicines m2
            WHERE m2.is_active = 1
            GROUP BY m2.name HAVING COUNT(DISTINCT m2.supplier_id) > 1
        )
        ORDER BY m.name, m.cost_price ASC");
    $compStmt->execute();
    $compRows = $compStmt->fetchAll();
    foreach ($compRows as $row) {
        $comparisonData[$row['medicine_name']][] = $row;
    }
}

// Stats
$totalProducts = count($products);
$avgMargin = $totalProducts > 0 ? round(array_sum(array_column($products, 'margin_pct')) / $totalProducts, 1) : 0;
$lowStockCount = 0;
foreach ($products as $p) {
    if ($p['quantity_in_stock'] <= ($p['min_stock_level'] ?? 10)) $lowStockCount++;
}
?>

<!-- Stats Cards -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card info">
            <div class="stat-label">Total Suppliers</div>
            <div class="stat-value"><?= count($suppliers) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card success">
            <div class="stat-label">Products Shown</div>
            <div class="stat-value"><?= number_format($totalProducts) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Avg Margin</div>
            <div class="stat-value"><?= $avgMargin ?>%</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card <?= $lowStockCount > 0 ? 'warning' : 'success' ?>">
            <div class="stat-label">Low Stock Items</div>
            <div class="stat-value"><?= $lowStockCount ?></div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small mb-1">Supplier</label>
            <select class="form-select form-select-sm" name="supplier">
                <option value="0">All Suppliers</option>
                <?php foreach ($suppliers as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $supplierId === intval($s['id']) ? 'selected' : '' ?>>
                    <?= sanitize($s['name']) ?> (<?= $s['product_count'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Category</label>
            <select class="form-select form-select-sm" name="category">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $categoryId === intval($cat['id']) ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="search" placeholder="Name, generic, barcode..." value="<?= sanitize($search) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">View</label>
            <select class="form-select form-select-sm" name="view">
                <option value="catalog" <?= $view === 'catalog' ? 'selected' : '' ?>>Catalog</option>
                <option value="compare" <?= $view === 'compare' ? 'selected' : '' ?>>Price Compare</option>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
        </div>
    </form>
</div>

<?php if ($view === 'compare' && !empty($comparisonData)): ?>
<!-- Price Comparison View -->
<div class="card mb-3">
    <div class="card-header">
        <h6 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>Price Comparison - Same Medicine Across Suppliers</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead>
                <tr>
                    <th>Medicine</th>
                    <th>Supplier</th>
                    <th class="text-end">Cost Price</th>
                    <th class="text-end">Sell Price</th>
                    <th class="text-end">Margin</th>
                    <th class="text-end">Stock</th>
                    <th>Best Deal</th>
                    <th class="no-print">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($comparisonData as $medName => $variants): ?>
                    <?php $bestPrice = min(array_column($variants, 'cost_price')); ?>
                    <?php foreach ($variants as $vi => $v): ?>
                    <tr class="<?= $vi === 0 ? 'table-light' : '' ?>">
                        <td><?= $vi === 0 ? '<strong>' . sanitize($medName) . '</strong>' : '' ?></td>
                        <td><?= sanitize($v['supplier_name'] ?? 'N/A') ?></td>
                        <td class="text-end <?= floatval($v['cost_price']) == $bestPrice ? 'text-success fw-bold' : '' ?>">
                            <?= formatCurrency($v['cost_price']) ?>
                        </td>
                        <td class="text-end"><?= formatCurrency($v['sell_price']) ?></td>
                        <td class="text-end">
                            <span class="badge bg-<?= $v['margin_pct'] >= 20 ? 'success' : ($v['margin_pct'] >= 10 ? 'warning' : 'danger') ?>"><?= $v['margin_pct'] ?>%</span>
                        </td>
                        <td class="text-end"><?= $v['quantity_in_stock'] ?></td>
                        <td>
                            <?php if (floatval($v['cost_price']) == $bestPrice): ?>
                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Best Price</span>
                            <?php else: ?>
                            <small class="text-muted">+<?= formatCurrency(floatval($v['cost_price']) - $bestPrice) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="no-print">
                            <a href="<?= BASE_URL ?>/modules/suppliers/orders.php?supplier=<?= $v['supplier_id'] ?>" class="btn btn-sm btn-outline-primary" title="Create order">
                                <i class="bi bi-cart-plus"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if (empty($comparisonData)): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">No medicines found from multiple suppliers</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- Catalog View -->
<div class="row g-3">
    <!-- Supplier Sidebar -->
    <div class="col-lg-3">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-building me-2"></i>Suppliers</h6></div>
            <div class="list-group list-group-flush" style="max-height:500px;overflow-y:auto">
                <a href="?supplier=0&category=<?= $categoryId ?>&search=<?= urlencode($search) ?>"
                   class="list-group-item list-group-item-action d-flex justify-content-between <?= $supplierId === 0 ? 'active' : '' ?>">
                    All Suppliers
                    <span class="badge bg-<?= $supplierId === 0 ? 'light text-dark' : 'primary' ?> rounded-pill">
                        <?= array_sum(array_column($suppliers, 'product_count')) ?>
                    </span>
                </a>
                <?php foreach ($suppliers as $s): ?>
                <a href="?supplier=<?= $s['id'] ?>&category=<?= $categoryId ?>&search=<?= urlencode($search) ?>"
                   class="list-group-item list-group-item-action d-flex justify-content-between <?= $supplierId === intval($s['id']) ? 'active' : '' ?>">
                    <div>
                        <strong><?= sanitize($s['name']) ?></strong><br>
                        <small class="text-muted">Avg: <?= formatCurrency($s['avg_cost']) ?> | Total: <?= formatCurrency($s['total_business']) ?></small>
                    </div>
                    <span class="badge bg-<?= $supplierId === intval($s['id']) ? 'light text-dark' : 'secondary' ?> rounded-pill align-self-center">
                        <?= $s['product_count'] ?>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Product Listing -->
    <div class="col-lg-9">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-box-seam me-2"></i>Products (<?= $totalProducts ?>)</h6>
                <button onclick="window.print()" class="btn btn-sm btn-outline-dark no-print"><i class="bi bi-printer me-1"></i>Print</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 data-table">
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Supplier</th>
                            <th>Category</th>
                            <th class="text-end">Cost</th>
                            <th class="text-end">Sell</th>
                            <th class="text-end">Margin</th>
                            <th class="text-end">Stock</th>
                            <th>Last Ordered</th>
                            <th class="no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                        <tr class="<?= $p['quantity_in_stock'] <= ($p['min_stock_level'] ?? 10) ? 'table-warning' : '' ?>">
                            <td>
                                <strong><?= sanitize($p['name']) ?></strong>
                                <?php if (!empty($p['generic_name'])): ?>
                                <br><small class="text-muted"><?= sanitize($p['generic_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><small><?= sanitize($p['supplier_name'] ?? 'N/A') ?></small></td>
                            <td><span class="badge bg-secondary"><?= sanitize($p['category_name'] ?? '-') ?></span></td>
                            <td class="text-end"><?= formatCurrency($p['cost_price']) ?></td>
                            <td class="text-end"><?= formatCurrency($p['sell_price']) ?></td>
                            <td class="text-end">
                                <span class="badge bg-<?= $p['margin_pct'] >= 20 ? 'success' : ($p['margin_pct'] >= 10 ? 'warning' : 'danger') ?>">
                                    <?= $p['margin_pct'] ?>%
                                </span>
                            </td>
                            <td class="text-end <?= $p['quantity_in_stock'] <= ($p['min_stock_level'] ?? 10) ? 'text-danger fw-bold' : '' ?>">
                                <?= $p['quantity_in_stock'] ?>
                            </td>
                            <td><small><?= $p['last_ordered'] ? formatDate($p['last_ordered'], 'M d, Y') : '-' ?></small></td>
                            <td class="no-print">
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $p['id'] ?>" class="btn btn-outline-secondary" title="View details"><i class="bi bi-eye"></i></a>
                                    <a href="<?= BASE_URL ?>/modules/suppliers/orders.php?supplier=<?= $p['supplier_id'] ?>" class="btn btn-outline-primary" title="Reorder"><i class="bi bi-cart-plus"></i></a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($products)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-3">No products found matching filters</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
