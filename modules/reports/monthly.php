<?php
$pageTitle = 'Monthly Report';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$month = intval($_GET['month'] ?? date('m'));
$year = intval($_GET['year'] ?? date('Y'));
$startDate = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
$endDate = date('Y-m-t', strtotime($startDate));

$totalSales = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date BETWEEN ? AND ? AND status = 'completed'");
$totalSales->execute([$startDate, $endDate . ' 23:59:59']);
$totalSales = $totalSales->fetchColumn();

$totalTransactions = $db->prepare("SELECT COUNT(*) FROM sales WHERE sale_date BETWEEN ? AND ? AND status = 'completed'");
$totalTransactions->execute([$startDate, $endDate . ' 23:59:59']);
$totalTransactions = $totalTransactions->fetchColumn();

$totalCost = $db->prepare("SELECT COALESCE(SUM(si.cost_price * si.quantity),0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.sale_date BETWEEN ? AND ? AND s.status = 'completed'");
$totalCost->execute([$startDate, $endDate . ' 23:59:59']);
$totalCost = $totalCost->fetchColumn();

$grossProfit = $totalSales - $totalCost;

$totalExpenses = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?");
$totalExpenses->execute([$startDate, $endDate]);
$totalExpenses = $totalExpenses->fetchColumn();

$netProfit = $grossProfit - $totalExpenses;

$totalReturns = $db->prepare("SELECT COALESCE(SUM(refund_amount),0) FROM sale_returns WHERE return_date BETWEEN ? AND ?");
$totalReturns->execute([$startDate, $endDate . ' 23:59:59']);
$totalReturns = $totalReturns->fetchColumn();

$dailySales = $db->prepare("SELECT DATE(sale_date) as day, COUNT(*) as transactions, SUM(total_amount) as total FROM sales WHERE sale_date BETWEEN ? AND ? AND status = 'completed' GROUP BY DATE(sale_date) ORDER BY day");
$dailySales->execute([$startDate, $endDate . ' 23:59:59']);
$dailySales = $dailySales->fetchAll();

$paymentMethods = $db->prepare("SELECT payment_method, COUNT(*) as cnt, SUM(total_amount) as total FROM sales WHERE sale_date BETWEEN ? AND ? AND status = 'completed' GROUP BY payment_method");
$paymentMethods->execute([$startDate, $endDate . ' 23:59:59']);
$paymentMethods = $paymentMethods->fetchAll();

$topProducts = $db->prepare("SELECT m.name, SUM(si.quantity) as qty, SUM(si.total_price) as revenue, SUM(si.total_price - (si.cost_price * si.quantity)) as profit FROM sale_items si JOIN medicines m ON si.medicine_id = m.id JOIN sales s ON si.sale_id = s.id WHERE s.sale_date BETWEEN ? AND ? AND s.status = 'completed' GROUP BY m.id ORDER BY revenue DESC LIMIT 20");
$topProducts->execute([$startDate, $endDate . ' 23:59:59']);
$topProducts = $topProducts->fetchAll();

$categoryBreakdown = $db->prepare("SELECT c.name, COUNT(DISTINCT si.sale_id) as sales_count, SUM(si.quantity) as qty, SUM(si.total_price) as revenue FROM sale_items si JOIN medicines m ON si.medicine_id = m.id LEFT JOIN categories c ON m.category_id = c.id JOIN sales s ON si.sale_id = s.id WHERE s.sale_date BETWEEN ? AND ? AND s.status = 'completed' GROUP BY c.id ORDER BY revenue DESC");
$categoryBreakdown->execute([$startDate, $endDate . ' 23:59:59']);
$categoryBreakdown = $categoryBreakdown->fetchAll();

$expenseBreakdown = $db->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
$expenseBreakdown->execute([$startDate, $endDate]);
$expenseBreakdown = $expenseBreakdown->fetchAll();

$prevMonthStart = date('Y-m-01', strtotime($startDate . ' -1 month'));
$prevMonthEnd = date('Y-m-t', strtotime($prevMonthStart));
$prevMonthSales = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date BETWEEN ? AND ? AND status = 'completed'");
$prevMonthSales->execute([$prevMonthStart, $prevMonthEnd . ' 23:59:59']);
$prevMonthSales = $prevMonthSales->fetchColumn();
$salesChange = $prevMonthSales > 0 ? round(($totalSales - $prevMonthSales) / $prevMonthSales * 100, 1) : 0;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <form class="d-flex gap-2" method="GET">
        <select class="form-select form-select-sm" name="month" style="width:auto">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
            <?php endfor; ?>
        </select>
        <select class="form-select form-select-sm" name="year" style="width:auto">
            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
            <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
    </form>
    <button onclick="window.print()" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer me-1"></i>Print</button>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card success">
        <div class="stat-label">Total Sales</div>
        <div class="stat-value"><?= formatCurrency($totalSales) ?></div>
        <small class="text-<?= $salesChange >= 0 ? 'success' : 'danger' ?>"><?= $salesChange >= 0 ? '+' : '' ?><?= $salesChange ?>% vs prev</small>
    </div></div>
    <div class="col-md-3"><div class="card stat-card info"><div class="stat-label">Gross Profit</div><div class="stat-value"><?= formatCurrency($grossProfit) ?></div><small class="text-muted"><?= $totalSales > 0 ? round($grossProfit / $totalSales * 100, 1) : 0 ?>% margin</small></div></div>
    <div class="col-md-3"><div class="card stat-card warning"><div class="stat-label">Expenses</div><div class="stat-value"><?= formatCurrency($totalExpenses) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card <?= $netProfit >= 0 ? 'success' : 'danger' ?>"><div class="stat-label">Net Profit</div><div class="stat-value"><?= formatCurrency($netProfit) ?></div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <h6><i class="bi bi-graph-up me-2"></i>Daily Sales Trend</h6>
            <canvas id="dailyChart" height="200"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-credit-card me-2"></i>Payment Methods</h6>
            <?php foreach ($paymentMethods as $pm): ?>
            <div class="d-flex justify-content-between mb-1">
                <span class="small"><?= ucfirst($pm['payment_method']) ?> (<?= $pm['cnt'] ?>)</span>
                <strong class="small"><?= formatCurrency($pm['total']) ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card p-3">
            <h6><i class="bi bi-wallet2 me-2"></i>Expense Breakdown</h6>
            <?php foreach ($expenseBreakdown as $eb): ?>
            <div class="d-flex justify-content-between mb-1">
                <span class="small"><?= ucfirst($eb['category']) ?></span>
                <strong class="small"><?= formatCurrency($eb['total']) ?></strong>
            </div>
            <?php endforeach; ?>
            <?php if (empty($expenseBreakdown)): ?><p class="text-muted small">No expenses recorded</p><?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card p-3">
            <h6><i class="bi bi-trophy me-2"></i>Top Products</h6>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>#</th><th>Medicine</th><th>Qty</th><th>Revenue</th><th>Profit</th></tr></thead>
                    <tbody>
                        <?php foreach ($topProducts as $i => $tp): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= sanitize($tp['name']) ?></td>
                            <td><?= $tp['qty'] ?></td>
                            <td><?= formatCurrency($tp['revenue']) ?></td>
                            <td class="text-success"><?= formatCurrency($tp['profit']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-3">
            <h6><i class="bi bi-tags me-2"></i>Sales by Category</h6>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Category</th><th>Sales</th><th>Qty</th><th>Revenue</th></tr></thead>
                    <tbody>
                        <?php foreach ($categoryBreakdown as $cb): ?>
                        <tr>
                            <td><?= sanitize($cb['name'] ?? 'Uncategorized') ?></td>
                            <td><?= $cb['sales_count'] ?></td>
                            <td><?= $cb['qty'] ?></td>
                            <td><?= formatCurrency($cb['revenue']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$chartLabels = [];
$chartData = [];
$daysInMonth = date('t', strtotime($startDate));
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateStr = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-" . str_pad($d, 2, '0', STR_PAD_LEFT);
    $chartLabels[] = $d;
    $found = false;
    foreach ($dailySales as $ds) {
        if ($ds['day'] === $dateStr) { $chartData[] = $ds['total']; $found = true; break; }
    }
    if (!$found) $chartData[] = 0;
}

$extraScripts = "<script>
new Chart(document.getElementById('dailyChart'), {
    type: 'line',
    data: {
        labels: " . json_encode($chartLabels) . ",
        datasets: [{
            label: 'Daily Sales (USD)',
            data: " . json_encode($chartData) . ",
            borderColor: '#3B82F6',
            backgroundColor: 'rgba(59,130,246,0.1)',
            fill: true,
            tension: 0.3,
            pointRadius: 3
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
</script>";
require_once __DIR__ . '/../../includes/footer.php';
?>
