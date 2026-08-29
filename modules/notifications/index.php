<?php
$pageTitle = 'Alerts & Notifications';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$expiryDays = intval(getSetting('expiry_warning_days', 90));

$expired = $db->query("SELECT m.*, c.name as category_name FROM medicines m LEFT JOIN categories c ON m.category_id = c.id WHERE m.expiry_date IS NOT NULL AND m.expiry_date < CURDATE() AND m.is_active = 1 ORDER BY m.expiry_date ASC")->fetchAll();

$expiringSoon = $db->prepare("SELECT m.*, c.name as category_name FROM medicines m LEFT JOIN categories c ON m.category_id = c.id WHERE m.expiry_date IS NOT NULL AND m.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) AND m.is_active = 1 ORDER BY m.expiry_date ASC");
$expiringSoon->execute([$expiryDays]);
$expiringSoon = $expiringSoon->fetchAll();

$outOfStock = $db->query("SELECT m.*, c.name as category_name FROM medicines m LEFT JOIN categories c ON m.category_id = c.id WHERE m.quantity_in_stock = 0 AND m.is_active = 1 ORDER BY m.name")->fetchAll();

$lowStock = $db->query("SELECT m.*, c.name as category_name FROM medicines m LEFT JOIN categories c ON m.category_id = c.id WHERE m.quantity_in_stock > 0 AND m.quantity_in_stock <= m.min_stock_level AND m.is_active = 1 ORDER BY m.quantity_in_stock ASC")->fetchAll();

$overstock = $db->query("SELECT m.*, c.name as category_name FROM medicines m LEFT JOIN categories c ON m.category_id = c.id WHERE m.quantity_in_stock > m.max_stock_level AND m.is_active = 1 ORDER BY (m.quantity_in_stock - m.max_stock_level) DESC")->fetchAll();

$expiredRx = [];
try {
    $expiredRx = $db->query("SELECT p.*, c.name as customer_name FROM prescriptions p LEFT JOIN customers c ON p.customer_id = c.id WHERE p.status = 'active' AND p.expiry_date IS NOT NULL AND p.expiry_date < CURDATE() ORDER BY p.expiry_date ASC")->fetchAll();
    foreach ($expiredRx as $rx) {
        $db->prepare("UPDATE prescriptions SET status = 'expired' WHERE id = ?")->execute([$rx['id']]);
    }
} catch (Exception $e) {}

$pendingClaims = $db->query("SELECT ic.*, ip.name as provider_name, c.name as customer_name FROM insurance_claims ic JOIN insurance_providers ip ON ic.insurance_provider_id = ip.id LEFT JOIN customers c ON ic.customer_id = c.id WHERE ic.status IN ('pending','submitted') ORDER BY ic.claim_date ASC")->fetchAll();

$pendingOrders = $db->query("SELECT po.*, s.name as supplier_name FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.id WHERE po.status IN ('draft','ordered','partial') ORDER BY po.order_date ASC")->fetchAll();

$totalAlerts = count($expired) + count($expiringSoon) + count($outOfStock) + count($lowStock) + count($overstock) + count($pendingClaims) + count($pendingOrders) + count($expiredRx);

$criticalCount = count($expired) + count($outOfStock) + count($expiredRx);
$warningCount = count($expiringSoon) + count($lowStock) + count($overstock);
$infoCount = count($pendingClaims) + count($pendingOrders);
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card danger"><div class="stat-label">Critical</div><div class="stat-value"><?= $criticalCount ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card warning"><div class="stat-label">Warnings</div><div class="stat-value"><?= $warningCount ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card info"><div class="stat-label">Pending Actions</div><div class="stat-value"><?= $infoCount ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card"><div class="stat-label">Total Alerts</div><div class="stat-value"><?= $totalAlerts ?></div></div></div>
</div>

<?php if ($totalAlerts === 0): ?>
<div class="card p-5 text-center">
    <i class="bi bi-check-circle text-success" style="font-size:3rem"></i>
    <h5 class="mt-3">All Clear!</h5>
    <p class="text-muted">No alerts or notifications at this time.</p>
</div>
<?php else: ?>

<?php if (!empty($expired)): ?>
<div class="card mb-3 border-danger">
    <div class="card-header bg-danger text-white"><i class="bi bi-exclamation-octagon me-2"></i>Expired Medicines (<?= count($expired) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Medicine</th><th>Category</th><th>Expired On</th><th>Stock</th><th>Value at Risk</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($expired as $m): ?>
                <tr>
                    <td><strong><?= sanitize($m['name']) ?></strong></td>
                    <td><small><?= sanitize($m['category_name'] ?? '-') ?></small></td>
                    <td class="text-danger"><?= formatDate($m['expiry_date'], 'M d, Y') ?></td>
                    <td><?= $m['quantity_in_stock'] ?></td>
                    <td class="text-danger fw-bold"><?= formatCurrency($m['quantity_in_stock'] * $m['cost_price']) ?></td>
                    <td><a href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-danger">Manage</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($outOfStock)): ?>
<div class="card mb-3 border-danger">
    <div class="card-header bg-danger bg-opacity-75 text-white"><i class="bi bi-x-circle me-2"></i>Out of Stock (<?= count($outOfStock) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Medicine</th><th>Category</th><th>Min Level</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($outOfStock as $m): ?>
                <tr>
                    <td><strong><?= sanitize($m['name']) ?></strong></td>
                    <td><small><?= sanitize($m['category_name'] ?? '-') ?></small></td>
                    <td><?= $m['min_stock_level'] ?></td>
                    <td><a href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">Restock</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($expiringSoon)): ?>
<div class="card mb-3 border-warning">
    <div class="card-header bg-warning"><i class="bi bi-clock me-2"></i>Expiring Within <?= $expiryDays ?> Days (<?= count($expiringSoon) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Medicine</th><th>Category</th><th>Expiry Date</th><th>Days Left</th><th>Stock</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($expiringSoon as $m): ?>
                <?php $daysLeft = ceil((strtotime($m['expiry_date']) - time()) / 86400); ?>
                <tr>
                    <td><strong><?= sanitize($m['name']) ?></strong></td>
                    <td><small><?= sanitize($m['category_name'] ?? '-') ?></small></td>
                    <td><?= formatDate($m['expiry_date'], 'M d, Y') ?></td>
                    <td><span class="badge bg-<?= $daysLeft <= 30 ? 'danger' : 'warning' ?>"><?= $daysLeft ?> days</span></td>
                    <td><?= $m['quantity_in_stock'] ?></td>
                    <td><a href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-warning">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($lowStock)): ?>
<div class="card mb-3 border-warning">
    <div class="card-header bg-warning bg-opacity-75"><i class="bi bi-arrow-down-circle me-2"></i>Low Stock (<?= count($lowStock) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Medicine</th><th>Category</th><th>Stock</th><th>Min Level</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($lowStock as $m): ?>
                <tr>
                    <td><strong><?= sanitize($m['name']) ?></strong></td>
                    <td><small><?= sanitize($m['category_name'] ?? '-') ?></small></td>
                    <td><span class="badge bg-warning"><?= $m['quantity_in_stock'] ?></span></td>
                    <td><?= $m['min_stock_level'] ?></td>
                    <td><a href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">Restock</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($overstock)): ?>
<div class="card mb-3 border-info">
    <div class="card-header bg-info bg-opacity-25"><i class="bi bi-arrow-up-circle me-2"></i>Overstock (<?= count($overstock) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Medicine</th><th>Stock</th><th>Max Level</th><th>Over By</th></tr></thead>
            <tbody>
                <?php foreach ($overstock as $m): ?>
                <tr>
                    <td><strong><?= sanitize($m['name']) ?></strong></td>
                    <td><?= $m['quantity_in_stock'] ?></td>
                    <td><?= $m['max_stock_level'] ?></td>
                    <td><span class="text-danger fw-bold">+<?= $m['quantity_in_stock'] - $m['max_stock_level'] ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($pendingClaims)): ?>
<div class="card mb-3 border-info">
    <div class="card-header bg-info bg-opacity-25"><i class="bi bi-shield-plus me-2"></i>Pending Insurance Claims (<?= count($pendingClaims) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Claim #</th><th>Provider</th><th>Patient</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($pendingClaims as $cl): ?>
                <tr>
                    <td><strong><?= sanitize($cl['claim_number']) ?></strong></td>
                    <td><?= sanitize($cl['provider_name']) ?></td>
                    <td><?= sanitize($cl['customer_name'] ?? '-') ?></td>
                    <td><?= formatCurrency($cl['total_amount']) ?></td>
                    <td><span class="badge bg-warning"><?= ucfirst($cl['status']) ?></span></td>
                    <td><a href="<?= BASE_URL ?>/modules/insurance/claims.php" class="btn btn-sm btn-outline-info">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($pendingOrders)): ?>
<div class="card mb-3 border-info">
    <div class="card-header bg-info bg-opacity-25"><i class="bi bi-truck me-2"></i>Pending Purchase Orders (<?= count($pendingOrders) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>PO #</th><th>Supplier</th><th>Order Date</th><th>Total</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($pendingOrders as $po): ?>
                <tr>
                    <td><strong><?= sanitize($po['po_number']) ?></strong></td>
                    <td><?= sanitize($po['supplier_name']) ?></td>
                    <td><?= formatDate($po['order_date'], 'M d, Y') ?></td>
                    <td><?= formatCurrency($po['total']) ?></td>
                    <td><span class="badge bg-<?= $po['status'] === 'draft' ? 'secondary' : 'warning' ?>"><?= ucfirst($po['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
