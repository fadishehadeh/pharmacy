<?php
$pageTitle = 'Profit & Loss Statement';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$dateFrom = $_GET['from'] ?? date('Y-01-01');
$dateTo = $_GET['to'] ?? date('Y-12-31');

$revenue = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sale_date) BETWEEN ? AND ? AND status = 'completed'");
$revenue->execute([$dateFrom, $dateTo]);
$revenue = $revenue->fetchColumn();

$cogs = $db->prepare("SELECT COALESCE(SUM(si.cost_price * si.quantity),0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE DATE(s.sale_date) BETWEEN ? AND ? AND s.status = 'completed'");
$cogs->execute([$dateFrom, $dateTo]);
$cogs = $cogs->fetchColumn();

$returns = $db->prepare("SELECT COALESCE(SUM(refund_amount),0) FROM sale_returns WHERE DATE(return_date) BETWEEN ? AND ?");
$returns->execute([$dateFrom, $dateTo]);
$returns = $returns->fetchColumn();

$expensesByCategory = $db->prepare("SELECT category, COALESCE(SUM(amount),0) as total FROM expenses WHERE DATE(expense_date) BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
$expensesByCategory->execute([$dateFrom, $dateTo]);
$expensesByCategory = $expensesByCategory->fetchAll();
$totalExpenses = array_sum(array_column($expensesByCategory, 'total'));

$grossProfit = $revenue - $cogs - $returns;
$netProfit = $grossProfit - $totalExpenses;
?>

<div class="card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label small">From</label><input type="date" class="form-control" name="from" value="<?= $dateFrom ?>"></div>
        <div class="col-md-2"><label class="form-label small">To</label><input type="date" class="form-control" name="to" value="<?= $dateTo ?>"></div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary">Generate</button></div>
        <div class="col text-end"><button class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button></div>
    </form>
</div>

<div class="card p-4">
    <h5 class="text-center mb-1"><?= sanitize(getSetting('pharmacy_name', 'My Pharmacy')) ?></h5>
    <h6 class="text-center text-muted mb-4">Profit & Loss Statement<br><small><?= formatDate($dateFrom, 'M d, Y') ?> - <?= formatDate($dateTo, 'M d, Y') ?></small></h6>

    <table class="table">
        <tbody>
            <tr class="table-light"><td colspan="2"><strong>REVENUE</strong></td></tr>
            <tr><td class="ps-4">Sales Revenue</td><td class="text-end"><?= formatCurrency($revenue) ?></td></tr>
            <tr><td class="ps-4">Less: Returns & Refunds</td><td class="text-end text-danger">-<?= formatCurrency($returns) ?></td></tr>
            <tr class="table-primary"><td><strong>Net Revenue</strong></td><td class="text-end fw-bold"><?= formatCurrency($revenue - $returns) ?></td></tr>

            <tr class="table-light"><td colspan="2"><strong>COST OF GOODS SOLD</strong></td></tr>
            <tr><td class="ps-4">Medicine Costs</td><td class="text-end text-danger">-<?= formatCurrency($cogs) ?></td></tr>
            <tr class="table-info"><td><strong>GROSS PROFIT</strong></td><td class="text-end fw-bold"><?= formatCurrency($grossProfit) ?></td></tr>

            <tr class="table-light"><td colspan="2"><strong>OPERATING EXPENSES</strong></td></tr>
            <?php foreach ($expensesByCategory as $exp): ?>
            <tr><td class="ps-4"><?= ucfirst($exp['category']) ?></td><td class="text-end text-danger">-<?= formatCurrency($exp['total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($expensesByCategory)): ?>
            <tr><td class="ps-4 text-muted" colspan="2">No expenses recorded</td></tr>
            <?php endif; ?>
            <tr><td class="ps-4 fw-semibold">Total Operating Expenses</td><td class="text-end fw-semibold text-danger">-<?= formatCurrency($totalExpenses) ?></td></tr>

            <tr class="table-<?= $netProfit >= 0 ? 'success' : 'danger' ?>">
                <td><strong class="fs-5">NET PROFIT</strong></td>
                <td class="text-end fw-bold fs-5"><?= formatCurrency($netProfit) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="row mt-3">
        <div class="col-md-4">
            <small class="text-muted">Gross Margin</small>
            <div class="fw-bold"><?= $revenue > 0 ? number_format($grossProfit / $revenue * 100, 1) : 0 ?>%</div>
        </div>
        <div class="col-md-4">
            <small class="text-muted">Net Margin</small>
            <div class="fw-bold"><?= $revenue > 0 ? number_format($netProfit / $revenue * 100, 1) : 0 ?>%</div>
        </div>
        <div class="col-md-4">
            <small class="text-muted">Expense Ratio</small>
            <div class="fw-bold"><?= $revenue > 0 ? number_format($totalExpenses / $revenue * 100, 1) : 0 ?>%</div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
