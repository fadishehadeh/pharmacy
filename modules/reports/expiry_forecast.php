<?php
$pageTitle = 'Expiry Forecast';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$months = intval($_GET['months'] ?? 12);

$forecast = [];
for ($i = 0; $i < $months; $i++) {
    $start = date('Y-m-01', strtotime("+$i months"));
    $end = date('Y-m-t', strtotime("+$i months"));
    $label = date('M Y', strtotime("+$i months"));

    $data = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(quantity_in_stock), 0) as qty,
        COALESCE(SUM(quantity_in_stock * cost_price), 0) as value
        FROM medicines WHERE is_active = 1 AND expiry_date BETWEEN ? AND ?");
    $data->execute([$start, $end]);
    $row = $data->fetch();
    $row['label'] = $label;
    $row['month'] = $start;
    $forecast[] = $row;
}

$totalExpiringValue = array_sum(array_column($forecast, 'value'));
$totalExpiringQty = array_sum(array_column($forecast, 'qty'));
$totalExpiringItems = array_sum(array_column($forecast, 'count'));

$alreadyExpired = $db->query("SELECT m.*, c.name as category_name,
    (m.quantity_in_stock * m.cost_price) as waste_value
    FROM medicines m LEFT JOIN categories c ON m.category_id = c.id
    WHERE m.is_active = 1 AND m.expiry_date < CURDATE() AND m.quantity_in_stock > 0
    ORDER BY m.expiry_date ASC")->fetchAll();

$expiringSoon = $db->query("SELECT m.*, c.name as category_name,
    (m.quantity_in_stock * m.cost_price) as waste_value
    FROM medicines m LEFT JOIN categories c ON m.category_id = c.id
    WHERE m.is_active = 1 AND m.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 MONTH) AND m.quantity_in_stock > 0
    ORDER BY m.expiry_date ASC")->fetchAll();

$byCategory = $db->query("SELECT c.name, COUNT(m.id) as count, SUM(m.quantity_in_stock * m.cost_price) as value
    FROM medicines m LEFT JOIN categories c ON m.category_id = c.id
    WHERE m.is_active = 1 AND m.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 MONTH) AND m.quantity_in_stock > 0
    GROUP BY c.id ORDER BY value DESC")->fetchAll();
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card danger"><div class="stat-label">Already Expired</div><div class="stat-value"><?= count($alreadyExpired) ?></div><small class="text-muted"><?= formatCurrency(array_sum(array_column($alreadyExpired, 'waste_value'))) ?> at risk</small></div></div>
    <div class="col-md-3"><div class="card stat-card warning"><div class="stat-label">Expiring (<?= $months ?>mo)</div><div class="stat-value"><?= $totalExpiringItems ?></div><small class="text-muted"><?= number_format($totalExpiringQty) ?> units</small></div></div>
    <div class="col-md-3"><div class="card stat-card"><div class="stat-label">Potential Loss</div><div class="stat-value"><?= formatCurrency($totalExpiringValue) ?></div><small class="text-muted">if unsold</small></div></div>
    <div class="col-md-3">
        <div class="card p-3">
            <select class="form-select form-select-sm" onchange="location.href='?months='+this.value">
                <option value="6" <?= $months == 6 ? 'selected' : '' ?>>6 months</option>
                <option value="12" <?= $months == 12 ? 'selected' : '' ?>>12 months</option>
                <option value="18" <?= $months == 18 ? 'selected' : '' ?>>18 months</option>
                <option value="24" <?= $months == 24 ? 'selected' : '' ?>>24 months</option>
            </select>
            <button onclick="window.print()" class="btn btn-sm btn-outline-dark w-100 mt-2 no-print"><i class="bi bi-printer me-1"></i>Print</button>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-graph-up me-2"></i>Expiry Timeline</h6>
            <canvas id="expiryChart" height="200"></canvas>
        </div>

        <div class="card p-3">
            <h6><i class="bi bi-clock me-2"></i>Expiring Within 3 Months (<?= count($expiringSoon) ?> items)</h6>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Category</th><th>Expiry</th><th>Days Left</th><th>Qty</th><th>Value at Risk</th></tr></thead>
                    <tbody>
                        <?php foreach ($expiringSoon as $m): ?>
                        <?php $daysLeft = max(0, ceil((strtotime($m['expiry_date']) - time()) / 86400)); ?>
                        <tr>
                            <td><strong class="small"><?= sanitize($m['name']) ?></strong></td>
                            <td><small><?= sanitize($m['category_name'] ?? '-') ?></small></td>
                            <td><?= formatDate($m['expiry_date'], 'M d, Y') ?></td>
                            <td><span class="badge bg-<?= $daysLeft <= 30 ? 'danger' : ($daysLeft <= 60 ? 'warning' : 'info') ?>"><?= $daysLeft ?>d</span></td>
                            <td><?= $m['quantity_in_stock'] ?></td>
                            <td class="text-danger"><?= formatCurrency($m['waste_value']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-pie-chart me-2"></i>By Category (6mo)</h6>
            <div class="list-group list-group-flush">
                <?php foreach ($byCategory as $bc): ?>
                <div class="list-group-item px-0 py-2 d-flex justify-content-between">
                    <div><small><?= sanitize($bc['name'] ?? 'Uncategorized') ?></small><br><small class="text-muted"><?= $bc['count'] ?> items</small></div>
                    <strong class="small text-danger"><?= formatCurrency($bc['value']) ?></strong>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card p-3">
            <h6><i class="bi bi-lightbulb me-2"></i>Recommendations</h6>
            <ul class="small text-muted mb-0">
                <li class="mb-1">Prioritize selling items expiring within 30 days</li>
                <li class="mb-1">Consider promotions for slow-moving near-expiry stock</li>
                <li class="mb-1">Return to supplier if within return policy</li>
                <li class="mb-1">Adjust reorder quantities for frequently expiring items</li>
                <li>Record proper disposal for expired medicines</li>
            </ul>
        </div>
    </div>
</div>

<?php
$chartLabels = json_encode(array_column($forecast, 'label'));
$chartValues = json_encode(array_map(fn($f) => round($f['value'], 2), $forecast));
$chartCounts = json_encode(array_column($forecast, 'count'));

$extraScripts = "<script>
new Chart(document.getElementById('expiryChart'), {
    type: 'bar',
    data: {
        labels: $chartLabels,
        datasets: [{
            label: 'Value at Risk',
            data: $chartValues,
            backgroundColor: 'rgba(220, 38, 38, 0.3)',
            borderColor: '#DC2626',
            borderWidth: 1,
            yAxisID: 'y'
        },{
            label: 'Items Count',
            data: $chartCounts,
            type: 'line',
            borderColor: '#F59E0B',
            backgroundColor: 'transparent',
            borderWidth: 2,
            yAxisID: 'y1',
            pointRadius: 4
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true, title: { display: true, text: 'Value (USD)' } },
            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Items' } }
        }
    }
});
</script>";
require_once __DIR__ . '/../../includes/footer.php';
?>
