<?php
$pageTitle = 'Controlled Substances';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_transaction'])) {
    $medicineId = intval($_POST['medicine_id']);
    $quantity = intval($_POST['quantity']);
    $type = $_POST['transaction_type'];

    $currentStock = $db->prepare("SELECT quantity_in_stock FROM medicines WHERE id = ?");
    $currentStock->execute([$medicineId]);
    $current = $currentStock->fetchColumn();

    $balanceAfter = $type === 'received' ? $current + $quantity : $current - $quantity;

    $db->prepare("INSERT INTO controlled_substance_log (medicine_id, transaction_type, quantity, balance_after, prescription_number, doctor_name, doctor_license, patient_name, patient_id, supplier_name, witness_name, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
        $medicineId, $type, $quantity, $balanceAfter,
        $_POST['prescription_number'] ?: null, $_POST['doctor_name'] ?: null, $_POST['doctor_license'] ?: null,
        $_POST['patient_name'] ?: null, $_POST['patient_id'] ?: null, $_POST['supplier_name'] ?: null,
        $_POST['witness_name'] ?: null, $_POST['notes'] ?: null, $_SESSION['user_id'] ?? null
    ]);

    $stockChange = $type === 'received' ? $quantity : -$quantity;
    updateStock($medicineId, $stockChange);
    addStockMovement($medicineId, $type === 'received' ? 'in' : 'out', $quantity, "Controlled: $type");

    flashMessage('Transaction logged');
    header('Location: controlled.php');
    exit;
}

$controlledMeds = $db->query("SELECT * FROM medicines WHERE is_controlled = 1 AND is_active = 1 ORDER BY name")->fetchAll();

$recentLogs = $db->query("SELECT csl.*, m.name as medicine_name FROM controlled_substance_log csl JOIN medicines m ON csl.medicine_id = m.id ORDER BY csl.created_at DESC LIMIT 50")->fetchAll();
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-shield-lock me-2"></i>Log Transaction</h6>
            <form method="POST">
                <div class="mb-2">
                    <select class="form-select" name="medicine_id" required>
                        <option value="">Select controlled substance</option>
                        <?php foreach ($controlledMeds as $m): ?>
                        <option value="<?= $m['id'] ?>"><?= sanitize($m['name']) ?> (<?= sanitize($m['controlled_schedule'] ?? '') ?>) - Stock: <?= $m['quantity_in_stock'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <select class="form-select" name="transaction_type" required>
                        <option value="">Type</option>
                        <option value="received">Received</option>
                        <option value="dispensed">Dispensed</option>
                        <option value="destroyed">Destroyed</option>
                        <option value="returned">Returned</option>
                    </select>
                </div>
                <div class="mb-2"><input type="number" class="form-control" name="quantity" placeholder="Quantity" min="1" required></div>
                <hr>
                <div class="mb-2"><input type="text" class="form-control" name="prescription_number" placeholder="Prescription #"></div>
                <div class="mb-2"><input type="text" class="form-control" name="doctor_name" placeholder="Doctor name"></div>
                <div class="mb-2"><input type="text" class="form-control" name="doctor_license" placeholder="Doctor license #"></div>
                <div class="mb-2"><input type="text" class="form-control" name="patient_name" placeholder="Patient name"></div>
                <div class="mb-2"><input type="text" class="form-control" name="patient_id" placeholder="Patient ID"></div>
                <div class="mb-2"><input type="text" class="form-control" name="supplier_name" placeholder="Supplier (if received)"></div>
                <div class="mb-2"><input type="text" class="form-control" name="witness_name" placeholder="Witness name"></div>
                <div class="mb-3"><textarea class="form-control" name="notes" placeholder="Notes" rows="2"></textarea></div>
                <button type="submit" name="log_transaction" value="1" class="btn btn-primary w-100">Log Transaction</button>
            </form>
        </div>

        <div class="card p-3">
            <h6>Controlled Inventory</h6>
            <?php foreach ($controlledMeds as $m): ?>
            <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                <div>
                    <strong class="small"><?= sanitize($m['name']) ?></strong>
                    <br><small class="text-muted">Schedule <?= sanitize($m['controlled_schedule'] ?? '-') ?></small>
                </div>
                <span class="badge bg-<?= $m['quantity_in_stock'] > 0 ? 'primary' : 'danger' ?>"><?= $m['quantity_in_stock'] ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($controlledMeds)): ?><p class="text-muted small text-center">No controlled substances in inventory</p><?php endif; ?>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="p-3 border-bottom d-flex justify-content-between">
                <h6 class="mb-0">Transaction Log</h6>
                <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Date</th><th>Medicine</th><th>Type</th><th>Qty</th><th>Balance</th><th>Patient</th><th>Doctor</th><th>Rx #</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td><small><?= formatDate($log['created_at'], 'M d, H:i') ?></small></td>
                            <td><?= sanitize($log['medicine_name']) ?></td>
                            <td>
                                <?php $colors = ['received'=>'success','dispensed'=>'primary','destroyed'=>'danger','returned'=>'warning']; ?>
                                <span class="badge bg-<?= $colors[$log['transaction_type']] ?? 'secondary' ?>"><?= ucfirst($log['transaction_type']) ?></span>
                            </td>
                            <td><?= $log['quantity'] ?></td>
                            <td><?= $log['balance_after'] ?></td>
                            <td><small><?= sanitize($log['patient_name'] ?? '-') ?></small></td>
                            <td><small><?= sanitize($log['doctor_name'] ?? '-') ?></small></td>
                            <td><small><?= sanitize($log['prescription_number'] ?? '-') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
