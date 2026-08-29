<?php
$pageTitle = 'Inventory Movement';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));

// Date range
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$medicineFilter = $_GET['medicine'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$typeFilter = $_GET['type'] ?? '';

// Build WHERE
$where = ['DATE(sm.created_at) BETWEEN ? AND ?'];
$params = [$startDate, $endDate];

if ($medicineFilter) {
    $where[] = 'sm.medicine_id = ?';
    $params[] = intval($medicineFilter);
}
if ($categoryFilter) {
    $where[] = 'm.category_id = ?';
    $params[] = intval($categoryFilter);
}
if ($typeFilter) {
    $where[] = 'sm.type = ?';
    $params[] = $typeFilter;
}

$whereStr = implode(' AND ', $where);

// Summary cards
$summaryStmt = $db->prepare("SELECT
    COALESCE(SUM(CASE WHEN sm.type IN ('in','return') THEN sm.quantity ELSE 0 END), 0) as total_in,
    COALESCE(SUM(CASE WHEN sm.type IN ('out') THEN sm.quantity ELSE 0 END), 0) as total_out,
    COALESCE(SUM(CASE WHEN sm.type IN ('adjustment') THEN sm.quantity ELSE 0 END), 0) as total_adj,
    COALESCE(SUM(CASE WHEN sm.type IN ('expired','damaged') THEN sm.quantity ELSE 0 END), 0) as total_loss
    FROM stock_movements sm
    JOIN medicines m ON sm.medicine_id = m.id
    WHERE $whereStr");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch();

$totalIn = intval($summary['total_in']);
$totalOut = intval($summary['total_out']);
$totalAdj = intval($summary['total_adj']);
$totalLoss = intval($summary['total_loss']);
$netMovement = $totalIn - $totalOut - $totalLoss + $totalAdj;

// Trend data (daily in/out/net)
$trendStmt = $db->prepare("SELECT
    DATE(sm.created_at) as move_date,
    COALESCE(SUM(CASE WHEN sm.type IN ('in','return') THEN sm.quantity ELSE 0 END), 0) as day_in,
    COALESCE(SUM(CASE WHEN sm.type IN ('out') THEN sm.quantity ELSE 0 END), 0) as day_out,
    COALESCE(SUM(CASE WHEN sm.type IN ('expired','damaged') THEN sm.quantity ELSE 0 END), 0) as day_loss
    FROM stock_movements sm
    JOIN medicines m ON sm.medicine_id = m.id
    WHERE $whereStr
    GROUP BY DATE(sm.created_at)
    ORDER BY move_date");
$trendStmt->execute($params);
$trendData = $trendStmt->fetchAll();

$trendLabels = [];
$trendIn = [];
$trendOut = [];
$trendNet = [];
foreach ($trendData as $t) {
    $trendLabels[] = formatDate($t['move_date'], 'M d');
    $dayIn = intval($t['day_in']);
    $dayOut = intval($t['day_out']) + intval($t['day_loss']);
    $trendIn[] = $dayIn;
    $trendOut[] = $dayOut;
    $trendNet[] = $dayIn - $dayOut;
}

// Movement type distribution
$typeDistStmt = $db->prepare("SELECT sm.type, COUNT(*) as cnt, SUM(sm.quantity) as total_qty
    FROM stock_movements sm
    JOIN medicines m ON sm.medicine_id = m.id
    WHERE $whereStr
    GROUP BY sm.type
    ORDER BY total_qty DESC");
$typeDistStmt->execute($params);
$typeDist = $typeDistStmt->fetchAll();

$pieLabels = array_map(function($t) { return ucfirst($t['type']); }, $typeDist);
$pieValues = array_map(function($t) { return intval($t['total_qty']); }, $typeDist);

// Detailed movements
$movementsStmt = $db->prepare("SELECT sm.*, m.name as medicine_name, m.barcode,
    u.full_name as user_name, c.name as category_name
    FROM stock_movements sm
    JOIN medicines m ON sm.medicine_id = m.id
    LEFT JOIN categories c ON m.category_id = c.id
    LEFT JOIN users u ON sm.created_by = u.id
    WHERE $whereStr
    ORDER BY sm.created_at DESC
    LIMIT 500");
$movementsStmt->execute($params);
$movements = $movementsStmt->fetchAll();

// Calculate running balance per medicine
$balances = [];
foreach (array_reverse($movements) as $mov) {
    $mid = $mov['medicine_id'];
    if (!isset($balances[$mid])) {
        // Get current stock and work backwards
        $balances[$mid] = 0;
    }
    if (in_array($mov['type'], ['in', 'return'])) {
        $balances[$mid] += $mov['quantity'];
    } else {
        $balances[$mid] -= $mov['quantity'];
    }
}
// Re-calculate forward for display
$runningBalances = [];
$balanceTracker = [];
foreach (array_reverse($movements) as $i => $mov) {
    $mid = $mov['medicine_id'];
    if (!isset($balanceTracker[$mid])) {
        $balanceTracker[$mid] = 0;
    }
    if (in_array($mov['type'], ['in', 'return'])) {
        $balanceTracker[$mid] += $mov['quantity'];
    } else {
        $balanceTracker[$mid] -= $mov['quantity'];
    }
    $runningBalances[$mov['id']] = $balanceTracker[$mid];
}

// Filters data
$medicines = $db->query("SELECT id, name FROM medicines WHERE is_active = 1 ORDER BY name")->fetchAll();
$categories = $db->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h6 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>Inventory Movement Report</h6>
    <button onclick="window.print()" class="btn btn-sm btn-outline-dark no-print"><i class="bi bi-printer me-1"></i>Print</button>
</div>

<!-- Filters -->
<div class="card p-3 mb-3 no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label small mb-0">From</label>
            <input type="date" class="form-control form-control-sm" name="start_date" value="<?= sanitize($startDate) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-0">To</label>
            <input type="date" class="form-control form-control-sm" name="end_date" value="<?= sanitize($endDate) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-0">Medicine</label>
            <select class="form-select form-select-sm" name="medicine">
                <option value="">All Medicines</option>
                <?php foreach ($medicines as $m): ?>
                <option value="<?= $m['id'] ?>" <?= $medicineFilter == $m['id'] ? 'selected' : '' ?>><?= sanitize($m['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-0">Category</label>
            <select class="form-select form-select-sm" name="category">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $categoryFilter == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-0">Type</label>
            <select class="form-select form-select-sm" name="type">
                <option value="">All Types</option>
                <option value="in" <?= $typeFilter === 'in' ? 'selected' : '' ?>>Stock In</option>
                <option value="out" <?= $typeFilter === 'out' ? 'selected' : '' ?>>Stock Out</option>
                <option value="adjustment" <?= $typeFilter === 'adjustment' ? 'selected' : '' ?>>Adjustment</option>
                <option value="return" <?= $typeFilter === 'return' ? 'selected' : '' ?>>Return</option>
                <option value="expired" <?= $typeFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                <option value="damaged" <?= $typeFilter === 'damaged' ? 'selected' : '' ?>>Damaged</option>
            </select>
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card success">
            <div class="stat-label">Total In</div>
            <div class="stat-value"><?= number_format($totalIn) ?></div>
            <small class="text-muted">Stock received + returns</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card danger">
            <div class="stat-label">Total Out</div>
            <div class="stat-value"><?= number_format($totalOut) ?></div>
            <small class="text-muted">Sales dispatched</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="stat-label">Adjustments / Losses</div>
            <div class="stat-value"><?= number_format($totalAdj + $totalLoss) ?></div>
            <small class="text-muted">Adj: <?= $totalAdj ?> | Exp/Dmg: <?= $totalLoss ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card <?= $netMovement >= 0 ? 'info' : 'danger' ?>">
            <div class="stat-label">Net Movement</div>
            <div class="stat-value"><?= ($netMovement >= 0 ? '+' : '') . number_format($netMovement) ?></div>
            <small class="text-muted"><?= formatDate($startDate, 'M d') ?> - <?= formatDate($endDate, 'M d, Y') ?></small>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-graph-up me-2"></i>Movement Trend</h6>
            <canvas id="trendChart" height="100"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-pie-chart me-2"></i>Movement Type Distribution</h6>
            <canvas id="typeChart" height="200"></canvas>
        </div>
    </div>
</div>

<!-- Detailed Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-table me-2"></i>Movement Details</h6>
        <small class="text-muted"><?= count($movements) ?> records</small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Medicine</th>
                    <th>Type</th>
                    <th class="text-end">Quantity</th>
                    <th>Reference</th>
                    <th>Notes</th>
                    <th>User</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movements as $mov): ?>
                <?php
                    $typeColors = ['in'=>'success','out'=>'danger','adjustment'=>'warning','return'=>'info','expired'=>'dark','damaged'=>'secondary'];
                    $isOutgoing = in_array($mov['type'], ['out','expired','damaged']);
                ?>
                <tr>
                    <td><small><?= formatDate($mov['created_at'], 'M d, Y H:i') ?></small></td>
                    <td>
                        <a href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $mov['medicine_id'] ?>">
                            <?= sanitize($mov['medicine_name']) ?>
                        </a>
                        <?php if ($mov['category_name']): ?>
                        <br><small class="text-muted"><?= sanitize($mov['category_name']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $typeColors[$mov['type']] ?? 'secondary' ?>"><?= strtoupper(sanitize($mov['type'])) ?></span>
                    </td>
                    <td class="text-end fw-semibold <?= $isOutgoing ? 'text-danger' : 'text-success' ?>">
                        <?= $isOutgoing ? '-' : '+' ?><?= $mov['quantity'] ?>
                    </td>
                    <td>
                        <small>
                        <?php if ($mov['reference_type'] && $mov['reference_id']): ?>
                            <?= sanitize(ucfirst(str_replace('_', ' ', $mov['reference_type']))) ?> #<?= sanitize($mov['reference_id']) ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                        </small>
                    </td>
                    <td><small><?= sanitize($mov['notes'] ?? '') ?></small></td>
                    <td><small><?= sanitize($mov['user_name'] ?? '-') ?></small></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($movements)): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">No movements found for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$pieColors = ['#198754','#dc3545','#ffc107','#0dcaf0','#212529','#6c757d','#6f42c1','#fd7e14'];

$extraScripts = "<script>
// Movement Trend
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: " . json_encode($trendLabels) . ",
        datasets: [
            {
                label: 'In',
                data: " . json_encode($trendIn) . ",
                borderColor: '#198754',
                backgroundColor: 'rgba(25,135,84,0.1)',
                fill: true,
                tension: 0.3
            },
            {
                label: 'Out',
                data: " . json_encode($trendOut) . ",
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220,53,69,0.1)',
                fill: true,
                tension: 0.3
            },
            {
                label: 'Net',
                data: " . json_encode($trendNet) . ",
                borderColor: '#0d6efd',
                borderDash: [5, 5],
                fill: false,
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

// Movement Type Distribution
new Chart(document.getElementById('typeChart'), {
    type: 'pie',
    data: {
        labels: " . json_encode($pieLabels) . ",
        datasets: [{
            data: " . json_encode($pieValues) . ",
            backgroundColor: " . json_encode(array_slice($pieColors, 0, count($pieLabels))) . "
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } }
    }
});
</script>";

require_once __DIR__ . '/../../includes/footer.php';
?>
