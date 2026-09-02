<?php
$pageTitle = 'Batch Tracking';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_batch'])) {
    $stmt = $db->prepare("INSERT INTO medicine_batches (medicine_id, batch_number, expiry_date, quantity, cost_price, supplier_id, received_date, notes) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $_POST['medicine_id'],
        $_POST['batch_number'],
        $_POST['expiry_date'] ?: null,
        intval($_POST['quantity']),
        $_POST['cost_price'] ?: 0,
        $_POST['supplier_id'] ?: null,
        $_POST['received_date'] ?: date('Y-m-d'),
        $_POST['notes'] ?: null
    ]);

    updateStock(intval($_POST['medicine_id']), intval($_POST['quantity']));
    addStockMovement(intval($_POST['medicine_id']), 'in', intval($_POST['quantity']), 'Batch: ' . $_POST['batch_number']);
    addAuditLog('create', 'medicine_batches', $db->lastInsertId());
    flashMessage('Batch added and stock updated');
    header('Location: batches.php');
    exit;
}

$filter = $_GET['filter'] ?? 'all';
$medFilter = $_GET['medicine'] ?? '';

$where = "WHERE 1=1";
$params = [];
if ($filter === 'expiring') { $where .= " AND mb.expiry_date IS NOT NULL AND mb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)"; }
elseif ($filter === 'expired') { $where .= " AND mb.expiry_date IS NOT NULL AND mb.expiry_date < CURDATE()"; }
elseif ($filter === 'active') { $where .= " AND mb.quantity > 0"; }
if ($medFilter) { $where .= " AND mb.medicine_id = ?"; $params[] = $medFilter; }

$batches = $db->prepare("SELECT mb.*, m.name as med_name, m.strength, m.form, s.name as supplier_name
    FROM medicine_batches mb
    JOIN medicines m ON mb.medicine_id = m.id
    LEFT JOIN suppliers s ON mb.supplier_id = s.id
    $where
    ORDER BY mb.expiry_date ASC, mb.received_date DESC
    LIMIT 200");
$batches->execute($params);
$batches = $batches->fetchAll();

$medicines = $db->query("SELECT id, name, strength FROM medicines WHERE is_active = 1 ORDER BY name")->fetchAll();
$suppliers = $db->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();

$totalBatches = $db->query("SELECT COUNT(*) FROM medicine_batches")->fetchColumn();
$expiringBatches = $db->query("SELECT COUNT(*) FROM medicine_batches WHERE expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND quantity > 0")->fetchColumn();
$expiredBatches = $db->query("SELECT COUNT(*) FROM medicine_batches WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND quantity > 0")->fetchColumn();
$activeBatches = $db->query("SELECT COUNT(*) FROM medicine_batches WHERE quantity > 0")->fetchColumn();
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card"><div class="stat-label">Total Batches</div><div class="stat-value"><?= $totalBatches ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card success"><div class="stat-label">Active (in stock)</div><div class="stat-value"><?= $activeBatches ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card warning"><div class="stat-label">Expiring (90d)</div><div class="stat-value"><?= $expiringBatches ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card danger"><div class="stat-label">Expired</div><div class="stat-value"><?= $expiredBatches ?></div></div></div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="btn-group btn-group-sm">
            <a href="?filter=all" class="btn btn-<?= $filter === 'all' ? 'primary' : 'outline-primary' ?>">All</a>
            <a href="?filter=active" class="btn btn-<?= $filter === 'active' ? 'primary' : 'outline-primary' ?>">Active</a>
            <a href="?filter=expiring" class="btn btn-<?= $filter === 'expiring' ? 'primary' : 'outline-primary' ?>">Expiring</a>
            <a href="?filter=expired" class="btn btn-<?= $filter === 'expired' ? 'primary' : 'outline-primary' ?>">Expired</a>
        </div>
        <div class="d-flex gap-2">
            <form method="GET" class="d-flex gap-2">
                <input type="hidden" name="filter" value="<?= sanitize($filter) ?>">
                <select class="form-select form-select-sm" name="medicine" onchange="this.form.submit()">
                    <option value="">All Medicines</option>
                    <?php foreach ($medicines as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $medFilter == $m['id'] ? 'selected' : '' ?>><?= sanitize($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addBatchModal"><i class="bi bi-plus me-1"></i>Add Batch</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead><tr><th>Medicine</th><th>Batch #</th><th>Expiry</th><th>Qty</th><th>Cost</th><th>Supplier</th><th>Received</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($batches as $b): ?>
                <?php
                $status = 'active';
                $statusColor = 'success';
                if ($b['quantity'] <= 0) { $status = 'depleted'; $statusColor = 'secondary'; }
                elseif ($b['expiry_date'] && strtotime($b['expiry_date']) < time()) { $status = 'expired'; $statusColor = 'danger'; }
                elseif ($b['expiry_date'] && strtotime($b['expiry_date']) < strtotime('+90 days')) { $status = 'expiring'; $statusColor = 'warning'; }
                ?>
                <tr class="<?= $status === 'expired' ? 'table-danger' : ($status === 'expiring' ? 'table-warning' : '') ?>">
                    <td>
                        <strong><?= sanitize($b['med_name']) ?></strong>
                        <?php if ($b['strength']): ?><br><small class="text-muted"><?= sanitize($b['strength']) ?> - <?= ucfirst($b['form'] ?? '') ?></small><?php endif; ?>
                    </td>
                    <td><code><?= sanitize($b['batch_number']) ?></code></td>
                    <td>
                        <?php if ($b['expiry_date']): ?>
                        <?= formatDate($b['expiry_date'], 'M d, Y') ?>
                        <?php $daysLeft = ceil((strtotime($b['expiry_date']) - time()) / 86400); ?>
                        <br><small class="text-<?= $daysLeft <= 0 ? 'danger' : ($daysLeft <= 90 ? 'warning' : 'muted') ?>"><?= $daysLeft <= 0 ? abs($daysLeft) . 'd ago' : $daysLeft . 'd left' ?></small>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= $b['quantity'] ?></strong></td>
                    <td><?= formatCurrency($b['cost_price']) ?></td>
                    <td><small><?= sanitize($b['supplier_name'] ?? '-') ?></small></td>
                    <td><?= formatDate($b['received_date'], 'M d, Y') ?></td>
                    <td><span class="badge bg-<?= $statusColor ?>"><?= ucfirst($status) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addBatchModal"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title"><i class="bi bi-box me-2"></i>Add New Batch</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">Medicine <span class="text-danger">*</span></label>
            <select class="form-select" name="medicine_id" required>
                <option value="">Select Medicine</option>
                <?php foreach ($medicines as $m): ?>
                <option value="<?= $m['id'] ?>"><?= sanitize($m['name']) ?> <?= $m['strength'] ? '('.$m['strength'].')' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Batch Number <span class="text-danger">*</span></label><input type="text" class="form-control" name="batch_number" required></div>
            <div class="col-md-6"><label class="form-label">Expiry Date</label><input type="date" class="form-control" name="expiry_date"></div>
            <div class="col-md-6"><label class="form-label">Quantity <span class="text-danger">*</span></label><input type="number" class="form-control" name="quantity" min="1" required></div>
            <div class="col-md-6"><label class="form-label">Cost Price ($)</label><input type="number" class="form-control" name="cost_price" step="0.01"></div>
            <div class="col-md-6"><label class="form-label">Supplier</label><select class="form-select" name="supplier_id"><option value="">--</option><?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= sanitize($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">Received Date</label><input type="date" class="form-control" name="received_date" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
        </div>
    </div>
    <div class="modal-footer"><button type="submit" name="add_batch" value="1" class="btn btn-primary"><i class="bi bi-check me-1"></i>Add Batch</button></div>
    </form>
</div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
