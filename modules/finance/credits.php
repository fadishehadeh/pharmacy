<?php
$pageTitle = 'Customer Credits';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { header('Location: ' . BASE_URL); exit; }
$db = getDB();

$exchangeRate = getSetting('exchange_rate', '89500');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_credit'])) {
        $customerId = intval($_POST['customer_id']);
        $amount = floatval($_POST['amount']);
        $type = $_POST['type'];
        $notes = $_POST['notes'] ?? '';
        $currency = $_POST['currency'] ?? 'USD';

        if ($customerId > 0 && $amount > 0 && in_array($type, ['credit', 'payment'])) {
            // Convert to USD if LBP
            $amountUsd = $currency === 'LBP' ? ($amount / $exchangeRate) : $amount;

            $db->prepare("INSERT INTO customer_credits (customer_id, amount, amount_usd, currency, type, notes, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())")
                ->execute([$customerId, $amount, $amountUsd, $currency, $type, $notes ?: null, $_SESSION['user_id'] ?? null]);

            $creditId = $db->lastInsertId();
            addAuditLog('create', 'customer_credits', $creditId, null, ['customer_id' => $customerId, 'amount' => $amount, 'type' => $type]);

            $typeLabel = $type === 'credit' ? 'Credit' : 'Payment';
            flashMessage("$typeLabel of " . formatCurrency($amount, $currency) . " recorded successfully");
        } else {
            flashMessage('Invalid data provided', 'error');
        }
        header('Location: credits.php');
        exit;
    }
}

// Summary stats
$totalOutstanding = $db->query("SELECT COALESCE(SUM(CASE WHEN type = 'credit' THEN amount_usd ELSE -amount_usd END), 0) FROM customer_credits")->fetchColumn();
$customersWithCredit = $db->query("SELECT COUNT(DISTINCT customer_id) FROM customer_credits GROUP BY customer_id HAVING SUM(CASE WHEN type = 'credit' THEN amount_usd ELSE -amount_usd END) > 0")->fetchAll();
$customersWithCreditCount = count($customersWithCredit);

$overdueAmount = $db->query("SELECT COALESCE(SUM(cc.amount_usd), 0) FROM customer_credits cc WHERE cc.type = 'credit' AND cc.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND cc.customer_id IN (SELECT customer_id FROM customer_credits GROUP BY customer_id HAVING SUM(CASE WHEN type = 'credit' THEN amount_usd ELSE -amount_usd END) > 0)")->fetchColumn();

$monthCollections = $db->query("SELECT COALESCE(SUM(amount_usd), 0) FROM customer_credits WHERE type = 'payment' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetchColumn();

// Customer balances
$customers = $db->query("SELECT
    p.id, p.first_name, p.last_name, p.phone,
    COALESCE(SUM(CASE WHEN cc.type = 'credit' THEN cc.amount_usd ELSE 0 END), 0) as total_credit,
    COALESCE(SUM(CASE WHEN cc.type = 'payment' THEN cc.amount_usd ELSE 0 END), 0) as total_payments,
    COALESCE(SUM(CASE WHEN cc.type = 'credit' THEN cc.amount_usd ELSE -cc.amount_usd END), 0) as balance,
    MAX(cc.created_at) as last_transaction,
    MIN(CASE WHEN cc.type = 'credit' THEN cc.created_at END) as oldest_credit
    FROM patients p
    LEFT JOIN customer_credits cc ON p.id = cc.customer_id
    GROUP BY p.id
    HAVING balance > 0 OR total_credit > 0
    ORDER BY balance DESC")->fetchAll();

$allPatients = $db->query("SELECT id, first_name, last_name, phone FROM patients ORDER BY first_name, last_name")->fetchAll();

// Transaction detail if requested
$detailCustomerId = intval($_GET['customer'] ?? 0);
$transactions = [];
$detailCustomer = null;
if ($detailCustomerId > 0) {
    $detailCustomer = $db->prepare("SELECT * FROM patients WHERE id = ?");
    $detailCustomer->execute([$detailCustomerId]);
    $detailCustomer = $detailCustomer->fetch();

    $transactions = $db->prepare("SELECT cc.*, u.full_name as created_by_name
        FROM customer_credits cc
        LEFT JOIN users u ON cc.created_by = u.id
        WHERE cc.customer_id = ?
        ORDER BY cc.created_at DESC");
    $transactions->execute([$detailCustomerId]);
    $transactions = $transactions->fetchAll();
}
?>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card danger">
            <div class="stat-label">Total Outstanding</div>
            <div class="stat-value"><?= formatCurrency($totalOutstanding) ?></div>
            <small class="text-muted"><?= formatCurrency($totalOutstanding * $exchangeRate, 'LBP') ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="stat-label">Customers with Credit</div>
            <div class="stat-value"><?= $customersWithCreditCount ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Overdue (>30 days)</div>
            <div class="stat-value"><?= formatCurrency($overdueAmount) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card success">
            <div class="stat-label">This Month Collections</div>
            <div class="stat-value"><?= formatCurrency($monthCollections) ?></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-<?= $detailCustomerId ? '6' : '8' ?>">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-people me-2"></i>Customer Balances</h6>
                <button class="btn btn-primary btn-sm no-print" data-bs-toggle="modal" data-bs-target="#addTransaction"><i class="bi bi-plus me-1"></i>Add Credit / Payment</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 data-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th class="text-end">Total Credit</th>
                            <th class="text-end">Payments</th>
                            <th class="text-end">Balance</th>
                            <th>Last Transaction</th>
                            <th class="no-print"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $c): ?>
                        <?php if ($c['balance'] <= 0 && $c['total_credit'] == 0) continue; ?>
                        <tr class="<?= $c['balance'] > 100 ? 'table-warning' : '' ?>">
                            <td><strong class="small"><?= sanitize($c['first_name'] . ' ' . $c['last_name']) ?></strong></td>
                            <td><small><?= sanitize($c['phone'] ?? '-') ?></small></td>
                            <td class="text-end text-danger"><?= formatCurrency($c['total_credit']) ?></td>
                            <td class="text-end text-success"><?= formatCurrency($c['total_payments']) ?></td>
                            <td class="text-end fw-bold <?= $c['balance'] > 0 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency($c['balance']) ?></td>
                            <td><small><?= $c['last_transaction'] ? formatDate($c['last_transaction'], 'M d, Y') : '-' ?></small></td>
                            <td class="no-print">
                                <div class="btn-group btn-group-sm">
                                    <a href="?customer=<?= $c['id'] ?>" class="btn btn-outline-primary" title="View History"><i class="bi bi-clock-history"></i></a>
                                    <button class="btn btn-outline-success btn-record-payment" data-id="<?= $c['id'] ?>" data-name="<?= sanitize($c['first_name'] . ' ' . $c['last_name']) ?>" data-balance="<?= $c['balance'] ?>" title="Record Payment"><i class="bi bi-cash"></i></button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($detailCustomerId && $detailCustomer): ?>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>History: <?= sanitize($detailCustomer['first_name'] . ' ' . $detailCustomer['last_name']) ?></h6>
                <a href="credits.php" class="btn btn-sm btn-outline-secondary no-print"><i class="bi bi-x"></i></a>
            </div>
            <div class="table-responsive" style="max-height:500px;overflow-y:auto">
                <table class="table table-sm table-hover mb-0">
                    <thead class="sticky-top bg-white">
                        <tr><th>Date</th><th>Type</th><th class="text-end">Amount</th><th>Notes</th><th>By</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $runningBalance = 0;
                        $reversedTx = array_reverse($transactions);
                        foreach ($reversedTx as $tx) {
                            if ($tx['type'] === 'credit') $runningBalance += $tx['amount_usd'];
                            else $runningBalance -= $tx['amount_usd'];
                        }
                        ?>
                        <?php foreach ($transactions as $tx): ?>
                        <tr>
                            <td><small><?= formatDate($tx['created_at'], 'M d, Y H:i') ?></small></td>
                            <td>
                                <?php if ($tx['type'] === 'credit'): ?>
                                <span class="badge bg-danger">Credit</span>
                                <?php else: ?>
                                <span class="badge bg-success">Payment</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold <?= $tx['type'] === 'credit' ? 'text-danger' : 'text-success' ?>">
                                <?= $tx['type'] === 'credit' ? '+' : '-' ?><?= formatCurrency($tx['amount'], $tx['currency']) ?>
                            </td>
                            <td><small><?= sanitize($tx['notes'] ?? '-') ?></small></td>
                            <td><small><?= sanitize($tx['created_by_name'] ?? '-') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php elseif (!$detailCustomerId): ?>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-info-circle me-2"></i>Quick Info</h6>
            <div class="small text-muted">
                <p><strong>Credits</strong> represent amounts owed by customers (e.g., unpaid balances on sales).</p>
                <p><strong>Payments</strong> reduce the customer's outstanding balance.</p>
                <p>Click <i class="bi bi-clock-history"></i> on a customer row to see their full transaction history.</p>
                <p>Click <i class="bi bi-cash"></i> to quickly record a payment against a customer's balance.</p>
                <hr>
                <p class="mb-0"><strong>Exchange Rate:</strong> 1 USD = <?= number_format($exchangeRate, 0, '.', ',') ?> LBP</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Add Transaction Modal -->
<div class="modal fade" id="addTransaction"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Credit / Payment</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">Customer</label>
            <select class="form-select" name="customer_id" id="txCustomer" required>
                <option value="">Select customer...</option>
                <?php foreach ($allPatients as $p): ?>
                <option value="<?= $p['id'] ?>"><?= sanitize($p['first_name'] . ' ' . $p['last_name']) ?><?= $p['phone'] ? ' (' . sanitize($p['phone']) . ')' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Type</label>
            <div class="btn-group w-100" role="group">
                <input type="radio" class="btn-check" name="type" value="credit" id="typeCredit" checked>
                <label class="btn btn-outline-danger" for="typeCredit"><i class="bi bi-arrow-up me-1"></i>Credit (Owes)</label>
                <input type="radio" class="btn-check" name="type" value="payment" id="typePayment">
                <label class="btn btn-outline-success" for="typePayment"><i class="bi bi-arrow-down me-1"></i>Payment</label>
            </div>
        </div>
        <div class="row g-2 mb-3">
            <div class="col-8">
                <label class="form-label">Amount</label>
                <input type="number" class="form-control" name="amount" step="0.01" min="0.01" required>
            </div>
            <div class="col-4">
                <label class="form-label">Currency</label>
                <select class="form-select" name="currency">
                    <option value="USD">USD</option>
                    <option value="LBP">LBP</option>
                </select>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="notes" rows="2" placeholder="Reference, invoice number, etc."></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="add_credit" value="1" class="btn btn-primary"><i class="bi bi-check me-1"></i>Save</button>
    </div>
    </form>
</div></div></div>

<?php
$extraScripts = <<<'SCRIPT'
<script>
$('.btn-record-payment').on('click', function() {
    var id = $(this).data('id');
    var name = $(this).data('name');
    var balance = $(this).data('balance');
    $('#txCustomer').val(id);
    $('#typePayment').prop('checked', true);
    $('input[name="amount"]').val(parseFloat(balance).toFixed(2));
    var modal = new bootstrap.Modal(document.getElementById('addTransaction'));
    modal.show();
});
</script>
SCRIPT;

require_once __DIR__ . '/../../includes/footer.php';
?>
