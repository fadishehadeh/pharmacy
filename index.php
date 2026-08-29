<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
requireLogin();

$db = getDB();

$totalMedicines = $db->query("SELECT COUNT(*) FROM medicines WHERE is_active = 1")->fetchColumn();
$outOfStock = $db->query("SELECT COUNT(*) FROM medicines WHERE quantity_in_stock = 0 AND is_active = 1")->fetchColumn();
$lowStock = $db->query("SELECT COUNT(*) FROM medicines WHERE quantity_in_stock > 0 AND quantity_in_stock <= min_stock_level AND is_active = 1")->fetchColumn();
$expiringCount = $db->query("SELECT COUNT(*) FROM medicines WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND expiry_date >= CURDATE() AND is_active = 1")->fetchColumn();
$expiredCount = $db->query("SELECT COUNT(*) FROM medicines WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND is_active = 1")->fetchColumn();

$todaySales = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed'")->fetchColumn();
$todayTransactions = $db->query("SELECT COUNT(*) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed'")->fetchColumn();
$monthSales = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE()) AND status = 'completed'")->fetchColumn();

$pendingClaims = $db->query("SELECT COUNT(*) FROM insurance_claims WHERE status IN ('pending','submitted')")->fetchColumn();
$inventoryValue = $db->query("SELECT COALESCE(SUM(quantity_in_stock * cost_price), 0) FROM medicines WHERE is_active = 1")->fetchColumn();

$recentSales = $db->query("SELECT s.*, c.name as customer_name FROM sales s LEFT JOIN customers c ON s.customer_id = c.id ORDER BY s.sale_date DESC LIMIT 10")->fetchAll();
$expiringMeds = $db->query("SELECT m.*, c.name as category_name FROM medicines m LEFT JOIN categories c ON m.category_id = c.id WHERE m.expiry_date IS NOT NULL AND m.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND m.expiry_date >= CURDATE() AND m.is_active = 1 ORDER BY m.expiry_date ASC LIMIT 10")->fetchAll();

$last7days = $db->query("SELECT DATE(sale_date) as day, COALESCE(SUM(total_amount),0) as total FROM sales WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status = 'completed' GROUP BY DATE(sale_date) ORDER BY day")->fetchAll();
?>

<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Total Medicines</div>
                    <div class="stat-value"><?= number_format($totalMedicines) ?></div>
                </div>
                <i class="bi bi-capsule stat-icon text-primary"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card success">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Today's Sales</div>
                    <div class="stat-value"><?= formatCurrency($todaySales) ?></div>
                    <small class="text-muted"><?= $todayTransactions ?> transactions</small>
                </div>
                <i class="bi bi-cash-stack stat-icon text-success"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Low Stock Items</div>
                    <div class="stat-value"><?= number_format($lowStock) ?></div>
                    <small class="text-muted"><?= $outOfStock ?> out of stock</small>
                </div>
                <i class="bi bi-exclamation-triangle stat-icon text-warning"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card danger">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Expiring Soon</div>
                    <div class="stat-value"><?= number_format($expiringCount) ?></div>
                    <small class="text-muted"><?= $expiredCount ?> expired</small>
                </div>
                <i class="bi bi-clock stat-icon text-danger"></i>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Monthly Sales</div>
                    <div class="stat-value"><?= formatCurrency($monthSales) ?></div>
                </div>
                <i class="bi bi-graph-up stat-icon text-info"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Inventory Value</div>
                    <div class="stat-value"><?= formatCurrency($inventoryValue) ?></div>
                </div>
                <i class="bi bi-box-seam stat-icon text-primary"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Pending Claims</div>
                    <div class="stat-value"><?= number_format($pendingClaims) ?></div>
                </div>
                <i class="bi bi-shield-plus stat-icon text-warning"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card p-3">
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>/modules/pos/index.php" class="btn btn-primary flex-fill"><i class="bi bi-cart3 me-1"></i>POS</a>
                <a href="<?= BASE_URL ?>/modules/inventory/add.php" class="btn btn-outline-primary flex-fill"><i class="bi bi-plus me-1"></i>Add</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-graph-up me-2"></i>Sales - Last 7 Days</h6>
            <canvas id="salesChart" height="200"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-clock me-2"></i>Expiring Soon</h6>
            <?php if (empty($expiringMeds)): ?>
                <p class="text-muted text-center py-3">No medicines expiring soon</p>
            <?php else: ?>
                <div class="list-group list-group-flush" style="max-height:350px;overflow-y:auto">
                    <?php foreach ($expiringMeds as $med): ?>
                    <?php $daysLeft = (strtotime($med['expiry_date']) - time()) / 86400; ?>
                    <a href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $med['id'] ?>" class="list-group-item list-group-item-action p-2">
                        <div class="d-flex justify-content-between">
                            <strong class="small"><?= sanitize($med['name']) ?></strong>
                            <span class="badge bg-<?= $daysLeft <= 30 ? 'danger' : 'warning' ?>"><?= ceil($daysLeft) ?>d</span>
                        </div>
                        <small class="text-muted"><?= formatDate($med['expiry_date'], 'M d, Y') ?> | Qty: <?= $med['quantity_in_stock'] ?></small>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3 mt-2">
    <div class="col-12">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-receipt me-2"></i>Recent Sales</h6>
            <?php if (empty($recentSales)): ?>
                <p class="text-muted text-center py-3">No sales yet</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Payment</th>
                            <th class="text-end">Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentSales as $sale): ?>
                        <tr>
                            <td><a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $sale['id'] ?>"><?= sanitize($sale['invoice_number']) ?></a></td>
                            <td><?= sanitize($sale['customer_name'] ?? 'Walk-in') ?></td>
                            <td><?= formatDate($sale['sale_date'], 'M d, H:i') ?></td>
                            <td><span class="badge bg-secondary"><?= ucfirst($sale['payment_method']) ?></span></td>
                            <td class="text-end fw-semibold"><?= formatCurrency($sale['total_amount'], $sale['currency']) ?></td>
                            <td><span class="badge bg-<?= $sale['status'] === 'completed' ? 'success' : 'warning' ?>"><?= ucfirst($sale['status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$chartLabels = [];
$chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('D', strtotime($date));
    $found = false;
    foreach ($last7days as $row) {
        if ($row['day'] === $date) {
            $chartData[] = $row['total'];
            $found = true;
            break;
        }
    }
    if (!$found) $chartData[] = 0;
}

$extraScripts = "<script>
new Chart(document.getElementById('salesChart'), {
    type: 'bar',
    data: {
        labels: " . json_encode($chartLabels) . ",
        datasets: [{
            label: 'Sales (USD)',
            data: " . json_encode($chartData) . ",
            backgroundColor: 'rgba(37, 99, 235, 0.2)',
            borderColor: 'rgba(37, 99, 235, 1)',
            borderWidth: 2,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});
</script>";

require_once __DIR__ . '/includes/footer.php';
?>
