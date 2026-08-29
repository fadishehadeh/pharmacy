<?php
$pageTitle = 'Supplier Performance';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$suppliers = $db->query("SELECT s.*,
    (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id) as total_orders,
    (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id AND po.status = 'received') as completed_orders,
    (SELECT COALESCE(SUM(po.total), 0) FROM purchase_orders po WHERE po.supplier_id = s.id AND po.status = 'received') as total_spent,
    (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id AND po.actual_delivery IS NOT NULL AND po.actual_delivery <= po.expected_delivery) as on_time_deliveries,
    (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id AND po.actual_delivery IS NOT NULL) as tracked_deliveries,
    (SELECT MAX(po.actual_delivery) FROM purchase_orders po WHERE po.supplier_id = s.id AND po.status = 'received') as last_delivery,
    (SELECT COUNT(DISTINCT poi.medicine_id) FROM purchase_order_items poi JOIN purchase_orders po ON poi.po_id = po.id WHERE po.supplier_id = s.id) as products_supplied
    FROM suppliers s
    WHERE s.is_active = 1
    ORDER BY total_spent DESC")->fetchAll();

$totalSpent = 0;
foreach ($suppliers as $s) $totalSpent += $s['total_spent'];
?>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card stat-card"><div class="stat-label">Active Suppliers</div><div class="stat-value"><?= count($suppliers) ?></div></div></div>
    <div class="col-md-4"><div class="card stat-card info"><div class="stat-label">Total Purchase Value</div><div class="stat-value"><?= formatCurrency($totalSpent) ?></div></div></div>
    <div class="col-md-4"><div class="card stat-card success"><div class="stat-label">Avg per Supplier</div><div class="stat-value"><?= count($suppliers) > 0 ? formatCurrency($totalSpent / count($suppliers)) : '$0.00' ?></div></div></div>
</div>

<div class="card">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Supplier Performance Overview</h6></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead><tr><th>Supplier</th><th>Orders</th><th>Completed</th><th>On-Time %</th><th>Total Spent</th><th>Products</th><th>Last Delivery</th><th>Rating</th></tr></thead>
            <tbody>
                <?php foreach ($suppliers as $s): ?>
                <?php
                $completionRate = $s['total_orders'] > 0 ? round($s['completed_orders'] / $s['total_orders'] * 100) : 0;
                $onTimeRate = $s['tracked_deliveries'] > 0 ? round($s['on_time_deliveries'] / $s['tracked_deliveries'] * 100) : 0;
                $rating = 0;
                if ($completionRate >= 90) $rating += 2; elseif ($completionRate >= 70) $rating += 1;
                if ($onTimeRate >= 90) $rating += 2; elseif ($onTimeRate >= 70) $rating += 1;
                if ($s['total_orders'] >= 10) $rating += 1;
                ?>
                <tr>
                    <td>
                        <strong><?= sanitize($s['name']) ?></strong>
                        <?php if ($s['contact_person']): ?><br><small class="text-muted"><?= sanitize($s['contact_person']) ?></small><?php endif; ?>
                    </td>
                    <td><?= $s['total_orders'] ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <span><?= $s['completed_orders'] ?></span>
                            <div class="progress flex-grow-1" style="height:6px;width:60px">
                                <div class="progress-bar bg-<?= $completionRate >= 80 ? 'success' : ($completionRate >= 50 ? 'warning' : 'danger') ?>" style="width:<?= $completionRate ?>%"></div>
                            </div>
                            <small><?= $completionRate ?>%</small>
                        </div>
                    </td>
                    <td>
                        <?php if ($s['tracked_deliveries'] > 0): ?>
                        <span class="badge bg-<?= $onTimeRate >= 80 ? 'success' : ($onTimeRate >= 50 ? 'warning' : 'danger') ?>"><?= $onTimeRate ?>%</span>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= formatCurrency($s['total_spent']) ?></strong></td>
                    <td><?= $s['products_supplied'] ?></td>
                    <td><?= $s['last_delivery'] ? formatDate($s['last_delivery'], 'M d, Y') : '-' ?></td>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
