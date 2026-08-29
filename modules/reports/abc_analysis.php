<?php
$pageTitle = 'ABC Analysis';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$period = intval($_GET['period'] ?? 90);

$items = $db->prepare("SELECT m.id, m.name, m.category_id, m.cost_price, m.sell_price, m.quantity_in_stock,
    c.name as category_name,
    COALESCE(SUM(si.quantity), 0) as qty_sold,
    COALESCE(SUM(si.total_price), 0) as revenue,
    COALESCE(SUM(si.total_price - (si.cost_price * si.quantity)), 0) as profit,
    (m.quantity_in_stock * m.cost_price) as stock_value
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    LEFT JOIN sale_items si ON si.medicine_id = m.id
    LEFT JOIN sales s ON si.sale_id = s.id AND s.status = 'completed' AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    WHERE m.is_active = 1
    GROUP BY m.id
    ORDER BY revenue DESC");
$items->execute([$period]);
$items = $items->fetchAll();

$totalRevenue = array_sum(array_column($items, 'revenue'));
$cumulative = 0;
$classified = [];
foreach ($items as &$item) {
    $cumulative += $item['revenue'];
    $pct = $totalRevenue > 0 ? ($cumulative / $totalRevenue * 100) : 0;
    if ($pct <= 80) {
        $item['class'] = 'A';
    } elseif ($pct <= 95) {
        $item['class'] = 'B';
    } else {
        $item['class'] = 'C';
    }
    $item['cumulative_pct'] = round($pct, 1);
    $classified[] = $item;
}

$classA = array_filter($classified, fn($i) => $i['class'] === 'A');
$classB = array_filter($classified, fn($i) => $i['class'] === 'B');
$classC = array_filter($classified, fn($i) => $i['class'] === 'C');

$aRevenue = array_sum(array_column($classA, 'revenue'));
$bRevenue = array_sum(array_column($classB, 'revenue'));
$cRevenue = array_sum(array_column($classC, 'revenue'));
$aProfit = array_sum(array_column($classA, 'profit'));

$filterClass = $_GET['class'] ?? '';
if ($filterClass) {
    $classified = array_filter($classified, fn($i) => $i['class'] === strtoupper($filterClass));
}
?>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card success">
            <div class="stat-label">Class A (Top 80%)</div>
            <div class="stat-value"><?= count($classA) ?> items</div>
            <small class="text-muted"><?= formatCurrency($aRevenue) ?> revenue</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="stat-label">Class B (Next 15%)</div>
            <div class="stat-value"><?= count($classB) ?> items</div>
            <small class="text-muted"><?= formatCurrency($bRevenue) ?> revenue</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Class C (Last 5%)</div>
            <div class="stat-value"><?= count($classC) ?> items</div>
            <small class="text-muted"><?= formatCurrency($cRevenue) ?> revenue</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3">
            <form class="d-flex gap-2" method="GET">
                <select class="form-select form-select-sm" name="period">
                    <option value="30" <?= $period == 30 ? 'selected' : '' ?>>30 days</option>
                    <option value="60" <?= $period == 60 ? 'selected' : '' ?>>60 days</option>
                    <option value="90" <?= $period == 90 ? 'selected' : '' ?>>90 days</option>
                    <option value="180" <?= $period == 180 ? 'selected' : '' ?>>180 days</option>
                    <option value="365" <?= $period == 365 ? 'selected' : '' ?>>1 year</option>
                </select>
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i></button>
            </form>
            <div class="btn-group btn-group-sm mt-2 w-100">
                <a href="?period=<?= $period ?>" class="btn btn-<?= !$filterClass ? 'dark' : 'outline-dark' ?>">All</a>
                <a href="?period=<?= $period ?>&class=A" class="btn btn-<?= $filterClass === 'A' ? 'success' : 'outline-success' ?>">A</a>
                <a href="?period=<?= $period ?>&class=B" class="btn btn-<?= $filterClass === 'B' ? 'warning' : 'outline-warning' ?>">B</a>
                <a href="?period=<?= $period ?>&class=C" class="btn btn-<?= $filterClass === 'C' ? 'secondary' : 'outline-secondary' ?>">C</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>ABC Classification (<?= $period ?>-day period)</h6>
                <button onclick="window.print()" class="btn btn-sm btn-outline-dark no-print"><i class="bi bi-printer me-1"></i>Print</button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 data-table">
                    <thead><tr><th>#</th><th>Medicine</th><th>Category</th><th>Class</th><th>Qty Sold</th><th>Revenue</th><th>Profit</th><th>Stock Value</th><th>Cum %</th></tr></thead>
                    <tbody>
                        <?php foreach ($classified as $i => $item): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong class="small"><?= sanitize($item['name']) ?></strong></td>
                            <td><small><?= sanitize($item['category_name'] ?? '-') ?></small></td>
                            <td>
                                <?php $classColors = ['A' => 'success', 'B' => 'warning', 'C' => 'secondary']; ?>
                                <span class="badge bg-<?= $classColors[$item['class']] ?>"><?= $item['class'] ?></span>
                            </td>
                            <td><?= number_format($item['qty_sold']) ?></td>
                            <td><?= formatCurrency($item['revenue']) ?></td>
                            <td class="text-<?= $item['profit'] >= 0 ? 'success' : 'danger' ?>"><?= formatCurrency($item['profit']) ?></td>
                            <td><?= formatCurrency($item['stock_value']) ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-1">
                                    <div class="progress" style="height:4px;width:50px"><div class="progress-bar bg-<?= $classColors[$item['class']] ?>" style="width:<?= min($item['cumulative_pct'], 100) ?>%"></div></div>
                                    <small><?= $item['cumulative_pct'] ?>%</small>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-pie-chart me-2"></i>Revenue Distribution</h6>
            <canvas id="abcChart" height="250"></canvas>
        </div>
        <div class="card p-3">
            <h6><i class="bi bi-lightbulb me-2"></i>Insights</h6>
            <div class="small text-muted">
                <p><span class="badge bg-success">A</span> <strong>High priority:</strong> <?= count($classA) ?> items generating <?= $totalRevenue > 0 ? round($aRevenue / $totalRevenue * 100) : 0 ?>% of revenue. Keep always in stock, negotiate best prices.</p>
                <p><span class="badge bg-warning">B</span> <strong>Medium priority:</strong> <?= count($classB) ?> items. Regular monitoring, standard reorder levels.</p>
                <p class="mb-0"><span class="badge bg-secondary">C</span> <strong>Low priority:</strong> <?= count($classC) ?> items. Consider reducing stock levels, dropping slow movers.</p>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = "<script>
new Chart(document.getElementById('abcChart'), {
    type: 'doughnut',
    data: {
        labels: ['A - " . count($classA) . " items', 'B - " . count($classB) . " items', 'C - " . count($classC) . " items'],
        datasets: [{
            data: [" . round($aRevenue, 2) . "," . round($bRevenue, 2) . "," . round($cRevenue, 2) . "],
            backgroundColor: ['#059669','#D97706','#9CA3AF']
        }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
});
</script>";
require_once __DIR__ . '/../../includes/footer.php';
?>
