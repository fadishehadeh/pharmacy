<?php
$pageTitle = 'Returns';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_return'])) {
    $saleItemId = intval($_POST['sale_item_id']);
    $quantity = intval($_POST['quantity']);
    $reason = $_POST['reason'] ?? '';

    $item = $db->prepare("SELECT si.*, s.id as sale_id, s.invoice_number FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.id = ?");
    $item->execute([$saleItemId]);
    $item = $item->fetch();

    if ($item && $quantity > 0 && $quantity <= $item['quantity']) {
        $refund = $item['unit_price'] * $quantity;
        $db->prepare("INSERT INTO sale_returns (sale_id, sale_item_id, quantity, reason, refund_amount, created_by) VALUES (?,?,?,?,?,?)")->execute([
            $item['sale_id'], $saleItemId, $quantity, $reason, $refund, $_SESSION['user_id'] ?? null
        ]);
        updateStock($item['medicine_id'], $quantity);
        addStockMovement($item['medicine_id'], 'return', $quantity, "Return from {$item['invoice_number']}: $reason", 'sale_return', $item['sale_id']);
        flashMessage("Return processed. Refund: " . formatCurrency($refund));
    } else {
        flashMessage('Invalid return', 'error');
    }
    header('Location: returns.php');
    exit;
}

$returns = $db->query("SELECT sr.*, si.unit_price, m.name as medicine_name, s.invoice_number FROM sale_returns sr JOIN sale_items si ON sr.sale_item_id = si.id JOIN medicines m ON si.medicine_id = m.id JOIN sales s ON sr.sale_id = s.id ORDER BY sr.return_date DESC LIMIT 50")->fetchAll();
?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card p-3">
            <h6><i class="bi bi-arrow-return-left me-2"></i>Process Return</h6>
            <form method="POST">
                <div class="mb-2">
                    <label class="form-label">Invoice Number</label>
                    <input type="text" class="form-control" id="returnInvoice" placeholder="Enter invoice number">
                    <div id="invoiceItems" class="mt-2"></div>
                </div>
                <input type="hidden" name="sale_item_id" id="returnItemId">
                <div class="mb-2">
                    <label class="form-label">Return Quantity</label>
                    <input type="number" class="form-control" name="quantity" min="1" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Reason</label>
                    <textarea class="form-control" name="reason" rows="2" required></textarea>
                </div>
                <button type="submit" name="process_return" value="1" class="btn btn-warning w-100">Process Return</button>
            </form>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Date</th><th>Invoice</th><th>Medicine</th><th>Qty</th><th>Refund</th><th>Reason</th></tr></thead>
                    <tbody>
                        <?php foreach ($returns as $r): ?>
                        <tr>
                            <td><?= formatDate($r['return_date'], 'M d, Y') ?></td>
                            <td><?= sanitize($r['invoice_number']) ?></td>
                            <td><?= sanitize($r['medicine_name']) ?></td>
                            <td><?= $r['quantity'] ?></td>
                            <td><?= formatCurrency($r['refund_amount']) ?></td>
                            <td><small><?= sanitize($r['reason'] ?? '') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<'SCRIPT'
<script>
$('#returnInvoice').on('change', function() {
    var inv = $(this).val();
    if (!inv) return;
    $.getJSON(window.location.href, {ajax: 'lookup', invoice: inv}, function(items) {
        var html = '';
        items.forEach(function(i) {
            html += '<div class="form-check"><input class="form-check-input" type="radio" name="item_select" value="' + i.id + '" onchange="$(\'#returnItemId\').val(this.value)">';
            html += '<label class="form-check-label">' + i.name + ' (Qty: ' + i.quantity + ', $' + parseFloat(i.unit_price).toFixed(2) + ')</label></div>';
        });
        if (!html) html = '<small class="text-muted">Invoice not found</small>';
        $('#invoiceItems').html(html);
    });
});
</script>
SCRIPT;

if (isset($_GET['ajax']) && $_GET['ajax'] === 'lookup') {
    header('Content-Type: application/json');
    $inv = $_GET['invoice'] ?? '';
    $stmt = $db->prepare("SELECT si.id, si.quantity, si.unit_price, m.name FROM sale_items si JOIN medicines m ON si.medicine_id = m.id JOIN sales s ON si.sale_id = s.id WHERE s.invoice_number = ?");
    $stmt->execute([$inv]);
    echo json_encode($stmt->fetchAll());
    exit;
}

require_once __DIR__ . '/../../includes/footer.php';
?>
