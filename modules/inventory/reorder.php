<?php
$pageTitle = 'Smart Reorder';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$lowStock = $db->query("SELECT m.*, c.name as category_name,
    (SELECT COALESCE(SUM(si.quantity),0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.medicine_id = m.id AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND s.status = 'completed') as sales_30d,
    (SELECT COALESCE(SUM(si.quantity),0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.medicine_id = m.id AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND s.status = 'completed') as sales_90d
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE m.is_active = 1 AND m.quantity_in_stock <= m.min_stock_level
    ORDER BY (m.quantity_in_stock / GREATEST(m.min_stock_level,1)) ASC")->fetchAll();

$deadStock = $db->query("SELECT m.*, c.name as category_name,
    (SELECT MAX(s.sale_date) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.medicine_id = m.id AND s.status = 'completed') as last_sold,
    (SELECT COALESCE(SUM(si.quantity),0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.medicine_id = m.id AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND s.status = 'completed') as sales_90d
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE m.is_active = 1 AND m.quantity_in_stock > 0
    AND m.id NOT IN (
        SELECT DISTINCT si.medicine_id FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND s.status = 'completed'
    )
    ORDER BY m.quantity_in_stock * m.cost_price DESC")->fetchAll();

$overstock = $db->query("SELECT m.*, c.name as category_name,
    (SELECT COALESCE(SUM(si.quantity),0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.medicine_id = m.id AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND s.status = 'completed') as sales_30d
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE m.is_active = 1 AND m.quantity_in_stock > m.max_stock_level
    ORDER BY (m.quantity_in_stock - m.max_stock_level) DESC")->fetchAll();

$topSellers = $db->query("SELECT m.name, m.quantity_in_stock, m.min_stock_level,
    SUM(si.quantity) as total_sold, COUNT(DISTINCT si.sale_id) as num_sales
    FROM sale_items si
    JOIN medicines m ON si.medicine_id = m.id
    JOIN sales s ON si.sale_id = s.id
    WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND s.status = 'completed'
    GROUP BY m.id ORDER BY total_sold DESC LIMIT 15")->fetchAll();

$deadStockValue = 0;
foreach ($deadStock as $d) $deadStockValue += $d['quantity_in_stock'] * $d['cost_price'];
?>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card danger">
            <div class="stat-label">Need Reorder</div>
            <div class="stat-value"><?= count($lowStock) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="stat-label">Dead Stock (60+ days)</div>
            <div class="stat-value"><?= count($deadStock) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card info">
            <div class="stat-label">Overstock</div>
            <div class="stat-value"><?= count($overstock) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Dead Stock Value</div>
            <div class="stat-value"><?= formatCurrency($deadStockValue) ?></div>
        </div>
    </div>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#reorder">Reorder Suggestions</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#deadstock">Dead Stock</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#overstockTab">Overstock</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#topSellersTab">Top Sellers</a></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="reorder">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0 data-table">
                    <thead><tr><th>Medicine</th><th>Category</th><th>Stock</th><th>Min</th><th>Sales (30d)</th><th>Sales (90d)</th><th>Avg Daily</th><th>Suggested Order</th></tr></thead>
                    <tbody>
                        <?php foreach ($lowStock as $m): ?>
                        <?php
                        $avgDaily = $m['sales_30d'] > 0 ? round($m['sales_30d'] / 30, 1) : ($m['sales_90d'] > 0 ? round($m['sales_90d'] / 90, 1) : 0);
                        $suggestedOrder = max($m['max_stock_level'] - $m['quantity_in_stock'], $m['min_stock_level'] * 2);
                        if ($avgDaily > 0) $suggestedOrder = max($suggestedOrder, ceil($avgDaily * 30));
                        ?>
                        <tr class="<?= $m['quantity_in_stock'] <= 0 ? 'table-danger' : 'table-warning' ?>">
                            <td><strong><?= sanitize($m['name']) ?></strong></td>
                            <td><small><?= sanitize($m['category_name'] ?? '-') ?></small></td>
                            <td><span class="badge bg-<?= $m['quantity_in_stock'] <= 0 ? 'danger' : 'warning' ?>"><?= $m['quantity_in_stock'] ?></span></td>
                            <td><?= $m['min_stock_level'] ?></td>
                            <td><?= $m['sales_30d'] ?></td>
                            <td><?= $m['sales_90d'] ?></td>
                            <td><?= $avgDaily ?>/day</td>
                            <td><strong class="text-primary"><?= $suggestedOrder ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($lowStock)): ?><tr><td colspan="8" class="text-center text-muted py-3">All medicines are well-stocked</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="deadstock">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0 data-table">
                    <thead><tr><th>Medicine</th><th>Category</th><th>Stock</th><th>Value</th><th>Last Sold</th><th>Sales (90d)</th></tr></thead>
                    <tbody>
                        <?php foreach ($deadStock as $m): ?>
                        <tr>
                            <td><strong><?= sanitize($m['name']) ?></strong></td>
                            <td><small><?= sanitize($m['category_name'] ?? '-') ?></small></td>
                            <td><?= $m['quantity_in_stock'] ?></td>
                            <td><?= formatCurrency($m['quantity_in_stock'] * $m['cost_price']) ?></td>
                            <td><small class="text-danger"><?= $m['last_sold'] ? formatDate($m['last_sold'], 'M d, Y') : 'Never' ?></small></td>
                            <td><?= $m['sales_90d'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($deadStock)): ?><tr><td colspan="6" class="text-center text-muted py-3">No dead stock detected</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="overstockTab">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0 data-table">
                    <thead><tr><th>Medicine</th><th>Category</th><th>Stock</th><th>Max</th><th>Over By</th><th>Sales (30d)</th></tr></thead>
                    <tbody>
                        <?php foreach ($overstock as $m): ?>
                        <tr>
                            <td><strong><?= sanitize($m['name']) ?></strong></td>
                            <td><small><?= sanitize($m['category_name'] ?? '-') ?></small></td>
                            <td><?= $m['quantity_in_stock'] ?></td>
                            <td><?= $m['max_stock_level'] ?></td>
                            <td><span class="text-danger fw-bold">+<?= $m['quantity_in_stock'] - $m['max_stock_level'] ?></span></td>
                            <td><?= $m['sales_30d'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($overstock)): ?><tr><td colspan="6" class="text-center text-muted py-3">No overstock detected</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="topSellersTab">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>#</th><th>Medicine</th><th>Units Sold (30d)</th><th># Sales</th><th>Current Stock</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($topSellers as $i => $m): ?>
                        <tr>
                            <td><strong><?= $i + 1 ?></strong></td>
                            <td><?= sanitize($m['name']) ?></td>
                            <td><strong><?= $m['total_sold'] ?></strong></td>
                            <td><?= $m['num_sales'] ?></td>
                            <td><?= $m['quantity_in_stock'] ?></td>
                            <td>
                                <?php if ($m['quantity_in_stock'] <= 0): ?><span class="badge bg-danger">Out</span>
                                <?php elseif ($m['quantity_in_stock'] <= $m['min_stock_level']): ?><span class="badge bg-warning">Low</span>
                                <?php else: ?><span class="badge bg-success">OK</span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
