<?php
$pageTitle = 'Profit & Loss Statement';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));
$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');

// Date range presets
$preset = $_GET['preset'] ?? 'this_month';
$now = new DateTime();

switch ($preset) {
    case 'this_month':
        $dateFrom = $now->format('Y-m-01');
        $dateTo = $now->format('Y-m-t');
        break;
    case 'last_month':
        $lastMonth = (clone $now)->modify('-1 month');
        $dateFrom = $lastMonth->format('Y-m-01');
        $dateTo = $lastMonth->format('Y-m-t');
        break;
    case 'this_quarter':
        $quarter = ceil($now->format('n') / 3);
        $qStart = ($quarter - 1) * 3 + 1;
        $dateFrom = $now->format('Y') . '-' . str_pad($qStart, 2, '0', STR_PAD_LEFT) . '-01';
        $qEnd = new DateTime($dateFrom);
        $qEnd->modify('+3 months -1 day');
        $dateTo = $qEnd->format('Y-m-d');
        break;
    case 'this_year':
        $dateFrom = $now->format('Y-01-01');
        $dateTo = $now->format('Y-12-31');
        break;
    case 'custom':
        $dateFrom = $_GET['from'] ?? $now->format('Y-m-01');
        $dateTo = $_GET['to'] ?? $now->format('Y-m-t');
        break;
    default:
        $dateFrom = $now->format('Y-m-01');
        $dateTo = $now->format('Y-m-t');
}

// Allow GET override
if (isset($_GET['from'])) $dateFrom = $_GET['from'];
if (isset($_GET['to'])) $dateTo = $_GET['to'];

// Calculate previous period for comparison
$fromDt = new DateTime($dateFrom);
$toDt = new DateTime($dateTo);
$daysDiff = $fromDt->diff($toDt)->days + 1;
$prevTo = (clone $fromDt)->modify('-1 day')->format('Y-m-d');
$prevFrom = (clone $fromDt)->modify("-{$daysDiff} days")->format('Y-m-d');

// --- Current Period Data ---

// Sales Revenue
$stmt = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sale_date) BETWEEN ? AND ? AND status = 'completed'");
$stmt->execute([$dateFrom, $dateTo]);
$salesRevenue = floatval($stmt->fetchColumn());

// Insurance Reimbursements
$stmt = $db->prepare("SELECT COALESCE(SUM(payment_amount),0) FROM insurance_claims WHERE status = 'paid' AND DATE(payment_date) BETWEEN ? AND ?");
$stmt->execute([$dateFrom, $dateTo]);
$insuranceRevenue = floatval($stmt->fetchColumn());

// Returns
$stmt = $db->prepare("SELECT COALESCE(SUM(refund_amount),0) FROM sale_returns WHERE DATE(return_date) BETWEEN ? AND ?");
$stmt->execute([$dateFrom, $dateTo]);
$returns = floatval($stmt->fetchColumn());

// COGS
$stmt = $db->prepare("SELECT COALESCE(SUM(si.cost_price * si.quantity),0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE DATE(s.sale_date) BETWEEN ? AND ? AND s.status = 'completed'");
$stmt->execute([$dateFrom, $dateTo]);
$cogs = floatval($stmt->fetchColumn());

// Purchase Expenses (from purchase_orders if table exists)
$purchaseExpenses = 0;
try {
    $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders WHERE DATE(order_date) BETWEEN ? AND ? AND status IN ('received','completed')");
    $stmt->execute([$dateFrom, $dateTo]);
    $purchaseExpenses = floatval($stmt->fetchColumn());
} catch (Exception $e) {}

// Waste / Disposal costs (stock_movements with type 'disposal' or 'expired')
$wasteCost = 0;
try {
    $stmt = $db->prepare("SELECT COALESCE(SUM(sm.quantity * m.cost_price),0) FROM stock_movements sm JOIN medicines m ON sm.medicine_id = m.id WHERE sm.type IN ('disposal','expired','waste','adjustment') AND sm.quantity > 0 AND DATE(sm.created_at) BETWEEN ? AND ?");
    $stmt->execute([$dateFrom, $dateTo]);
    $wasteCost = floatval($stmt->fetchColumn());
} catch (Exception $e) {}

// Operating Expenses by category
$stmt = $db->prepare("SELECT category, COALESCE(SUM(amount),0) as total FROM expenses WHERE DATE(expense_date) BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
$stmt->execute([$dateFrom, $dateTo]);
$expensesByCategory = $stmt->fetchAll();
$totalExpenses = array_sum(array_column($expensesByCategory, 'total'));

// Calculations
$totalRevenue = $salesRevenue + $insuranceRevenue;
$netRevenue = $totalRevenue - $returns;
$totalCosts = $cogs + $wasteCost;
$grossProfit = $netRevenue - $totalCosts;
$operatingProfit = $grossProfit - $totalExpenses;
$netProfit = $operatingProfit;
$grossMargin = $netRevenue > 0 ? ($grossProfit / $netRevenue * 100) : 0;
$netMargin = $netRevenue > 0 ? ($netProfit / $netRevenue * 100) : 0;

// --- Previous Period Data for Comparison ---
$stmt = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sale_date) BETWEEN ? AND ? AND status = 'completed'");
$stmt->execute([$prevFrom, $prevTo]);
$prevSalesRevenue = floatval($stmt->fetchColumn());

$prevInsurance = 0;
try {
    $stmt = $db->prepare("SELECT COALESCE(SUM(payment_amount),0) FROM insurance_claims WHERE status = 'paid' AND DATE(payment_date) BETWEEN ? AND ?");
    $stmt->execute([$prevFrom, $prevTo]);
    $prevInsurance = floatval($stmt->fetchColumn());
} catch (Exception $e) {}

$stmt = $db->prepare("SELECT COALESCE(SUM(refund_amount),0) FROM sale_returns WHERE DATE(return_date) BETWEEN ? AND ?");
$stmt->execute([$prevFrom, $prevTo]);
$prevReturns = floatval($stmt->fetchColumn());

$stmt = $db->prepare("SELECT COALESCE(SUM(si.cost_price * si.quantity),0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE DATE(s.sale_date) BETWEEN ? AND ? AND s.status = 'completed'");
$stmt->execute([$prevFrom, $prevTo]);
$prevCogs = floatval($stmt->fetchColumn());

$stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE(expense_date) BETWEEN ? AND ?");
$stmt->execute([$prevFrom, $prevTo]);
$prevExpenses = floatval($stmt->fetchColumn());

$prevTotalRevenue = $prevSalesRevenue + $prevInsurance;
$prevNetRevenue = $prevTotalRevenue - $prevReturns;
$prevGrossProfit = $prevNetRevenue - $prevCogs;
$prevNetProfit = $prevGrossProfit - $prevExpenses;
$prevGrossMargin = $prevNetRevenue > 0 ? ($prevGrossProfit / $prevNetRevenue * 100) : 0;

// Helper for percent change
function pctChange($current, $previous) {
    if ($previous == 0) return $current > 0 ? 100 : 0;
    return (($current - $previous) / abs($previous)) * 100;
}

$revenueChange = pctChange($netRevenue, $prevNetRevenue);
$profitChange = pctChange($netProfit, $prevNetProfit);
$marginChange = $grossMargin - $prevGrossMargin;

// --- Monthly Trend Data (last 6 months) ---
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $mDate = (clone $now)->modify("-{$i} months");
    $mFrom = $mDate->format('Y-m-01');
    $mTo = $mDate->format('Y-m-t');
    $label = $mDate->format('M Y');

    $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sale_date) BETWEEN ? AND ? AND status = 'completed'");
    $stmt->execute([$mFrom, $mTo]);
    $mRevenue = floatval($stmt->fetchColumn());

    $stmt = $db->prepare("SELECT COALESCE(SUM(si.cost_price * si.quantity),0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE DATE(s.sale_date) BETWEEN ? AND ? AND s.status = 'completed'");
    $stmt->execute([$mFrom, $mTo]);
    $mCogs = floatval($stmt->fetchColumn());

    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE(expense_date) BETWEEN ? AND ?");
    $stmt->execute([$mFrom, $mTo]);
    $mExp = floatval($stmt->fetchColumn());

    $mProfit = $mRevenue - $mCogs - $mExp;
    $mGrossMargin = $mRevenue > 0 ? (($mRevenue - $mCogs) / $mRevenue * 100) : 0;

    $monthlyData[] = [
        'label' => $label,
        'revenue' => $mRevenue,
        'costs' => $mCogs + $mExp,
        'profit' => $mProfit,
        'gross_margin' => round($mGrossMargin, 1)
    ];
}
?>

<!-- Date Range Filter -->
<div class="card p-3 mb-3 no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small">Period</label>
            <select class="form-select" name="preset" id="presetSelect" onchange="toggleCustomDates()">
                <option value="this_month" <?= $preset === 'this_month' ? 'selected' : '' ?>>This Month</option>
                <option value="last_month" <?= $preset === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                <option value="this_quarter" <?= $preset === 'this_quarter' ? 'selected' : '' ?>>This Quarter</option>
                <option value="this_year" <?= $preset === 'this_year' ? 'selected' : '' ?>>This Year</option>
                <option value="custom" <?= $preset === 'custom' ? 'selected' : '' ?>>Custom Range</option>
            </select>
        </div>
        <div class="col-md-2" id="customFrom" style="<?= $preset !== 'custom' ? 'display:none' : '' ?>">
            <label class="form-label small">From</label>
            <input type="date" class="form-control" name="from" value="<?= $dateFrom ?>">
        </div>
        <div class="col-md-2" id="customTo" style="<?= $preset !== 'custom' ? 'display:none' : '' ?>">
            <label class="form-label small">To</label>
            <input type="date" class="form-control" name="to" value="<?= $dateTo ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Generate</button>
        </div>
        <div class="col text-end">
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
        </div>
    </form>
</div>

<!-- KPI Summary Cards -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <small class="text-muted">Net Revenue</small>
                    <div class="fs-5 fw-bold"><?= formatCurrency($netRevenue) ?></div>
                </div>
                <span class="badge bg-<?= $revenueChange >= 0 ? 'success' : 'danger' ?> bg-opacity-10 text-<?= $revenueChange >= 0 ? 'success' : 'danger' ?>">
                    <i class="bi bi-arrow-<?= $revenueChange >= 0 ? 'up' : 'down' ?>"></i> <?= number_format(abs($revenueChange), 1) ?>%
                </span>
            </div>
            <small class="text-muted">vs previous period</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <small class="text-muted">Gross Profit</small>
                    <div class="fs-5 fw-bold"><?= formatCurrency($grossProfit) ?></div>
                </div>
                <span class="badge bg-info bg-opacity-10 text-info"><?= number_format($grossMargin, 1) ?>%</span>
            </div>
            <small class="text-muted">Gross Margin</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <small class="text-muted">Net Profit</small>
                    <div class="fs-5 fw-bold <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatCurrency($netProfit) ?></div>
                </div>
                <span class="badge bg-<?= $profitChange >= 0 ? 'success' : 'danger' ?> bg-opacity-10 text-<?= $profitChange >= 0 ? 'success' : 'danger' ?>">
                    <i class="bi bi-arrow-<?= $profitChange >= 0 ? 'up' : 'down' ?>"></i> <?= number_format(abs($profitChange), 1) ?>%
                </span>
            </div>
            <small class="text-muted">Net Margin: <?= number_format($netMargin, 1) ?>%</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <small class="text-muted">Total Expenses</small>
                    <div class="fs-5 fw-bold text-danger"><?= formatCurrency($totalExpenses) ?></div>
                </div>
                <span class="badge bg-secondary bg-opacity-10 text-secondary">
                    <?= $netRevenue > 0 ? number_format($totalExpenses / $netRevenue * 100, 1) : 0 ?>%
                </span>
            </div>
            <small class="text-muted">of revenue</small>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- P&L Statement -->
    <div class="col-lg-7">
        <div class="card p-4 mb-3">
            <h5 class="text-center mb-1"><?= sanitize($pharmacyName) ?></h5>
            <h6 class="text-center text-muted mb-4">Profit & Loss Statement<br><small><?= formatDate($dateFrom, 'M d, Y') ?> - <?= formatDate($dateTo, 'M d, Y') ?></small></h6>

            <table class="table mb-0">
                <tbody>
                    <tr class="table-light"><td colspan="3"><strong>INCOME</strong></td></tr>
                    <tr>
                        <td class="ps-4">Sales Revenue</td>
                        <td class="text-end"><?= formatCurrency($salesRevenue) ?></td>
                        <td class="text-end small text-muted" style="width:90px">
                            <?php $c = pctChange($salesRevenue, $prevSalesRevenue); ?>
                            <span class="text-<?= $c >= 0 ? 'success' : 'danger' ?>"><?= $c >= 0 ? '+' : '' ?><?= number_format($c, 1) ?>%</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="ps-4">Insurance Reimbursements</td>
                        <td class="text-end"><?= formatCurrency($insuranceRevenue) ?></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td class="ps-4">Less: Returns & Refunds</td>
                        <td class="text-end text-danger">-<?= formatCurrency($returns) ?></td>
                        <td></td>
                    </tr>
                    <tr class="table-primary">
                        <td><strong>Net Revenue</strong></td>
                        <td class="text-end fw-bold"><?= formatCurrency($netRevenue) ?></td>
                        <td class="text-end small">
                            <span class="text-<?= $revenueChange >= 0 ? 'success' : 'danger' ?>"><?= $revenueChange >= 0 ? '+' : '' ?><?= number_format($revenueChange, 1) ?>%</span>
                        </td>
                    </tr>

                    <tr class="table-light"><td colspan="3"><strong>COST OF GOODS SOLD</strong></td></tr>
                    <tr>
                        <td class="ps-4">Medicine Costs (COGS)</td>
                        <td class="text-end text-danger">-<?= formatCurrency($cogs) ?></td>
                        <td></td>
                    </tr>
                    <?php if ($wasteCost > 0): ?>
                    <tr>
                        <td class="ps-4">Waste / Disposal</td>
                        <td class="text-end text-danger">-<?= formatCurrency($wasteCost) ?></td>
                        <td></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="table-info">
                        <td><strong>GROSS PROFIT</strong></td>
                        <td class="text-end fw-bold"><?= formatCurrency($grossProfit) ?></td>
                        <td class="text-end small text-info"><?= number_format($grossMargin, 1) ?>%</td>
                    </tr>

                    <tr class="table-light"><td colspan="3"><strong>OPERATING EXPENSES</strong></td></tr>
                    <?php foreach ($expensesByCategory as $exp): ?>
                    <tr>
                        <td class="ps-4"><?= sanitize(ucfirst($exp['category'])) ?></td>
                        <td class="text-end text-danger">-<?= formatCurrency($exp['total']) ?></td>
                        <td class="text-end small text-muted"><?= $netRevenue > 0 ? number_format($exp['total'] / $netRevenue * 100, 1) : 0 ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($expensesByCategory)): ?>
                    <tr><td class="ps-4 text-muted" colspan="3">No expenses recorded</td></tr>
                    <?php endif; ?>
                    <tr>
                        <td class="ps-4 fw-semibold">Total Operating Expenses</td>
                        <td class="text-end fw-semibold text-danger">-<?= formatCurrency($totalExpenses) ?></td>
                        <td></td>
                    </tr>

                    <tr class="table-warning">
                        <td><strong>OPERATING PROFIT</strong></td>
                        <td class="text-end fw-bold"><?= formatCurrency($operatingProfit) ?></td>
                        <td></td>
                    </tr>

                    <tr class="table-<?= $netProfit >= 0 ? 'success' : 'danger' ?>">
                        <td><strong class="fs-5">NET PROFIT</strong></td>
                        <td class="text-end fw-bold fs-5"><?= formatCurrency($netProfit) ?></td>
                        <td class="text-end">
                            <span class="badge bg-<?= $profitChange >= 0 ? 'success' : 'danger' ?>">
                                <?= $profitChange >= 0 ? '+' : '' ?><?= number_format($profitChange, 1) ?>%
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="mt-3 p-2 bg-light rounded small">
                <strong>LBP Equivalent:</strong> Net Profit = <?= formatCurrency($netProfit * $exchangeRate, 'LBP') ?> (Rate: <?= number_format($exchangeRate, 0) ?> LBP/USD)
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="col-lg-5">
        <!-- Expense Breakdown -->
        <div class="card p-3 mb-3">
            <h6 class="mb-3"><i class="bi bi-pie-chart me-2"></i>Expense Breakdown</h6>
            <canvas id="expenseChart" height="220"></canvas>
        </div>

        <!-- Comparison Panel -->
        <div class="card p-3 mb-3">
            <h6 class="mb-2"><i class="bi bi-arrow-left-right me-2"></i>Period Comparison</h6>
            <small class="text-muted d-block mb-3">Current vs <?= formatDate($prevFrom, 'M d') ?> - <?= formatDate($prevTo, 'M d, Y') ?></small>
            <table class="table table-sm mb-0">
                <thead><tr><th>Metric</th><th class="text-end">Previous</th><th class="text-end">Current</th><th class="text-end">Change</th></tr></thead>
                <tbody>
                    <tr>
                        <td class="small">Revenue</td>
                        <td class="text-end small"><?= formatCurrency($prevNetRevenue) ?></td>
                        <td class="text-end small"><?= formatCurrency($netRevenue) ?></td>
                        <td class="text-end small text-<?= $revenueChange >= 0 ? 'success' : 'danger' ?>"><?= $revenueChange >= 0 ? '+' : '' ?><?= number_format($revenueChange, 1) ?>%</td>
                    </tr>
                    <tr>
                        <td class="small">Gross Profit</td>
                        <td class="text-end small"><?= formatCurrency($prevGrossProfit) ?></td>
                        <td class="text-end small"><?= formatCurrency($grossProfit) ?></td>
                        <?php $gpChange = pctChange($grossProfit, $prevGrossProfit); ?>
                        <td class="text-end small text-<?= $gpChange >= 0 ? 'success' : 'danger' ?>"><?= $gpChange >= 0 ? '+' : '' ?><?= number_format($gpChange, 1) ?>%</td>
                    </tr>
                    <tr>
                        <td class="small">Net Profit</td>
                        <td class="text-end small"><?= formatCurrency($prevNetProfit) ?></td>
                        <td class="text-end small"><?= formatCurrency($netProfit) ?></td>
                        <td class="text-end small text-<?= $profitChange >= 0 ? 'success' : 'danger' ?>"><?= $profitChange >= 0 ? '+' : '' ?><?= number_format($profitChange, 1) ?>%</td>
                    </tr>
                    <tr>
                        <td class="small">Gross Margin</td>
                        <td class="text-end small"><?= number_format($prevGrossMargin, 1) ?>%</td>
                        <td class="text-end small"><?= number_format($grossMargin, 1) ?>%</td>
                        <td class="text-end small text-<?= $marginChange >= 0 ? 'success' : 'danger' ?>"><?= $marginChange >= 0 ? '+' : '' ?><?= number_format($marginChange, 1) ?>pp</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Monthly Trend Chart -->
<div class="card p-3 mb-3">
    <h6 class="mb-3"><i class="bi bi-graph-up me-2"></i>Monthly P&L Trend (Last 6 Months)</h6>
    <canvas id="trendChart" height="100"></canvas>
</div>

<!-- Gross Margin Trend -->
<div class="card p-3">
    <h6 class="mb-3"><i class="bi bi-graph-up-arrow me-2"></i>Gross Margin Trend</h6>
    <canvas id="marginChart" height="80"></canvas>
</div>

<?php
$monthlyLabels = json_encode(array_column($monthlyData, 'label'));
$monthlyRevenue = json_encode(array_column($monthlyData, 'revenue'));
$monthlyCosts = json_encode(array_column($monthlyData, 'costs'));
$monthlyProfit = json_encode(array_column($monthlyData, 'profit'));
$monthlyMargin = json_encode(array_column($monthlyData, 'gross_margin'));

$expLabels = json_encode(array_column($expensesByCategory, 'category'));
$expValues = json_encode(array_column($expensesByCategory, 'total'));

$extraScripts = <<<SCRIPT
<script>
function toggleCustomDates() {
    var show = document.getElementById('presetSelect').value === 'custom';
    document.getElementById('customFrom').style.display = show ? '' : 'none';
    document.getElementById('customTo').style.display = show ? '' : 'none';
}

// Expense Breakdown Doughnut
var expLabels = $expLabels;
var expValues = $expValues;
if (expLabels.length > 0) {
    new Chart(document.getElementById('expenseChart'), {
        type: 'doughnut',
        data: {
            labels: expLabels.map(function(l) { return l.charAt(0).toUpperCase() + l.slice(1); }),
            datasets: [{
                data: expValues,
                backgroundColor: ['#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f','#edc948','#b07aa1','#ff9da7','#9c755f','#bab0ac']
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
} else {
    document.getElementById('expenseChart').parentElement.innerHTML += '<p class="text-center text-muted small mt-3">No expense data for this period</p>';
}

// Monthly P&L Trend (Grouped Bar)
new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
        labels: $monthlyLabels,
        datasets: [
            { label: 'Revenue', data: $monthlyRevenue, backgroundColor: '#4e79a7' },
            { label: 'Costs', data: $monthlyCosts, backgroundColor: '#e15759' },
            { label: 'Profit', data: $monthlyProfit, backgroundColor: '#59a14f' }
        ]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true, ticks: { callback: function(v) { return '\$' + v.toLocaleString(); } } }
        },
        plugins: {
            legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } },
            tooltip: { callbacks: { label: function(ctx) { return ctx.dataset.label + ': \$' + ctx.parsed.y.toFixed(2); } } }
        }
    }
});

// Gross Margin Trend Line
new Chart(document.getElementById('marginChart'), {
    type: 'line',
    data: {
        labels: $monthlyLabels,
        datasets: [{
            label: 'Gross Margin %',
            data: $monthlyMargin,
            borderColor: '#4e79a7',
            backgroundColor: 'rgba(78,121,167,0.1)',
            fill: true,
            tension: 0.3,
            pointRadius: 5,
            pointBackgroundColor: '#4e79a7'
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true, max: 100, ticks: { callback: function(v) { return v + '%'; } } }
        },
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: function(ctx) { return 'Gross Margin: ' + ctx.parsed.y.toFixed(1) + '%'; } } }
        }
    }
});
</script>
SCRIPT;

require_once __DIR__ . '/../../includes/footer.php';
?>
