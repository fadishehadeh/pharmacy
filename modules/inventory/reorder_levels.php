<?php
$pageTitle = 'Reorder Levels';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { header('Location: ' . BASE_URL); exit; }
$db = getDB();

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_levels'])) {
        $medicineIds = $_POST['medicine_id'] ?? [];
        $minStocks = $_POST['min_stock'] ?? [];
        $maxStocks = $_POST['max_stock'] ?? [];
        $reorderQtys = $_POST['reorder_qty'] ?? [];

        $updated = 0;
        $stmt = $db->prepare("UPDATE medicines SET min_stock_level = ?, max_stock_level = ?, reorder_quantity = ? WHERE id = ?");
        foreach ($medicineIds as $i => $id) {
            $min = intval($minStocks[$i] ?? 0);
            $max = intval($maxStocks[$i] ?? 0);
            $reorder = intval($reorderQtys[$i] ?? 0);
            if ($min >= 0 && $max >= $min) {
                $stmt->execute([$min, $max, $reorder, intval($id)]);
                $updated++;
            }
        }

        addAuditLog('bulk_update', 'medicines', 0, null, ['updated_count' => $updated, 'field' => 'reorder_levels']);
        flashMessage("$updated medicine reorder levels updated");
        header('Location: reorder_levels.php');
        exit;
    }

    if (isset($_POST['auto_calculate_all'])) {
        $medicines = $db->query("SELECT m.id,
            COALESCE((SELECT SUM(si.quantity) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.medicine_id = m.id AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND s.status = 'completed'), 0) as sales_90d
            FROM medicines m WHERE m.is_active = 1")->fetchAll();

        $updated = 0;
        $stmt = $db->prepare("UPDATE medicines SET min_stock_level = ?, max_stock_level = ?, reorder_quantity = ? WHERE id = ?");
        foreach ($medicines as $m) {
            $avgDaily = $m['sales_90d'] / 90;
            $minStock = max(ceil($avgDaily * 7), 5);         // 1 week supply, min 5
            $maxStock = max(ceil($avgDaily * 30), $minStock * 3); // 1 month supply
            $reorderQty = max(ceil($avgDaily * 14), 10);     // 2 weeks supply, min 10
            $stmt->execute([$minStock, $maxStock, $reorderQty, $m['id']]);
            $updated++;
        }

        addAuditLog('auto_calculate', 'medicines', 0, null, ['updated_count' => $updated, 'field' => 'reorder_levels']);
        flashMessage("Auto-calculated reorder levels for $updated medicines based on 90-day sales data");
        header('Location: reorder_levels.php');
        exit;
    }

    if (isset($_POST['reset_defaults'])) {
        $db->query("UPDATE medicines SET min_stock_level = 10, max_stock_level = 100, reorder_quantity = 20 WHERE is_active = 1");
        addAuditLog('reset', 'medicines', 0, null, ['field' => 'reorder_levels', 'defaults' => 'min=10,max=100,reorder=20']);
        flashMessage('All reorder levels reset to defaults (min: 10, max: 100, reorder qty: 20)');
        header('Location: reorder_levels.php');
        exit;
    }
}

// Search/filter
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$where = "WHERE m.is_active = 1";
$params = [];
if ($search) {
    $where .= " AND m.name LIKE ?";
    $params[] = "%$search%";
}

// Get medicines with reorder data
$medicines = $db->prepare("SELECT m.id, m.name, m.quantity_in_stock, m.min_stock_level, m.max_stock_level, m.reorder_quantity,
    m.cost_price, m.sell_price,
    c.name as category_name,
    COALESCE((SELECT SUM(si.quantity) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.medicine_id = m.id AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND s.status = 'completed'), 0) as sales_90d
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    $where
    ORDER BY m.name");
$medicines->execute($params);
$medicines = $medicines->fetchAll();

// Enrich with calculated fields
$enriched = [];
foreach ($medicines as &$m) {
    $avgDaily = $m['sales_90d'] > 0 ? round($m['sales_90d'] / 90, 2) : 0;
    $daysRemaining = ($avgDaily > 0) ? round($m['quantity_in_stock'] / $avgDaily, 0) : 999;
    $suggestedMin = max(ceil($avgDaily * 7), 5);
    $suggestedMax = max(ceil($avgDaily * 30), $suggestedMin * 3);
    $suggestedReorder = max(ceil($avgDaily * 14), 10);

    // Status
    if ($m['quantity_in_stock'] <= 0) {
        $status = 'out';
        $statusColor = 'danger';
        $statusLabel = 'Out of Stock';
    } elseif ($m['quantity_in_stock'] < $m['min_stock_level']) {
        $status = 'below';
        $statusColor = 'danger';
        $statusLabel = 'Below Min';
    } elseif ($m['quantity_in_stock'] <= $m['min_stock_level'] * 1.5) {
        $status = 'approaching';
        $statusColor = 'warning';
        $statusLabel = 'Approaching Min';
    } elseif ($m['max_stock_level'] > 0 && $m['quantity_in_stock'] > $m['max_stock_level']) {
        $status = 'over';
        $statusColor = 'info';
        $statusLabel = 'Overstocked';
    } else {
        $status = 'ok';
        $statusColor = 'success';
        $statusLabel = 'Adequate';
    }

    $m['avg_daily'] = $avgDaily;
    $m['days_remaining'] = $daysRemaining;
    $m['suggested_min'] = $suggestedMin;
    $m['suggested_max'] = $suggestedMax;
    $m['suggested_reorder'] = $suggestedReorder;
    $m['status'] = $status;
    $m['status_color'] = $statusColor;
    $m['status_label'] = $statusLabel;

    // Apply status filter
    if ($statusFilter && $status !== $statusFilter) continue;
    $enriched[] = $m;
}

// Stats
$belowMin = count(array_filter($enriched, fn($m) => $m['status'] === 'below' || $m['status'] === 'out'));
$approaching = count(array_filter($enriched, fn($m) => $m['status'] === 'approaching'));
$adequate = count(array_filter($enriched, fn($m) => $m['status'] === 'ok'));
$overstocked = count(array_filter($enriched, fn($m) => $m['status'] === 'over'));
?>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card danger">
            <div class="stat-label">Below Minimum</div>
            <div class="stat-value"><?= $belowMin ?></div>
            <small class="text-muted">Need immediate reorder</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="stat-label">Approaching Min</div>
            <div class="stat-value"><?= $approaching ?></div>
            <small class="text-muted">Within 1.5x min level</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card success">
            <div class="stat-label">Adequate Stock</div>
            <div class="stat-value"><?= $adequate ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card info">
            <div class="stat-label">Overstocked</div>
            <div class="stat-value"><?= $overstocked ?></div>
            <small class="text-muted">Above max level</small>
        </div>
    </div>
</div>

<div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <form method="GET" class="d-flex gap-2">
            <input type="text" class="form-control form-control-sm" name="search" value="<?= sanitize($search) ?>" placeholder="Search medicine..." style="width:250px">
            <select class="form-select form-select-sm" name="status" style="width:auto">
                <option value="">All Statuses</option>
                <option value="below" <?= $statusFilter === 'below' ? 'selected' : '' ?>>Below Min</option>
                <option value="approaching" <?= $statusFilter === 'approaching' ? 'selected' : '' ?>>Approaching</option>
                <option value="ok" <?= $statusFilter === 'ok' ? 'selected' : '' ?>>Adequate</option>
                <option value="over" <?= $statusFilter === 'over' ? 'selected' : '' ?>>Overstocked</option>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
        </form>
        <div class="d-flex gap-2 no-print">
            <form method="POST" class="d-inline" onsubmit="return confirm('Auto-calculate reorder levels for ALL medicines based on 90-day sales data?')">
                <button type="submit" name="auto_calculate_all" value="1" class="btn btn-sm btn-warning"><i class="bi bi-calculator me-1"></i>Auto-Calculate All</button>
            </form>
            <form method="POST" class="d-inline" onsubmit="return confirm('Reset ALL reorder levels to defaults? (min: 10, max: 100, reorder: 20)')">
                <button type="submit" name="reset_defaults" value="1" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset Defaults</button>
            </form>
        </div>
    </div>
</div>

<form method="POST">
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-sliders me-2"></i>Reorder Level Settings (<?= count($enriched) ?> medicines)</h6>
        <button type="submit" name="save_levels" value="1" class="btn btn-primary btn-sm no-print"><i class="bi bi-check-lg me-1"></i>Save All Changes</button>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 data-table">
            <thead>
                <tr>
                    <th>Medicine</th>
                    <th>Category</th>
                    <th class="text-center">Current Stock</th>
                    <th class="text-center">Avg Daily Sales</th>
                    <th class="text-center">Days Remaining</th>
                    <th class="text-center" style="min-width:90px">Min Stock</th>
                    <th class="text-center" style="min-width:90px">Max Stock</th>
                    <th class="text-center" style="min-width:90px">Reorder Qty</th>
                    <th>Status</th>
                    <th class="text-center">Suggested</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($enriched as $m): ?>
                <tr class="<?= $m['status'] === 'below' || $m['status'] === 'out' ? 'table-danger' : ($m['status'] === 'approaching' ? 'table-warning' : ($m['status'] === 'over' ? 'table-info' : '')) ?>">
                    <td>
                        <strong class="small"><?= sanitize($m['name']) ?></strong>
                        <input type="hidden" name="medicine_id[]" value="<?= $m['id'] ?>">
                    </td>
                    <td><small><?= sanitize($m['category_name'] ?? '-') ?></small></td>
                    <td class="text-center">
                        <span class="badge bg-<?= $m['status_color'] ?>"><?= $m['quantity_in_stock'] ?></span>
                    </td>
                    <td class="text-center"><small><?= $m['avg_daily'] ?>/day</small></td>
                    <td class="text-center">
                        <?php if ($m['days_remaining'] >= 999): ?>
                        <small class="text-muted">N/A</small>
                        <?php elseif ($m['days_remaining'] <= 7): ?>
                        <span class="text-danger fw-bold"><?= $m['days_remaining'] ?>d</span>
                        <?php elseif ($m['days_remaining'] <= 14): ?>
                        <span class="text-warning"><?= $m['days_remaining'] ?>d</span>
                        <?php else: ?>
                        <span class="text-success"><?= $m['days_remaining'] ?>d</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><input type="number" class="form-control form-control-sm text-center" name="min_stock[]" value="<?= $m['min_stock_level'] ?>" min="0" style="width:80px;display:inline-block"></td>
                    <td class="text-center"><input type="number" class="form-control form-control-sm text-center" name="max_stock[]" value="<?= $m['max_stock_level'] ?>" min="0" style="width:80px;display:inline-block"></td>
                    <td class="text-center"><input type="number" class="form-control form-control-sm text-center" name="reorder_qty[]" value="<?= $m['reorder_quantity'] ?? 0 ?>" min="0" style="width:80px;display:inline-block"></td>
                    <td><span class="badge bg-<?= $m['status_color'] ?>"><?= $m['status_label'] ?></span></td>
                    <td class="text-center">
                        <?php if ($m['avg_daily'] > 0): ?>
                        <small class="text-muted" title="Suggested: min=<?= $m['suggested_min'] ?>, max=<?= $m['suggested_max'] ?>, reorder=<?= $m['suggested_reorder'] ?>">
                            <?= $m['suggested_min'] ?>/<?= $m['suggested_max'] ?>/<?= $m['suggested_reorder'] ?>
                        </small>
                        <?php else: ?>
                        <small class="text-muted">-</small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($enriched)): ?><tr><td colspan="10" class="text-center text-muted py-3">No medicines found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (!empty($enriched)): ?>
    <div class="card-footer text-end no-print">
        <button type="submit" name="save_levels" value="1" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save All Changes</button>
    </div>
    <?php endif; ?>
</div>
</form>

<div class="card mt-3 p-3">
    <h6><i class="bi bi-info-circle me-2"></i>How Auto-Calculate Works</h6>
    <div class="small text-muted">
        <p class="mb-1"><strong>Min Stock (Reorder Point):</strong> 1 week of average daily sales (minimum 5 units). When stock falls below this, a reorder is triggered.</p>
        <p class="mb-1"><strong>Max Stock:</strong> 1 month of average daily sales (minimum 3x min level). Prevents over-ordering.</p>
        <p class="mb-1"><strong>Reorder Quantity:</strong> 2 weeks of average daily sales (minimum 10 units). The quantity to order when reorder point is reached.</p>
        <p class="mb-0"><strong>Suggested column:</strong> Shows auto-calculated values as min/max/reorder based on the last 90 days of sales.</p>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
