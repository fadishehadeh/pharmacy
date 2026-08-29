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
$yesterdaySales = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE DATE(sale_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND status = 'completed'")->fetchColumn();

$pendingClaims = $db->query("SELECT COUNT(*) FROM insurance_claims WHERE status IN ('pending','submitted')")->fetchColumn();
$inventoryValue = $db->query("SELECT COALESCE(SUM(quantity_in_stock * cost_price), 0) FROM medicines WHERE is_active = 1")->fetchColumn();

$patientCount = 0;
$interactionCount = 0;
$needReorder = 0;
$controlledCount = 0;
try {
    $patientCount = $db->query("SELECT COUNT(*) FROM patient_profiles")->fetchColumn();
} catch (Exception $e) {}
try {
    $interactionCount = $db->query("SELECT COUNT(*) FROM drug_interactions")->fetchColumn();
} catch (Exception $e) {}
try {
    $needReorder = $db->query("SELECT COUNT(*) FROM medicines WHERE is_active = 1 AND quantity_in_stock <= min_stock_level")->fetchColumn();
} catch (Exception $e) {}
$controlledCount = $db->query("SELECT COUNT(*) FROM medicines WHERE is_controlled = 1 AND is_active = 1")->fetchColumn();

$pendingOrders = $db->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('draft','ordered','partial')")->fetchColumn();

$todayProfit = $db->query("SELECT COALESCE(SUM(si.total_price - (si.cost_price * si.quantity)), 0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE DATE(s.sale_date) = CURDATE() AND s.status = 'completed'")->fetchColumn();

$recentSales = $db->query("SELECT s.*, c.name as customer_name FROM sales s LEFT JOIN customers c ON s.customer_id = c.id ORDER BY s.sale_date DESC LIMIT 10")->fetchAll();
$expiringMeds = $db->query("SELECT m.*, c.name as category_name FROM medicines m LEFT JOIN categories c ON m.category_id = c.id WHERE m.expiry_date IS NOT NULL AND m.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND m.expiry_date >= CURDATE() AND m.is_active = 1 ORDER BY m.expiry_date ASC LIMIT 10")->fetchAll();
$lowStockMeds = $db->query("SELECT m.*, c.name as category_name FROM medicines m LEFT JOIN categories c ON m.category_id = c.id WHERE m.is_active = 1 AND m.quantity_in_stock <= m.min_stock_level ORDER BY m.quantity_in_stock ASC LIMIT 10")->fetchAll();

$last7days = $db->query("SELECT DATE(sale_date) as day, COALESCE(SUM(total_amount),0) as total FROM sales WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status = 'completed' GROUP BY DATE(sale_date) ORDER BY day")->fetchAll();

$topProducts = $db->query("SELECT m.name, SUM(si.quantity) as qty_sold, SUM(si.total_price) as revenue FROM sale_items si JOIN medicines m ON si.medicine_id = m.id JOIN sales s ON si.sale_id = s.id WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND s.status = 'completed' GROUP BY m.id ORDER BY qty_sold DESC LIMIT 5")->fetchAll();

$salesByPayment = $db->query("SELECT payment_method, COUNT(*) as cnt, SUM(total_amount) as total FROM sales WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE()) AND status = 'completed' GROUP BY payment_method")->fetchAll();
?>

<div class="row g-3 mb-3">
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Total Medicines</div>
                    <div class="stat-value"><?= number_format($totalMedicines) ?></div>
                    <small class="text-muted"><?= $controlledCount ?> controlled</small>
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
                    <small class="text-muted"><?= $todayTransactions ?> transactions | Profit: <?= formatCurrency($todayProfit) ?></small>
                </div>
                <i class="bi bi-cash-stack stat-icon text-success"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Low Stock</div>
                    <div class="stat-value"><?= number_format($needReorder) ?></div>
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
                    <small class="text-muted"><?= $expiredCount ?> already expired</small>
                </div>
                <i class="bi bi-clock stat-icon text-danger"></i>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
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
                    <div class="stat-label">Pending</div>
                    <div class="stat-value"><?= $pendingClaims ?> / <?= $pendingOrders ?></div>
                    <small class="text-muted">Claims / Orders</small>
                </div>
                <i class="bi bi-shield-plus stat-icon text-warning"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Patients</div>
                    <div class="stat-value"><?= number_format($patientCount) ?></div>
                    <small class="text-muted"><?= $interactionCount ?> interactions tracked</small>
                </div>
                <i class="bi bi-person-heart stat-icon text-primary"></i>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="card p-3">
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= BASE_URL ?>/modules/pos/index.php" class="btn btn-primary"><i class="bi bi-cart3 me-1"></i>Open POS</a>
                <a href="<?= BASE_URL ?>/modules/inventory/add.php" class="btn btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>Add Medicine</a>
                <a href="<?= BASE_URL ?>/modules/inventory/reorder.php" class="btn btn-outline-warning"><i class="bi bi-arrow-repeat me-1"></i>Smart Reorder</a>
                <a href="<?= BASE_URL ?>/modules/patients/index.php" class="btn btn-outline-info"><i class="bi bi-person-heart me-1"></i>Patients</a>
                <a href="<?= BASE_URL ?>/modules/interactions/index.php" class="btn btn-outline-danger"><i class="bi bi-shield-exclamation me-1"></i>Drug Interactions</a>
                <a href="<?= BASE_URL ?>/modules/prescriptions/index.php" class="btn btn-outline-success"><i class="bi bi-file-medical me-1"></i>Prescriptions</a>
                <a href="<?= BASE_URL ?>/modules/reports/daily.php" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-bar-graph me-1"></i>Daily Report</a>
                <a href="<?= BASE_URL ?>/modules/notifications/index.php" class="btn btn-outline-dark position-relative"><i class="bi bi-bell me-1"></i>Alerts
                    <?php if ($needReorder + $expiringCount + $expiredCount > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $needReorder + $expiringCount + $expiredCount ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-3 mb-3">
            <h6 class="mb-3"><i class="bi bi-graph-up me-2"></i>Sales - Last 7 Days</h6>
            <canvas id="salesChart" height="180"></canvas>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="card p-3">
                    <h6 class="mb-3"><i class="bi bi-trophy me-2"></i>Top Products (30d)</h6>
                    <?php if (empty($topProducts)): ?>
                        <p class="text-muted text-center py-2">No sales data</p>
                    <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($topProducts as $i => $tp): ?>
                        <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge bg-primary me-1"><?= $i + 1 ?></span>
                                <span class="small"><?= sanitize($tp['name']) ?></span>
                            </div>
                            <div class="text-end">
                                <strong class="small"><?= $tp['qty_sold'] ?> sold</strong><br>
                                <small class="text-muted"><?= formatCurrency($tp['revenue']) ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card p-3">
                    <h6 class="mb-3"><i class="bi bi-credit-card me-2"></i>Payments (This Month)</h6>
                    <?php if (empty($salesByPayment)): ?>
                        <p class="text-muted text-center py-2">No sales this month</p>
                    <?php else: ?>
                    <canvas id="paymentChart" height="200"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-receipt me-2"></i>Recent Sales</h6>
            <?php if (empty($recentSales)): ?>
                <p class="text-muted text-center py-3">No sales yet</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>Invoice</th><th>Customer</th><th>Date</th><th>Payment</th><th class="text-end">Total</th><th>Status</th></tr>
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

    <div class="col-lg-4">
        <?php if ($expiredCount > 0): ?>
        <div class="card p-3 mb-3 border-danger">
            <h6 class="text-danger"><i class="bi bi-exclamation-octagon me-2"></i>Expired Medicines (<?= $expiredCount ?>)</h6>
            <p class="small text-muted mb-2">These medicines have passed their expiry date and should be removed from shelves.</p>
            <a href="<?= BASE_URL ?>/modules/inventory/alerts.php" class="btn btn-sm btn-outline-danger w-100">View All Expired</a>
        </div>
        <?php endif; ?>

        <div class="card p-3 mb-3">
            <h6><i class="bi bi-clock me-2"></i>Expiring Soon (<?= count($expiringMeds) ?>)</h6>
            <?php if (empty($expiringMeds)): ?>
                <p class="text-muted text-center py-2">No medicines expiring soon</p>
            <?php else: ?>
                <div class="list-group list-group-flush" style="max-height:250px;overflow-y:auto">
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

        <div class="card p-3 mb-3">
            <h6><i class="bi bi-arrow-down-circle me-2"></i>Low Stock (<?= count($lowStockMeds) ?>)</h6>
            <?php if (empty($lowStockMeds)): ?>
                <p class="text-muted text-center py-2">All medicines well-stocked</p>
            <?php else: ?>
                <div class="list-group list-group-flush" style="max-height:250px;overflow-y:auto">
                    <?php foreach ($lowStockMeds as $med): ?>
                    <a href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $med['id'] ?>" class="list-group-item list-group-item-action p-2">
                        <div class="d-flex justify-content-between">
                            <strong class="small"><?= sanitize($med['name']) ?></strong>
                            <span class="badge bg-<?= $med['quantity_in_stock'] <= 0 ? 'danger' : 'warning' ?>"><?= $med['quantity_in_stock'] ?>/<?= $med['min_stock_level'] ?></span>
                        </div>
                        <small class="text-muted"><?= sanitize($med['category_name'] ?? '-') ?></small>
                    </a>
                    <?php endforeach; ?>
                </div>
                <a href="<?= BASE_URL ?>/modules/inventory/reorder.php" class="btn btn-sm btn-outline-warning w-100 mt-2">View Reorder Suggestions</a>
            <?php endif; ?>
        </div>

        <div class="card p-3">
            <h6><i class="bi bi-info-circle me-2"></i>Quick Info</h6>
            <div class="d-flex justify-content-between mb-1"><span class="small">Yesterday's Sales</span><strong class="small"><?= formatCurrency($yesterdaySales) ?></strong></div>
            <div class="d-flex justify-content-between mb-1"><span class="small">Pending PO</span><strong class="small"><?= $pendingOrders ?></strong></div>
            <div class="d-flex justify-content-between mb-1"><span class="small">Insurance Claims</span><strong class="small"><?= $pendingClaims ?></strong></div>
            <div class="d-flex justify-content-between"><span class="small">Drug Interactions DB</span><strong class="small"><?= $interactionCount ?></strong></div>
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

$paymentLabels = [];
$paymentData = [];
$paymentColors = ['cash' => '#10B981', 'card' => '#3B82F6', 'credit' => '#F59E0B', 'insurance' => '#8B5CF6'];
foreach ($salesByPayment as $sp) {
    $paymentLabels[] = ucfirst($sp['payment_method']);
    $paymentData[] = $sp['total'];
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
" . (!empty($salesByPayment) ? "
new Chart(document.getElementById('paymentChart'), {
    type: 'doughnut',
    data: {
        labels: " . json_encode($paymentLabels) . ",
        datasets: [{
            data: " . json_encode($paymentData) . ",
            backgroundColor: ['#10B981','#3B82F6','#F59E0B','#8B5CF6']
        }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
});" : "") . "
</script>";

require_once __DIR__ . '/includes/footer.php';
?>
