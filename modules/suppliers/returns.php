<?php
$pageTitle = 'Supplier Returns';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { header('Location: ' . BASE_URL); exit; }
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_return'])) {
    $supplierId = intval($_POST['supplier_id']);
    $medicineId = intval($_POST['medicine_id']);
    $quantity = intval($_POST['quantity']);
    $reason = $_POST['reason'] ?? '';
    $batchNumber = $_POST['batch_number'] ?? '';
    $notes = $_POST['notes'] ?? '';

    $med = $db->prepare("SELECT * FROM medicines WHERE id = ?");
    $med->execute([$medicineId]);
    $med = $med->fetch();

    if ($med && $quantity > 0 && $quantity <= $med['quantity_in_stock']) {
        $returnValue = $quantity * $med['cost_price'];

        $db->prepare("INSERT INTO supplier_returns (supplier_id, medicine_id, quantity, reason, batch_number, unit_cost, total_value, notes, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([
                $supplierId, $medicineId, $quantity, $reason,
                $batchNumber ?: $med['batch_number'],
                $med['cost_price'], $returnValue,
                $notes ?: null, 'pending',
                $_SESSION['user_id'] ?? null
            ]);
        $returnId = $db->lastInsertId();

        updateStock($medicineId, -$quantity);
        addStockMovement($medicineId, 'supplier_return', $quantity, "Return to supplier: $reason" . ($batchNumber ? " (Batch: $batchNumber)" : ''), 'supplier_return', $returnId);
        addAuditLog('create', 'supplier_returns', $returnId, null, ['medicine_id' => $medicineId, 'quantity' => $quantity, 'reason' => $reason]);

        flashMessage("Supplier return recorded. $quantity units removed from stock. Value: " . formatCurrency($returnValue));
    } else {
        flashMessage('Invalid quantity or medicine not found', 'error');
    }
    header('Location: returns.php');
    exit;
}

if (isset($_GET['update_status']) && isset($_GET['id'])) {
    $newStatus = $_GET['update_status'];
    $returnId = intval($_GET['id']);
    $validStatuses = ['approved', 'refunded', 'rejected'];
    if (in_array($newStatus, $validStatuses)) {
        $oldReturn = $db->prepare("SELECT * FROM supplier_returns WHERE id = ?");
        $oldReturn->execute([$returnId]);
        $oldReturn = $oldReturn->fetch();
        if ($oldReturn) {
            $db->prepare("UPDATE supplier_returns SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$newStatus, $returnId]);

            if ($newStatus === 'rejected' && $oldReturn['status'] === 'pending') {
                updateStock($oldReturn['medicine_id'], $oldReturn['quantity']);
                addStockMovement($oldReturn['medicine_id'], 'adjustment', $oldReturn['quantity'], "Supplier return rejected - stock restored", 'supplier_return', $returnId);
            }

            addAuditLog('update', 'supplier_returns', $returnId, ['status' => $oldReturn['status']], ['status' => $newStatus]);
            flashMessage("Return status updated to " . ucfirst($newStatus));
        }
    }
    header('Location: returns.php');
    exit;
}

// Stats
$totalReturns = $db->query("SELECT COUNT(*) FROM supplier_returns")->fetchColumn();
$pendingReturns = $db->query("SELECT COUNT(*) FROM supplier_returns WHERE status = 'pending'")->fetchColumn();
$totalReturnValue = $db->query("SELECT COALESCE(SUM(total_value), 0) FROM supplier_returns")->fetchColumn();
$refundedAmount = $db->query("SELECT COALESCE(SUM(total_value), 0) FROM supplier_returns WHERE status = 'refunded'")->fetchColumn();

// Returns list
$returns = $db->query("SELECT sr.*, s.name as supplier_name, m.name as medicine_name, u.full_name as created_by_name
    FROM supplier_returns sr
    JOIN suppliers s ON sr.supplier_id = s.id
    JOIN medicines m ON sr.medicine_id = m.id
    LEFT JOIN users u ON sr.created_by = u.id
    ORDER BY sr.created_at DESC LIMIT 200")->fetchAll();

// Dropdowns
$suppliers = $db->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();
$medicines = $db->query("SELECT id, name, quantity_in_stock, batch_number, cost_price FROM medicines WHERE is_active = 1 AND quantity_in_stock > 0 ORDER BY name")->fetchAll();
?>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Total Returns</div>
            <div class="stat-value"><?= number_format($totalReturns) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="stat-label">Pending Returns</div>
            <div class="stat-value"><?= number_format($pendingReturns) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card danger">
            <div class="stat-label">Total Return Value</div>
            <div class="stat-value"><?= formatCurrency($totalReturnValue) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card success">
            <div class="stat-label">Refunded Amount</div>
            <div class="stat-value"><?= formatCurrency($refundedAmount) ?></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-arrow-return-right me-2"></i>Supplier Returns</h6>
        <button class="btn btn-primary no-print" data-bs-toggle="modal" data-bs-target="#newReturn"><i class="bi bi-plus me-1"></i>New Return</button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Supplier</th>
                    <th>Medicine</th>
                    <th>Batch</th>
                    <th>Qty</th>
                    <th>Value</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>By</th>
                    <th class="no-print"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($returns as $r): ?>
                <tr>
                    <td><small><?= formatDate($r['created_at'], 'M d, Y') ?></small></td>
                    <td><strong class="small"><?= sanitize($r['supplier_name']) ?></strong></td>
                    <td><?= sanitize($r['medicine_name']) ?></td>
                    <td><small><?= sanitize($r['batch_number'] ?? '-') ?></small></td>
                    <td><?= $r['quantity'] ?></td>
                    <td><?= formatCurrency($r['total_value']) ?></td>
                    <td>
                        <?php
                        $reasonColors = ['expired' => 'danger', 'damaged' => 'warning', 'recalled' => 'dark', 'wrong_item' => 'info', 'quality_issue' => 'secondary'];
                        $color = $reasonColors[$r['reason']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $color ?>"><?= ucfirst(str_replace('_', ' ', $r['reason'])) ?></span>
                    </td>
                    <td>
                        <?php
                        $statusColors = ['pending' => 'warning', 'approved' => 'primary', 'refunded' => 'success', 'rejected' => 'danger'];
                        $sColor = $statusColors[$r['status']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $sColor ?>"><?= ucfirst($r['status']) ?></span>
                    </td>
                    <td><small><?= sanitize($r['created_by_name'] ?? '-') ?></small></td>
                    <td class="no-print">
                        <?php if ($r['status'] === 'pending'): ?>
                        <div class="btn-group btn-group-sm">
                            <a href="?id=<?= $r['id'] ?>&update_status=approved" class="btn btn-outline-primary" title="Approve"><i class="bi bi-check"></i></a>
                            <a href="?id=<?= $r['id'] ?>&update_status=refunded" class="btn btn-outline-success" title="Mark Refunded"><i class="bi bi-cash"></i></a>
                            <a href="?id=<?= $r['id'] ?>&update_status=rejected" class="btn btn-outline-danger" title="Reject" data-confirm="Reject this return? Stock will be restored."><i class="bi bi-x"></i></a>
                        </div>
                        <?php elseif ($r['status'] === 'approved'): ?>
                        <a href="?id=<?= $r['id'] ?>&update_status=refunded" class="btn btn-sm btn-outline-success" title="Mark Refunded"><i class="bi bi-cash me-1"></i>Refund</a>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($returns)): ?><tr><td colspan="10" class="text-center text-muted py-3">No supplier returns recorded</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- New Return Modal -->
<div class="modal fade" id="newReturn"><div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title"><i class="bi bi-arrow-return-right me-2"></i>New Supplier Return</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Supplier</label>
                <select class="form-select" name="supplier_id" required>
                    <option value="">Select supplier...</option>
                    <?php foreach ($suppliers as $sup): ?>
                    <option value="<?= $sup['id'] ?>"><?= sanitize($sup['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Medicine</label>
                <select class="form-select" name="medicine_id" id="returnMedicine" required>
                    <option value="">Select medicine...</option>
                    <?php foreach ($medicines as $m): ?>
                    <option value="<?= $m['id'] ?>" data-stock="<?= $m['quantity_in_stock'] ?>" data-batch="<?= sanitize($m['batch_number'] ?? '') ?>" data-cost="<?= $m['cost_price'] ?>"><?= sanitize($m['name']) ?> (Stock: <?= $m['quantity_in_stock'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Quantity</label>
                <input type="number" class="form-control" name="quantity" id="returnQty" min="1" required>
                <small class="text-muted" id="maxStockHint"></small>
            </div>
            <div class="col-md-4">
                <label class="form-label">Reason</label>
                <select class="form-select" name="reason" required>
                    <option value="expired">Expired</option>
                    <option value="damaged">Damaged</option>
                    <option value="recalled">Recalled</option>
                    <option value="wrong_item">Wrong Item</option>
                    <option value="quality_issue">Quality Issue</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Batch Number</label>
                <input type="text" class="form-control" name="batch_number" id="returnBatch" placeholder="Auto from medicine">
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="2" placeholder="Additional details..."></textarea>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="create_return" value="1" class="btn btn-primary" onclick="return confirm('Create this return? Stock will be decreased.')"><i class="bi bi-arrow-return-right me-1"></i>Create Return</button>
    </div>
    </form>
</div></div></div>

<?php
$extraScripts = <<<'SCRIPT'
<script>
$('#returnMedicine').on('change', function() {
    var opt = $(this).find(':selected');
    var stock = opt.data('stock') || 0;
    var batch = opt.data('batch') || '';
    $('#returnQty').attr('max', stock);
    $('#maxStockHint').text(stock > 0 ? 'Max: ' + stock + ' units in stock' : '');
    if (batch) $('#returnBatch').val(batch);
});
</script>
SCRIPT;

require_once __DIR__ . '/../../includes/footer.php';
?>
