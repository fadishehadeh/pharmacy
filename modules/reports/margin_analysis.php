<?php
$pageTitle = 'Margin Analysis';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

$exchangeRate = getSetting('exchange_rate', '89500');
$marginThreshold = floatval($_GET['threshold'] ?? getSetting('margin_threshold', '15'));

// All active medicines with margin calculation
$medicines = $db->query("SELECT m.id, m.name, m.cost_price, m.sell_price, m.quantity_in_stock,
    m.moph_price, m.is_active,
    c.name as category_name,
    CASE WHEN m.sell_price > 0 THEN ROUND(((m.sell_price - m.cost_price) / m.sell_price) * 100, 2) ELSE 0 END as margin_pct,
    CASE WHEN m.cost_price > 0 THEN ROUND(((m.sell_price - m.cost_price) / m.cost_price) * 100, 2) ELSE 0 END as markup_pct,
    COALESCE(SUM(si.quantity), 0) as qty_sold_30d,
    COALESCE(SUM(si.total_price), 0) as revenue_30d,
    COALESCE(SUM(si.cost_price * si.quantity), 0) as cost_30d
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    LEFT JOIN sale_items si ON si.medicine_id = m.id
    LEFT JOIN sales s ON si.sale_id = s.id AND s.status = 'completed' AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    WHERE m.is_active = 1
    GROUP BY m.id
    ORDER BY margin_pct ASC")->fetchAll();

// Category averages
$categoryMargins = $db->query("SELECT c.name as category_name,
    COUNT(m.id) as med_count,
    ROUND(AVG(CASE WHEN m.sell_price > 0 THEN ((m.sell_price - m.cost_price) / m.sell_price) * 100 ELSE 0 END), 1) as avg_margin,
    ROUND(MIN(CASE WHEN m.sell_price > 0 THEN ((m.sell_price - m.cost_price) / m.sell_price) * 100 ELSE 0 END), 1) as min_margin,
    ROUND(MAX(CASE WHEN m.sell_price > 0 THEN ((m.sell_price - m.cost_price) / m.sell_price) * 100 ELSE 0 END), 1) as max_margin
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE m.is_active = 1 AND m.sell_price > 0
    GROUP BY c.id
    ORDER BY avg_margin DESC")->fetchAll();

// Stats
$totalMedicines = count($medicines);
$belowThreshold = array_filter($medicines, fn($m) => $m['margin_pct'] < $marginThreshold);
$belowThresholdCount = count($belowThreshold);
$avgMargin = $totalMedicines > 0 ? round(array_sum(array_column($medicines, 'margin_pct')) / $totalMedicines, 1) : 0;
$negativeMarginsCount = count(array_filter($medicines, fn($m) => $m['margin_pct'] < 0));

// Margin distribution for histogram
$distribution = [
    'negative' => count(array_filter($medicines, fn($m) => $m['margin_pct'] < 0)),
    '0-10' => count(array_filter($medicines, fn($m) => $m['margin_pct'] >= 0 && $m['margin_pct'] < 10)),
    '10-20' => count(array_filter($medicines, fn($m) => $m['margin_pct'] >= 10 && $m['margin_pct'] < 20)),
    '20-30' => count(array_filter($medicines, fn($m) => $m['margin_pct'] >= 20 && $m['margin_pct'] < 30)),
    '30-40' => count(array_filter($medicines, fn($m) => $m['margin_pct'] >= 30 && $m['margin_pct'] < 40)),
    '40-50' => count(array_filter($medicines, fn($m) => $m['margin_pct'] >= 40 && $m['margin_pct'] < 50)),
    '50+' => count(array_filter($medicines, fn($m) => $m['margin_pct'] >= 50)),
];

// Filter
$filterMargin = $_GET['filter'] ?? '';
if ($filterMargin === 'low') {
    $medicines = array_filter($medicines, fn($m) => $m['margin_pct'] < $marginThreshold);
} elseif ($filterMargin === 'negative') {
    $medicines = array_filter($medicines, fn($m) => $m['margin_pct'] < 0);
}
?>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card info">
            <div class="stat-label">Average Margin</div>
            <div class="stat-value"><?= $avgMargin ?>%</div>
            <small class="text-muted"><?= $totalMedicines ?> active medicines</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="stat-label">Below <?= $marginThreshold ?>% Threshold</div>
            <div class="stat-value"><?= $belowThresholdCount ?></div>
            <small class="text-muted"><?= $totalMedicines > 0 ? round($belowThresholdCount / $totalMedicines * 100) : 0 ?>% of total</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card danger">
            <div class="stat-label">Negative Margins</div>
            <div class="stat-value"><?= $negativeMarginsCount ?></div>
            <small class="text-muted">Selling below cost</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3">
            <form class="d-flex gap-2" method="GET">
                <div>
                    <label class="form-label small mb-1">Threshold %</label>
                    <input type="number" class="form-control form-control-sm" name="threshold" value="<?= $marginThreshold ?>" min="0" max="100" step="1">
                </div>
                <div class="align-self-end">
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i></button>
                </div>
            </form>
            <div class="btn-group btn-group-sm mt-2 w-100">
                <a href="?threshold=<?= $marginThreshold ?>" class="btn btn-<?= !$filterMargin ? 'dark' : 'outline-dark' ?>">All</a>
                <a href="?threshold=<?= $marginThreshold ?>&filter=low" class="btn btn-<?= $filterMargin === 'low' ? 'warning' : 'outline-warning' ?>">Low</a>
                <a href="?threshold=<?= $marginThreshold ?>&filter=negative" class="btn btn-<?= $filterMargin === 'negative' ? 'danger' : 'outline-danger' ?>">Negative</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-percent me-2"></i>Medicine Margins</h6>
                <button onclick="window.print()" class="btn btn-sm btn-outline-dark no-print"><i class="bi bi-printer me-1"></i>Print</button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 data-table">
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Category</th>
                            <th class="text-end">Cost</th>
                            <th class="text-end">Sell</th>
                            <th class="text-end">Margin %</th>
                            <th class="text-end">Markup %</th>
                            <th>MoPH</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medicines as $m): ?>
                        <?php
                        if ($m['margin_pct'] < 10) $rowClass = 'table-danger';
                        elseif ($m['margin_pct'] < 20) $rowClass = 'table-warning';
                        else $rowClass = '';
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td><strong class="small"><?= sanitize($m['name']) ?></strong></td>
                            <td><small><?= sanitize($m['category_name'] ?? '-') ?></small></td>
                            <td class="text-end"><?= formatCurrency($m['cost_price']) ?></td>
                            <td class="text-end"><?= formatCurrency($m['sell_price']) ?></td>
                            <td class="text-end fw-semibold">
                                <?php if ($m['margin_pct'] < 0): ?>
                                <span class="text-danger"><?= $m['margin_pct'] ?>%</span>
                                <?php elseif ($m['margin_pct'] < 10): ?>
                                <span class="text-danger"><?= $m['margin_pct'] ?>%</span>
                                <?php elseif ($m['margin_pct'] < 20): ?>
                                <span class="text-warning"><?= $m['margin_pct'] ?>%</span>
                                <?php else: ?>
                                <span class="text-success"><?= $m['margin_pct'] ?>%</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= $m['markup_pct'] ?>%</td>
                            <td>
                                <?php if ($m['moph_price'] && $m['moph_price'] > 0): ?>
                                    <?= formatCurrency($m['moph_price']) ?>
                                    <?php if ($m['sell_price'] > $m['moph_price']): ?>
                                    <span class="badge bg-danger ms-1" title="Above MoPH price"><i class="bi bi-exclamation"></i></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <small class="text-muted">-</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($m['margin_pct'] < 0): ?>
                                <span class="badge bg-danger">Loss</span>
                                <?php elseif ($m['margin_pct'] < 10): ?>
                                <span class="badge bg-danger">Critical</span>
                                <?php elseif ($m['margin_pct'] < 20): ?>
                                <span class="badge bg-warning">Low</span>
                                <?php else: ?>
                                <span class="badge bg-success">Good</span>
                                <?php endif; ?>
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
            <h6><i class="bi bi-bar-chart me-2"></i>Margin Distribution</h6>
            <canvas id="marginHistogram" height="220"></canvas>
        </div>
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-pie-chart me-2"></i>Category Margins</h6>
            <canvas id="categoryChart" height="220"></canvas>
        </div>
        <div class="card p-3">
            <h6><i class="bi bi-list-ul me-2"></i>Category Breakdown</h6>
            <div class="list-group list-group-flush" style="max-height:300px;overflow-y:auto">
                <?php foreach ($categoryMargins as $cm): ?>
                <div class="list-group-item px-0 py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="fw-bold"><?= sanitize($cm['category_name'] ?? 'Uncategorized') ?></small>
                        <span class="badge bg-<?= $cm['avg_margin'] < 10 ? 'danger' : ($cm['avg_margin'] < 20 ? 'warning' : 'success') ?>"><?= $cm['avg_margin'] ?>%</span>
                    </div>
                    <small class="text-muted"><?= $cm['med_count'] ?> items | Min: <?= $cm['min_margin'] ?>% | Max: <?= $cm['max_margin'] ?>%</small>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php
$catLabels = array_map(function($c) { return $c['category_name'] ?? 'Uncategorized'; }, $categoryMargins);
$catValues = array_column($categoryMargins, 'avg_margin');
$catColors = array_map(function($v) {
    if ($v < 10) return '#DC2626';
    if ($v < 20) return '#D97706';
    return '#059669';
}, $catValues);

$extraScripts = "<script>
new Chart(document.getElementById('marginHistogram'), {
    type: 'bar',
    data: {
        labels: ['<0%', '0-10%', '10-20%', '20-30%', '30-40%', '40-50%', '50%+'],
        datasets: [{
            label: 'Medicines',
            data: " . json_encode(array_values($distribution)) . ",
            backgroundColor: ['#DC2626','#F87171','#D97706','#059669','#059669','#059669','#059669']
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});

new Chart(document.getElementById('categoryChart'), {
    type: 'bar',
    data: {
        labels: " . json_encode($catLabels) . ",
        datasets: [{
            label: 'Avg Margin %',
            data: " . json_encode($catValues) . ",
            backgroundColor: " . json_encode($catColors) . "
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, title: { display: true, text: 'Margin %' } } }
    }
});
</script>";

require_once __DIR__ . '/../../includes/footer.php';
?>
