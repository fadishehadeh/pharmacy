<?php
$pageTitle = 'Process Return';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));

// AJAX lookup for sale by invoice
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'lookup_sale') {
        $invoice = $_GET['invoice'] ?? '';
        $sale = $db->prepare("SELECT s.*, c.name as customer_name
            FROM sales s LEFT JOIN customers c ON s.customer_id = c.id
            WHERE s.invoice_number = ? AND s.status = 'completed'");
        $sale->execute([$invoice]);
        $sale = $sale->fetch();

        if (!$sale) { echo json_encode(['found' => false]); exit; }

        $items = $db->prepare("SELECT si.*, m.name as medicine_name, m.id as medicine_id, m.barcode,
            COALESCE((SELECT SUM(sr.quantity) FROM sale_returns sr WHERE sr.sale_item_id = si.id), 0) as already_returned
            FROM sale_items si
            JOIN medicines m ON si.medicine_id = m.id
            WHERE si.sale_id = ?");
        $items->execute([$sale['id']]);
        $items = $items->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'found' => true,
            'sale' => [
                'id' => $sale['id'],
                'invoice_number' => $sale['invoice_number'],
                'sale_date' => $sale['sale_date'],
                'customer_name' => $sale['customer_name'] ?? 'Walk-in',
                'payment_method' => $sale['payment_method'],
                'total_amount' => $sale['total_amount'],
                'currency' => $sale['currency'] ?? 'USD',
            ],
            'items' => $items
        ]);
        exit;
    }
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

// POST: Process return
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_return'])) {
    $saleId = intval($_POST['sale_id']);
    $returnItems = $_POST['return_items'] ?? [];
    $returnReason = $_POST['return_reason'] ?? 'customer_request';
    $refundMethod = $_POST['refund_method'] ?? 'same';
    $returnNotes = $_POST['return_notes'] ?? '';

    $sale = $db->prepare("SELECT * FROM sales WHERE id = ? AND status = 'completed'");
    $sale->execute([$saleId]);
    $sale = $sale->fetch();

    if (!$sale) {
        flashMessage('Sale not found or not completed', 'error');
        header('Location: returns.php');
        exit;
    }

    $totalRefund = 0;
    $returnedCount = 0;

    $db->beginTransaction();
    try {
        foreach ($returnItems as $itemId => $qty) {
            $qty = intval($qty);
            if ($qty <= 0) continue;

            $item = $db->prepare("SELECT si.*, m.name as medicine_name,
                COALESCE((SELECT SUM(sr.quantity) FROM sale_returns sr WHERE sr.sale_item_id = si.id), 0) as already_returned
                FROM sale_items si
                JOIN medicines m ON si.medicine_id = m.id
                WHERE si.id = ? AND si.sale_id = ?");
            $item->execute([intval($itemId), $saleId]);
            $item = $item->fetch();

            if (!$item) continue;
            $maxReturn = $item['quantity'] - $item['already_returned'];
            if ($qty > $maxReturn) $qty = $maxReturn;
            if ($qty <= 0) continue;

            $refundAmount = $item['unit_price'] * $qty;
            $totalRefund += $refundAmount;

            // Create return record
            $db->prepare("INSERT INTO sale_returns (sale_id, sale_item_id, quantity, reason, refund_amount, refund_method, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")->execute([
                $saleId, $item['id'], $qty, $returnReason, $refundAmount,
                $refundMethod, $returnNotes ?: null, $_SESSION['user_id'] ?? null
            ]);

            // Update stock
            updateStock($item['medicine_id'], $qty);
            addStockMovement($item['medicine_id'], 'return', $qty,
                "Return from {$sale['invoice_number']}: " . ucfirst(str_replace('_', ' ', $returnReason)),
                'sale_return', $saleId);

            $returnedCount++;
        }

        // If store credit, create customer credit entry
        if ($refundMethod === 'store_credit' && $sale['customer_id'] && $totalRefund > 0) {
            try {
                $amountUsd = ($sale['currency'] ?? 'USD') === 'LBP' ? ($totalRefund / $exchangeRate) : $totalRefund;
                $db->prepare("INSERT INTO customer_credits (customer_id, amount, amount_usd, currency, type, notes, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())")->execute([
                    $sale['customer_id'], $totalRefund, $amountUsd,
                    $sale['currency'] ?? 'USD', 'credit',
                    "Store credit from return - Invoice: {$sale['invoice_number']}",
                    $_SESSION['user_id'] ?? null
                ]);
            } catch (Exception $e) {
                // customer_credits table may not have all columns
            }
        }

        addAuditLog('create', 'sale_returns', $saleId, null, [
            'invoice' => $sale['invoice_number'],
            'items_returned' => $returnedCount,
            'refund_amount' => $totalRefund,
            'refund_method' => $refundMethod,
            'reason' => $returnReason
        ]);

        $db->commit();

        $refundDisplay = formatCurrency($totalRefund, $sale['currency'] ?? 'USD');
        flashMessage("Return processed: $returnedCount item(s), refund $refundDisplay via " . str_replace('_', ' ', $refundMethod));
    } catch (Exception $e) {
        $db->rollBack();
        flashMessage('Error processing return: ' . $e->getMessage(), 'error');
    }

    header('Location: returns.php?print_return=' . $saleId);
    exit;
}

// Recent returns
$recentReturns = $db->query("SELECT sr.*, si.unit_price, si.quantity as orig_qty,
    m.name as medicine_name, s.invoice_number, s.customer_id,
    c.name as customer_name, u.full_name as processed_by
    FROM sale_returns sr
    JOIN sale_items si ON sr.sale_item_id = si.id
    JOIN medicines m ON si.medicine_id = m.id
    JOIN sales s ON sr.sale_id = s.id
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN users u ON sr.created_by = u.id
    ORDER BY sr.return_date DESC LIMIT 50")->fetchAll();

// Return stats
$todayReturns = $db->query("SELECT COUNT(*) as cnt, COALESCE(SUM(refund_amount), 0) as total
    FROM sale_returns WHERE DATE(return_date) = CURDATE()")->fetch();
$monthReturns = $db->query("SELECT COUNT(*) as cnt, COALESCE(SUM(refund_amount), 0) as total
    FROM sale_returns WHERE MONTH(return_date) = MONTH(CURDATE()) AND YEAR(return_date) = YEAR(CURDATE())")->fetch();

// Print return receipt
$printReturn = intval($_GET['print_return'] ?? 0);
?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card stat-card warning">
            <div class="stat-label">Today's Returns</div>
            <div class="stat-value"><?= formatCurrency($todayReturns['total']) ?></div>
            <small class="text-muted"><?= $todayReturns['cnt'] ?> return(s)</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="stat-label">This Month</div>
            <div class="stat-value"><?= formatCurrency($monthReturns['total']) ?></div>
            <small class="text-muted"><?= $monthReturns['cnt'] ?> return(s)</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card info">
            <div class="stat-label">Month LBP Equivalent</div>
            <div class="stat-value small"><?= formatCurrency($monthReturns['total'] * $exchangeRate, 'LBP') ?></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Return Form -->
    <div class="col-lg-5">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-search me-2"></i>Lookup Sale</h6>
            <div class="input-group mb-3">
                <input type="text" class="form-control" id="invoiceSearch" placeholder="Enter invoice number (e.g. INV-20260829-0001)" autofocus>
                <button class="btn btn-primary" id="searchBtn"><i class="bi bi-search"></i></button>
            </div>
            <div id="saleNotFound" class="alert alert-warning d-none small"><i class="bi bi-exclamation-triangle me-1"></i>Invoice not found or sale not completed.</div>
        </div>

        <!-- Sale details (populated by JS) -->
        <div id="saleDetails" class="d-none">
            <div class="card p-3 mb-3">
                <h6><i class="bi bi-receipt me-2"></i>Sale Details</h6>
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Invoice</td><td id="detailInvoice"></td></tr>
                    <tr><td class="text-muted">Date</td><td id="detailDate"></td></tr>
                    <tr><td class="text-muted">Customer</td><td id="detailCustomer"></td></tr>
                    <tr><td class="text-muted">Payment</td><td id="detailPayment"></td></tr>
                    <tr><td class="text-muted">Total</td><td id="detailTotal" class="fw-bold"></td></tr>
                </table>
            </div>

            <form method="POST" id="returnForm">
                <input type="hidden" name="sale_id" id="saleIdField">

                <div class="card p-3 mb-3">
                    <h6><i class="bi bi-list-check me-2"></i>Select Items to Return</h6>
                    <div id="itemsList"></div>
                </div>

                <div class="card p-3 mb-3">
                    <div class="mb-2">
                        <label class="form-label small">Return Reason</label>
                        <select class="form-select form-select-sm" name="return_reason">
                            <option value="customer_request">Customer Request</option>
                            <option value="defective">Defective Product</option>
                            <option value="wrong_item">Wrong Item Dispensed</option>
                            <option value="expired">Expired Product</option>
                            <option value="adverse_reaction">Adverse Reaction</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Refund Method</label>
                        <select class="form-select form-select-sm" name="refund_method" id="refundMethod">
                            <option value="same">Same as Payment</option>
                            <option value="cash">Cash</option>
                            <option value="store_credit">Store Credit</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Notes</label>
                        <textarea class="form-control form-control-sm" name="return_notes" rows="2" placeholder="Additional notes..."></textarea>
                    </div>

                    <div class="border rounded p-2 mb-3 bg-light">
                        <div class="d-flex justify-content-between">
                            <span>Refund Amount:</span>
                            <strong id="refundAmount" class="text-danger">$0.00</strong>
                        </div>
                    </div>

                    <button type="submit" name="process_return" value="1" class="btn btn-warning w-100 no-print" id="processBtn" disabled>
                        <i class="bi bi-arrow-return-left me-1"></i>Process Return
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Recent Returns -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Returns</h6>
                <button onclick="window.print()" class="btn btn-sm btn-outline-dark no-print"><i class="bi bi-printer me-1"></i>Print</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Invoice</th>
                            <th>Medicine</th>
                            <th>Qty</th>
                            <th class="text-end">Refund</th>
                            <th>Reason</th>
                            <th>Method</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentReturns as $r): ?>
                        <?php
                            $reasonLabels = [
                                'customer_request' => 'Customer Request',
                                'defective' => 'Defective',
                                'wrong_item' => 'Wrong Item',
                                'expired' => 'Expired',
                                'adverse_reaction' => 'Adverse Reaction'
                            ];
                            $reasonColors = [
                                'customer_request' => 'secondary',
                                'defective' => 'danger',
                                'wrong_item' => 'warning',
                                'expired' => 'dark',
                                'adverse_reaction' => 'info'
                            ];
                        ?>
                        <tr>
                            <td><small><?= formatDate($r['return_date'], 'M d, Y H:i') ?></small></td>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $r['sale_id'] ?>">
                                    <?= sanitize($r['invoice_number']) ?>
                                </a>
                            </td>
                            <td><?= sanitize($r['medicine_name']) ?></td>
                            <td><?= $r['quantity'] ?></td>
                            <td class="text-end fw-semibold text-danger"><?= formatCurrency($r['refund_amount']) ?></td>
                            <td>
                                <span class="badge bg-<?= $reasonColors[$r['reason']] ?? 'secondary' ?> bg-opacity-75">
                                    <?= sanitize($reasonLabels[$r['reason']] ?? ucfirst(str_replace('_', ' ', $r['reason'] ?? ''))) ?>
                                </span>
                            </td>
                            <td><small><?= sanitize(ucfirst(str_replace('_', ' ', $r['refund_method'] ?? 'N/A'))) ?></small></td>
                            <td><small><?= sanitize($r['processed_by'] ?? '-') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentReturns)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">No returns processed yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<'SCRIPT'
<script>
function escapeHtml(text) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

function calculateRefund() {
    var total = 0;
    $('#itemsList .return-qty').each(function() {
        var qty = parseInt($(this).val()) || 0;
        var price = parseFloat($(this).data('price')) || 0;
        total += qty * price;
    });
    $('#refundAmount').text('$' + total.toFixed(2));
    $('#processBtn').prop('disabled', total <= 0);
}

// Search sale
function lookupSale() {
    var invoice = $('#invoiceSearch').val().trim();
    if (!invoice) return;

    $('#saleNotFound').addClass('d-none');
    $('#saleDetails').addClass('d-none');

    $.getJSON(window.location.pathname, { ajax: 'lookup_sale', invoice: invoice }, function(data) {
        if (!data.found) {
            $('#saleNotFound').removeClass('d-none');
            return;
        }

        var sale = data.sale;
        $('#saleIdField').val(sale.id);
        $('#detailInvoice').text(sale.invoice_number);
        $('#detailDate').text(sale.sale_date);
        $('#detailCustomer').text(sale.customer_name);
        $('#detailPayment').text(sale.payment_method.charAt(0).toUpperCase() + sale.payment_method.slice(1));
        $('#detailTotal').text('$' + parseFloat(sale.total_amount).toFixed(2));

        var html = '<table class="table table-sm mb-0"><thead><tr><th></th><th>Medicine</th><th>Sold</th><th>Returned</th><th>Return Qty</th></tr></thead><tbody>';
        data.items.forEach(function(item) {
            var maxReturn = item.quantity - item.already_returned;
            var disabled = maxReturn <= 0 ? 'disabled' : '';
            var rowClass = maxReturn <= 0 ? 'text-muted' : '';
            html += '<tr class="' + rowClass + '">';
            html += '<td><input type="checkbox" class="form-check-input item-check" data-item="' + item.id + '" ' + disabled + '></td>';
            html += '<td>' + escapeHtml(item.medicine_name) + '<br><small class="text-muted">$' + parseFloat(item.unit_price).toFixed(2) + '/unit</small></td>';
            html += '<td>' + item.quantity + '</td>';
            html += '<td>' + (item.already_returned > 0 ? '<span class="text-warning">' + item.already_returned + '</span>' : '0') + '</td>';
            html += '<td><input type="number" class="form-control form-control-sm return-qty" name="return_items[' + item.id + ']" min="0" max="' + maxReturn + '" value="0" data-price="' + item.unit_price + '" ' + disabled + ' style="width:80px"></td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        $('#itemsList').html(html);

        // Bind events
        $('.item-check').on('change', function() {
            var itemId = $(this).data('item');
            var qtyInput = $('input[name="return_items[' + itemId + ']"]');
            if (this.checked) {
                qtyInput.val(1).trigger('change');
            } else {
                qtyInput.val(0).trigger('change');
            }
        });
        $('.return-qty').on('change input', function() {
            var max = parseInt($(this).attr('max'));
            var val = parseInt($(this).val()) || 0;
            if (val > max) $(this).val(max);
            if (val < 0) $(this).val(0);
            calculateRefund();
        });

        $('#saleDetails').removeClass('d-none');
    }).fail(function() {
        $('#saleNotFound').removeClass('d-none');
    });
}

$('#searchBtn').on('click', lookupSale);
$('#invoiceSearch').on('keypress', function(e) {
    if (e.which === 13) { e.preventDefault(); lookupSale(); }
});

// Confirm before processing
$('#returnForm').on('submit', function(e) {
    var refund = $('#refundAmount').text();
    if (!confirm('Process this return for ' + refund + '?')) {
        e.preventDefault();
    }
});
</script>
SCRIPT;

require_once __DIR__ . '/../../includes/footer.php';
?>
