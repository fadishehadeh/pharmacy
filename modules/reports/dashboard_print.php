<?php
$pageTitle = 'Dashboard Summary';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');
$pharmacistName = getSetting('pharmacist_name', '');
$exchangeRate = floatval(getSetting('exchange_rate', '89500'));
$operator = currentUser()['full_name'] ?? 'N/A';
$today = date('Y-m-d');
$monthStart = date('Y-m-01');

// Today's sales summary
$todaySales = $db->prepare("SELECT
    COUNT(s.id) as sale_count,
    COALESCE(SUM(s.total_amount), 0) as revenue,
    COALESCE(SUM(si_agg.profit), 0) as profit
    FROM sales s
    LEFT JOIN (
        SELECT si.sale_id, SUM(si.total_price - (si.cost_price * si.quantity)) as profit
        FROM sale_items si GROUP BY si.sale_id
    ) si_agg ON si_agg.sale_id = s.id
    WHERE s.status = 'completed' AND DATE(s.sale_date) = ?");
$todaySales->execute([$today]);
$todaySales = $todaySales->fetch();

$todayReturns = $db->prepare("SELECT COALESCE(SUM(refund_amount), 0) as total FROM sale_returns WHERE DATE(return_date) = ?");
$todayReturns->execute([$today]);
$todayReturnTotal = floatval($todayReturns->fetchColumn());

// Inventory overview
$invOverview = $db->query("SELECT
    COUNT(*) as total_items,
    COALESCE(SUM(quantity_in_stock * cost_price), 0) as total_value,
    SUM(CASE WHEN quantity_in_stock <= min_stock_level AND is_active = 1 THEN 1 ELSE 0 END) as low_stock_count,
    SUM(CASE WHEN quantity_in_stock = 0 AND is_active = 1 THEN 1 ELSE 0 END) as out_of_stock_count
    FROM medicines WHERE is_active = 1")->fetch();

$expiringCount = $db->query("SELECT COUNT(*) FROM medicines WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND expiry_date >= CURDATE() AND is_active = 1")->fetchColumn();
$expiredCount = $db->query("SELECT COUNT(*) FROM medicines WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND is_active = 1")->fetchColumn();

// Top 5 selling today
$topToday = $db->prepare("SELECT m.name, SUM(si.quantity) as qty_sold, SUM(si.total_price) as revenue
    FROM sale_items si
    JOIN medicines m ON si.medicine_id = m.id
    JOIN sales s ON si.sale_id = s.id
    WHERE DATE(s.sale_date) = ? AND s.status = 'completed'
    GROUP BY m.id ORDER BY qty_sold DESC LIMIT 5");
$topToday->execute([$today]);
$topToday = $topToday->fetchAll();

// Cash register status
$openRegister = $db->query("SELECT cr.*, u.full_name as opener FROM cash_register cr LEFT JOIN users u ON cr.opened_by = u.id WHERE cr.status = 'open' ORDER BY cr.opened_at DESC LIMIT 1")->fetch();

// Critical alerts
$expiredMeds = $db->query("SELECT name, expiry_date FROM medicines WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND is_active = 1 ORDER BY expiry_date ASC LIMIT 10")->fetchAll();
$outOfStock = $db->query("SELECT name FROM medicines WHERE quantity_in_stock = 0 AND is_active = 1 ORDER BY name LIMIT 10")->fetchAll();

// MTD financial summary
$mtdRevenue = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE status = 'completed' AND DATE(sale_date) BETWEEN ? AND ?");
$mtdRevenue->execute([$monthStart, $today]);
$mtdRevenue = floatval($mtdRevenue->fetchColumn());

$mtdProfit = $db->prepare("SELECT COALESCE(SUM(si.total_price - (si.cost_price * si.quantity)), 0)
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?");
$mtdProfit->execute([$monthStart, $today]);
$mtdProfit = floatval($mtdProfit->fetchColumn());

$mtdExpenses = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN ? AND ?");
$mtdExpenses->execute([$monthStart, $today]);
$mtdExpenses = floatval($mtdExpenses->fetchColumn());

// Today's expenses
$todayExpenses = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date = ?");
$todayExpenses->execute([$today]);
$todayExpenses = floatval($todayExpenses->fetchColumn());

// Payment breakdown today
$payBreakdown = $db->prepare("SELECT payment_method, COALESCE(SUM(total_amount), 0) as total
    FROM sales WHERE status = 'completed' AND DATE(sale_date) = ?
    GROUP BY payment_method");
$payBreakdown->execute([$today]);
$payMethods = [];
while ($row = $payBreakdown->fetch()) {
    $payMethods[$row['payment_method']] = floatval($row['total']);
}

$autoprint = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';
?>

<style>
@media print {
    .no-print, #wrapper > nav, .sidebar, #sidebarToggle, .navbar,
    #page-content-wrapper > nav { display: none !important; }
    #page-content-wrapper { margin: 0 !important; padding: 0 !important; }
    .container-fluid { padding: 0 !important; }
    body { font-size: 11pt; }
    .print-report { border: none !important; box-shadow: none !important; }
    .stat-card { border: 1px solid #ddd !important; }
    @page { size: A4; margin: 10mm; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <div>
        <a href="<?= BASE_URL ?>/modules/reports/daily.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Daily Report</a>
    </div>
    <div class="d-flex gap-2">
        <a href="?autoprint=1" class="btn btn-outline-primary btn-sm"><i class="bi bi-printer me-1"></i>Auto-Print</a>
        <button onclick="window.print()" class="btn btn-outline-dark btn-sm"><i class="bi bi-printer me-1"></i>Print</button>
    </div>
</div>

<div class="card p-4 print-report">
    <!-- Pharmacy Header -->
    <div class="text-center mb-4 border-bottom pb-3">
        <h4 class="mb-1"><?= sanitize($pharmacyName) ?></h4>
        <h5 class="text-muted mb-1">End-of-Day Dashboard Summary</h5>
        <div class="d-flex justify-content-center gap-4 text-muted small">
            <span><i class="bi bi-calendar me-1"></i><?= formatDate($today, 'l, F d, Y') ?></span>
            <span><i class="bi bi-clock me-1"></i><?= date('H:i:s') ?></span>
            <span><i class="bi bi-person me-1"></i><?= sanitize($operator) ?></span>
        </div>
    </div>

    <!-- Today's Sales Summary -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card stat-card success">
                <div class="stat-label">Today's Revenue</div>
                <div class="stat-value"><?= formatCurrency($todaySales['revenue']) ?></div>
                <small class="text-muted"><?= formatCurrency($todaySales['revenue'] * $exchangeRate, 'LBP') ?></small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card info">
                <div class="stat-label">Today's Profit</div>
                <div class="stat-value"><?= formatCurrency($todaySales['profit']) ?></div>
                <small class="text-muted"><?= $todaySales['revenue'] > 0 ? round($todaySales['profit'] / $todaySales['revenue'] * 100, 1) : 0 ?>% margin</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card">
                <div class="stat-label">Transactions</div>
                <div class="stat-value"><?= intval($todaySales['sale_count']) ?></div>
                <small class="text-muted">Avg: <?= $todaySales['sale_count'] > 0 ? formatCurrency($todaySales['revenue'] / $todaySales['sale_count']) : '$0.00' ?></small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card warning">
                <div class="stat-label">Returns / Expenses</div>
                <div class="stat-value"><?= formatCurrency($todayReturnTotal + $todayExpenses) ?></div>
                <small class="text-muted">Returns: <?= formatCurrency($todayReturnTotal) ?></small>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <!-- Payment Breakdown -->
        <div class="col-md-4">
            <h6 class="text-primary border-bottom pb-2"><i class="bi bi-cash-stack me-2"></i>Payment Breakdown</h6>
            <table class="table table-sm mb-0">
                <tr><td>Cash</td><td class="text-end fw-bold"><?= formatCurrency($payMethods['cash'] ?? 0) ?></td></tr>
                <tr><td>Card</td><td class="text-end fw-bold"><?= formatCurrency($payMethods['card'] ?? 0) ?></td></tr>
                <tr><td>Credit</td><td class="text-end fw-bold"><?= formatCurrency($payMethods['credit'] ?? 0) ?></td></tr>
                <tr><td>Insurance</td><td class="text-end fw-bold"><?= formatCurrency($payMethods['insurance'] ?? 0) ?></td></tr>
            </table>
        </div>

        <!-- Top 5 Selling Today -->
        <div class="col-md-4">
            <h6 class="text-primary border-bottom pb-2"><i class="bi bi-trophy me-2"></i>Top 5 Selling Today</h6>
            <?php if (!empty($topToday)): ?>
            <table class="table table-sm mb-0">
                <?php foreach ($topToday as $i => $t): ?>
                <tr>
                    <td><small><?= $i + 1 ?>.</small> <?= sanitize($t['name']) ?></td>
                    <td class="text-end"><strong><?= $t['qty_sold'] ?></strong> <small class="text-muted">units</small></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php else: ?>
            <p class="text-muted small">No sales recorded today.</p>
            <?php endif; ?>
        </div>

        <!-- Cash Register Status -->
        <div class="col-md-4">
            <h6 class="text-primary border-bottom pb-2"><i class="bi bi-cash-coin me-2"></i>Cash Register</h6>
            <?php if ($openRegister): ?>
            <table class="table table-sm mb-0">
                <tr><td class="text-muted">Status</td><td><span class="badge bg-success">Open</span></td></tr>
                <tr><td class="text-muted">Opened By</td><td><?= sanitize($openRegister['opener'] ?? 'N/A') ?></td></tr>
                <tr><td class="text-muted">Opened At</td><td><?= formatDate($openRegister['opened_at'], 'H:i') ?></td></tr>
                <tr><td class="text-muted">Opening USD</td><td><?= formatCurrency($openRegister['opening_amount']) ?></td></tr>
                <tr><td class="text-muted">Opening LBP</td><td><?= formatCurrency($openRegister['opening_lbp'] ?? 0, 'LBP') ?></td></tr>
            </table>
            <?php else: ?>
            <p class="text-muted small"><i class="bi bi-lock me-1"></i>No register currently open.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Inventory Overview -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <h6 class="text-primary border-bottom pb-2"><i class="bi bi-box-seam me-2"></i>Inventory Overview</h6>
            <table class="table table-sm mb-0">
                <tr><td>Total Active Items</td><td class="text-end fw-bold"><?= number_format($invOverview['total_items']) ?></td></tr>
                <tr><td>Total Inventory Value (cost)</td><td class="text-end fw-bold"><?= formatCurrency($invOverview['total_value']) ?></td></tr>
                <tr><td>Low Stock Items</td><td class="text-end"><span class="badge bg-warning"><?= intval($invOverview['low_stock_count']) ?></span></td></tr>
                <tr><td>Out of Stock Items</td><td class="text-end"><span class="badge bg-danger"><?= intval($invOverview['out_of_stock_count']) ?></span></td></tr>
                <tr><td>Expiring (90 days)</td><td class="text-end"><span class="badge bg-warning"><?= intval($expiringCount) ?></span></td></tr>
                <tr><td>Already Expired</td><td class="text-end"><span class="badge bg-danger"><?= intval($expiredCount) ?></span></td></tr>
            </table>
        </div>

        <!-- MTD Financial Summary -->
        <div class="col-md-6">
            <h6 class="text-primary border-bottom pb-2"><i class="bi bi-graph-up me-2"></i>Month-to-Date Financial Summary</h6>
            <table class="table table-sm mb-0">
                <tr><td>MTD Revenue</td><td class="text-end fw-bold"><?= formatCurrency($mtdRevenue) ?></td></tr>
                <tr><td>MTD Revenue (LBP)</td><td class="text-end"><?= formatCurrency($mtdRevenue * $exchangeRate, 'LBP') ?></td></tr>
                <tr><td>MTD Gross Profit</td><td class="text-end fw-bold text-success"><?= formatCurrency($mtdProfit) ?></td></tr>
                <tr><td>MTD Expenses</td><td class="text-end text-danger"><?= formatCurrency($mtdExpenses) ?></td></tr>
                <tr class="table-active">
                    <td><strong>MTD Net Profit</strong></td>
                    <td class="text-end fw-bold <?= ($mtdProfit - $mtdExpenses) >= 0 ? 'text-success' : 'text-danger' ?>">
                        <?= formatCurrency($mtdProfit - $mtdExpenses) ?>
                    </td>
                </tr>
                <tr><td>Profit Margin</td><td class="text-end"><?= $mtdRevenue > 0 ? round($mtdProfit / $mtdRevenue * 100, 1) : 0 ?>%</td></tr>
            </table>
        </div>
    </div>

    <!-- Critical Alerts -->
    <?php if (!empty($expiredMeds) || !empty($outOfStock)): ?>
    <div class="row g-3 mb-4">
        <?php if (!empty($expiredMeds)): ?>
        <div class="col-md-6">
            <h6 class="text-danger border-bottom pb-2"><i class="bi bi-exclamation-triangle me-2"></i>Expired Medicines</h6>
            <table class="table table-sm mb-0">
                <?php foreach ($expiredMeds as $em): ?>
                <tr>
                    <td><?= sanitize($em['name']) ?></td>
                    <td class="text-end text-danger small"><?= formatDate($em['expiry_date'], 'M d, Y') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($outOfStock)): ?>
        <div class="col-md-6">
            <h6 class="text-danger border-bottom pb-2"><i class="bi bi-x-circle me-2"></i>Out of Stock</h6>
            <div class="d-flex flex-wrap gap-1">
                <?php foreach ($outOfStock as $os): ?>
                <span class="badge bg-danger"><?= sanitize($os['name']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="text-center text-muted mt-3 pt-3 border-top">
        <small>Dashboard Summary generated on <?= date('M d, Y H:i:s') ?> | <?= sanitize($pharmacyName) ?> | Exchange Rate: 1 USD = <?= number_format($exchangeRate, 0, '.', ',') ?> LBP</small>
    </div>
</div>

<?php
$extraScripts = '';
if ($autoprint) {
    $extraScripts = '<script>window.addEventListener("load", function() { setTimeout(function() { window.print(); }, 500); });</script>';
}
require_once __DIR__ . '/../../includes/footer.php';
?>
