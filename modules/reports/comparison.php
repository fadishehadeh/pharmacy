<?php
$pageTitle = 'Period Comparison';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));

// Determine comparison periods
$preset = $_GET['preset'] ?? 'month';
$startA = $_GET['start_a'] ?? '';
$endA = $_GET['end_a'] ?? '';
$startB = $_GET['start_b'] ?? '';
$endB = $_GET['end_b'] ?? '';

switch ($preset) {
    case 'month':
        $startA = date('Y-m-01');
        $endA = date('Y-m-d');
        $startB = date('Y-m-01', strtotime('-1 month'));
        $endB = date('Y-m-t', strtotime('-1 month'));
        $labelA = 'This Month (' . date('M Y') . ')';
        $labelB = 'Last Month (' . date('M Y', strtotime('-1 month')) . ')';
        break;
    case 'quarter':
        $currentQ = ceil(date('n') / 3);
        $currentQStart = date('Y') . '-' . str_pad(($currentQ - 1) * 3 + 1, 2, '0', STR_PAD_LEFT) . '-01';
        $startA = $currentQStart;
        $endA = date('Y-m-d');
        $prevQ = $currentQ - 1;
        $prevY = date('Y');
        if ($prevQ <= 0) { $prevQ = 4; $prevY--; }
        $startB = $prevY . '-' . str_pad(($prevQ - 1) * 3 + 1, 2, '0', STR_PAD_LEFT) . '-01';
        $endB = date('Y-m-t', strtotime($prevY . '-' . str_pad($prevQ * 3, 2, '0', STR_PAD_LEFT) . '-01'));
        $labelA = "Q{$currentQ} " . date('Y');
        $labelB = "Q{$prevQ} {$prevY}";
        break;
    case 'year':
        $startA = date('Y') . '-01-01';
        $endA = date('Y-m-d');
        $startB = (date('Y') - 1) . '-01-01';
        $endB = (date('Y') - 1) . '-12-31';
        $labelA = 'This Year (' . date('Y') . ')';
        $labelB = 'Last Year (' . (date('Y') - 1) . ')';
        break;
    case 'custom':
        $labelA = 'Period A (' . formatDate($startA, 'M d') . ' - ' . formatDate($endA, 'M d, Y') . ')';
        $labelB = 'Period B (' . formatDate($startB, 'M d') . ' - ' . formatDate($endB, 'M d, Y') . ')';
        break;
    default:
        $preset = 'month';
        $startA = date('Y-m-01');
        $endA = date('Y-m-d');
        $startB = date('Y-m-01', strtotime('-1 month'));
        $endB = date('Y-m-t', strtotime('-1 month'));
        $labelA = 'This Month (' . date('M Y') . ')';
        $labelB = 'Last Month (' . date('M Y', strtotime('-1 month')) . ')';
}

// Helper function to get period metrics
function getPeriodMetrics($db, $start, $end) {
    $metrics = [];

    // Revenue, profit, transaction count
    $stmt = $db->prepare("SELECT
        COALESCE(SUM(s.total_amount), 0) as revenue,
        COUNT(s.id) as transactions,
        COALESCE(SUM(si_agg.profit), 0) as profit,
        COUNT(DISTINCT s.customer_id) as customer_count
        FROM sales s
        LEFT JOIN (
            SELECT si.sale_id, SUM(si.total_price - (si.cost_price * si.quantity)) as profit
            FROM sale_items si GROUP BY si.sale_id
        ) si_agg ON si_agg.sale_id = s.id
        WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?");
    $stmt->execute([$start, $end]);
    $row = $stmt->fetch();

    $metrics['revenue'] = floatval($row['revenue']);
    $metrics['profit'] = floatval($row['profit']);
    $metrics['transactions'] = intval($row['transactions']);
    $metrics['customer_count'] = intval($row['customer_count']);
    $metrics['avg_basket'] = $metrics['transactions'] > 0 ? $metrics['revenue'] / $metrics['transactions'] : 0;
    $metrics['margin'] = $metrics['revenue'] > 0 ? ($metrics['profit'] / $metrics['revenue']) * 100 : 0;

    // Expenses
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN ? AND ?");
    $stmt->execute([$start, $end]);
    $metrics['expenses'] = floatval($stmt->fetchColumn());

    // Items sold
    $stmt = $db->prepare("SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?");
    $stmt->execute([$start, $end]);
    $metrics['items_sold'] = intval($stmt->fetchColumn());

    // Top 5 medicines
    $stmt = $db->prepare("SELECT m.name, SUM(si.quantity) as qty, SUM(si.total_price) as revenue
        FROM sale_items si
        JOIN medicines m ON si.medicine_id = m.id
        JOIN sales s ON si.sale_id = s.id
        WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
        GROUP BY m.id ORDER BY qty DESC LIMIT 5");
    $stmt->execute([$start, $end]);
    $metrics['top_medicines'] = $stmt->fetchAll();

    // Inventory value at end (approximate current)
    $metrics['inventory_value'] = floatval($db->query("SELECT COALESCE(SUM(quantity_in_stock * cost_price), 0) FROM medicines WHERE is_active = 1")->fetchColumn());

    // Returns
    $stmt = $db->prepare("SELECT COALESCE(SUM(refund_amount), 0) FROM sale_returns WHERE DATE(return_date) BETWEEN ? AND ?");
    $stmt->execute([$start, $end]);
    $metrics['returns'] = floatval($stmt->fetchColumn());

    // Daily revenue for chart
    $stmt = $db->prepare("SELECT DATE(s.sale_date) as day, COALESCE(SUM(s.total_amount), 0) as revenue
        FROM sales s
        WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
        GROUP BY DATE(s.sale_date)
        ORDER BY day");
    $stmt->execute([$start, $end]);
    $metrics['daily_revenue'] = $stmt->fetchAll();

    return $metrics;
}

$metricsA = getPeriodMetrics($db, $startA, $endA);
$metricsB = getPeriodMetrics($db, $startB, $endB);

// Calculate changes
function calcChange($current, $previous) {
    if ($previous == 0) return $current > 0 ? 100 : 0;
    return round(($current - $previous) / $previous * 100, 1);
}
?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <div class="btn-group btn-group-sm">
        <a href="?preset=month" class="btn btn-<?= $preset === 'month' ? 'primary' : 'outline-primary' ?>">Month vs Month</a>
        <a href="?preset=quarter" class="btn btn-<?= $preset === 'quarter' ? 'primary' : 'outline-primary' ?>">Quarter vs Quarter</a>
        <a href="?preset=year" class="btn btn-<?= $preset === 'year' ? 'primary' : 'outline-primary' ?>">Year vs Year</a>
        <button class="btn btn-<?= $preset === 'custom' ? 'primary' : 'outline-primary' ?>" data-bs-toggle="collapse" data-bs-target="#customDates">Custom</button>
    </div>
    <button onclick="window.print()" class="btn btn-outline-dark btn-sm"><i class="bi bi-printer me-1"></i>Print</button>
</div>

<div class="collapse <?= $preset === 'custom' ? 'show' : '' ?> mb-3 no-print" id="customDates">
    <div class="card p-3">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="preset" value="custom">
            <div class="col-md-3">
                <label class="form-label small">Period A Start</label>
                <input type="date" class="form-control form-control-sm" name="start_a" value="<?= sanitize($startA) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Period A End</label>
                <input type="date" class="form-control form-control-sm" name="end_a" value="<?= sanitize($endA) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Period B Start</label>
                <input type="date" class="form-control form-control-sm" name="start_b" value="<?= sanitize($startB) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Period B End</label>
                <input type="date" class="form-control form-control-sm" name="end_b" value="<?= sanitize($endB) ?>" required>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100"><i class="bi bi-arrow-repeat me-1"></i>Compare</button>
            </div>
        </form>
    </div>
</div>

<div class="text-center mb-3">
    <h5 class="mb-1"><?= sanitize($labelA) ?> vs <?= sanitize($labelB) ?></h5>
    <small class="text-muted"><?= formatDate($startA, 'M d, Y') ?> - <?= formatDate($endA, 'M d, Y') ?> compared to <?= formatDate($startB, 'M d, Y') ?> - <?= formatDate($endB, 'M d, Y') ?></small>
</div>

<?php
// Comparison metrics data
$comparisons = [
    ['label' => 'Revenue', 'a' => $metricsA['revenue'], 'b' => $metricsB['revenue'], 'format' => 'currency'],
    ['label' => 'Gross Profit', 'a' => $metricsA['profit'], 'b' => $metricsB['profit'], 'format' => 'currency'],
    ['label' => 'Profit Margin', 'a' => $metricsA['margin'], 'b' => $metricsB['margin'], 'format' => 'percent'],
    ['label' => 'Transactions', 'a' => $metricsA['transactions'], 'b' => $metricsB['transactions'], 'format' => 'number'],
    ['label' => 'Avg Basket Size', 'a' => $metricsA['avg_basket'], 'b' => $metricsB['avg_basket'], 'format' => 'currency'],
    ['label' => 'Items Sold', 'a' => $metricsA['items_sold'], 'b' => $metricsB['items_sold'], 'format' => 'number'],
    ['label' => 'Customers', 'a' => $metricsA['customer_count'], 'b' => $metricsB['customer_count'], 'format' => 'number'],
    ['label' => 'Expenses', 'a' => $metricsA['expenses'], 'b' => $metricsB['expenses'], 'format' => 'currency', 'invert' => true],
    ['label' => 'Returns', 'a' => $metricsA['returns'], 'b' => $metricsB['returns'], 'format' => 'currency', 'invert' => true],
];
?>

<!-- Key Metric Cards -->
<div class="row g-3 mb-4">
    <?php
    $keyMetrics = array_slice($comparisons, 0, 4);
    foreach ($keyMetrics as $m):
        $change = calcChange($m['a'], $m['b']);
        $isPositive = isset($m['invert']) && $m['invert'] ? $change < 0 : $change > 0;
        $isNeutral = $change == 0;
    ?>
    <div class="col-md-3">
        <div class="card p-3">
            <div class="small text-muted mb-1"><?= $m['label'] ?></div>
            <div class="d-flex justify-content-between align-items-center">
                <div class="fw-bold fs-5">
                    <?php if ($m['format'] === 'currency'): ?><?= formatCurrency($m['a']) ?>
                    <?php elseif ($m['format'] === 'percent'): ?><?= round($m['a'], 1) ?>%
                    <?php else: ?><?= number_format($m['a']) ?>
                    <?php endif; ?>
                </div>
                <div class="text-end">
                    <?php if (!$isNeutral): ?>
                    <span class="badge bg-<?= $isPositive ? 'success' : 'danger' ?>">
                        <i class="bi bi-arrow-<?= $change > 0 ? 'up' : 'down' ?>"></i>
                        <?= abs($change) ?>%
                    </span>
                    <?php else: ?>
                    <span class="badge bg-secondary">0%</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="small text-muted mt-1">
                vs <?php if ($m['format'] === 'currency'): ?><?= formatCurrency($m['b']) ?>
                <?php elseif ($m['format'] === 'percent'): ?><?= round($m['b'], 1) ?>%
                <?php else: ?><?= number_format($m['b']) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Revenue Overlay Chart -->
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Revenue Trend Overlay</h6></div>
    <div class="card-body">
        <canvas id="comparisonChart" height="80"></canvas>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Full Comparison Table -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-table me-2"></i>Detailed Comparison</h6></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th class="text-end"><?= sanitize($labelA) ?></th>
                            <th class="text-end"><?= sanitize($labelB) ?></th>
                            <th class="text-end">Change</th>
                            <th class="text-end">% Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($comparisons as $m):
                            $change = calcChange($m['a'], $m['b']);
                            $diff = $m['a'] - $m['b'];
                            $isPositive = isset($m['invert']) && $m['invert'] ? $change < 0 : $change > 0;
                            $isNeutral = $change == 0;
                            $colorClass = $isNeutral ? '' : ($isPositive ? 'text-success' : 'text-danger');
                        ?>
                        <tr>
                            <td><strong><?= $m['label'] ?></strong></td>
                            <td class="text-end">
                                <?php if ($m['format'] === 'currency'): ?><?= formatCurrency($m['a']) ?>
                                <?php elseif ($m['format'] === 'percent'): ?><?= round($m['a'], 1) ?>%
                                <?php else: ?><?= number_format($m['a']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($m['format'] === 'currency'): ?><?= formatCurrency($m['b']) ?>
                                <?php elseif ($m['format'] === 'percent'): ?><?= round($m['b'], 1) ?>%
                                <?php else: ?><?= number_format($m['b']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end <?= $colorClass ?>">
                                <?php if (!$isNeutral): ?>
                                <i class="bi bi-arrow-<?= $diff > 0 ? 'up' : 'down' ?> me-1"></i>
                                <?php endif; ?>
                                <?php if ($m['format'] === 'currency'): ?><?= formatCurrency(abs($diff)) ?>
                                <?php elseif ($m['format'] === 'percent'): ?><?= round(abs($diff), 1) ?>pp
                                <?php else: ?><?= number_format(abs($diff)) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <span class="badge bg-<?= $isNeutral ? 'secondary' : ($isPositive ? 'success' : 'danger') ?>">
                                    <?= $change > 0 ? '+' : '' ?><?= $change ?>%
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Top Medicines Comparison -->
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-trophy me-2"></i>Top Medicines - <?= sanitize($labelA) ?></h6></div>
            <div class="card-body p-2">
                <?php if (!empty($metricsA['top_medicines'])): ?>
                <table class="table table-sm mb-0">
                    <?php foreach ($metricsA['top_medicines'] as $i => $tm): ?>
                    <tr>
                        <td><small class="text-muted"><?= $i + 1 ?>.</small> <?= sanitize($tm['name']) ?></td>
                        <td class="text-end small"><strong><?= $tm['qty'] ?></strong> units</td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php else: ?>
                <p class="text-muted small mb-0">No sales data</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-trophy me-2"></i>Top Medicines - <?= sanitize($labelB) ?></h6></div>
            <div class="card-body p-2">
                <?php if (!empty($metricsB['top_medicines'])): ?>
                <table class="table table-sm mb-0">
                    <?php foreach ($metricsB['top_medicines'] as $i => $tm): ?>
                    <tr>
                        <td><small class="text-muted"><?= $i + 1 ?>.</small> <?= sanitize($tm['name']) ?></td>
                        <td class="text-end small"><strong><?= $tm['qty'] ?></strong> units</td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php else: ?>
                <p class="text-muted small mb-0">No sales data</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
// Prepare chart data - normalize both periods to day index
$chartLabelsA = [];
$chartDataA = [];
$chartDataB = [];

$daysA = max(1, (strtotime($endA) - strtotime($startA)) / 86400 + 1);
$daysB = max(1, (strtotime($endB) - strtotime($startB)) / 86400 + 1);
$maxDays = max($daysA, $daysB);

// Build indexed arrays
$dailyA = [];
foreach ($metricsA['daily_revenue'] as $d) {
    $dayIndex = (strtotime($d['day']) - strtotime($startA)) / 86400;
    $dailyA[intval($dayIndex)] = floatval($d['revenue']);
}
$dailyB = [];
foreach ($metricsB['daily_revenue'] as $d) {
    $dayIndex = (strtotime($d['day']) - strtotime($startB)) / 86400;
    $dailyB[intval($dayIndex)] = floatval($d['revenue']);
}

$labels = [];
$dataA = [];
$dataB = [];
for ($i = 0; $i < $maxDays; $i++) {
    $labels[] = 'Day ' . ($i + 1);
    $dataA[] = $dailyA[$i] ?? 0;
    $dataB[] = $dailyB[$i] ?? 0;
}

$chartLabelsJson = json_encode($labels);
$chartDataAJson = json_encode($dataA);
$chartDataBJson = json_encode($dataB);
$labelAJs = json_encode($labelA);
$labelBJs = json_encode($labelB);

$extraScripts = <<<SCRIPT
<script>
var ctx = document.getElementById('comparisonChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: {$chartLabelsJson},
        datasets: [
            {
                label: {$labelAJs},
                data: {$chartDataAJson},
                borderColor: 'rgb(54, 162, 235)',
                backgroundColor: 'rgba(54, 162, 235, 0.1)',
                fill: true,
                tension: 0.3,
                borderWidth: 2
            },
            {
                label: {$labelBJs},
                data: {$chartDataBJson},
                borderColor: 'rgb(255, 159, 64)',
                backgroundColor: 'rgba(255, 159, 64, 0.1)',
                fill: true,
                tension: 0.3,
                borderWidth: 2,
                borderDash: [5, 5]
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { callback: function(v) { return '$' + v.toLocaleString(); } }
            }
        },
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(ctx) { return ctx.dataset.label + ': $' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits:2}); }
                }
            }
        }
    }
});
</script>
SCRIPT;

require_once __DIR__ . '/../../includes/footer.php';
?>
