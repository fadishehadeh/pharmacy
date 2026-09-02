<?php
$pageTitle = 'Sales History';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$status = $_GET['status'] ?? '';
$payment = $_GET['payment'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));

$where = ['DATE(s.sale_date) BETWEEN ? AND ?'];
$params = [$dateFrom, $dateTo];
if ($status) { $where[] = 's.status = ?'; $params[] = $status; }
if ($payment) { $where[] = 's.payment_method = ?'; $params[] = $payment; }
$whereStr = implode(' AND ', $where);

$result = paginate("SELECT s.*, c.name as customer_name FROM sales s LEFT JOIN customers c ON s.customer_id = c.id WHERE $whereStr ORDER BY s.sale_date DESC", $params, $page, 25);

$totals = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount),0) as total FROM sales s WHERE $whereStr AND s.status = 'completed'");
$totals->execute($params);
$totals = $totals->fetch();
?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card stat-card success">
            <div class="stat-label">Period Sales</div>
            <div class="stat-value"><?= formatCurrency($totals['total']) ?></div>
            <small class="text-muted"><?= $totals['count'] ?> transactions</small>
        </div>
    </div>
</div>

<div class="card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label small">From</label><input type="date" class="form-control" name="from" value="<?= $dateFrom ?>"></div>
        <div class="col-md-2"><label class="form-label small">To</label><input type="date" class="form-control" name="to" value="<?= $dateTo ?>"></div>
        <div class="col-md-2"><label class="form-label small">Status</label>
            <select class="form-select" name="status"><option value="">All</option>
                <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                <option value="refunded" <?= $status === 'refunded' ? 'selected' : '' ?>>Refunded</option>
                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label small">Payment</label>
            <select class="form-select" name="payment"><option value="">All</option>
                <option value="cash" <?= $payment === 'cash' ? 'selected' : '' ?>>Cash</option>
                <option value="card" <?= $payment === 'card' ? 'selected' : '' ?>>Card</option>
                <option value="insurance" <?= $payment === 'insurance' ? 'selected' : '' ?>>Insurance</option>
            </select>
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-outline-primary">Filter</button></div>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Invoice</th><th>Customer</th><th>Date</th><th>Payment</th><th>Items</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($result['data'] as $sale): ?>
                <?php $itemCount = $db->prepare("SELECT COUNT(*) FROM sale_items WHERE sale_id = ?"); $itemCount->execute([$sale['id']]); ?>
                <tr>
                    <td><a href="view.php?id=<?= $sale['id'] ?>"><?= sanitize($sale['invoice_number']) ?></a></td>
                    <td><?= sanitize($sale['customer_name'] ?? 'Walk-in') ?></td>
                    <td><?= formatDate($sale['sale_date'], 'M d, Y H:i') ?></td>
                    <td><span class="badge bg-secondary"><?= ucfirst($sale['payment_method']) ?></span></td>
                    <td><?= $itemCount->fetchColumn() ?></td>
                    <td class="text-end fw-semibold"><?= formatCurrency($sale['total_amount'], $sale['currency']) ?></td>
                    <td><span class="badge bg-<?= $sale['status'] === 'completed' ? 'success' : ($sale['status'] === 'cancelled' ? 'danger' : 'warning') ?>"><?= ucfirst($sale['status']) ?></span></td>
                    <td>
                        <?php if ($sale['payment_method'] === 'insurance'): ?>
                        <a href="<?= BASE_URL ?>/modules/insurance/claim_form.php?sale_id=<?= $sale['id'] ?>"
                            class="btn btn-xs btn-outline-primary btn-sm" title="Generate Insurance Claim">
                            <i class="bi bi-file-medical"></i> Claim
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($result['data'])): ?><tr><td colspan="8" class="text-center text-muted py-3">No sales found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= renderPagination($result, 'index.php?' . http_build_query(array_filter(['from' => $dateFrom, 'to' => $dateTo, 'status' => $status, 'payment' => $payment]))) ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
