<?php
$pageTitle = 'Daily Report';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

$reportDate = $_GET['date'] ?? date('Y-m-d');

$sales = $db->prepare("SELECT s.*, c.name as customer_name, u.full_name as cashier_name,
    (SELECT SUM(si.quantity) FROM sale_items si WHERE si.sale_id = s.id) as item_count,
    (SELECT SUM(si.total_price - (si.cost_price * si.quantity)) FROM sale_items si WHERE si.sale_id = s.id) as profit
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN users u ON s.created_by = u.id
    WHERE DATE(s.sale_date) = ?
    ORDER BY s.sale_date");
$sales->execute([$reportDate]);
$sales = $sales->fetchAll();

$totalSales = 0; $totalProfit = 0; $totalItems = 0; $totalDiscount = 0; $totalTax = 0;
$paymentBreakdown = ['cash' => 0, 'card' => 0, 'credit' => 0, 'insurance' => 0];
$completedSales = 0;
foreach ($sales as $s) {
    if ($s['status'] === 'completed') {
        $totalSales += $s['total_amount'];
        $totalProfit += $s['profit'] ?? 0;
        $totalItems += $s['item_count'] ?? 0;
        $totalDiscount += $s['discount_amount'];
        $totalTax += $s['tax_amount'];
        $paymentBreakdown[$s['payment_method']] += $s['total_amount'];
        $completedSales++;
    }
}

$returns = $db->prepare("SELECT sr.*, si.medicine_id, m.name as med_name, s.invoice_number
    FROM sale_returns sr
    JOIN sale_items si ON sr.sale_item_id = si.id
    JOIN sales s ON sr.sale_id = s.id
    JOIN medicines m ON si.medicine_id = m.id
    WHERE DATE(sr.return_date) = ?");
$returns->execute([$reportDate]);
$returns = $returns->fetchAll();
$totalReturns = 0;
foreach ($returns as $r) $totalReturns += $r['refund_amount'];

$stockMovements = $db->prepare("SELECT sm.*, m.name as med_name, u.full_name as user_name
    FROM stock_movements sm
    JOIN medicines m ON sm.medicine_id = m.id
    LEFT JOIN users u ON sm.created_by = u.id
    WHERE DATE(sm.created_at) = ?
    ORDER BY sm.created_at");
$stockMovements->execute([$reportDate]);
$stockMovements = $stockMovements->fetchAll();

$expenses = $db->prepare("SELECT * FROM expenses WHERE expense_date = ?");
$expenses->execute([$reportDate]);
$expenses = $expenses->fetchAll();
$totalExpenses = 0;
foreach ($expenses as $e) $totalExpenses += $e['amount'];

$topSold = $db->prepare("SELECT m.name, SUM(si.quantity) as qty_sold, SUM(si.total_price) as revenue
    FROM sale_items si
    JOIN medicines m ON si.medicine_id = m.id
    JOIN sales s ON si.sale_id = s.id
    WHERE DATE(s.sale_date) = ? AND s.status = 'completed'
    GROUP BY m.id ORDER BY qty_sold DESC LIMIT 10");
$topSold->execute([$reportDate]);
$topSold = $topSold->fetchAll();

$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');
$pharmacistName = getSetting('pharmacist_name', '');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <form class="d-flex gap-2" method="GET">
            <input type="date" class="form-control" name="date" value="<?= sanitize($reportDate) ?>">
            <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Load</button>
        </form>
    </div>
    <div class="d-flex gap-2">
        <a href="?date=<?= date('Y-m-d', strtotime($reportDate . ' -1 day')) ?>" class="btn btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
        <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-outline-primary">Today</a>
        <a href="?date=<?= date('Y-m-d', strtotime($reportDate . ' +1 day')) ?>" class="btn btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
        <button onclick="window.print()" class="btn btn-outline-dark"><i class="bi bi-printer me-1"></i>Print</button>
    </div>
</div>

<div class="card p-4 mb-3" id="printableReport">
    <div class="text-center mb-4">
        <h4><?= sanitize($pharmacyName) ?></h4>
        <h5 class="text-muted">Daily Report - <?= formatDate($reportDate, 'l, F d, Y') ?></h5>
        <?php if ($pharmacistName): ?><p class="text-muted mb-0">Pharmacist: <?= sanitize($pharmacistName) ?></p><?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card stat-card success"><div class="stat-label">Total Sales</div><div class="stat-value"><?= formatCurrency($totalSales) ?></div><small class="text-muted"><?= $completedSales ?> transactions</small></div></div>
        <div class="col-md-3"><div class="card stat-card info"><div class="stat-label">Gross Profit</div><div class="stat-value"><?= formatCurrency($totalProfit) ?></div><small class="text-muted"><?= $totalSales > 0 ? round($totalProfit / $totalSales * 100, 1) : 0 ?>% margin</small></div></div>
        <div class="col-md-3"><div class="card stat-card warning"><div class="stat-label">Expenses</div><div class="stat-value"><?= formatCurrency($totalExpenses) ?></div></div></div>
        <div class="col-md-3"><div class="card stat-card <?= ($totalProfit - $totalExpenses) >= 0 ? 'success' : 'danger' ?>"><div class="stat-label">Net Profit</div><div class="stat-value"><?= formatCurrency($totalProfit - $totalExpenses) ?></div></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <h6 class="text-primary border-bottom pb-2"><i class="bi bi-cash me-2"></i>Payment Breakdown</h6>
            <table class="table table-sm">
                <tr><td>Cash</td><td class="text-end fw-bold"><?= formatCurrency($paymentBreakdown['cash']) ?></td></tr>
                <tr><td>Card</td><td class="text-end fw-bold"><?= formatCurrency($paymentBreakdown['card']) ?></td></tr>
                <tr><td>Credit</td><td class="text-end fw-bold"><?= formatCurrency($paymentBreakdown['credit']) ?></td></tr>
                <tr><td>Insurance</td><td class="text-end fw-bold"><?= formatCurrency($paymentBreakdown['insurance']) ?></td></tr>
                <tr class="table-active"><td><strong>Total</strong></td><td class="text-end fw-bold"><?= formatCurrency($totalSales) ?></td></tr>
            </table>
        </div>
        <div class="col-md-6">
            <h6 class="text-primary border-bottom pb-2"><i class="bi bi-info-circle me-2"></i>Summary</h6>
            <table class="table table-sm">
                <tr><td>Items Sold</td><td class="text-end"><?= $totalItems ?></td></tr>
                <tr><td>Total Discount</td><td class="text-end"><?= formatCurrency($totalDiscount) ?></td></tr>
                <tr><td>Tax Collected</td><td class="text-end"><?= formatCurrency($totalTax) ?></td></tr>
                <tr><td>Returns</td><td class="text-end text-danger"><?= formatCurrency($totalReturns) ?> (<?= count($returns) ?>)</td></tr>
                <tr><td>Stock Movements</td><td class="text-end"><?= count($stockMovements) ?></td></tr>
            </table>
        </div>
    </div>

    <?php if (!empty($topSold)): ?>
    <h6 class="text-primary border-bottom pb-2"><i class="bi bi-trophy me-2"></i>Top Selling Products</h6>
    <table class="table table-sm table-hover mb-4">
        <thead><tr><th>#</th><th>Medicine</th><th>Qty Sold</th><th>Revenue</th></tr></thead>
        <tbody>
            <?php foreach ($topSold as $i => $t): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= sanitize($t['name']) ?></td>
                <td><strong><?= $t['qty_sold'] ?></strong></td>
                <td><?= formatCurrency($t['revenue']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($sales)): ?>
    <h6 class="text-primary border-bottom pb-2"><i class="bi bi-receipt me-2"></i>All Transactions</h6>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-4">
            <thead><tr><th>Invoice</th><th>Time</th><th>Customer</th><th>Cashier</th><th>Items</th><th>Payment</th><th class="text-end">Total</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($sales as $s): ?>
                <tr>
                    <td><a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $s['id'] ?>"><?= sanitize($s['invoice_number']) ?></a></td>
                    <td><?= formatDate($s['sale_date'], 'H:i') ?></td>
                    <td><?= sanitize($s['customer_name'] ?? 'Walk-in') ?></td>
                    <td><small><?= sanitize($s['cashier_name'] ?? '-') ?></small></td>
                    <td><?= $s['item_count'] ?></td>
                    <td><span class="badge bg-secondary"><?= ucfirst($s['payment_method']) ?></span></td>
                    <td class="text-end fw-semibold"><?= formatCurrency($s['total_amount']) ?></td>
                    <td><span class="badge bg-<?= $s['status'] === 'completed' ? 'success' : 'warning' ?>"><?= ucfirst($s['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($stockMovements)): ?>
    <h6 class="text-primary border-bottom pb-2"><i class="bi bi-arrow-left-right me-2"></i>Stock Movements</h6>
    <div class="table-responsive">
        <table class="table table-sm mb-4">
            <thead><tr><th>Time</th><th>Medicine</th><th>Type</th><th>Qty</th><th>Notes</th><th>By</th></tr></thead>
            <tbody>
                <?php foreach ($stockMovements as $sm): ?>
                <tr>
                    <td><?= formatDate($sm['created_at'], 'H:i') ?></td>
                    <td><?= sanitize($sm['med_name']) ?></td>
                    <td><span class="badge bg-<?= $sm['type'] === 'in' ? 'success' : ($sm['type'] === 'out' ? 'danger' : 'warning') ?>"><?= strtoupper($sm['type']) ?></span></td>
                    <td><?= $sm['quantity'] ?></td>
                    <td><small><?= sanitize($sm['notes'] ?? '') ?></small></td>
                    <td><small><?= sanitize($sm['user_name'] ?? '-') ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($expenses)): ?>
    <h6 class="text-primary border-bottom pb-2"><i class="bi bi-wallet2 me-2"></i>Expenses</h6>
    <table class="table table-sm mb-4">
        <thead><tr><th>Category</th><th>Description</th><th>Amount</th><th>Payment</th></tr></thead>
        <tbody>
            <?php foreach ($expenses as $e): ?>
            <tr>
                <td><span class="badge bg-secondary"><?= ucfirst($e['category']) ?></span></td>
                <td><?= sanitize($e['description']) ?></td>
                <td class="fw-bold"><?= formatCurrency($e['amount']) ?></td>
                <td><?= ucfirst($e['payment_method']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div class="text-center text-muted mt-4 pt-3 border-top">
        <small>Report generated on <?= date('M d, Y H:i:s') ?> | <?= sanitize($pharmacyName) ?></small>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
