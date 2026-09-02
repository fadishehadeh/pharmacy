<?php
$pageTitle = 'Supplier Analytics';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));

// Period selector
$period = intval($_GET['period'] ?? 90);
if (!in_array($period, [30, 60, 90, 180, 365])) $period = 90;
$dateFrom = date('Y-m-d', strtotime("-{$period} days"));
$dateTo = date('Y-m-d');

// Total and active suppliers
$totalSuppliers = intval($db->query("SELECT COUNT(*) FROM suppliers")->fetchColumn());
$activeSuppliers = $db->prepare("SELECT COUNT(DISTINCT po.supplier_id)
    FROM purchase_orders po
    WHERE po.status IN ('ordered','partial','received') AND DATE(po.order_date) BETWEEN ? AND ?");
$activeSuppliers->execute([$dateFrom, $dateTo]);
$activeSuppliersCount = intval($activeSuppliers->fetchColumn());

// Total purchase value in period
$totalPurchaseValue = $db->prepare("SELECT COALESCE(SUM(po.total), 0)
    FROM purchase_orders po
    WHERE po.status = 'received' AND DATE(po.order_date) BETWEEN ? AND ?");
$totalPurchaseValue->execute([$dateFrom, $dateTo]);
$totalPurchaseVal = floatval($totalPurchaseValue->fetchColumn());

// Avg delivery time in period
$avgDeliveryTime = $db->prepare("SELECT ROUND(AVG(DATEDIFF(po.actual_delivery, po.order_date)), 1)
    FROM purchase_orders po
    WHERE po.status = 'received' AND po.actual_delivery IS NOT NULL AND DATE(po.order_date) BETWEEN ? AND ?");
$avgDeliveryTime->execute([$dateFrom, $dateTo]);
$avgDeliveryDays = $avgDeliveryTime->fetchColumn() ?: 0;

// Best supplier (highest value in period)
$bestSupplier = $db->prepare("SELECT s.name, SUM(po.total) as total_value
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.status = 'received' AND DATE(po.order_date) BETWEEN ? AND ?
    GROUP BY po.supplier_id ORDER BY total_value DESC LIMIT 1");
$bestSupplier->execute([$dateFrom, $dateTo]);
$bestSupplierData = $bestSupplier->fetch();

// Purchase volume by supplier (bar chart)
$supplierVolume = $db->prepare("SELECT s.name, SUM(po.total) as total_value, COUNT(po.id) as order_count
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.status = 'received' AND DATE(po.order_date) BETWEEN ? AND ?
    GROUP BY po.supplier_id ORDER BY total_value DESC LIMIT 15");
$supplierVolume->execute([$dateFrom, $dateTo]);
$supplierVolume = $supplierVolume->fetchAll();

$volLabels = array_map(function($s) { return $s['name']; }, $supplierVolume);
$volValues = array_map(function($s) { return round(floatval($s['total_value']), 2); }, $supplierVolume);

// On-time delivery rate by supplier
$deliveryRates = $db->prepare("SELECT s.name,
    COUNT(po.id) as total_deliveries,
    SUM(CASE WHEN po.actual_delivery <= po.expected_delivery THEN 1 ELSE 0 END) as on_time,
    ROUND(SUM(CASE WHEN po.actual_delivery <= po.expected_delivery THEN 1 ELSE 0 END) / COUNT(po.id) * 100, 1) as on_time_pct
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.status = 'received' AND po.actual_delivery IS NOT NULL AND po.expected_delivery IS NOT NULL
        AND DATE(po.order_date) BETWEEN ? AND ?
    GROUP BY po.supplier_id
    ORDER BY on_time_pct DESC");
$deliveryRates->execute([$dateFrom, $dateTo]);
$deliveryRates = $deliveryRates->fetchAll();

$delLabels = array_map(function($d) { return $d['name']; }, $deliveryRates);
$delValues = array_map(function($d) { return floatval($d['on_time_pct']); }, $deliveryRates);
$delColors = array_map(function($d) { return floatval($d['on_time_pct']) >= 80 ? '#198754' : (floatval($d['on_time_pct']) >= 50 ? '#ffc107' : '#dc3545'); }, $deliveryRates);

// Monthly purchase trend
$monthlyTrend = $db->prepare("SELECT DATE_FORMAT(po.order_date, '%Y-%m') as month_key,
    MIN(DATE(po.order_date)) as month_date,
    SUM(po.total) as total_value,
    COUNT(po.id) as order_count
    FROM purchase_orders po
    WHERE po.status = 'received' AND DATE(po.order_date) BETWEEN ? AND ?
    GROUP BY month_key ORDER BY month_key");
$monthlyTrend->execute([$dateFrom, $dateTo]);
$monthlyTrend = $monthlyTrend->fetchAll();

$trendLabels = array_map(function($t) { return formatDate($t['month_date'], 'M Y'); }, $monthlyTrend);
$trendValues = array_map(function($t) { return round(floatval($t['total_value']), 2); }, $monthlyTrend);
$trendCounts = array_map(function($t) { return intval($t['order_count']); }, $monthlyTrend);

// Supplier scorecard
$scorecard = $db->prepare("SELECT s.id, s.name, s.contact_person, s.phone,
    COUNT(po.id) as total_orders,
    COALESCE(SUM(CASE WHEN po.status = 'received' THEN po.total ELSE 0 END), 0) as total_value,
    ROUND(AVG(CASE WHEN po.actual_delivery IS NOT NULL THEN DATEDIFF(po.actual_delivery, po.order_date) END), 1) as avg_delivery_days,
    SUM(CASE WHEN po.actual_delivery IS NOT NULL AND po.expected_delivery IS NOT NULL AND po.actual_delivery <= po.expected_delivery THEN 1 ELSE 0 END) as on_time_count,
    SUM(CASE WHEN po.actual_delivery IS NOT NULL AND po.expected_delivery IS NOT NULL THEN 1 ELSE 0 END) as tracked_count,
    (SELECT COUNT(*) FROM supplier_returns sr WHERE sr.supplier_id = s.id) as return_count
    FROM suppliers s
    LEFT JOIN purchase_orders po ON po.supplier_id = s.id AND DATE(po.order_date) BETWEEN ? AND ?
    WHERE s.is_active = 1
    GROUP BY s.id
    HAVING total_orders > 0
    ORDER BY total_value DESC");
$scorecard->execute([$dateFrom, $dateTo]);
$scorecard = $scorecard->fetchAll();

// Recent purchase orders
$recentOrders = $db->prepare("SELECT po.*, s.name as supplier_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE DATE(po.order_date) BETWEEN ? AND ?
    ORDER BY po.order_date DESC LIMIT 20");
$recentOrders->execute([$dateFrom, $dateTo]);
$recentOrders = $recentOrders->fetchAll();

// Top suppliers by reliability (on-time with enough orders)
$reliableSuppliers = array_filter($scorecard, function($s) { return $s['tracked_count'] >= 3; });
usort($reliableSuppliers, function($a, $b) {
    $aRate = $a['tracked_count'] > 0 ? $a['on_time_count'] / $a['tracked_count'] : 0;
    $bRate = $b['tracked_count'] > 0 ? $b['on_time_count'] / $b['tracked_count'] : 0;
    return $bRate <=> $aRate;
});
$reliableSuppliers = array_slice($reliableSuppliers, 0, 5);
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="btn-group btn-group-sm">
        <?php foreach ([30, 60, 90, 180, 365] as $p): ?>
        <a href="?period=<?= $p ?>" class="btn btn-<?= $period === $p ? 'primary' : 'outline-primary' ?>"><?= $p === 365 ? '1 Year' : $p . ' Days' ?></a>
        <?php endforeach; ?>
    </div>
    <button onclick="window.print()" class="btn btn-sm btn-outline-dark no-print"><i class="bi bi-printer me-1"></i>Print</button>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-3">
    <div class="col-md-4 col-lg">
        <div class="card stat-card">
            <div class="stat-label">Total Suppliers</div>
            <div class="stat-value"><?= number_format($totalSuppliers) ?></div>
        </div>
    </div>
    <div class="col-md-4 col-lg">
        <div class="card stat-card success">
            <div class="stat-label">Active Suppliers</div>
            <div class="stat-value"><?= number_format($activeSuppliersCount) ?></div>
            <small class="text-muted">Last <?= $period ?> days</small>
        </div>
    </div>
    <div class="col-md-4 col-lg">
        <div class="card stat-card info">
            <div class="stat-label">Total Purchases</div>
            <div class="stat-value"><?= formatCurrency($totalPurchaseVal) ?></div>
            <small class="text-muted"><?= formatCurrency($totalPurchaseVal * $exchangeRate, 'LBP') ?></small>
        </div>
    </div>
    <div class="col-md-4 col-lg">
        <div class="card stat-card warning">
            <div class="stat-label">Avg Delivery Time</div>
            <div class="stat-value"><?= $avgDeliveryDays ?> days</div>
        </div>
    </div>
    <div class="col-md-4 col-lg">
        <div class="card stat-card">
            <div class="stat-label">Best Supplier</div>
            <div class="stat-value small"><?= sanitize($bestSupplierData['name'] ?? 'N/A') ?></div>
            <small class="text-muted"><?= $bestSupplierData ? formatCurrency($bestSupplierData['total_value']) : '' ?></small>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-bar-chart me-2"></i>Purchase Volume by Supplier</h6>
            <canvas id="volumeChart" height="200"></canvas>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-speedometer2 me-2"></i>On-Time Delivery Rate by Supplier</h6>
            <canvas id="deliveryChart" height="200"></canvas>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-graph-up me-2"></i>Monthly Purchase Trend</h6>
            <canvas id="trendChart" height="100"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-award me-2"></i>Most Reliable Suppliers</h6>
            <?php if (!empty($reliableSuppliers)): ?>
            <div class="list-group list-group-flush">
                <?php foreach ($reliableSuppliers as $idx => $rs):
                    $onTimePct = $rs['tracked_count'] > 0 ? round($rs['on_time_count'] / $rs['tracked_count'] * 100) : 0;
                ?>
                <div class="list-group-item px-0 py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-<?= $idx === 0 ? 'warning' : 'secondary' ?> me-1">#<?= $idx + 1 ?></span>
                            <strong class="small"><?= sanitize($rs['name']) ?></strong>
                        </div>
                        <span class="badge bg-<?= $onTimePct >= 80 ? 'success' : ($onTimePct >= 50 ? 'warning' : 'danger') ?>"><?= $onTimePct ?>%</span>
                    </div>
                    <div class="progress mt-1" style="height:4px">
                        <div class="progress-bar bg-<?= $onTimePct >= 80 ? 'success' : ($onTimePct >= 50 ? 'warning' : 'danger') ?>" style="width:<?= $onTimePct ?>%"></div>
                    </div>
                    <small class="text-muted"><?= $rs['total_orders'] ?> orders | <?= $rs['avg_delivery_days'] ?? '-' ?> avg days</small>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-muted text-center py-3">Need at least 3 tracked deliveries to rank</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Supplier Scorecard -->
<div class="card mb-3">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-clipboard-data me-2"></i>Supplier Scorecard</h6></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 data-table">
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Contact</th>
                    <th class="text-end">Orders</th>
                    <th class="text-end">Total Value (USD)</th>
                    <th class="text-end">Total Value (LBP)</th>
                    <th class="text-end">Avg Delivery</th>
                    <th class="text-end">On-Time %</th>
                    <th class="text-end">Returns</th>
                    <th>Rating</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scorecard as $s):
                    $onTimePct = $s['tracked_count'] > 0 ? round($s['on_time_count'] / $s['tracked_count'] * 100) : 0;
                    $rating = 0;
                    if ($onTimePct >= 90) $rating += 2; elseif ($onTimePct >= 70) $rating += 1;
                    if ($s['total_orders'] >= 10) $rating += 1; elseif ($s['total_orders'] >= 5) $rating += 0.5;
                    $avgDel = floatval($s['avg_delivery_days'] ?? 0);
                    if ($avgDel > 0 && $avgDel <= 3) $rating += 2; elseif ($avgDel > 0 && $avgDel <= 7) $rating += 1;
                    $rating = min(5, round($rating));
                ?>
                <tr>
                    <td><strong><?= sanitize($s['name']) ?></strong></td>
                    <td><small><?= sanitize($s['contact_person'] ?? '-') ?></small></td>
                    <td class="text-end"><?= $s['total_orders'] ?></td>
                    <td class="text-end fw-semibold"><?= formatCurrency($s['total_value']) ?></td>
                    <td class="text-end"><small class="text-muted"><?= formatCurrency($s['total_value'] * $exchangeRate, 'LBP') ?></small></td>
                    <td class="text-end"><?= $s['avg_delivery_days'] ? $s['avg_delivery_days'] . ' days' : '-' ?></td>
                    <td class="text-end">
                        <?php if ($s['tracked_count'] > 0): ?>
                        <span class="badge bg-<?= $onTimePct >= 80 ? 'success' : ($onTimePct >= 50 ? 'warning' : 'danger') ?>"><?= $onTimePct ?>%</span>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($s['return_count'] > 0): ?>
                        <span class="text-danger"><?= $s['return_count'] ?></span>
                        <?php else: ?>
                        <span class="text-success">0</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php for ($i = 0; $i < 5; $i++): ?>
                        <i class="bi bi-star<?= $i < $rating ? '-fill text-warning' : ' text-muted' ?>" style="font-size:0.8em"></i>
                        <?php endfor; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Recent Purchase Orders -->
<div class="card mb-3">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-receipt me-2"></i>Recent Purchase Orders</h6></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 data-table">
            <thead>
                <tr>
                    <th>PO #</th>
                    <th>Supplier</th>
                    <th>Order Date</th>
                    <th>Expected</th>
                    <th>Delivered</th>
                    <th class="text-end">Total (USD)</th>
                    <th class="text-end">Total (LBP)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentOrders as $po): ?>
                <tr>
                    <td><a href="<?= BASE_URL ?>/modules/suppliers/order_detail.php?id=<?= $po['id'] ?>"><strong><?= sanitize($po['po_number']) ?></strong></a></td>
                    <td><?= sanitize($po['supplier_name']) ?></td>
                    <td><?= formatDate($po['order_date'], 'M d, Y') ?></td>
                    <td><?= $po['expected_delivery'] ? formatDate($po['expected_delivery'], 'M d, Y') : '-' ?></td>
                    <td>
                        <?php if ($po['actual_delivery']): ?>
                            <?= formatDate($po['actual_delivery'], 'M d, Y') ?>
                            <?php if ($po['expected_delivery'] && $po['actual_delivery'] > $po['expected_delivery']): ?>
                            <span class="badge bg-danger ms-1">Late</span>
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-semibold"><?= formatCurrency($po['total']) ?></td>
                    <td class="text-end"><small class="text-muted"><?= formatCurrency($po['total'] * $exchangeRate, 'LBP') ?></small></td>
                    <td>
                        <span class="badge bg-<?php
                            switch($po['status']) {
                                case 'received': echo 'success'; break;
                                case 'ordered': echo 'primary'; break;
                                case 'partial': echo 'warning'; break;
                                case 'cancelled': echo 'danger'; break;
                                default: echo 'secondary';
                            }
                        ?>"><?= ucfirst(sanitize($po['status'])) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$extraScripts = "<script>
// Purchase Volume by Supplier
new Chart(document.getElementById('volumeChart'), {
    type: 'bar',
    data: {
        labels: " . json_encode($volLabels) . ",
        datasets: [{
            label: 'Purchase Value (\$)',
            data: " . json_encode($volValues) . ",
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

// On-Time Delivery Rate
new Chart(document.getElementById('deliveryChart'), {
    type: 'bar',
    data: {
        labels: " . json_encode($delLabels) . ",
        datasets: [{
            label: 'On-Time %',
            data: " . json_encode($delValues) . ",
            backgroundColor: " . json_encode($delColors) . "
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, max: 100, title: { display: true, text: 'On-Time %' } } }
    }
});

// Monthly Purchase Trend
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: " . json_encode($trendLabels) . ",
        datasets: [
            {
                label: 'Purchase Value (\$)',
                data: " . json_encode($trendValues) . ",
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13,110,253,0.1)',
                fill: true,
                tension: 0.3,
                yAxisID: 'y'
            },
            {
                label: 'Order Count',
                data: " . json_encode($trendCounts) . ",
                borderColor: '#198754',
                borderDash: [5, 5],
                fill: false,
                tension: 0.3,
                yAxisID: 'y1'
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: {
            y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Value (\$)' } },
            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Orders' } }
        }
    }
});
</script>";

require_once __DIR__ . '/../../includes/footer.php';
?>
