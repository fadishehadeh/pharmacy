<?php
$pageTitle = 'Quotations';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_quotation'])) {
        $quoteNum = 'QT-' . date('Ymd') . '-' . str_pad(($db->query("SELECT COALESCE(MAX(id),0)+1 FROM quotations")->fetchColumn()), 4, '0', STR_PAD_LEFT);
        $customerId = intval($_POST['customer_id']) ?: null;
        $validDays = intval($_POST['valid_days']) ?: 7;
        $expiryDate = date('Y-m-d', strtotime("+$validDays days"));
        $notes = $_POST['notes'] ?? '';
        $discount = floatval($_POST['discount'] ?? 0);

        $db->beginTransaction();
        try {
            $subtotal = 0;
            $items = [];
            foreach ($_POST['med_id'] as $i => $medId) {
                $medId = intval($medId);
                $qty = intval($_POST['qty'][$i] ?? 0);
                if (!$medId || $qty <= 0) continue;
                $med = $db->prepare("SELECT sell_price, name FROM medicines WHERE id = ?");
                $med->execute([$medId]);
                $med = $med->fetch();
                if (!$med) continue;
                $price = floatval($_POST['price'][$i] ?? $med['sell_price']);
                $lineTotal = $price * $qty;
                $subtotal += $lineTotal;
                $items[] = [$medId, $qty, $price, $lineTotal];
            }

            $total = $subtotal - $discount;
            $db->prepare("INSERT INTO quotations (quote_number, customer_id, subtotal, discount, total, valid_until, notes, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$quoteNum, $customerId, $subtotal, $discount, $total, $expiryDate, $notes, 'active', $_SESSION['user_id']]);
            $quoteId = $db->lastInsertId();

            foreach ($items as $item) {
                $db->prepare("INSERT INTO quotation_items (quotation_id, medicine_id, quantity, unit_price, total_price) VALUES (?,?,?,?,?)")
                    ->execute([$quoteId, $item[0], $item[1], $item[2], $item[3]]);
            }
            $db->commit();
            flashMessage("Quotation $quoteNum created");
        } catch (Exception $e) {
            $db->rollBack();
            flashMessage('Error creating quotation', 'danger');
        }
        header('Location: quotations.php');
        exit;
    } elseif (isset($_POST['convert_to_sale'])) {
        header('Location: ' . BASE_URL . '/modules/pos/index.php?from_quote=' . intval($_POST['quote_id']));
        exit;
    } elseif (isset($_POST['cancel_quote'])) {
        $db->prepare("UPDATE quotations SET status = 'cancelled' WHERE id = ?")->execute([intval($_POST['quote_id'])]);
        flashMessage('Quotation cancelled');
        header('Location: quotations.php');
        exit;
    }
}

$db->exec("UPDATE quotations SET status = 'expired' WHERE status = 'active' AND valid_until < CURDATE()");

$statusFilter = $_GET['status'] ?? '';
$where = "WHERE 1=1";
$params = [];
if ($statusFilter) { $where .= " AND q.status = ?"; $params[] = $statusFilter; }

$quotations = $db->prepare("SELECT q.*, c.name as customer_name, u.full_name as user_name,
    (SELECT COUNT(*) FROM quotation_items WHERE quotation_id = q.id) as item_count
    FROM quotations q
    LEFT JOIN customers c ON q.customer_id = c.id
    LEFT JOIN users u ON q.created_by = u.id
    $where ORDER BY q.created_at DESC LIMIT 100");
$quotations->execute($params);
$quotations = $quotations->fetchAll();

$medicines = $db->query("SELECT id, name, sell_price, quantity_in_stock FROM medicines WHERE is_active = 1 ORDER BY name")->fetchAll();
$customers = $db->query("SELECT id, name, phone FROM customers ORDER BY name")->fetchAll();

$activeCount = $db->query("SELECT COUNT(*) FROM quotations WHERE status = 'active'")->fetchColumn();
$convertedCount = $db->query("SELECT COUNT(*) FROM quotations WHERE status = 'converted'")->fetchColumn();
$totalValue = $db->query("SELECT COALESCE(SUM(total), 0) FROM quotations WHERE status = 'active'")->fetchColumn();
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card"><div class="stat-label">Active Quotes</div><div class="stat-value"><?= $activeCount ?></div><small class="text-muted"><?= formatCurrency($totalValue) ?> total</small></div></div>
    <div class="col-md-3"><div class="card stat-card success"><div class="stat-label">Converted to Sale</div><div class="stat-value"><?= $convertedCount ?></div></div></div>
    <div class="col-md-3">
        <div class="card p-3">
            <select class="form-select form-select-sm" onchange="location.href='?status='+this.value">
                <option value="">All Statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="converted" <?= $statusFilter === 'converted' ? 'selected' : '' ?>>Converted</option>
                <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </div>
    </div>
    <div class="col-md-3"><div class="card p-3"><button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#newQuote"><i class="bi bi-plus me-1"></i>New Quotation</button></div></div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead><tr><th>Quote #</th><th>Customer</th><th>Items</th><th>Total</th><th>Valid Until</th><th>Status</th><th>Created</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($quotations as $q): ?>
                <tr>
                    <td><strong><?= sanitize($q['quote_number']) ?></strong></td>
                    <td><?= sanitize($q['customer_name'] ?? 'Walk-in') ?></td>
                    <td><?= $q['item_count'] ?></td>
                    <td><strong><?= formatCurrency($q['total']) ?></strong></td>
                    <td><?= formatDate($q['valid_until'], 'M d, Y') ?></td>
                    <td>
                        <?php $colors = ['active' => 'primary', 'converted' => 'success', 'expired' => 'secondary', 'cancelled' => 'danger']; ?>
                        <span class="badge bg-<?= $colors[$q['status']] ?? 'secondary' ?>"><?= ucfirst($q['status']) ?></span>
                    </td>
                    <td><small><?= formatDate($q['created_at'], 'M d, H:i') ?><br><?= sanitize($q['user_name'] ?? '') ?></small></td>
                    <td>
                        <?php if ($q['status'] === 'active'): ?>
                        <form method="POST" class="d-inline"><input type="hidden" name="quote_id" value="<?= $q['id'] ?>">
                            <button type="submit" name="convert_to_sale" value="1" class="btn btn-sm btn-success" title="Convert to sale"><i class="bi bi-cart-check"></i></button>
                            <button type="submit" name="cancel_quote" value="1" class="btn btn-sm btn-outline-danger" data-confirm="Cancel this quotation?"><i class="bi bi-x-lg"></i></button>
                        </form>
                        <?php endif; ?>
                        <a href="quotation_print.php?id=<?= $q['id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Print"><i class="bi bi-printer"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="newQuote"><div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title">New Quotation</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="row g-3 mb-3">
            <div class="col-md-5">
                <label class="form-label">Customer</label>
                <select class="form-select" name="customer_id">
                    <option value="">Walk-in Customer</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?> <?= $c['phone'] ? '(' . sanitize($c['phone']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label">Valid Days</label><input type="number" class="form-control" name="valid_days" value="7" min="1" max="90"></div>
            <div class="col-md-4"><label class="form-label">Discount (USD)</label><input type="number" class="form-control" name="discount" value="0" step="0.01" min="0"></div>
        </div>
        <div id="quoteItems">
            <div class="row g-2 mb-2 quote-item">
                <div class="col-md-5">
                    <select class="form-select form-select-sm med-select" name="med_id[]" required>
                        <option value="">Select medicine...</option>
                        <?php foreach ($medicines as $m): ?>
                        <option value="<?= $m['id'] ?>" data-price="<?= $m['sell_price'] ?>"><?= sanitize($m['name']) ?> (<?= formatCurrency($m['sell_price']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><input type="number" class="form-control form-control-sm" name="qty[]" value="1" min="1" placeholder="Qty"></div>
                <div class="col-md-3"><input type="number" class="form-control form-control-sm" name="price[]" step="0.01" placeholder="Price"></div>
                <div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger remove-item"><i class="bi bi-trash"></i></button></div>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addQuoteItem"><i class="bi bi-plus me-1"></i>Add Item</button>
        <div class="mt-3"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="submit" name="create_quotation" value="1" class="btn btn-primary">Create Quotation</button></div>
    </form>
</div></div></div>

<?php
$extraScripts = <<<'SCRIPT'
<script>
document.getElementById('addQuoteItem').addEventListener('click', function() {
    var container = document.getElementById('quoteItems');
    var first = container.querySelector('.quote-item');
    var clone = first.cloneNode(true);
    clone.querySelectorAll('input').forEach(function(i) { i.value = i.name === 'qty[]' ? '1' : ''; });
    clone.querySelector('select').selectedIndex = 0;
    container.appendChild(clone);
});
document.addEventListener('click', function(e) {
    if (e.target.closest('.remove-item')) {
        var items = document.querySelectorAll('.quote-item');
        if (items.length > 1) e.target.closest('.quote-item').remove();
    }
});
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('med-select')) {
        var opt = e.target.options[e.target.selectedIndex];
        var priceInput = e.target.closest('.quote-item').querySelector('input[name="price[]"]');
        if (opt.dataset.price) priceInput.value = opt.dataset.price;
    }
});
</script>
SCRIPT;
require_once __DIR__ . '/../../includes/footer.php';
?>
