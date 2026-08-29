<?php
$pageTitle = 'Sales Analytics';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));

// Period handling
$period = $_GET['period'] ?? '30';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

if ($period === 'custom' && $startDate && $endDate) {
    $dateFrom = $startDate;
    $dateTo = $endDate;
} else {
    $days = intval($period) ?: 30;
    if ($period === 'today') { $days = 0; }
    elseif ($period === '7') { $days = 7; }
    elseif ($period === '90') { $days = 90; }
    else { $days = 30; }
    $dateTo = date('Y-m-d');
    $dateFrom = $days === 0 ? date('Y-m-d') : date('Y-m-d', strtotime("-{$days} days"));
}

$prevDaysDiff = max(1, (strtotime($dateTo) - strtotime($dateFrom)) / 86400);
$prevFrom = date('Y-m-d', strtotime($dateFrom . " -{$prevDaysDiff} days"));
$prevTo = date('Y-m-d', strtotime($dateFrom . ' -1 day'));

// KPI: Current period
$kpi = $db->prepare("SELECT
    COALESCE(SUM(s.total_amount), 0) as revenue,
    COUNT(s.id) as transactions,
    COALESCE(SUM(si_agg.profit), 0) as profit
    FROM sales s
    LEFT JOIN (
        SELECT si.sale_id, SUM(si.total_price - (si.cost_price * si.quantity)) as profit
        FROM sale_items si GROUP BY si.sale_id
    ) si_agg ON si_agg.sale_id = s.id
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?");
$kpi->execute([$dateFrom, $dateTo]);
$kpi = $kpi->fetch();

$totalRevenue = floatval($kpi['revenue']);
$totalProfit = floatval($kpi['profit']);
$totalTransactions = intval($kpi['transactions']);
$avgTransaction = $totalTransactions > 0 ? $totalRevenue / $totalTransactions : 0;

// KPI: Previous period
$prevKpi = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) as revenue
    FROM sales WHERE status = 'completed' AND DATE(sale_date) BETWEEN ? AND ?");
$prevKpi->execute([$prevFrom, $prevTo]);
$prevRevenue = floatval($prevKpi->fetchColumn());
$growthPct = $prevRevenue > 0 ? round(($totalRevenue - $prevRevenue) / $prevRevenue * 100, 1) : ($totalRevenue > 0 ? 100 : 0);

// Top selling medicine
$topMed = $db->prepare("SELECT m.name, SUM(si.quantity) as qty
    FROM sale_items si
    JOIN medicines m ON si.medicine_id = m.id
    JOIN sales s ON si.sale_id = s.id
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY m.id ORDER BY qty DESC LIMIT 1");
$topMed->execute([$dateFrom, $dateTo]);
$topMedicine = $topMed->fetch();

// Revenue trend
$daysDiff = (strtotime($dateTo) - strtotime($dateFrom)) / 86400;
if ($daysDiff <= 31) {
    $trendGroup = 'DATE(s.sale_date)';
    $trendFormat = 'M d';
} elseif ($daysDiff <= 90) {
    $trendGroup = 'YEARWEEK(s.sale_date, 1)';
    $trendFormat = 'W';
} else {
    $trendGroup = "DATE_FORMAT(s.sale_date, '%Y-%m')";
    $trendFormat = 'M Y';
}

$trendData = $db->prepare("SELECT {$trendGroup} as period_key,
    MIN(DATE(s.sale_date)) as period_date,
    SUM(s.total_amount) as revenue,
    COALESCE(SUM(si_agg.profit), 0) as profit,
    COUNT(s.id) as tx_count
    FROM sales s
    LEFT JOIN (
        SELECT si.sale_id, SUM(si.total_price - (si.cost_price * si.quantity)) as profit
        FROM sale_items si GROUP BY si.sale_id
    ) si_agg ON si_agg.sale_id = s.id
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY period_key ORDER BY period_key");
$trendData->execute([$dateFrom, $dateTo]);
$trendData = $trendData->fetchAll();

$trendLabels = [];
$trendRevenue = [];
$trendProfit = [];
foreach ($trendData as $t) {
    $trendLabels[] = formatDate($t['period_date'], $trendFormat);
    $trendRevenue[] = round(floatval($t['revenue']), 2);
    $trendProfit[] = round(floatval($t['profit']), 2);
}

// Top 10 medicines by revenue
$topMedicines = $db->prepare("SELECT m.name, SUM(si.quantity) as qty_sold,
    SUM(si.total_price) as revenue,
    SUM(si.total_price - (si.cost_price * si.quantity)) as profit,
    CASE WHEN SUM(si.total_price) > 0 THEN ROUND(SUM(si.total_price - (si.cost_price * si.quantity)) / SUM(si.total_price) * 100, 1) ELSE 0 END as margin_pct
    FROM sale_items si
    JOIN medicines m ON si.medicine_id = m.id
    JOIN sales s ON si.sale_id = s.id
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY m.id ORDER BY revenue DESC LIMIT 10");
$topMedicines->execute([$dateFrom, $dateTo]);
$topMedicines = $topMedicines->fetchAll();

$topMedLabels = array_map(function($m) { return $m['name']; }, $topMedicines);
$topMedRevenues = array_map(function($m) { return round(floatval($m['revenue']), 2); }, $topMedicines);

// Sales by hour
$hourlyData = $db->prepare("SELECT HOUR(s.sale_date) as hour, COUNT(*) as tx_count, SUM(s.total_amount) as revenue
    FROM sales s
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY HOUR(s.sale_date) ORDER BY hour");
$hourlyData->execute([$dateFrom, $dateTo]);
$hourlyData = $hourlyData->fetchAll();

$hourLabels = [];
$hourRevenues = [];
$hourCounts = [];
for ($h = 0; $h < 24; $h++) {
    $hourLabels[] = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';
    $found = false;
    foreach ($hourlyData as $hd) {
        if (intval($hd['hour']) === $h) {
            $hourRevenues[] = round(floatval($hd['revenue']), 2);
            $hourCounts[] = intval($hd['tx_count']);
            $found = true;
            break;
        }
    }
    if (!$found) { $hourRevenues[] = 0; $hourCounts[] = 0; }
}

// Payment method distribution
$paymentData = $db->prepare("SELECT s.payment_method, COUNT(*) as cnt, SUM(s.total_amount) as total
    FROM sales s
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY s.payment_method ORDER BY total DESC");
$paymentData->execute([$dateFrom, $dateTo]);
$paymentData = $paymentData->fetchAll();

$payLabels = array_map(function($p) { return ucfirst($p['payment_method']); }, $paymentData);
$payValues = array_map(function($p) { return round(floatval($p['total']), 2); }, $paymentData);

// Revenue by category
$categoryData = $db->prepare("SELECT COALESCE(c.name, 'Uncategorized') as category_name,
    SUM(si.total_price) as revenue
    FROM sale_items si
    JOIN medicines m ON si.medicine_id = m.id
    LEFT JOIN categories c ON m.category_id = c.id
    JOIN sales s ON si.sale_id = s.id
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY c.id ORDER BY revenue DESC");
$categoryData->execute([$dateFrom, $dateTo]);
$categoryData = $categoryData->fetchAll();

$catLabels = array_map(function($c) { return $c['category_name']; }, $categoryData);
$catValues = array_map(function($c) { return round(floatval($c['revenue']), 2); }, $categoryData);

// Sales by cashier/user
$cashierData = $db->prepare("SELECT u.full_name, COUNT(s.id) as tx_count, SUM(s.total_amount) as revenue,
    COALESCE(SUM(si_agg.profit), 0) as profit
    FROM sales s
    LEFT JOIN users u ON s.created_by = u.id
    LEFT JOIN (
        SELECT si.sale_id, SUM(si.total_price - (si.cost_price * si.quantity)) as profit
        FROM sale_items si GROUP BY si.sale_id
    ) si_agg ON si_agg.sale_id = s.id
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY s.created_by ORDER BY revenue DESC");
$cashierData->execute([$dateFrom, $dateTo]);
$cashierData = $cashierData->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="btn-group btn-group-sm">
        <a href="?period=today" class="btn btn-<?= $period === 'today' ? 'primary' : 'outline-primary' ?>">Today</a>
        <a href="?period=7" class="btn btn-<?= $period === '7' ? 'primary' : 'outline-primary' ?>">7 Days</a>
        <a href="?period=30" class="btn btn-<?= $period === '30' ? 'primary' : 'outline-primary' ?>">30 Days</a>
        <a href="?period=90" class="btn btn-<?= $period === '90' ? 'primary' : 'outline-primary' ?>">90 Days</a>
    </div>
    <form class="d-flex gap-2 align-items-end" method="GET">
        <input type="hidden" name="period" value="custom">
        <div>
            <label class="form-label small mb-0">From</label>
            <input type="date" class="form-control form-control-sm" name="start_date" value="<?= sanitize($startDate ?: $dateFrom) ?>">
        </div>
        <div>
            <label class="form-label small mb-0">To</label>
            <input type="date" class="form-control form-control-sm" name="end_date" value="<?= sanitize($endDate ?: $dateTo) ?>">
        </div>
        <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
    </form>
    <button onclick="window.print()" class="btn btn-sm btn-outline-dark no-print"><i class="bi bi-printer me-1"></i>Print</button>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-3">
    <div class="col-md-4 col-lg-2">
        <div class="card stat-card success">
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value"><?= formatCurrency($totalRevenue) ?></div>
            <small class="text-muted"><?= formatCurrency($totalRevenue * $exchangeRate, 'LBP') ?></small>
        </div>
    </div>
    <div class="col-md-4 col-lg-2">
        <div class="card stat-card info">
            <div class="stat-label">Total Profit</div>
            <div class="stat-value"><?= formatCurrency($totalProfit) ?></div>
            <small class="text-muted"><?= $totalRevenue > 0 ? round($totalProfit / $totalRevenue * 100, 1) : 0 ?>% margin</small>
        </div>
    </div>
    <div class="col-md-4 col-lg-2">
        <div class="card stat-card">
            <div class="stat-label">Avg Transaction</div>
            <div class="stat-value"><?= formatCurrency($avgTransaction) ?></div>
            <small class="text-muted"><?= formatCurrency($avgTransaction * $exchangeRate, 'LBP') ?></small>
        </div>
    </div>
    <div class="col-md-4 col-lg-2">
        <div class="card stat-card warning">
            <div class="stat-label">Transactions</div>
            <div class="stat-value"><?= number_format($totalTransactions) ?></div>
            <small class="text-muted"><?= round($totalTransactions / max(1, $prevDaysDiff), 1) ?>/day avg</small>
        </div>
    </div>
    <div class="col-md-4 col-lg-2">
        <div class="card stat-card">
            <div class="stat-label">Top Medicine</div>
            <div class="stat-value small"><?= sanitize($topMedicine['name'] ?? 'N/A') ?></div>
            <small class="text-muted"><?= $topMedicine ? $topMedicine['qty'] . ' sold' : '' ?></small>
        </div>
    </div>
    <div class="col-md-4 col-lg-2">
        <div class="card stat-card <?= $growthPct >= 0 ? 'success' : 'danger' ?>">
            <div class="stat-label">Growth vs Prev</div>
            <div class="stat-value"><?= ($growthPct >= 0 ? '+' : '') . $growthPct ?>%</div>
            <small class="text-muted">Prev: <?= formatCurrency($prevRevenue) ?></small>
        </div>
    </div>
</div>

<!-- Charts Row 1 -->
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-graph-up me-2"></i>Revenue & Profit Trend</h6>
            <canvas id="trendChart" height="100"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-pie-chart me-2"></i>Payment Methods</h6>
            <canvas id="paymentChart" height="200"></canvas>
        </div>
    </div>
</div>

<!-- Charts Row 2 -->
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-bar-chart me-2"></i>Top 10 Medicines by Revenue</h6>
            <canvas id="topMedChart" height="200"></canvas>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-clock me-2"></i>Sales by Hour of Day</h6>
            <canvas id="hourlyChart" height="200"></canvas>
        </div>
    </div>
</div>

<!-- Charts Row 3 -->
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-diagram-3 me-2"></i>Revenue by Category</h6>
            <canvas id="categoryChart" height="200"></canvas>
        </div>
    </div>
</div>

<!-- Tables -->
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-trophy me-2"></i>Top Performing Medicines</h6></div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Medicine</th>
                            <th class="text-end">Qty Sold</th>
                            <th class="text-end">Revenue (USD)</th>
                            <th class="text-end">Revenue (LBP)</th>
                            <th class="text-end">Profit</th>
                            <th class="text-end">Margin %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topMedicines as $i => $m): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= sanitize($m['name']) ?></strong></td>
                            <td class="text-end"><?= number_format($m['qty_sold']) ?></td>
                            <td class="text-end"><?= formatCurrency($m['revenue']) ?></td>
                            <td class="text-end"><small class="text-muted"><?= formatCurrency($m['revenue'] * $exchangeRate, 'LBP') ?></small></td>
                            <td class="text-end text-success"><?= formatCurrency($m['profit']) ?></td>
                            <td class="text-end">
                                <span class="badge bg-<?= $m['margin_pct'] >= 20 ? 'success' : ($m['margin_pct'] >= 10 ? 'warning' : 'danger') ?>"><?= $m['margin_pct'] ?>%</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topMedicines)): ?><tr><td colspan="7" class="text-center text-muted py-3">No sales data for this period</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-people me-2"></i>Sales by Cashier</h6></div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 data-table">
                    <thead>
                        <tr>
                            <th>Cashier</th>
                            <th class="text-end">Sales</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">LBP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cashierData as $c): ?>
                        <tr>
                            <td><?= sanitize($c['full_name'] ?? 'Unknown') ?></td>
                            <td class="text-end"><?= $c['tx_count'] ?></td>
                            <td class="text-end fw-semibold"><?= formatCurrency($c['revenue']) ?></td>
                            <td class="text-end"><small class="text-muted"><?= formatCurrency($c['revenue'] * $exchangeRate, 'LBP') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($cashierData)): ?><tr><td colspan="4" class="text-center text-muted py-3">No data</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$payColors = ['#198754','#0d6efd','#ffc107','#dc3545','#6f42c1','#0dcaf0','#fd7e14','#20c997'];
$catColors = ['#0d6efd','#198754','#ffc107','#dc3545','#6f42c1','#0dcaf0','#fd7e14','#20c997','#d63384','#6610f2','#adb5bd','#495057'];

$extraScripts = "<script>
// Revenue & Profit Trend
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: " . json_encode($trendLabels) . ",
        datasets: [
            {
                label: 'Revenue',
                data: " . json_encode($trendRevenue) . ",
                borderColor: '#198754',
                backgroundColor: 'rgba(25,135,84,0.1)',
                fill: true,
                tension: 0.3
            },
            {
                label: 'Profit',
                data: " . json_encode($trendProfit) . ",
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13,110,253,0.1)',
                fill: true,
                tension: 0.3
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true } }
    }
});

// Top 10 Medicines
new Chart(document.getElementById('topMedChart'), {
    type: 'bar',
    data: {
        labels: " . json_encode($topMedLabels) . ",
        datasets: [{
            label: 'Revenue (\$)',
            data: " . json_encode($topMedRevenues) . ",
            backgroundColor: '#0d6efd'
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true } }
    }
});

// Sales by Hour
new Chart(document.getElementById('hourlyChart'), {
    type: 'bar',
    data: {
        labels: " . json_encode($hourLabels) . ",
        datasets: [{
            label: 'Revenue (\$)',
            data: " . json_encode($hourRevenues) . ",
            backgroundColor: " . json_encode(array_map(function($v) {
                return $v > 0 ? 'rgba(13,110,253,0.7)' : 'rgba(13,110,253,0.2)';
            }, $hourRevenues)) . "
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

// Payment Methods
new Chart(document.getElementById('paymentChart'), {
    type: 'doughnut',
    data: {
        labels: " . json_encode($payLabels) . ",
        datasets: [{
            data: " . json_encode($payValues) . ",
            backgroundColor: " . json_encode(array_slice($payColors, 0, count($payLabels))) . "
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } }
    }
});

// Revenue by Category
new Chart(document.getElementById('categoryChart'), {
    type: 'pie',
    data: {
        labels: " . json_encode($catLabels) . ",
        datasets: [{
            data: " . json_encode($catValues) . ",
            backgroundColor: " . json_encode(array_slice($catColors, 0, count($catLabels))) . "
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'right' } }
    }
});
</script>";

require_once __DIR__ . '/../../includes/footer.php';
?>
