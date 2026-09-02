<?php
$pageTitle = 'Price History';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

$exchangeRate = getSetting('exchange_rate', '89500');
$medicineId = intval($_GET['medicine_id'] ?? 0);

if ($medicineId > 0) {
    // Specific medicine history
    $medicine = $db->prepare("SELECT m.*, c.name as category_name FROM medicines m LEFT JOIN categories c ON m.category_id = c.id WHERE m.id = ?");
    $medicine->execute([$medicineId]);
    $medicine = $medicine->fetch();

    if (!$medicine) {
        flashMessage('Medicine not found', 'error');
        header('Location: price_history.php');
        exit;
    }

    $history = $db->prepare("SELECT ph.*, u.full_name as changed_by_name
        FROM price_history ph
        LEFT JOIN users u ON ph.changed_by = u.id
        WHERE ph.medicine_id = ?
        ORDER BY ph.changed_at DESC");
    $history->execute([$medicineId]);
    $history = $history->fetchAll();

    // Chart data (chronological)
    $chartData = array_reverse($history);
    $chartLabels = [];
    $chartCost = [];
    $chartSell = [];
    foreach ($chartData as $h) {
        $chartLabels[] = formatDate($h['changed_at'], 'M d, Y');
        $chartCost[] = round($h['new_cost_price'] ?? $h['old_cost_price'] ?? 0, 2);
        $chartSell[] = round($h['new_sell_price'] ?? $h['old_sell_price'] ?? 0, 2);
    }
    // Add current prices as last point if different
    if (!empty($chartData)) {
        $lastEntry = end($chartData);
        $chartLabels[] = 'Current';
        $chartCost[] = round($medicine['cost_price'], 2);
        $chartSell[] = round($medicine['sell_price'], 2);
    }
} else {
    // Summary view
    $mostChanges = $db->query("SELECT m.id, m.name, m.cost_price, m.sell_price, COUNT(ph.id) as change_count,
        MAX(ph.changed_at) as last_change
        FROM medicines m
        JOIN price_history ph ON ph.medicine_id = m.id
        WHERE m.is_active = 1
        GROUP BY m.id
        ORDER BY change_count DESC LIMIT 20")->fetchAll();

    $largestIncreases = $db->query("SELECT m.id, m.name, ph.old_sell_price, ph.new_sell_price,
        ROUND(((ph.new_sell_price - ph.old_sell_price) / GREATEST(ph.old_sell_price, 0.01)) * 100, 1) as change_pct,
        ph.changed_at
        FROM price_history ph
        JOIN medicines m ON ph.medicine_id = m.id
        WHERE ph.old_sell_price > 0 AND ph.new_sell_price > ph.old_sell_price
        ORDER BY change_pct DESC LIMIT 15")->fetchAll();

    $largestDecreases = $db->query("SELECT m.id, m.name, ph.old_sell_price, ph.new_sell_price,
        ROUND(((ph.new_sell_price - ph.old_sell_price) / GREATEST(ph.old_sell_price, 0.01)) * 100, 1) as change_pct,
        ph.changed_at
        FROM price_history ph
        JOIN medicines m ON ph.medicine_id = m.id
        WHERE ph.old_sell_price > 0 AND ph.new_sell_price < ph.old_sell_price
        ORDER BY change_pct ASC LIMIT 15")->fetchAll();

    $recentChanges = $db->query("SELECT ph.*, m.name as medicine_name, u.full_name as changed_by_name
        FROM price_history ph
        JOIN medicines m ON ph.medicine_id = m.id
        LEFT JOIN users u ON ph.changed_by = u.id
        ORDER BY ph.changed_at DESC LIMIT 50")->fetchAll();
}

// Medicine search dropdown
$allMedicines = $db->query("SELECT id, name FROM medicines WHERE is_active = 1 ORDER BY name")->fetchAll();
?>

<div class="card p-3 mb-3">
    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
        <label class="form-label mb-0 me-2"><i class="bi bi-search me-1"></i>Medicine:</label>
        <select class="form-select" name="medicine_id" style="max-width:400px">
            <option value="">-- All medicines (summary view) --</option>
            <?php foreach ($allMedicines as $am): ?>
            <option value="<?= $am['id'] ?>" <?= $medicineId == $am['id'] ? 'selected' : '' ?>><?= sanitize($am['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>View</button>
        <?php if ($medicineId): ?>
        <a href="price_history.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Summary</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($medicineId && $medicine): ?>
<!-- Single medicine view -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Current Cost Price</div>
            <div class="stat-value"><?= formatCurrency($medicine['cost_price']) ?></div>
            <small class="text-muted"><?= formatCurrency($medicine['cost_price'] * $exchangeRate, 'LBP') ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card info">
            <div class="stat-label">Current Sell Price</div>
            <div class="stat-value"><?= formatCurrency($medicine['sell_price']) ?></div>
            <small class="text-muted"><?= formatCurrency($medicine['sell_price'] * $exchangeRate, 'LBP') ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card success">
            <div class="stat-label">Current Margin</div>
            <?php $margin = $medicine['sell_price'] > 0 ? round(($medicine['sell_price'] - $medicine['cost_price']) / $medicine['sell_price'] * 100, 1) : 0; ?>
            <div class="stat-value"><?= $margin ?>%</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="stat-label">Total Changes</div>
            <div class="stat-value"><?= count($history) ?></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-<?= count($history) > 1 ? '7' : '12' ?>">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Price Changes: <?= sanitize($medicine['name']) ?></h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>Date</th><th>Old Cost</th><th>New Cost</th><th>Cost Change</th><th>Old Sell</th><th>New Sell</th><th>Sell Change</th><th>By</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                        <?php
                        $costChange = ($h['old_cost_price'] > 0) ? round((($h['new_cost_price'] - $h['old_cost_price']) / $h['old_cost_price']) * 100, 1) : 0;
                        $sellChange = ($h['old_sell_price'] > 0) ? round((($h['new_sell_price'] - $h['old_sell_price']) / $h['old_sell_price']) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><small><?= formatDate($h['changed_at'], 'M d, Y H:i') ?></small></td>
                            <td><?= formatCurrency($h['old_cost_price']) ?></td>
                            <td><?= formatCurrency($h['new_cost_price']) ?></td>
                            <td>
                                <?php if ($costChange != 0): ?>
                                <span class="badge bg-<?= $costChange > 0 ? 'danger' : 'success' ?>"><?= $costChange > 0 ? '+' : '' ?><?= $costChange ?>%</span>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= formatCurrency($h['old_sell_price']) ?></td>
                            <td><?= formatCurrency($h['new_sell_price']) ?></td>
                            <td>
                                <?php if ($sellChange != 0): ?>
                                <span class="badge bg-<?= $sellChange > 0 ? 'danger' : 'success' ?>"><?= $sellChange > 0 ? '+' : '' ?><?= $sellChange ?>%</span>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><small><?= sanitize($h['changed_by_name'] ?? '-') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php if (count($history) > 1): ?>
    <div class="col-lg-5">
        <div class="card p-3">
            <h6><i class="bi bi-graph-up me-2"></i>Price Trend</h6>
            <canvas id="priceChart" height="300"></canvas>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- Summary view -->
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-sort-numeric-down me-2"></i>Most Price Changes</h6></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Changes</th><th>Current Cost</th><th>Current Sell</th><th>Last Change</th><th class="no-print"></th></tr></thead>
                    <tbody>
                        <?php foreach ($mostChanges as $mc): ?>
                        <tr>
                            <td><strong class="small"><?= sanitize($mc['name']) ?></strong></td>
                            <td><span class="badge bg-primary"><?= $mc['change_count'] ?></span></td>
                            <td><?= formatCurrency($mc['cost_price']) ?></td>
                            <td><?= formatCurrency($mc['sell_price']) ?></td>
                            <td><small><?= formatDate($mc['last_change'], 'M d, Y') ?></small></td>
                            <td class="no-print"><a href="?medicine_id=<?= $mc['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-3">
        <div class="card p-3 mb-3 border-danger">
            <h6 class="text-danger"><i class="bi bi-graph-up-arrow me-2"></i>Largest Increases</h6>
            <div class="list-group list-group-flush" style="max-height:350px;overflow-y:auto">
                <?php foreach ($largestIncreases as $li): ?>
                <a href="?medicine_id=<?= $li['id'] ?>" class="list-group-item list-group-item-action px-0 py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="fw-bold"><?= sanitize($li['name']) ?></small>
                        <span class="badge bg-danger">+<?= $li['change_pct'] ?>%</span>
                    </div>
                    <small class="text-muted"><?= formatCurrency($li['old_sell_price']) ?> &rarr; <?= formatCurrency($li['new_sell_price']) ?></small>
                </a>
                <?php endforeach; ?>
                <?php if (empty($largestIncreases)): ?><p class="text-muted small py-2">No data</p><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-3">
        <div class="card p-3 mb-3 border-success">
            <h6 class="text-success"><i class="bi bi-graph-down-arrow me-2"></i>Largest Decreases</h6>
            <div class="list-group list-group-flush" style="max-height:350px;overflow-y:auto">
                <?php foreach ($largestDecreases as $ld): ?>
                <a href="?medicine_id=<?= $ld['id'] ?>" class="list-group-item list-group-item-action px-0 py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="fw-bold"><?= sanitize($ld['name']) ?></small>
                        <span class="badge bg-success"><?= $ld['change_pct'] ?>%</span>
                    </div>
                    <small class="text-muted"><?= formatCurrency($ld['old_sell_price']) ?> &rarr; <?= formatCurrency($ld['new_sell_price']) ?></small>
                </a>
                <?php endforeach; ?>
                <?php if (empty($largestDecreases)): ?><p class="text-muted small py-2">No data</p><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Price Changes</h6></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead>
                <tr><th>Date</th><th>Medicine</th><th>Old Cost</th><th>New Cost</th><th>Old Sell</th><th>New Sell</th><th>Change %</th><th>By</th><th class="no-print"></th></tr>
            </thead>
            <tbody>
                <?php foreach ($recentChanges as $rc): ?>
                <?php $sellPctChange = ($rc['old_sell_price'] > 0) ? round((($rc['new_sell_price'] - $rc['old_sell_price']) / $rc['old_sell_price']) * 100, 1) : 0; ?>
                <tr>
                    <td><small><?= formatDate($rc['changed_at'], 'M d, Y') ?></small></td>
                    <td><strong class="small"><?= sanitize($rc['medicine_name']) ?></strong></td>
                    <td><?= formatCurrency($rc['old_cost_price']) ?></td>
                    <td><?= formatCurrency($rc['new_cost_price']) ?></td>
                    <td><?= formatCurrency($rc['old_sell_price']) ?></td>
                    <td><?= formatCurrency($rc['new_sell_price']) ?></td>
                    <td>
                        <?php if ($sellPctChange != 0): ?>
                        <span class="badge bg-<?= $sellPctChange > 0 ? 'danger' : 'success' ?>"><?= $sellPctChange > 0 ? '+' : '' ?><?= $sellPctChange ?>%</span>
                        <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                    </td>
                    <td><small><?= sanitize($rc['changed_by_name'] ?? '-') ?></small></td>
                    <td class="no-print"><a href="?medicine_id=<?= $rc['medicine_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
$extraScripts = '';
if ($medicineId && isset($chartLabels) && count($chartLabels) > 1) {
    $extraScripts = "<script>
new Chart(document.getElementById('priceChart'), {
    type: 'line',
    data: {
        labels: " . json_encode($chartLabels) . ",
        datasets: [
            {
                label: 'Cost Price',
                data: " . json_encode($chartCost) . ",
                borderColor: '#DC2626',
                backgroundColor: 'rgba(220,38,38,0.1)',
                fill: true,
                tension: 0.3
            },
            {
                label: 'Sell Price',
                data: " . json_encode($chartSell) . ",
                borderColor: '#059669',
                backgroundColor: 'rgba(5,150,105,0.1)',
                fill: true,
                tension: 0.3
            }
        ]
    },
    options: {
        responsive: true,
        scales: { y: { beginAtZero: false } },
        plugins: { legend: { position: 'bottom' } }
    }
});
</script>";
}

require_once __DIR__ . '/../../includes/footer.php';
?>
