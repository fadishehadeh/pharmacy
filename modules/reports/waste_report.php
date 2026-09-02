<?php
$pageTitle = 'Waste Report';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));

// Period handling
$period = $_GET['period'] ?? 'year';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

if ($period === 'custom' && $startDate && $endDate) {
    $dateFrom = $startDate;
    $dateTo = $endDate;
} elseif ($period === 'month') {
    $dateFrom = date('Y-m-01');
    $dateTo = date('Y-m-t');
} elseif ($period === 'quarter') {
    $quarter = ceil(date('n') / 3);
    $dateFrom = date('Y-' . str_pad(($quarter - 1) * 3 + 1, 2, '0', STR_PAD_LEFT) . '-01');
    $dateTo = date('Y-m-t', strtotime($dateFrom . ' +2 months'));
} else {
    $period = 'year';
    $dateFrom = date('Y-01-01');
    $dateTo = date('Y-12-31');
}

// KPI: Total Waste Value
$totalWaste = $db->prepare("SELECT COALESCE(SUM(cost_value), 0) FROM waste_disposal WHERE disposal_date BETWEEN ? AND ?");
$totalWaste->execute([$dateFrom, $dateTo]);
$totalWasteValue = floatval($totalWaste->fetchColumn());

// KPI: Total Items Disposed
$totalItems = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM waste_disposal WHERE disposal_date BETWEEN ? AND ?");
$totalItems->execute([$dateFrom, $dateTo]);
$totalItemsDisposed = intval($totalItems->fetchColumn());

// KPI: Monthly average
$monthsDiff = max(1, round((strtotime($dateTo) - strtotime($dateFrom)) / (86400 * 30)));
$avgMonthlyLoss = $totalWasteValue / $monthsDiff;

// KPI: % of Inventory Value
$inventoryValue = floatval($db->query("SELECT COALESCE(SUM(cost_price * quantity_in_stock), 0) FROM medicines WHERE is_active = 1")->fetchColumn());
$wastePercent = $inventoryValue > 0 ? round($totalWasteValue / $inventoryValue * 100, 2) : 0;

// Monthly waste trend
$monthlyTrend = $db->prepare("SELECT DATE_FORMAT(disposal_date, '%Y-%m') as month_key,
    DATE_FORMAT(disposal_date, '%b %Y') as month_label,
    COALESCE(SUM(cost_value), 0) as value,
    COALESCE(SUM(quantity), 0) as qty
    FROM waste_disposal
    WHERE disposal_date BETWEEN ? AND ?
    GROUP BY month_key
    ORDER BY month_key ASC");
$monthlyTrend->execute([$dateFrom, $dateTo]);
$monthlyTrend = $monthlyTrend->fetchAll();

// Waste by reason
$byReason = $db->prepare("SELECT reason, COALESCE(SUM(cost_value), 0) as value, COALESCE(SUM(quantity), 0) as qty
    FROM waste_disposal
    WHERE disposal_date BETWEEN ? AND ?
    GROUP BY reason
    ORDER BY value DESC");
$byReason->execute([$dateFrom, $dateTo]);
$byReason = $byReason->fetchAll();

// Waste by category
$byCategory = $db->prepare("SELECT COALESCE(c.name, 'Uncategorized') as category_name,
    COALESCE(SUM(wd.cost_value), 0) as value, COALESCE(SUM(wd.quantity), 0) as qty
    FROM waste_disposal wd
    JOIN medicines m ON wd.medicine_id = m.id
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE wd.disposal_date BETWEEN ? AND ?
    GROUP BY c.id
    ORDER BY value DESC");
$byCategory->execute([$dateFrom, $dateTo]);
$byCategory = $byCategory->fetchAll();

// Top 10 wasted medicines
$topWasted = $db->prepare("SELECT m.name, COALESCE(SUM(wd.cost_value), 0) as value,
    COALESCE(SUM(wd.quantity), 0) as qty
    FROM waste_disposal wd
    JOIN medicines m ON wd.medicine_id = m.id
    WHERE wd.disposal_date BETWEEN ? AND ?
    GROUP BY m.id
    ORDER BY value DESC
    LIMIT 10");
$topWasted->execute([$dateFrom, $dateTo]);
$topWasted = $topWasted->fetchAll();

// Cost recovery: returned to supplier vs destroyed
$recovery = $db->prepare("SELECT disposal_method,
    COALESCE(SUM(cost_value), 0) as value, COALESCE(SUM(quantity), 0) as qty
    FROM waste_disposal
    WHERE disposal_date BETWEEN ? AND ?
    GROUP BY disposal_method
    ORDER BY value DESC");
$recovery->execute([$dateFrom, $dateTo]);
$recovery = $recovery->fetchAll();

$returnedValue = 0;
$destroyedValue = 0;
foreach ($recovery as $r) {
    if ($r['disposal_method'] === 'return_supplier') {
        $returnedValue += $r['value'];
    } else {
        $destroyedValue += $r['value'];
    }
}

// Expired stock still on shelves
$expiredOnShelf = $db->query("SELECT m.name, m.quantity_in_stock, m.cost_price, m.expiry_date,
    (m.quantity_in_stock * m.cost_price) as shelf_value,
    DATEDIFF(CURDATE(), m.expiry_date) as days_expired
    FROM medicines m
    WHERE m.is_active = 1 AND m.expiry_date < CURDATE() AND m.quantity_in_stock > 0
    ORDER BY shelf_value DESC")->fetchAll();
$expiredShelfValue = array_sum(array_column($expiredOnShelf, 'shelf_value'));

// Detailed disposal records
$details = $db->prepare("SELECT wd.*, m.name as med_name, c.name as category_name
    FROM waste_disposal wd
    JOIN medicines m ON wd.medicine_id = m.id
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE wd.disposal_date BETWEEN ? AND ?
    ORDER BY wd.disposal_date DESC");
$details->execute([$dateFrom, $dateTo]);
$details = $details->fetchAll();

// Recommendations based on data
$recommendations = [];
if ($wastePercent > 5) {
    $recommendations[] = ['danger', 'Waste exceeds 5% of inventory value. Review purchasing quantities and supplier return policies.'];
}
if (count($expiredOnShelf) > 0) {
    $recommendations[] = ['warning', count($expiredOnShelf) . ' expired items (' . formatCurrency($expiredShelfValue) . ') still on shelves. Dispose immediately per MoPH guidelines.'];
}
if (!empty($topWasted)) {
    $recommendations[] = ['info', 'Reduce order quantity for frequently wasted items: ' . sanitize($topWasted[0]['name']) . ' (' . formatCurrency($topWasted[0]['value']) . ' wasted).'];
}
if ($returnedValue > 0) {
    $recoveryRate = round($returnedValue / max(1, $totalWasteValue) * 100, 1);
    $recommendations[] = ['success', 'Supplier return recovery rate: ' . $recoveryRate . '%. Negotiate better return policies to improve recovery.'];
}
if ($avgMonthlyLoss > 100) {
    $recommendations[] = ['warning', 'Average monthly loss of ' . formatCurrency($avgMonthlyLoss) . '. Consider implementing FEFO (First Expiry, First Out) strictly.'];
}
if (empty($recommendations)) {
    $recommendations[] = ['success', 'Waste levels appear manageable. Continue monitoring monthly.'];
}
?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <div class="btn-group btn-group-sm">
        <a href="?period=month" class="btn btn-<?= $period === 'month' ? 'dark' : 'outline-dark' ?>">This Month</a>
        <a href="?period=quarter" class="btn btn-<?= $period === 'quarter' ? 'dark' : 'outline-dark' ?>">Quarter</a>
        <a href="?period=year" class="btn btn-<?= $period === 'year' ? 'dark' : 'outline-dark' ?>">Year</a>
        <button class="btn btn-<?= $period === 'custom' ? 'dark' : 'outline-dark' ?>" data-bs-toggle="collapse" data-bs-target="#customRange">Custom</button>
    </div>
    <button onclick="window.print()" class="btn btn-sm btn-outline-dark no-print"><i class="bi bi-printer me-1"></i>Print</button>
</div>

<div class="collapse <?= $period === 'custom' ? 'show' : '' ?> mb-3 no-print" id="customRange">
    <form class="card p-3" method="GET">
        <input type="hidden" name="period" value="custom">
        <div class="row g-2 align-items-end">
            <div class="col-auto"><label class="form-label small mb-1">From</label><input type="date" class="form-control form-control-sm" name="start_date" value="<?= sanitize($startDate ?: $dateFrom) ?>"></div>
            <div class="col-auto"><label class="form-label small mb-1">To</label><input type="date" class="form-control form-control-sm" name="end_date" value="<?= sanitize($endDate ?: $dateTo) ?>"></div>
            <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button></div>
        </div>
    </form>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card danger">
            <div class="stat-label">Total Waste Value</div>
            <div class="stat-value"><?= formatCurrency($totalWasteValue) ?></div>
            <small class="text-muted"><?= formatCurrency($totalWasteValue * $exchangeRate, 'LBP') ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="stat-label">Items Disposed</div>
            <div class="stat-value"><?= number_format($totalItemsDisposed) ?></div>
            <small class="text-muted">units in period</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Avg Monthly Loss</div>
            <div class="stat-value"><?= formatCurrency($avgMonthlyLoss) ?></div>
            <small class="text-muted">over <?= $monthsDiff ?> month(s)</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card <?= $wastePercent > 5 ? 'danger' : ($wastePercent > 2 ? 'warning' : 'info') ?>">
            <div class="stat-label">% of Inventory</div>
            <div class="stat-value"><?= $wastePercent ?>%</div>
            <small class="text-muted">of <?= formatCurrency($inventoryValue) ?> total</small>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card p-3">
            <h6><i class="bi bi-bar-chart me-2"></i>Monthly Waste Trend</h6>
            <canvas id="trendChart" height="200"></canvas>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-3">
            <h6><i class="bi bi-pie-chart me-2"></i>Waste by Reason</h6>
            <canvas id="reasonChart" height="200"></canvas>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card p-3">
            <h6><i class="bi bi-bar-chart-horizontal me-2"></i>Waste by Category</h6>
            <canvas id="categoryChart" height="200"></canvas>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-3">
            <h6><i class="bi bi-bar-chart me-2"></i>Top 10 Wasted Medicines</h6>
            <canvas id="topChart" height="200"></canvas>
        </div>
    </div>
</div>

<!-- Cost Recovery & Recommendations -->
<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-arrow-return-left me-2"></i>Cost Recovery</h6>
            <div class="list-group list-group-flush">
                <div class="list-group-item px-0 py-2 d-flex justify-content-between">
                    <div><small class="fw-bold text-success">Returned to Supplier</small></div>
                    <strong class="small text-success"><?= formatCurrency($returnedValue) ?></strong>
                </div>
                <div class="list-group-item px-0 py-2 d-flex justify-content-between">
                    <div><small class="fw-bold text-danger">Destroyed / Other</small></div>
                    <strong class="small text-danger"><?= formatCurrency($destroyedValue) ?></strong>
                </div>
                <?php foreach ($recovery as $r): ?>
                <div class="list-group-item px-0 py-2 d-flex justify-content-between">
                    <small><?= ucfirst(str_replace('_', ' ', sanitize($r['disposal_method']))) ?></small>
                    <div class="text-end">
                        <small><?= formatCurrency($r['value']) ?></small><br>
                        <small class="text-muted"><?= number_format($r['qty']) ?> units</small>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!empty($expiredOnShelf)): ?>
        <div class="card p-3 border-danger">
            <h6 class="text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Expired on Shelves</h6>
            <p class="small text-muted mb-2"><?= count($expiredOnShelf) ?> items worth <?= formatCurrency($expiredShelfValue) ?></p>
            <div class="list-group list-group-flush" style="max-height:250px;overflow-y:auto">
                <?php foreach ($expiredOnShelf as $es): ?>
                <div class="list-group-item px-0 py-2 d-flex justify-content-between">
                    <div>
                        <small class="fw-bold"><?= sanitize($es['name']) ?></small><br>
                        <small class="text-danger">Expired <?= $es['days_expired'] ?> days ago</small>
                    </div>
                    <div class="text-end">
                        <small><?= $es['quantity_in_stock'] ?> units</small><br>
                        <small class="text-danger"><?= formatCurrency($es['shelf_value']) ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <!-- Recommendations -->
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-lightbulb me-2"></i>Recommendations</h6>
            <?php foreach ($recommendations as $rec): ?>
            <div class="alert alert-<?= $rec[0] ?> small py-2 mb-2">
                <i class="bi bi-<?= $rec[0] === 'danger' ? 'exclamation-triangle' : ($rec[0] === 'warning' ? 'exclamation-circle' : ($rec[0] === 'success' ? 'check-circle' : 'info-circle')) ?> me-1"></i>
                <?= $rec[1] ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Detailed Table -->
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-table me-2"></i>Disposal Records</h6></div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Medicine</th>
                            <th>Category</th>
                            <th>Qty</th>
                            <th class="text-end">Value</th>
                            <th>Reason</th>
                            <th>Method</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($details as $d): ?>
                        <tr>
                            <td><small><?= formatDate($d['disposal_date'], 'M d, Y') ?></small></td>
                            <td><strong class="small"><?= sanitize($d['med_name']) ?></strong></td>
                            <td><small><?= sanitize($d['category_name'] ?? '-') ?></small></td>
                            <td><?= $d['quantity'] ?></td>
                            <td class="text-end text-danger"><?= formatCurrency($d['cost_value']) ?></td>
                            <td>
                                <span class="badge bg-<?= $d['reason'] === 'expired' ? 'danger' : ($d['reason'] === 'damaged' ? 'warning' : ($d['reason'] === 'recalled' ? 'dark' : 'secondary')) ?>">
                                    <?= ucfirst(sanitize($d['reason'])) ?>
                                </span>
                            </td>
                            <td><small><?= ucfirst(str_replace('_', ' ', sanitize($d['disposal_method']))) ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$trendLabels = json_encode(array_column($monthlyTrend, 'month_label'));
$trendValues = json_encode(array_map(fn($t) => round($t['value'], 2), $monthlyTrend));

$reasonLabels = json_encode(array_map(fn($r) => ucfirst($r['reason']), $byReason));
$reasonValues = json_encode(array_map(fn($r) => round($r['value'], 2), $byReason));
$reasonColors = json_encode(array_map(function($r) {
    $map = ['expired' => '#DC2626', 'damaged' => '#D97706', 'recalled' => '#1F2937', 'contaminated' => '#7C3AED', 'other' => '#6B7280'];
    return $map[$r['reason']] ?? '#6B7280';
}, $byReason));

$catLabels = json_encode(array_column($byCategory, 'category_name'));
$catValues = json_encode(array_map(fn($c) => round($c['value'], 2), $byCategory));

$topLabels = json_encode(array_column($topWasted, 'name'));
$topValues = json_encode(array_map(fn($t) => round($t['value'], 2), $topWasted));

$extraScripts = "<script>
// Monthly Waste Trend
new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
        labels: $trendLabels,
        datasets: [{
            label: 'Waste Value (USD)',
            data: $trendValues,
            backgroundColor: 'rgba(220, 38, 38, 0.3)',
            borderColor: '#DC2626',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, title: { display: true, text: 'Value (USD)' } } }
    }
});

// Waste by Reason (Pie)
new Chart(document.getElementById('reasonChart'), {
    type: 'pie',
    data: {
        labels: $reasonLabels,
        datasets: [{
            data: $reasonValues,
            backgroundColor: $reasonColors
        }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});

// Waste by Category (Horizontal Bar)
new Chart(document.getElementById('categoryChart'), {
    type: 'bar',
    data: {
        labels: $catLabels,
        datasets: [{
            label: 'Value (USD)',
            data: $catValues,
            backgroundColor: 'rgba(217, 119, 6, 0.4)',
            borderColor: '#D97706',
            borderWidth: 1
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, title: { display: true, text: 'Value (USD)' } } }
    }
});

// Top 10 Wasted Medicines
new Chart(document.getElementById('topChart'), {
    type: 'bar',
    data: {
        labels: $topLabels,
        datasets: [{
            label: 'Value (USD)',
            data: $topValues,
            backgroundColor: 'rgba(220, 38, 38, 0.5)',
            borderColor: '#DC2626',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});
</script>";

require_once __DIR__ . '/../../includes/footer.php';
?>
