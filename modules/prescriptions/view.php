<?php
$pageTitle = 'View Prescription';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$id = intval($_GET['id'] ?? 0);
$rx = $db->prepare("SELECT p.*, c.name as customer_name, c.phone as customer_phone FROM prescriptions p LEFT JOIN customers c ON p.customer_id = c.id WHERE p.id = ?");
$rx->execute([$id]);
$rx = $rx->fetch();

if (!$rx) {
    flashMessage('Prescription not found', 'error');
    header('Location: index.php');
    exit;
}

$items = $db->prepare("SELECT pi.*, m.name as med_name, m.strength, m.form, m.sell_price, m.quantity_in_stock, m.requires_prescription FROM prescription_items pi LEFT JOIN medicines m ON pi.medicine_id = m.id WHERE pi.prescription_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['dispense'])) {
        $allDispensed = true;
        foreach ($_POST['dispense_qty'] as $itemId => $qty) {
            $qty = intval($qty);
            if ($qty <= 0) continue;
            $item = $db->prepare("SELECT * FROM prescription_items WHERE id = ? AND prescription_id = ?");
            $item->execute([$itemId, $id]);
            $item = $item->fetch();
            if (!$item) continue;

            $remaining = $item['quantity_prescribed'] - $item['quantity_dispensed'];
            $qty = min($qty, $remaining);
            if ($qty <= 0) continue;

            $db->prepare("UPDATE prescription_items SET quantity_dispensed = quantity_dispensed + ? WHERE id = ?")->execute([$qty, $itemId]);
            if ($item['medicine_id']) {
                updateStock($item['medicine_id'], -$qty);
                addStockMovement($item['medicine_id'], 'out', $qty, 'Prescription ' . $rx['rx_number'], 'prescription', $id);
            }
        }

        $checkItems = $db->prepare("SELECT SUM(quantity_prescribed) as total_prescribed, SUM(quantity_dispensed) as total_dispensed FROM prescription_items WHERE prescription_id = ?");
        $checkItems->execute([$id]);
        $totals = $checkItems->fetch();
        $newStatus = $totals['total_dispensed'] >= $totals['total_prescribed'] ? 'dispensed' : 'partial';
        $db->prepare("UPDATE prescriptions SET status = ? WHERE id = ?")->execute([$newStatus, $id]);

        addAuditLog('dispense', 'prescriptions', $id);
        flashMessage('Medicines dispensed from prescription');
        header("Location: view.php?id=$id");
        exit;
    }

    if (isset($_POST['update_status'])) {
        $db->prepare("UPDATE prescriptions SET status = ? WHERE id = ?")->execute([$_POST['status'], $id]);
        flashMessage('Prescription status updated');
        header("Location: view.php?id=$id");
        exit;
    }
}
?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h5 class="mb-1"><i class="bi bi-file-medical me-2"></i><?= sanitize($rx['rx_number']) ?></h5>
                    <small class="text-muted">Created: <?= formatDate($rx['created_at'], 'M d, Y H:i') ?></small>
                </div>
                <div>
                    <?php $sColors = ['active'=>'success','dispensed'=>'info','partial'=>'warning','expired'=>'danger','cancelled'=>'secondary']; ?>
                    <span class="badge bg-<?= $sColors[$rx['status']] ?? 'secondary' ?> fs-6"><?= strtoupper($rx['status']) ?></span>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <h6 class="text-primary">Patient Information</h6>
                    <table class="table table-sm mb-0">
                        <tr><td class="text-muted" style="width:120px">Name</td><td><strong><?= sanitize($rx['customer_name'] ?? 'Walk-in') ?></strong></td></tr>
                        <tr><td class="text-muted">Phone</td><td><?= sanitize($rx['customer_phone'] ?? '-') ?></td></tr>
                        <tr><td class="text-muted">Diagnosis</td><td><?= sanitize($rx['diagnosis'] ?? '-') ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6 class="text-primary">Doctor Information</h6>
                    <table class="table table-sm mb-0">
                        <tr><td class="text-muted" style="width:120px">Doctor</td><td><strong><?= sanitize($rx['doctor_name']) ?></strong></td></tr>
                        <tr><td class="text-muted">License #</td><td><?= sanitize($rx['doctor_license'] ?? '-') ?></td></tr>
                        <tr><td class="text-muted">Phone</td><td><?= sanitize($rx['doctor_phone'] ?? '-') ?></td></tr>
                    </table>
                </div>
            </div>

            <h6 class="text-primary border-bottom pb-2">Prescribed Medicines</h6>
            <form method="POST">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th>Prescribed</th><th>Dispensed</th><th>Remaining</th>
                    <?php if ($rx['status'] === 'active' || $rx['status'] === 'partial'): ?><th>Dispense</th><?php endif; ?>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <?php $remaining = $item['quantity_prescribed'] - $item['quantity_dispensed']; ?>
                        <tr class="<?= $remaining <= 0 ? 'table-success' : '' ?>">
                            <td>
                                <strong><?= sanitize($item['med_name'] ?? 'Unknown') ?></strong>
                                <?php if ($item['strength']): ?><br><small class="text-muted"><?= sanitize($item['strength']) ?> - <?= ucfirst($item['form'] ?? '') ?></small><?php endif; ?>
                                <?php if ($item['instructions']): ?><br><small class="text-info"><i class="bi bi-info-circle me-1"></i><?= sanitize($item['instructions']) ?></small><?php endif; ?>
                            </td>
                            <td><?= sanitize($item['dosage'] ?? '-') ?></td>
                            <td><?= sanitize($item['frequency'] ?? '-') ?></td>
                            <td><?= sanitize($item['duration'] ?? '-') ?></td>
                            <td><strong><?= $item['quantity_prescribed'] ?></strong></td>
                            <td><span class="badge bg-info"><?= $item['quantity_dispensed'] ?></span></td>
                            <td>
                                <?php if ($remaining > 0): ?>
                                <span class="badge bg-warning"><?= $remaining ?></span>
                                <?php else: ?>
                                <span class="badge bg-success">Done</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($rx['status'] === 'active' || $rx['status'] === 'partial'): ?>
                            <td>
                                <?php if ($remaining > 0): ?>
                                <input type="number" class="form-control form-control-sm" name="dispense_qty[<?= $item['id'] ?>]" min="0" max="<?= $remaining ?>" value="<?= $remaining ?>" style="width:70px">
                                <?php if ($item['quantity_in_stock'] !== null && $item['quantity_in_stock'] < $remaining): ?>
                                <small class="text-danger">Stock: <?= $item['quantity_in_stock'] ?></small>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-success"><i class="bi bi-check-circle"></i></span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($rx['status'] === 'active' || $rx['status'] === 'partial'): ?>
            <div class="mt-3">
                <button type="submit" name="dispense" value="1" class="btn btn-primary"><i class="bi bi-check2-all me-1"></i>Dispense Selected</button>
            </div>
            <?php endif; ?>
            </form>

            <?php if ($rx['notes']): ?>
            <div class="mt-3 p-3 bg-light rounded">
                <strong class="small">Notes:</strong><br>
                <small><?= sanitize($rx['notes']) ?></small>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-calendar me-2"></i>Dates</h6>
            <div class="d-flex justify-content-between mb-1"><span class="small text-muted">Issue Date</span><strong class="small"><?= formatDate($rx['issue_date'], 'M d, Y') ?></strong></div>
            <?php if ($rx['expiry_date']): ?>
            <?php $daysLeft = (strtotime($rx['expiry_date']) - time()) / 86400; ?>
            <div class="d-flex justify-content-between"><span class="small text-muted">Expiry Date</span><strong class="small <?= $daysLeft <= 0 ? 'text-danger' : ($daysLeft <= 7 ? 'text-warning' : '') ?>"><?= formatDate($rx['expiry_date'], 'M d, Y') ?></strong></div>
            <?php if ($daysLeft > 0): ?><small class="text-muted"><?= ceil($daysLeft) ?> days remaining</small><?php else: ?><small class="text-danger">Expired</small><?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="card p-3 mb-3">
            <h6><i class="bi bi-gear me-2"></i>Actions</h6>
            <form method="POST" class="d-flex gap-2 mb-2">
                <select class="form-select form-select-sm" name="status">
                    <?php foreach (['active','partial','dispensed','expired','cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= $rx['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="update_status" value="1" class="btn btn-sm btn-outline-primary">Update</button>
            </form>
            <a href="index.php" class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-arrow-left me-1"></i>Back to List</a>
        </div>

        <div class="card p-3">
            <h6><i class="bi bi-printer me-2"></i>Print</h6>
            <button onclick="window.print()" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-printer me-1"></i>Print Prescription</button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
