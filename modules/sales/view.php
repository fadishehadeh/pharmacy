<?php
$pageTitle = 'Sale Details';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$id = intval($_GET['id'] ?? 0);
$sale = $db->prepare("SELECT s.*, c.name as customer_name, c.phone as customer_phone FROM sales s LEFT JOIN customers c ON s.customer_id = c.id WHERE s.id = ?");
$sale->execute([$id]);
$sale = $sale->fetch();
if (!$sale) { flashMessage('Sale not found', 'error'); header('Location: index.php'); exit; }

$items = $db->prepare("SELECT si.*, m.name as medicine_name, m.strength, m.form FROM sale_items si JOIN medicines m ON si.medicine_id = m.id WHERE si.sale_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();
?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-4">
            <div class="d-flex justify-content-between mb-3">
                <div>
                    <h5><?= sanitize($sale['invoice_number']) ?></h5>
                    <span class="badge bg-<?= $sale['status'] === 'completed' ? 'success' : 'warning' ?> fs-6"><?= ucfirst($sale['status']) ?></span>
                </div>
                <div class="text-end">
                    <a href="<?= BASE_URL ?>/modules/pos/receipt.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-primary"><i class="bi bi-printer me-1"></i>Print Receipt</a>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Medicine</th><th>Qty</th><th>Unit Price</th><th>Discount</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <strong><?= sanitize($item['medicine_name']) ?></strong>
                                <?php if ($item['strength']): ?><br><small class="text-muted"><?= sanitize($item['strength']) ?> - <?= ucfirst($item['form']) ?></small><?php endif; ?>
                            </td>
                            <td><?= $item['quantity'] ?></td>
                            <td><?= formatCurrency($item['unit_price']) ?></td>
                            <td><?= $item['discount'] > 0 ? formatCurrency($item['discount']) : '-' ?></td>
                            <td class="text-end"><?= formatCurrency($item['total_price']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="4" class="text-end">Subtotal:</td><td class="text-end"><?= formatCurrency($sale['subtotal']) ?></td></tr>
                        <?php if ($sale['discount_amount'] > 0): ?>
                        <tr><td colspan="4" class="text-end">Discount:</td><td class="text-end text-danger">-<?= formatCurrency($sale['discount_amount']) ?></td></tr>
                        <?php endif; ?>
                        <tr class="table-primary"><td colspan="4" class="text-end fw-bold">Total:</td><td class="text-end fw-bold fs-5"><?= formatCurrency($sale['total_amount'], $sale['currency']) ?></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6>Sale Info</h6>
            <table class="table table-sm">
                <tr><td class="text-muted">Date</td><td><?= formatDate($sale['sale_date'], 'M d, Y H:i') ?></td></tr>
                <tr><td class="text-muted">Customer</td><td><?= sanitize($sale['customer_name'] ?? 'Walk-in') ?></td></tr>
                <tr><td class="text-muted">Payment</td><td><?= ucfirst($sale['payment_method']) ?></td></tr>
                <tr><td class="text-muted">Currency</td><td><?= $sale['currency'] ?></td></tr>
                <tr><td class="text-muted">Exchange Rate</td><td><?= number_format($sale['exchange_rate'], 0) ?> LBP</td></tr>
                <?php if ($sale['prescription_number']): ?>
                <tr><td class="text-muted">Rx #</td><td><?= sanitize($sale['prescription_number']) ?></td></tr>
                <?php endif; ?>
                <?php if ($sale['doctor_name']): ?>
                <tr><td class="text-muted">Doctor</td><td><?= sanitize($sale['doctor_name']) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
