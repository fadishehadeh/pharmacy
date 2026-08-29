<?php
$pageTitle = 'Deliveries';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_delivery'])) {
        $saleId = intval($_POST['sale_id']);
        $db->prepare("INSERT INTO deliveries (sale_id, customer_name, phone, address, delivery_date, time_slot, driver_name, notes, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$saleId ?: null, $_POST['customer_name'], $_POST['phone'], $_POST['address'],
                $_POST['delivery_date'], $_POST['time_slot'], $_POST['driver_name'] ?? null,
                $_POST['notes'] ?? null, 'pending', $_SESSION['user_id']]);
        flashMessage('Delivery scheduled');
        header('Location: deliveries.php');
        exit;
    } elseif (isset($_POST['update_status'])) {
        $newStatus = $_POST['new_status'];
        $deliveryId = intval($_POST['delivery_id']);
        $db->prepare("UPDATE deliveries SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$newStatus, $deliveryId]);
        if ($newStatus === 'delivered') {
            $db->prepare("UPDATE deliveries SET delivered_at = NOW() WHERE id = ?")->execute([$deliveryId]);
        }
        flashMessage('Delivery status updated');
        header('Location: deliveries.php');
        exit;
    }
}

$statusFilter = $_GET['status'] ?? '';
$dateFilter = $_GET['date'] ?? date('Y-m-d');
$where = "WHERE 1=1";
$params = [];
if ($statusFilter) { $where .= " AND d.status = ?"; $params[] = $statusFilter; }
if ($dateFilter) { $where .= " AND d.delivery_date = ?"; $params[] = $dateFilter; }

$deliveries = $db->prepare("SELECT d.*, s.invoice_number, u.full_name as creator_name
    FROM deliveries d
    LEFT JOIN sales s ON d.sale_id = s.id
    LEFT JOIN users u ON d.created_by = u.id
    $where ORDER BY d.delivery_date ASC, d.time_slot ASC");
$deliveries->execute($params);
$deliveries = $deliveries->fetchAll();

$todayPending = $db->query("SELECT COUNT(*) FROM deliveries WHERE status = 'pending' AND delivery_date = CURDATE()")->fetchColumn();
$todayDelivered = $db->query("SELECT COUNT(*) FROM deliveries WHERE status = 'delivered' AND delivery_date = CURDATE()")->fetchColumn();
$inTransit = $db->query("SELECT COUNT(*) FROM deliveries WHERE status = 'in_transit'")->fetchColumn();

$recentSales = $db->query("SELECT s.id, s.invoice_number, s.total_amount, c.name as customer_name, c.phone, c.address
    FROM sales s LEFT JOIN customers c ON s.customer_id = c.id
    WHERE s.status = 'completed' ORDER BY s.created_at DESC LIMIT 50")->fetchAll();
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card warning"><div class="stat-label">Today Pending</div><div class="stat-value"><?= $todayPending ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card info"><div class="stat-label">In Transit</div><div class="stat-value"><?= $inTransit ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card success"><div class="stat-label">Today Delivered</div><div class="stat-value"><?= $todayDelivered ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#newDelivery"><i class="bi bi-truck me-1"></i>Schedule Delivery</button></div></div>
</div>

<div class="card p-3 mb-3">
    <form class="d-flex gap-2" method="GET">
        <input type="date" class="form-control form-control-sm" name="date" value="<?= sanitize($dateFilter) ?>" style="width:auto">
        <select class="form-select form-select-sm" name="status" style="width:auto">
            <option value="">All Status</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
            <option value="in_transit" <?= $statusFilter === 'in_transit' ? 'selected' : '' ?>>In Transit</option>
            <option value="delivered" <?= $statusFilter === 'delivered' ? 'selected' : '' ?>>Delivered</option>
            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
        </select>
        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Filter</button>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Date</th><th>Time</th><th>Customer</th><th>Address</th><th>Phone</th><th>Invoice</th><th>Driver</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($deliveries as $d): ?>
                <tr>
                    <td><?= formatDate($d['delivery_date'], 'M d') ?></td>
                    <td><?= sanitize($d['time_slot'] ?? '-') ?></td>
                    <td><strong><?= sanitize($d['customer_name']) ?></strong></td>
                    <td><small><?= sanitize($d['address']) ?></small></td>
                    <td><a href="tel:<?= sanitize($d['phone']) ?>"><?= sanitize($d['phone']) ?></a></td>
                    <td><?= $d['invoice_number'] ? sanitize($d['invoice_number']) : '-' ?></td>
                    <td><?= sanitize($d['driver_name'] ?? '-') ?></td>
                    <td>
                        <?php $colors = ['pending' => 'warning', 'confirmed' => 'primary', 'in_transit' => 'info', 'delivered' => 'success', 'cancelled' => 'danger']; ?>
                        <span class="badge bg-<?= $colors[$d['status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_', ' ', $d['status'])) ?></span>
                    </td>
                    <td>
                        <?php if ($d['status'] !== 'delivered' && $d['status'] !== 'cancelled'): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="delivery_id" value="<?= $d['id'] ?>">
                            <?php if ($d['status'] === 'pending'): ?>
                            <button type="submit" name="update_status" value="1" class="btn btn-sm btn-outline-primary"><input type="hidden" name="new_status" value="confirmed"><i class="bi bi-check"></i></button>
                            <?php elseif ($d['status'] === 'confirmed'): ?>
                            <button type="submit" name="update_status" value="1" class="btn btn-sm btn-outline-info"><input type="hidden" name="new_status" value="in_transit"><i class="bi bi-truck"></i></button>
                            <?php elseif ($d['status'] === 'in_transit'): ?>
                            <button type="submit" name="update_status" value="1" class="btn btn-sm btn-outline-success"><input type="hidden" name="new_status" value="delivered"><i class="bi bi-check-circle"></i></button>
                            <?php endif; ?>
                            <button type="submit" name="update_status" value="1" class="btn btn-sm btn-outline-danger" data-confirm="Cancel delivery?"><input type="hidden" name="new_status" value="cancelled"><i class="bi bi-x"></i></button>
                        </form>
                        <?php elseif ($d['status'] === 'delivered'): ?>
                        <small class="text-muted"><?= $d['delivered_at'] ? formatDate($d['delivered_at'], 'H:i') : '' ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($deliveries)): ?><tr><td colspan="9" class="text-center text-muted py-3">No deliveries found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="newDelivery"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title">Schedule Delivery</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">Link to Sale (optional)</label>
            <select class="form-select" name="sale_id" id="saleSelect">
                <option value="">No linked sale</option>
                <?php foreach ($recentSales as $s): ?>
                <option value="<?= $s['id'] ?>" data-name="<?= sanitize($s['customer_name'] ?? '') ?>" data-phone="<?= sanitize($s['phone'] ?? '') ?>" data-address="<?= sanitize($s['address'] ?? '') ?>">
                    <?= sanitize($s['invoice_number']) ?> - <?= sanitize($s['customer_name'] ?? 'Walk-in') ?> (<?= formatCurrency($s['total_amount']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-2"><label class="form-label">Customer Name</label><input type="text" class="form-control" name="customer_name" id="delName" required></div>
        <div class="mb-2"><label class="form-label">Phone</label><input type="tel" class="form-control" name="phone" id="delPhone" required></div>
        <div class="mb-2"><label class="form-label">Address</label><textarea class="form-control" name="address" id="delAddress" rows="2" required></textarea></div>
        <div class="row mb-2">
            <div class="col"><label class="form-label">Delivery Date</label><input type="date" class="form-control" name="delivery_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col">
                <label class="form-label">Time Slot</label>
                <select class="form-select" name="time_slot">
                    <option value="morning">Morning (9-12)</option>
                    <option value="afternoon">Afternoon (12-4)</option>
                    <option value="evening">Evening (4-8)</option>
                    <option value="asap">ASAP</option>
                </select>
            </div>
        </div>
        <div class="mb-2"><label class="form-label">Driver</label><input type="text" class="form-control" name="driver_name"></div>
        <div><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="submit" name="create_delivery" value="1" class="btn btn-primary">Schedule</button></div>
    </form>
</div></div></div>

<?php
$extraScripts = <<<'SCRIPT'
<script>
document.getElementById('saleSelect').addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    if (opt.value) {
        document.getElementById('delName').value = opt.dataset.name || '';
        document.getElementById('delPhone').value = opt.dataset.phone || '';
        document.getElementById('delAddress').value = opt.dataset.address || '';
    }
});
</script>
SCRIPT;
require_once __DIR__ . '/../../includes/footer.php';
?>
