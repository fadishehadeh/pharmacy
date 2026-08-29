<?php
$pageTitle = 'Waste & Disposal';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { header('Location: ' . BASE_URL); exit; }
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_disposal'])) {
    $medId = intval($_POST['medicine_id']);
    $qty = intval($_POST['quantity']);
    $reason = $_POST['reason'];

    $med = $db->prepare("SELECT * FROM medicines WHERE id = ?");
    $med->execute([$medId]);
    $med = $med->fetch();

    if ($med && $qty > 0 && $qty <= $med['quantity_in_stock']) {
        $db->prepare("INSERT INTO waste_disposal (medicine_id, quantity, reason, disposal_method, batch_number, expiry_date, cost_value, witness_name, witness_license, notes, disposed_by, disposal_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([
                $medId, $qty, $reason, $_POST['disposal_method'],
                $_POST['batch_number'] ?: $med['batch_number'],
                $_POST['expiry_date'] ?: $med['expiry_date'],
                $qty * $med['cost_price'],
                $_POST['witness_name'] ?? null, $_POST['witness_license'] ?? null,
                $_POST['notes'] ?? null, $_SESSION['user_id']
            ]);

        updateStock($medId, -$qty);
        $movementType = $reason === 'expired' ? 'expired' : 'damaged';
        addStockMovement($medId, $movementType, $qty, "Disposal: $reason - " . ($_POST['notes'] ?? ''));

        if ($med['is_controlled']) {
            $balance = $med['quantity_in_stock'] - $qty;
            $db->prepare("INSERT INTO controlled_substance_log (medicine_id, transaction_type, quantity, balance_after, witness_name, notes, created_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$medId, 'destroyed', $qty, $balance, $_POST['witness_name'] ?? null, "Disposal: $reason", $_SESSION['user_id']]);
        }

        addAuditLog('disposal', 'medicines', $medId, null, ['quantity' => $qty, 'reason' => $reason]);
        flashMessage("$qty units disposed. Stock adjusted.");
    } else {
        flashMessage('Invalid quantity or medicine', 'danger');
    }
    header('Location: disposal.php');
    exit;
}

$disposals = $db->query("SELECT wd.*, m.name as med_name, m.is_controlled, u.full_name as user_name
    FROM waste_disposal wd
    JOIN medicines m ON wd.medicine_id = m.id
    LEFT JOIN users u ON wd.disposed_by = u.id
    ORDER BY wd.disposal_date DESC LIMIT 100")->fetchAll();

$totalWaste = $db->query("SELECT COALESCE(SUM(cost_value), 0) FROM waste_disposal WHERE YEAR(disposal_date) = YEAR(CURDATE())")->fetchColumn();
$totalQty = $db->query("SELECT COALESCE(SUM(quantity), 0) FROM waste_disposal WHERE YEAR(disposal_date) = YEAR(CURDATE())")->fetchColumn();
$expiredCount = $db->query("SELECT COALESCE(SUM(quantity), 0) FROM waste_disposal WHERE reason = 'expired' AND YEAR(disposal_date) = YEAR(CURDATE())")->fetchColumn();
$monthlyWaste = $db->query("SELECT COALESCE(SUM(cost_value), 0) FROM waste_disposal WHERE MONTH(disposal_date) = MONTH(CURDATE()) AND YEAR(disposal_date) = YEAR(CURDATE())")->fetchColumn();

$expiredMeds = $db->query("SELECT * FROM medicines WHERE is_active = 1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE() AND quantity_in_stock > 0 ORDER BY expiry_date ASC")->fetchAll();
$medicines = $db->query("SELECT id, name, quantity_in_stock, batch_number, expiry_date, is_controlled FROM medicines WHERE is_active = 1 AND quantity_in_stock > 0 ORDER BY name")->fetchAll();
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card danger"><div class="stat-label">Year Waste Value</div><div class="stat-value"><?= formatCurrency($totalWaste) ?></div><small class="text-muted"><?= number_format($totalQty) ?> units</small></div></div>
    <div class="col-md-3"><div class="card stat-card warning"><div class="stat-label">This Month</div><div class="stat-value"><?= formatCurrency($monthlyWaste) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card"><div class="stat-label">Expired Disposed</div><div class="stat-value"><?= number_format($expiredCount) ?></div><small class="text-muted">units this year</small></div></div>
    <div class="col-md-3"><div class="card stat-card info"><div class="stat-label">Pending Disposal</div><div class="stat-value"><?= count($expiredMeds) ?></div><small class="text-muted">expired items in stock</small></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0"><i class="bi bi-trash3 me-2"></i>Disposal Records</h6>
                <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#newDisposal"><i class="bi bi-plus me-1"></i>Record Disposal</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 data-table">
                    <thead><tr><th>Date</th><th>Medicine</th><th>Qty</th><th>Reason</th><th>Method</th><th>Value</th><th>Witness</th><th>By</th></tr></thead>
                    <tbody>
                        <?php foreach ($disposals as $d): ?>
                        <tr>
                            <td><small><?= formatDate($d['disposal_date'], 'M d, Y') ?></small></td>
                            <td>
                                <strong class="small"><?= sanitize($d['med_name']) ?></strong>
                                <?php if ($d['is_controlled']): ?><span class="badge bg-danger ms-1">Ctrl</span><?php endif; ?>
                                <?php if ($d['batch_number']): ?><br><small class="text-muted">Batch: <?= sanitize($d['batch_number']) ?></small><?php endif; ?>
                            </td>
                            <td><?= $d['quantity'] ?></td>
                            <td><span class="badge bg-<?= $d['reason'] === 'expired' ? 'danger' : ($d['reason'] === 'damaged' ? 'warning' : 'secondary') ?>"><?= ucfirst($d['reason']) ?></span></td>
                            <td><small><?= ucfirst(str_replace('_', ' ', $d['disposal_method'])) ?></small></td>
                            <td class="text-danger"><?= formatCurrency($d['cost_value']) ?></td>
                            <td><small><?= sanitize($d['witness_name'] ?? '-') ?></small></td>
                            <td><small><?= sanitize($d['user_name'] ?? '-') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <?php if (!empty($expiredMeds)): ?>
        <div class="card p-3 mb-3 border-danger">
            <h6 class="text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Expired - Needs Disposal</h6>
            <div class="list-group list-group-flush" style="max-height:400px;overflow-y:auto">
                <?php foreach ($expiredMeds as $em): ?>
                <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                    <div>
                        <small class="fw-bold"><?= sanitize($em['name']) ?></small><br>
                        <small class="text-danger">Expired: <?= formatDate($em['expiry_date'], 'M d, Y') ?></small><br>
                        <small class="text-muted"><?= $em['quantity_in_stock'] ?> units | <?= formatCurrency($em['quantity_in_stock'] * $em['cost_price']) ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="newDisposal"><div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title">Record Disposal</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Medicine</label>
                <select class="form-select" name="medicine_id" required>
                    <option value="">Select...</option>
                    <?php foreach ($medicines as $m): ?>
                    <option value="<?= $m['id'] ?>"><?= sanitize($m['name']) ?> (Stock: <?= $m['quantity_in_stock'] ?>)<?= $m['is_controlled'] ? ' [CTRL]' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label">Quantity</label><input type="number" class="form-control" name="quantity" min="1" required></div>
            <div class="col-md-3">
                <label class="form-label">Reason</label>
                <select class="form-select" name="reason" required>
                    <option value="expired">Expired</option>
                    <option value="damaged">Damaged</option>
                    <option value="recalled">Recalled</option>
                    <option value="contaminated">Contaminated</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Disposal Method</label>
                <select class="form-select" name="disposal_method" required>
                    <option value="return_supplier">Return to Supplier</option>
                    <option value="incineration">Incineration</option>
                    <option value="chemical_treatment">Chemical Treatment</option>
                    <option value="landfill">Authorized Landfill</option>
                    <option value="moph_collection">MoPH Collection Program</option>
                </select>
            </div>
            <div class="col-md-4"><label class="form-label">Batch Number</label><input type="text" class="form-control" name="batch_number" placeholder="Auto from medicine"></div>
            <div class="col-md-4"><label class="form-label">Expiry Date</label><input type="date" class="form-control" name="expiry_date"></div>
            <div class="col-md-6"><label class="form-label">Witness Name</label><input type="text" class="form-control" name="witness_name" placeholder="Required for controlled substances"></div>
            <div class="col-md-6"><label class="form-label">Witness License #</label><input type="text" class="form-control" name="witness_license"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
        </div>
    </div>
    <div class="modal-footer"><button type="submit" name="record_disposal" value="1" class="btn btn-danger" onclick="return confirm('Record disposal and adjust stock?')"><i class="bi bi-trash me-1"></i>Record Disposal</button></div>
    </form>
</div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
