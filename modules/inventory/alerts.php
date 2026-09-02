<?php
$pageTitle = 'Stock & Expiry Alerts';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }

$expired = getExpiredMedicines();
$expiring = getExpiringMedicines(90);
$lowStock = getLowStockMedicines();
$outOfStock = getOutOfStockMedicines();
?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#expired">Expired <span class="badge bg-danger"><?= count($expired) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#expiring">Expiring Soon <span class="badge bg-warning text-dark"><?= count($expiring) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#low">Low Stock <span class="badge bg-warning text-dark"><?= count($lowStock) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#out">Out of Stock <span class="badge bg-danger"><?= count($outOfStock) ?></span></a></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="expired">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Expiry Date</th><th>Stock</th><th>Batch</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($expired as $med): ?>
                        <tr class="table-danger">
                            <td><strong><?= sanitize($med['name']) ?></strong><br><small class="text-muted"><?= sanitize($med['strength'] ?? '') ?></small></td>
                            <td><span class="badge bg-danger"><?= formatDate($med['expiry_date'], 'M d, Y') ?></span></td>
                            <td><?= $med['quantity_in_stock'] ?></td>
                            <td><?= sanitize($med['batch_number'] ?? '-') ?></td>
                            <td><a href="edit.php?id=<?= $med['id'] ?>" class="btn btn-sm btn-outline-primary">Manage</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="expiring">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Expiry Date</th><th>Days Left</th><th>Stock</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($expiring as $med): ?>
                        <?php $daysLeft = ceil((strtotime($med['expiry_date']) - time()) / 86400); ?>
                        <tr class="<?= $daysLeft <= 30 ? 'table-warning' : '' ?>">
                            <td><strong><?= sanitize($med['name']) ?></strong></td>
                            <td><?= formatDate($med['expiry_date'], 'M d, Y') ?></td>
                            <td><span class="badge bg-<?= $daysLeft <= 30 ? 'danger' : 'warning' ?>"><?= $daysLeft ?> days</span></td>
                            <td><?= $med['quantity_in_stock'] ?></td>
                            <td><a href="edit.php?id=<?= $med['id'] ?>" class="btn btn-sm btn-outline-primary">Manage</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="low">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Current Stock</th><th>Min Level</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($lowStock as $med): ?>
                        <tr class="table-warning">
                            <td><strong><?= sanitize($med['name']) ?></strong></td>
                            <td><span class="badge badge-stock-low"><?= $med['quantity_in_stock'] ?></span></td>
                            <td><?= $med['min_stock_level'] ?></td>
                            <td><a href="edit.php?id=<?= $med['id'] ?>" class="btn btn-sm btn-outline-primary">Restock</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($lowStock)): ?><tr><td colspan="4" class="text-center text-muted py-3">All medicines above minimum stock</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="out">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Category</th><th>Last Sold</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($outOfStock as $med): ?>
                        <tr class="table-danger">
                            <td><strong><?= sanitize($med['name']) ?></strong></td>
                            <td><?= sanitize($med['category_id'] ?? '-') ?></td>
                            <td>-</td>
                            <td><a href="edit.php?id=<?= $med['id'] ?>" class="btn btn-sm btn-outline-primary">Restock</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
