<?php
$pageTitle = 'Purchase Orders';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_po'])) {
    $poNumber = generatePONumber();
    $stmt = $db->prepare("INSERT INTO purchase_orders (po_number, supplier_id, order_date, expected_delivery, notes, created_by) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$poNumber, $_POST['supplier_id'], $_POST['order_date'], $_POST['expected_delivery'] ?: null, $_POST['notes'] ?: null, $_SESSION['user_id'] ?? null]);
    $poId = $db->lastInsertId();
    flashMessage("Purchase order $poNumber created");
    header("Location: order_detail.php?id=$poId");
    exit;
}

if (isset($_GET['receive']) && $_GET['receive']) {
    $poId = intval($_GET['receive']);
    $poItems = $db->prepare("SELECT * FROM purchase_order_items WHERE po_id = ?");
    $poItems->execute([$poId]);
    foreach ($poItems->fetchAll() as $item) {
        $toReceive = $item['quantity_ordered'] - $item['quantity_received'];
        if ($toReceive > 0) {
            updateStock($item['medicine_id'], $toReceive);
            addStockMovement($item['medicine_id'], 'in', $toReceive, "PO received", 'purchase_order', $poId);
            $db->prepare("UPDATE purchase_order_items SET quantity_received = quantity_ordered WHERE id = ?")->execute([$item['id']]);
        }
    }
    $db->prepare("UPDATE purchase_orders SET status = 'received', actual_delivery = CURDATE() WHERE id = ?")->execute([$poId]);
    flashMessage('All items received and stock updated');
    header('Location: orders.php');
    exit;
}

$status = $_GET['status'] ?? '';
$where = ['1=1'];
$params = [];
if ($status) { $where[] = 'po.status = ?'; $params[] = $status; }

$orders = $db->prepare("SELECT po.*, s.name as supplier_name FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.id WHERE " . implode(' AND ', $where) . " ORDER BY po.created_at DESC");
$orders->execute($params);
$orders = $orders->fetchAll();
$suppliers = $db->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();
?>

<div class="d-flex justify-content-between mb-3">
    <div class="btn-group">
        <a href="?status=" class="btn btn-sm btn-<?= !$status ? 'primary' : 'outline-primary' ?>">All</a>
        <a href="?status=draft" class="btn btn-sm btn-<?= $status === 'draft' ? 'primary' : 'outline-primary' ?>">Draft</a>
        <a href="?status=ordered" class="btn btn-sm btn-<?= $status === 'ordered' ? 'primary' : 'outline-primary' ?>">Ordered</a>
        <a href="?status=received" class="btn btn-sm btn-<?= $status === 'received' ? 'primary' : 'outline-primary' ?>">Received</a>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newPO"><i class="bi bi-plus me-1"></i>New Purchase Order</button>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>PO #</th><th>Supplier</th><th>Date</th><th>Items</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($orders as $po): ?>
                <?php $itemCount = $db->prepare("SELECT COUNT(*) FROM purchase_order_items WHERE po_id = ?"); $itemCount->execute([$po['id']]); ?>
                <tr>
                    <td><a href="order_detail.php?id=<?= $po['id'] ?>"><?= sanitize($po['po_number']) ?></a></td>
                    <td><?= sanitize($po['supplier_name']) ?></td>
                    <td><?= formatDate($po['order_date'], 'M d, Y') ?></td>
                    <td><?= $itemCount->fetchColumn() ?></td>
                    <td class="text-end"><?= formatCurrency($po['total']) ?></td>
                    <td>
                        <?php $colors = ['draft'=>'secondary','ordered'=>'primary','partial'=>'warning','received'=>'success','cancelled'=>'danger']; ?>
                        <span class="badge bg-<?= $colors[$po['status']] ?? 'secondary' ?>"><?= ucfirst($po['status']) ?></span>
                    </td>
                    <td>
                        <?php if ($po['status'] === 'ordered'): ?>
                        <a href="?receive=<?= $po['id'] ?>" class="btn btn-sm btn-success" data-confirm="Receive all items?"><i class="bi bi-check-lg"></i> Receive</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="newPO"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title">New Purchase Order</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-2"><label class="form-label">Supplier</label><select class="form-select" name="supplier_id" required><option value="">Select</option>
            <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= sanitize($s['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="mb-2"><label class="form-label">Order Date</label><input type="date" class="form-control" name="order_date" value="<?= date('Y-m-d') ?>" required></div>
        <div class="mb-2"><label class="form-label">Expected Delivery</label><input type="date" class="form-control" name="expected_delivery"></div>
        <div><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="submit" name="create_po" value="1" class="btn btn-primary">Create</button></div>
    </form>
</div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
