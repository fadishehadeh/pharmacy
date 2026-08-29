<?php
$pageTitle = 'Stock Transfers';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { header('Location: ' . BASE_URL); exit; }
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_transfer'])) {
    $medId = intval($_POST['medicine_id']);
    $qty = intval($_POST['quantity']);
    $fromShelf = intval($_POST['from_shelf']);
    $toShelf = intval($_POST['to_shelf']);

    if ($medId && $qty > 0 && $fromShelf !== $toShelf) {
        $db->prepare("INSERT INTO stock_transfers (medicine_id, quantity, from_shelf_id, to_shelf_id, reason, notes, transferred_by, transfer_date) VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute([$medId, $qty, $fromShelf, $toShelf, $_POST['reason'] ?? 'reorganization', $_POST['notes'] ?? null, $_SESSION['user_id']]);

        $db->prepare("UPDATE medicines SET shelf_id = ? WHERE id = ? AND shelf_id = ?")->execute([$toShelf, $medId, $fromShelf]);

        addAuditLog('transfer', 'medicines', $medId, ['shelf' => $fromShelf], ['shelf' => $toShelf]);
        flashMessage("Transfer recorded: $qty units moved");
    } else {
        flashMessage('Invalid transfer parameters', 'danger');
    }
    header('Location: transfer.php');
    exit;
}

$transfers = $db->query("SELECT st.*, m.name as med_name,
    CONCAT(cf.name, ' - Shelf ', sf.shelf_number) as from_location,
    CONCAT(ct.name, ' - Shelf ', sto.shelf_number) as to_location,
    u.full_name as user_name
    FROM stock_transfers st
    JOIN medicines m ON st.medicine_id = m.id
    LEFT JOIN shelves sf ON st.from_shelf_id = sf.id LEFT JOIN cabinets cf ON sf.cabinet_id = cf.id
    LEFT JOIN shelves sto ON st.to_shelf_id = sto.id LEFT JOIN cabinets ct ON sto.cabinet_id = ct.id
    LEFT JOIN users u ON st.transferred_by = u.id
    ORDER BY st.transfer_date DESC LIMIT 100")->fetchAll();

$medicines = $db->query("SELECT m.id, m.name, m.shelf_id, m.quantity_in_stock, CONCAT(c.name, ' - Shelf ', s.shelf_number) as location
    FROM medicines m LEFT JOIN shelves s ON m.shelf_id = s.id LEFT JOIN cabinets c ON s.cabinet_id = c.id
    WHERE m.is_active = 1 ORDER BY m.name")->fetchAll();
$shelves = $db->query("SELECT s.id, s.shelf_number, c.name as cabinet_name FROM shelves s JOIN cabinets c ON s.cabinet_id = c.id ORDER BY c.name, s.shelf_number")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>Stock Transfers Between Shelves</h6>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newTransfer"><i class="bi bi-plus me-1"></i>New Transfer</button>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead><tr><th>Date</th><th>Medicine</th><th>Qty</th><th>From</th><th>To</th><th>Reason</th><th>By</th></tr></thead>
            <tbody>
                <?php foreach ($transfers as $t): ?>
                <tr>
                    <td><small><?= formatDate($t['transfer_date'], 'M d, H:i') ?></small></td>
                    <td><strong class="small"><?= sanitize($t['med_name']) ?></strong></td>
                    <td><?= $t['quantity'] ?></td>
                    <td><small><?= sanitize($t['from_location'] ?? '-') ?></small></td>
                    <td><small><?= sanitize($t['to_location'] ?? '-') ?></small></td>
                    <td><span class="badge bg-secondary"><?= ucfirst($t['reason']) ?></span></td>
                    <td><small><?= sanitize($t['user_name'] ?? '-') ?></small></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($transfers)): ?><tr><td colspan="7" class="text-center text-muted py-3">No transfers recorded</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="newTransfer"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title">New Stock Transfer</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">Medicine</label>
            <select class="form-select" name="medicine_id" required>
                <option value="">Select...</option>
                <?php foreach ($medicines as $m): ?>
                <option value="<?= $m['id'] ?>" data-shelf="<?= $m['shelf_id'] ?>"><?= sanitize($m['name']) ?> (<?= sanitize($m['location'] ?? 'No shelf') ?>) [<?= $m['quantity_in_stock'] ?>]</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3"><label class="form-label">Quantity</label><input type="number" class="form-control" name="quantity" min="1" required></div>
        <div class="mb-3">
            <label class="form-label">From Shelf</label>
            <select class="form-select" name="from_shelf" id="fromShelf" required>
                <option value="">Select...</option>
                <?php foreach ($shelves as $s): ?>
                <option value="<?= $s['id'] ?>"><?= sanitize($s['cabinet_name']) ?> - Shelf <?= $s['shelf_number'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">To Shelf</label>
            <select class="form-select" name="to_shelf" required>
                <option value="">Select...</option>
                <?php foreach ($shelves as $s): ?>
                <option value="<?= $s['id'] ?>"><?= sanitize($s['cabinet_name']) ?> - Shelf <?= $s['shelf_number'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Reason</label>
            <select class="form-select" name="reason">
                <option value="reorganization">Reorganization</option>
                <option value="space_optimization">Space Optimization</option>
                <option value="temperature">Temperature Requirements</option>
                <option value="accessibility">Accessibility</option>
                <option value="other">Other</option>
            </select>
        </div>
        <div><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="submit" name="create_transfer" value="1" class="btn btn-primary">Transfer</button></div>
    </form>
</div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
