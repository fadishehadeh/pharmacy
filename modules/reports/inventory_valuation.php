<?php
$pageTitle = 'Inventory Valuation';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

$groupBy = $_GET['group'] ?? 'category';

if ($groupBy === 'category') {
    $groups = $db->query("SELECT c.name as group_name, COUNT(m.id) as item_count,
        SUM(m.quantity_in_stock) as total_qty,
        SUM(m.quantity_in_stock * m.cost_price) as cost_value,
        SUM(m.quantity_in_stock * m.sell_price) as retail_value
        FROM medicines m LEFT JOIN categories c ON m.category_id = c.id
        WHERE m.is_active = 1
        GROUP BY c.id
        ORDER BY cost_value DESC")->fetchAll();
} elseif ($groupBy === 'form') {
    $groups = $db->query("SELECT m.form as group_name, COUNT(m.id) as item_count,
        SUM(m.quantity_in_stock) as total_qty,
        SUM(m.quantity_in_stock * m.cost_price) as cost_value,
        SUM(m.quantity_in_stock * m.sell_price) as retail_value
        FROM medicines m WHERE m.is_active = 1
        GROUP BY m.form ORDER BY cost_value DESC")->fetchAll();
} else {
    $groups = $db->query("SELECT m.manufacturer as group_name, COUNT(m.id) as item_count,
        SUM(m.quantity_in_stock) as total_qty,
        SUM(m.quantity_in_stock * m.cost_price) as cost_value,
        SUM(m.quantity_in_stock * m.sell_price) as retail_value
        FROM medicines m WHERE m.is_active = 1
        GROUP BY m.manufacturer ORDER BY cost_value DESC")->fetchAll();
}

$totalCost = $db->query("SELECT COALESCE(SUM(quantity_in_stock * cost_price), 0) FROM medicines WHERE is_active = 1")->fetchColumn();
$totalRetail = $db->query("SELECT COALESCE(SUM(quantity_in_stock * sell_price), 0) FROM medicines WHERE is_active = 1")->fetchColumn();
$totalItems = $db->query("SELECT COUNT(*) FROM medicines WHERE is_active = 1")->fetchColumn();
$totalQty = $db->query("SELECT COALESCE(SUM(quantity_in_stock), 0) FROM medicines WHERE is_active = 1")->fetchColumn();
$potentialProfit = $totalRetail - $totalCost;

$topValue = $db->query("SELECT m.name, m.quantity_in_stock, m.cost_price, (m.quantity_in_stock * m.cost_price) as value FROM medicines m WHERE m.is_active = 1 AND m.quantity_in_stock > 0 ORDER BY value DESC LIMIT 15")->fetchAll();
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card"><div class="stat-label">Cost Value</div><div class="stat-value"><?= formatCurrency($totalCost) ?></div><small class="text-muted"><?= number_format($totalItems) ?> products</small></div></div>
    <div class="col-md-3"><div class="card stat-card info"><div class="stat-label">Retail Value</div><div class="stat-value"><?= formatCurrency($totalRetail) ?></div><small class="text-muted"><?= number_format($totalQty) ?> units</small></div></div>
    <div class="col-md-3"><div class="card stat-card success"><div class="stat-label">Potential Profit</div><div class="stat-value"><?= formatCurrency($potentialProfit) ?></div><small class="text-muted"><?= $totalCost > 0 ? round($potentialProfit / $totalCost * 100, 1) : 0 ?>% markup</small></div></div>
    <div class="col-md-3">
        <div class="card p-3">
            <div class="btn-group btn-group-sm w-100">
                <a href="?group=category" class="btn btn-<?= $groupBy === 'category' ? 'primary' : 'outline-primary' ?>">Category</a>
                <a href="?group=form" class="btn btn-<?= $groupBy === 'form' ? 'primary' : 'outline-primary' ?>">Form</a>
                <a href="?group=manufacturer" class="btn btn-<?= $groupBy === 'manufacturer' ? 'primary' : 'outline-primary' ?>">Manufacturer</a>
            </div>
            <button onclick="window.print()" class="btn btn-sm btn-outline-dark w-100 mt-2"><i class="bi bi-printer me-1"></i>Print</button>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Valuation by <?= ucfirst($groupBy) ?></h6></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 data-table">
                    <thead><tr><th><?= ucfirst($groupBy) ?></th><th>Items</th><th>Qty</th><th>Cost Value</th><th>Retail Value</th><th>Margin</th><th>% of Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($groups as $g): ?>
                        <?php $margin = $g['cost_value'] > 0 ? round(($g['retail_value'] - $g['cost_value']) / $g['cost_value'] * 100, 1) : 0; ?>
                        <tr>
                            <td><strong><?= sanitize($g['group_name'] ?? 'Uncategorized') ?></strong></td>
                            <td><?= $g['item_count'] ?></td>
                            <td><?= number_format($g['total_qty']) ?></td>
                            <td><?= formatCurrency($g['cost_value']) ?></td>
                            <td><?= formatCurrency($g['retail_value']) ?></td>
                            <td><span class="text-<?= $margin >= 20 ? 'success' : ($margin >= 10 ? 'warning' : 'danger') ?>"><?= $margin ?>%</span></td>
                            <td>
                                <div class="d-flex align-items-center gap-1">
                                    <div class="progress flex-grow-1" style="height:6px;width:80px">
                                        <div class="progress-bar" style="width:<?= $totalCost > 0 ? round($g['cost_value'] / $totalCost * 100) : 0 ?>%"></div>
                                    </div>
                                    <small><?= $totalCost > 0 ? round($g['cost_value'] / $totalCost * 100, 1) : 0 ?>%</small>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-active">
                            <td><strong>Total</strong></td>
                            <td><strong><?= $totalItems ?></strong></td>
                            <td><strong><?= number_format($totalQty) ?></strong></td>
                            <td><strong><?= formatCurrency($totalCost) ?></strong></td>
                            <td><strong><?= formatCurrency($totalRetail) ?></strong></td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-trophy me-2"></i>Highest Value Items</h6>
            <div class="list-group list-group-flush" style="max-height:500px;overflow-y:auto">
                <?php foreach ($topValue as $tv): ?>
                <div class="list-group-item px-0 py-2 d-flex justify-content-between">
                    <div>
                        <small><?= sanitize($tv['name']) ?></small><br>
                        <small class="text-muted"><?= $tv['quantity_in_stock'] ?> x <?= formatCurrency($tv['cost_price']) ?></small>
                    </div>
                    <strong class="small"><?= formatCurrency($tv['value']) ?></strong>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
