<?php
$pageTitle = 'Daily Summary';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));
$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');

$date = $_GET['date'] ?? date('Y-m-d');
$dateDisplay = formatDate($date, 'l, F d, Y');

// ---- Revenue Breakdown by Payment Method ----
$salesByMethod = $db->prepare("SELECT payment_method, currency, COUNT(*) as tx_count, COALESCE(SUM(total_amount),0) as total
    FROM sales WHERE DATE(sale_date) = ? AND status = 'completed'
    GROUP BY payment_method, currency ORDER BY payment_method");
$salesByMethod->execute([$date]);
$salesByMethod = $salesByMethod->fetchAll();

$totalSalesUSD = 0;
$totalSalesLBP = 0;
$totalTxCount = 0;
$methodTotals = ['cash' => 0, 'card' => 0, 'insurance' => 0, 'credit' => 0];

foreach ($salesByMethod as $sm) {
    $totalTxCount += $sm['tx_count'];
    if ($sm['currency'] === 'LBP') {
        $totalSalesLBP += $sm['total'];
        $methodTotals[$sm['payment_method']] += $sm['total'] / $exchangeRate;
    } else {
        $totalSalesUSD += $sm['total'];
        $methodTotals[$sm['payment_method']] += $sm['total'];
    }
}

$totalRevenueUSD = $totalSalesUSD + ($totalSalesLBP / $exchangeRate);

// ---- Itemized Sales ----
$salesList = $db->prepare("SELECT s.id, s.invoice_number, s.sale_date, s.total_amount, s.payment_method, s.currency,
    c.name as customer_name, u.full_name as cashier_name,
    (SELECT GROUP_CONCAT(m.name SEPARATOR ', ') FROM sale_items si JOIN medicines m ON si.medicine_id = m.id WHERE si.sale_id = s.id LIMIT 3) as items_preview
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN users u ON s.created_by = u.id
    WHERE DATE(s.sale_date) = ? AND s.status = 'completed'
    ORDER BY s.sale_date ASC");
$salesList->execute([$date]);
$salesList = $salesList->fetchAll();

// ---- Returns/Refunds ----
$returns = $db->prepare("SELECT sr.*, s.invoice_number, m.name as medicine_name
    FROM sale_returns sr
    JOIN sales s ON sr.sale_id = s.id
    JOIN sale_items si ON sr.sale_item_id = si.id
    JOIN medicines m ON si.medicine_id = m.id
    WHERE DATE(sr.return_date) = ?
    ORDER BY sr.return_date ASC");
$returns->execute([$date]);
$returns = $returns->fetchAll();
$totalRefunds = array_sum(array_column($returns, 'refund_amount'));

// ---- Expenses ----
$expenses = $db->prepare("SELECT * FROM expenses WHERE expense_date = ? ORDER BY created_at ASC");
$expenses->execute([$date]);
$expenses = $expenses->fetchAll();

$totalExpensesUSD = 0;
$totalExpensesLBP = 0;
foreach ($expenses as $exp) {
    if ($exp['currency'] === 'LBP') {
        $totalExpensesLBP += $exp['amount'];
    } else {
        $totalExpensesUSD += $exp['amount'];
    }
}
$totalExpensesEquiv = $totalExpensesUSD + ($totalExpensesLBP / $exchangeRate);

// ---- Cash Register ----
$register = null;
try {
    $register = $db->prepare("SELECT cr.*, uo.full_name as opener, uc.full_name as closer
        FROM cash_register cr
        LEFT JOIN users uo ON cr.opened_by = uo.id
        LEFT JOIN users uc ON cr.closed_by = uc.id
        WHERE DATE(cr.opened_at) = ?
        ORDER BY cr.opened_at DESC LIMIT 1");
    $register->execute([$date]);
    $register = $register->fetch();
} catch (Exception $e) {}

// ---- Net Position ----
$netPositionUSD = $totalRevenueUSD - $totalRefunds - $totalExpensesEquiv;

// ---- Hourly Sales ----
$hourlySales = $db->prepare("SELECT HOUR(sale_date) as hour, COUNT(*) as count, COALESCE(SUM(CASE WHEN currency='USD' THEN total_amount ELSE total_amount/? END),0) as total
    FROM sales WHERE DATE(sale_date) = ? AND status = 'completed'
    GROUP BY HOUR(sale_date) ORDER BY hour");
$hourlySales->execute([$exchangeRate, $date]);
$hourlySales = $hourlySales->fetchAll();

// Build hourly data for chart (0-23)
$hourlyLabels = [];
$hourlyData = [];
$hourlyCountData = [];
$hourMap = [];
foreach ($hourlySales as $h) {
    $hourMap[$h['hour']] = $h;
}
for ($i = 8; $i <= 22; $i++) {
    $hourlyLabels[] = str_pad($i, 2, '0', STR_PAD_LEFT) . ':00';
    $hourlyData[] = round($hourMap[$i]['total'] ?? 0, 2);
    $hourlyCountData[] = $hourMap[$i]['count'] ?? 0;
}

// ---- Payment Method Pie ----
$pieLabels = [];
$pieValues = [];
foreach ($methodTotals as $method => $total) {
    if ($total > 0) {
        $pieLabels[] = ucfirst($method);
        $pieValues[] = round($total, 2);
    }
}
?>

<!-- Date Selector -->
<div class="card p-3 mb-3 no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small">Date</label>
            <input type="date" class="form-control" name="date" value="<?= $date ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>View</button>
        </div>
        <div class="col-md-2">
            <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary">Today</a>
        </div>
        <div class="col text-end">
            <button type="button" class="btn btn-outline-dark" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print Report</button>
        </div>
    </form>
</div>

<!-- Print Header (only visible when printing) -->
<div class="d-none d-print-block text-center mb-4">
    <h4><?= sanitize($pharmacyName) ?></h4>
    <h5>Daily Cash Summary</h5>
    <p><?= $dateDisplay ?></p>
    <hr>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card p-3">
            <small class="text-muted">Total Revenue (USD)</small>
            <div class="fs-4 fw-bold text-success"><?= formatCurrency($totalRevenueUSD) ?></div>
            <small class="text-muted"><?= formatCurrency($totalRevenueUSD * $exchangeRate, 'LBP') ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3">
            <small class="text-muted">Transactions</small>
            <div class="fs-4 fw-bold text-primary"><?= $totalTxCount ?></div>
            <small class="text-muted"><?= $totalTxCount > 0 ? 'Avg: ' . formatCurrency($totalRevenueUSD / $totalTxCount) : 'No sales' ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3">
            <small class="text-muted">Returns/Refunds</small>
            <div class="fs-4 fw-bold text-danger"><?= formatCurrency($totalRefunds) ?></div>
            <small class="text-muted"><?= count($returns) ?> return(s)</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3">
            <small class="text-muted">Net Position</small>
            <div class="fs-4 fw-bold text-<?= $netPositionUSD >= 0 ? 'success' : 'danger' ?>"><?= formatCurrency($netPositionUSD) ?></div>
            <small class="text-muted">Revenue - Refunds - Expenses</small>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <!-- Revenue Breakdown -->
    <div class="col-lg-7">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-cash-stack me-2"></i>Revenue Breakdown</h6>
            <table class="table table-sm mb-0">
                <thead><tr><th>Payment Method</th><th>Currency</th><th class="text-center">Transactions</th><th class="text-end">Amount</th><th class="text-end">USD Equiv.</th></tr></thead>
                <tbody>
                    <?php foreach ($salesByMethod as $sm): ?>
                    <tr>
                        <td><span class="badge bg-<?= $sm['payment_method'] === 'cash' ? 'success' : ($sm['payment_method'] === 'card' ? 'primary' : ($sm['payment_method'] === 'insurance' ? 'info' : 'warning')) ?>"><?= ucfirst($sm['payment_method']) ?></span></td>
                        <td><?= $sm['currency'] ?></td>
                        <td class="text-center"><?= $sm['tx_count'] ?></td>
                        <td class="text-end"><?= formatCurrency($sm['total'], $sm['currency']) ?></td>
                        <td class="text-end"><?= formatCurrency($sm['currency'] === 'LBP' ? $sm['total'] / $exchangeRate : $sm['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($salesByMethod)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No sales recorded</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light">
                        <td colspan="3"><strong>Total (USD)</strong></td>
                        <td class="text-end fw-bold"><?= formatCurrency($totalSalesUSD) ?></td>
                        <td class="text-end fw-bold"><?= formatCurrency($totalRevenueUSD) ?></td>
                    </tr>
                    <?php if ($totalSalesLBP > 0): ?>
                    <tr class="table-light">
                        <td colspan="3"><strong>Total (LBP)</strong></td>
                        <td class="text-end fw-bold"><?= formatCurrency($totalSalesLBP, 'LBP') ?></td>
                        <td></td>
                    </tr>
                    <?php endif; ?>
                </tfoot>
            </table>
        </div>

        <!-- Cash Register -->
        <?php if ($register): ?>
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-safe me-2"></i>Cash Register</h6>
            <div class="row g-2">
                <div class="col-md-6">
                    <table class="table table-sm mb-0">
                        <tr><td class="text-muted">Status</td><td><span class="badge bg-<?= $register['status'] === 'open' ? 'success' : 'secondary' ?>"><?= ucfirst($register['status']) ?></span></td></tr>
                        <tr><td class="text-muted">Opened</td><td><?= formatDate($register['opened_at'], 'H:i') ?> by <?= sanitize($register['opener'] ?? '-') ?></td></tr>
                        <?php if ($register['closed_at']): ?>
                        <tr><td class="text-muted">Closed</td><td><?= formatDate($register['closed_at'], 'H:i') ?> by <?= sanitize($register['closer'] ?? '-') ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm mb-0">
                        <tr><td class="text-muted">Opening USD</td><td class="text-end"><?= formatCurrency($register['opening_amount']) ?></td></tr>
                        <tr><td class="text-muted">Opening LBP</td><td class="text-end"><?= formatCurrency($register['opening_lbp'], 'LBP') ?></td></tr>
                        <?php if ($register['closing_amount'] !== null): ?>
                        <tr><td class="text-muted">Closing USD</td><td class="text-end"><?= formatCurrency($register['closing_amount']) ?></td></tr>
                        <tr><td class="text-muted">Expected USD</td><td class="text-end"><?= formatCurrency($register['expected_amount']) ?></td></tr>
                        <tr class="<?= $register['difference_amount'] != 0 ? 'table-danger' : 'table-success' ?>">
                            <td class="fw-bold">Difference USD</td>
                            <td class="text-end fw-bold"><?= $register['difference_amount'] >= 0 ? '+' : '' ?><?= formatCurrency($register['difference_amount']) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Charts -->
    <div class="col-lg-5">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-pie-chart me-2"></i>Payment Methods</h6>
            <?php if (!empty($pieLabels)): ?>
            <canvas id="paymentPie" height="220"></canvas>
            <?php else: ?>
            <p class="text-center text-muted py-4">No sales data</p>
            <?php endif; ?>
        </div>
        <div class="card p-3">
            <h6><i class="bi bi-bar-chart me-2"></i>Hourly Sales Distribution</h6>
            <?php if (!empty($hourlySales)): ?>
            <canvas id="hourlyChart" height="200"></canvas>
            <?php else: ?>
            <p class="text-center text-muted py-4">No sales data</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Itemized Sales -->
<div class="card p-3 mb-3">
    <h6><i class="bi bi-receipt me-2"></i>Itemized Sales (<?= count($salesList) ?>)</h6>
    <div class="table-responsive">
        <table class="table table-sm table-hover data-table mb-0">
            <thead><tr><th>Invoice</th><th>Time</th><th>Customer</th><th>Items</th><th>Payment</th><th>Cashier</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
                <?php foreach ($salesList as $s): ?>
                <tr>
                    <td><a href="<?= BASE_URL ?>/modules/sales/invoice.php?id=<?= $s['id'] ?>" class="small"><?= sanitize($s['invoice_number']) ?></a></td>
                    <td><small><?= formatDate($s['sale_date'], 'H:i') ?></small></td>
                    <td><small><?= sanitize($s['customer_name'] ?? 'Walk-in') ?></small></td>
                    <td><small class="text-muted"><?= sanitize(mb_strimwidth($s['items_preview'] ?? '-', 0, 50, '...')) ?></small></td>
                    <td><span class="badge bg-<?= $s['payment_method'] === 'cash' ? 'success' : ($s['payment_method'] === 'card' ? 'primary' : 'info') ?> bg-opacity-75"><?= ucfirst($s['payment_method']) ?></span></td>
                    <td><small><?= sanitize($s['cashier_name'] ?? '-') ?></small></td>
                    <td class="text-end fw-semibold"><?= formatCurrency($s['total_amount'], $s['currency']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($salesList)): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">No sales on this day</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Returns and Expenses side by side -->
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card p-3">
            <h6><i class="bi bi-arrow-return-left me-2"></i>Returns &amp; Refunds (<?= count($returns) ?>)</h6>
            <?php if (empty($returns)): ?>
            <p class="text-center text-muted py-3 small">No returns on this day</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Invoice</th><th>Medicine</th><th class="text-center">Qty</th><th class="text-end">Refund</th><th>Reason</th></tr></thead>
                    <tbody>
                        <?php foreach ($returns as $r): ?>
                        <tr>
                            <td><small><?= sanitize($r['invoice_number']) ?></small></td>
                            <td><small><?= sanitize($r['medicine_name']) ?></small></td>
                            <td class="text-center"><?= $r['quantity'] ?></td>
                            <td class="text-end text-danger"><?= formatCurrency($r['refund_amount']) ?></td>
                            <td><small class="text-muted"><?= sanitize(mb_strimwidth($r['reason'] ?? '-', 0, 30, '...')) ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr class="table-light"><td colspan="3"><strong>Total Refunds</strong></td><td class="text-end fw-bold text-danger"><?= formatCurrency($totalRefunds) ?></td><td></td></tr></tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-3">
            <h6><i class="bi bi-wallet2 me-2"></i>Expenses (<?= count($expenses) ?>)</h6>
            <?php if (empty($expenses)): ?>
            <p class="text-center text-muted py-3 small">No expenses on this day</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Category</th><th>Description</th><th>Payment</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($expenses as $exp): ?>
                        <tr>
                            <td><span class="badge bg-secondary"><?= ucfirst($exp['category']) ?></span></td>
                            <td><small><?= sanitize($exp['description']) ?></small></td>
                            <td><small><?= ucfirst($exp['payment_method']) ?></small></td>
                            <td class="text-end fw-semibold"><?= formatCurrency($exp['amount'], $exp['currency']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td colspan="3"><strong>Total Expenses</strong></td>
                            <td class="text-end fw-bold text-danger"><?= formatCurrency($totalExpensesEquiv) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Net Summary -->
<div class="card p-3 mb-3">
    <h6><i class="bi bi-calculator me-2"></i>Day End Summary</h6>
    <table class="table table-sm mb-0" style="max-width:500px">
        <tbody>
            <tr><td>Total Revenue (USD equiv.)</td><td class="text-end fw-semibold text-success">+<?= formatCurrency($totalRevenueUSD) ?></td></tr>
            <?php if ($totalSalesLBP > 0): ?>
            <tr><td class="text-muted ps-3">of which LBP</td><td class="text-end text-muted"><?= formatCurrency($totalSalesLBP, 'LBP') ?></td></tr>
            <?php endif; ?>
            <tr><td>Less: Refunds</td><td class="text-end text-danger">-<?= formatCurrency($totalRefunds) ?></td></tr>
            <tr><td>Less: Expenses</td><td class="text-end text-danger">-<?= formatCurrency($totalExpensesEquiv) ?></td></tr>
            <tr class="table-<?= $netPositionUSD >= 0 ? 'success' : 'danger' ?>">
                <td><strong class="fs-6">Net Position</strong></td>
                <td class="text-end fw-bold fs-6"><?= formatCurrency($netPositionUSD) ?></td>
            </tr>
            <tr>
                <td class="text-muted">LBP Equivalent</td>
                <td class="text-end text-muted"><?= formatCurrency($netPositionUSD * $exchangeRate, 'LBP') ?></td>
            </tr>
        </tbody>
    </table>
    <small class="text-muted mt-2 d-block">Exchange Rate: 1 USD = <?= number_format($exchangeRate, 0) ?> LBP</small>
</div>

<style>
@media print {
    .no-print, nav, .sidebar, #sidebarToggle { display: none !important; }
    .d-print-block { display: block !important; }
    .card { border: 1px solid #ddd !important; box-shadow: none !important; margin-bottom: 10px !important; }
    body { font-size: 11px; }
    .fs-4 { font-size: 1.1rem !important; }
    @page { size: A4 portrait; margin: 15mm; }
}
</style>

<?php
$pieLabelsJson = json_encode($pieLabels);
$pieValuesJson = json_encode($pieValues);
$hourlyLabelsJson = json_encode($hourlyLabels);
$hourlyDataJson = json_encode($hourlyData);

$extraScripts = <<<SCRIPT
<script>
// Payment Method Pie
var pieLabels = $pieLabelsJson;
var pieValues = $pieValuesJson;
if (pieLabels.length > 0) {
    new Chart(document.getElementById('paymentPie'), {
        type: 'doughnut',
        data: {
            labels: pieLabels,
            datasets: [{
                data: pieValues,
                backgroundColor: ['#59a14f','#4e79a7','#76b7b2','#f28e2b','#e15759']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: { callbacks: { label: function(ctx) { return ctx.label + ': \$' + ctx.parsed.toFixed(2); } } }
            }
        }
    });
}

// Hourly Sales Bar
var hourlyLabels = $hourlyLabelsJson;
var hourlyData = $hourlyDataJson;
if (hourlyLabels.length > 0) {
    new Chart(document.getElementById('hourlyChart'), {
        type: 'bar',
        data: {
            labels: hourlyLabels,
            datasets: [{
                label: 'Sales (USD)',
                data: hourlyData,
                backgroundColor: '#4e79a7'
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: { beginAtZero: true, ticks: { callback: function(v) { return '\$' + v; } } },
                x: { ticks: { font: { size: 10 } } }
            },
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function(ctx) { return '\$' + ctx.parsed.y.toFixed(2); } } }
            }
        }
    });
}
</script>
SCRIPT;
require_once __DIR__ . '/../../includes/footer.php';
?>
