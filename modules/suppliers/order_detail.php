<?php
$pageTitle = 'Purchase Order Details';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$id = intval($_GET['id'] ?? 0);
$po = $db->prepare("SELECT po.*, s.name as supplier_name FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.id WHERE po.id = ?");
$po->execute([$id]);
$po = $po->fetch();
if (!$po) { flashMessage('Order not found', 'error'); header('Location: orders.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_item'])) {
        $qty = intval($_POST['quantity']);
        $cost = floatval($_POST['unit_cost']);
        $total = $qty * $cost;
        $db->prepare("INSERT INTO purchase_order_items (po_id, medicine_id, quantity_ordered, unit_cost, total_cost, batch_number, expiry_date) VALUES (?,?,?,?,?,?,?)")->execute([
            $id, $_POST['medicine_id'], $qty, $cost, $total, $_POST['batch_number'] ?: null, $_POST['expiry_date'] ?: null
        ]);
        $newTotal = $db->prepare("SELECT SUM(total_cost) FROM purchase_order_items WHERE po_id = ?");
        $newTotal->execute([$id]);
        $db->prepare("UPDATE purchase_orders SET total = ?, subtotal = ? WHERE id = ?")->execute([$newTotal->fetchColumn(), $newTotal->fetchColumn(), $id]);
        flashMessage('Item added');
    } elseif (isset($_POST['update_status'])) {
        $db->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?")->execute([$_POST['status'], $id]);
        flashMessage('Status updated');
    }
    header("Location: order_detail.php?id=$id");
    exit;
}

if (isset($_GET['remove_item'])) {
    $db->prepare("DELETE FROM purchase_order_items WHERE id = ? AND po_id = ?")->execute([$_GET['remove_item'], $id]);
    flashMessage('Item removed');
    header("Location: order_detail.php?id=$id");
    exit;
}

$items = $db->prepare("SELECT poi.*, m.name as medicine_name, m.strength FROM purchase_order_items poi JOIN medicines m ON poi.medicine_id = m.id WHERE poi.po_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();

$medicines = $db->query("SELECT id, name, strength, cost_price FROM medicines WHERE is_active = 1 ORDER BY name")->fetchAll();
?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-4">
            <div class="d-flex justify-content-between mb-3">
                <div>
                    <h5><?= sanitize($po['po_number']) ?></h5>
                    <span class="text-muted">Supplier: <?= sanitize($po['supplier_name']) ?></span>
                </div>
                <div>
                    <?php $colors = ['draft'=>'secondary','ordered'=>'primary','partial'=>'warning','received'=>'success','cancelled'=>'danger']; ?>
                    <span class="badge bg-<?= $colors[$po['status']] ?? 'secondary' ?> fs-6"><?= ucfirst($po['status']) ?></span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th>Medicine</th><th>Qty Ordered</th><th>Qty Received</th><th>Unit Cost</th><th class="text-end">Total</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= sanitize($item['medicine_name']) ?><?= $item['strength'] ? " ({$item['strength']})" : '' ?></td>
                            <td><?= $item['quantity_ordered'] ?></td>
                            <td><?= $item['quantity_received'] ?></td>
                            <td><?= formatCurrency($item['unit_cost']) ?></td>
                            <td class="text-end"><?= formatCurrency($item['total_cost']) ?></td>
                            <td>
                                <?php if ($po['status'] === 'draft'): ?>
                                <a href="?id=<?= $id ?>&remove_item=<?= $item['id'] ?>" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($items)): ?><tr><td colspan="6" class="text-center text-muted py-3">No items added yet</td></tr><?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-primary"><td colspan="4" class="text-end fw-bold">Total:</td><td class="text-end fw-bold"><?= formatCurrency($po['total']) ?></td><td></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            <h6>Order Info</h6>
            <table class="table table-sm">
                <tr><td class="text-muted">Date</td><td><?= formatDate($po['order_date'], 'M d, Y') ?></td></tr>
                <tr><td class="text-muted">Expected</td><td><?= $po['expected_delivery'] ? formatDate($po['expected_delivery'], 'M d, Y') : '-' ?></td></tr>
                <tr><td class="text-muted">Received</td><td><?= $po['actual_delivery'] ? formatDate($po['actual_delivery'], 'M d, Y') : '-' ?></td></tr>
            </table>
            <?php if ($po['status'] === 'draft'): ?>
            <form method="POST"><input type="hidden" name="status" value="ordered">
                <button type="submit" name="update_status" value="1" class="btn btn-primary w-100 mb-2">Mark as Ordered</button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (in_array($po['status'], ['draft', 'ordered'])): ?>
        <div class="card p-3">
            <h6>Add Item</h6>
            <form method="POST">
                <div class="mb-2">
                    <select class="form-select" name="medicine_id" required>
                        <option value="">Select medicine</option>
                        <?php foreach ($medicines as $m): ?>
                        <option value="<?= $m['id'] ?>" data-cost="<?= $m['cost_price'] ?>"><?= sanitize($m['name']) ?><?= $m['strength'] ? " ({$m['strength']})" : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2"><input type="number" class="form-control" name="quantity" placeholder="Quantity" min="1" required></div>
                <div class="mb-2"><input type="number" class="form-control" name="unit_cost" placeholder="Unit cost ($)" step="0.01" min="0" required></div>
                <div class="mb-2"><input type="text" class="form-control" name="batch_number" placeholder="Batch #"></div>
                <div class="mb-3"><input type="date" class="form-control" name="expiry_date"></div>
                <button type="submit" name="add_item" value="1" class="btn btn-outline-primary w-100">Add Item</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
