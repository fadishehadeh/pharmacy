<?php
$pageTitle = 'Financial Overview';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$month = $_GET['month'] ?? date('Y-m');
$year = substr($month, 0, 4);
$monthNum = substr($month, 5, 2);

$monthlySales = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE_FORMAT(sale_date,'%Y-%m') = ? AND status = 'completed'");
$monthlySales->execute([$month]);
$monthlySales = $monthlySales->fetchColumn();

$monthlyCost = $db->prepare("SELECT COALESCE(SUM(si.cost_price * si.quantity),0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE DATE_FORMAT(s.sale_date,'%Y-%m') = ? AND s.status = 'completed'");
$monthlyCost->execute([$month]);
$monthlyCost = $monthlyCost->fetchColumn();

$monthlyExpenses = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m') = ?");
$monthlyExpenses->execute([$month]);
$monthlyExpenses = $monthlyExpenses->fetchColumn();

$monthlyPurchases = $db->prepare("SELECT COALESCE(SUM(total),0) FROM purchase_orders WHERE DATE_FORMAT(order_date,'%Y-%m') = ? AND status != 'cancelled'");
$monthlyPurchases->execute([$month]);
$monthlyPurchases = $monthlyPurchases->fetchColumn();

$grossProfit = $monthlySales - $monthlyCost;
$netProfit = $grossProfit - $monthlyExpenses;
$margin = $monthlySales > 0 ? ($grossProfit / $monthlySales * 100) : 0;

$last12 = $db->query("SELECT DATE_FORMAT(sale_date,'%Y-%m') as m, COALESCE(SUM(total_amount),0) as sales, COALESCE(SUM(si_cost.cost_total),0) as cost FROM sales s LEFT JOIN (SELECT sale_id, SUM(cost_price * quantity) as cost_total FROM sale_items GROUP BY sale_id) si_cost ON s.id = si_cost.sale_id WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND s.status = 'completed' GROUP BY m ORDER BY m")->fetchAll();

$expenseCategories = $db->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m') = ? GROUP BY category ORDER BY total DESC");
$expenseCategories->execute([$month]);
$expenseCategories = $expenseCategories->fetchAll();
?>

<div class="card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-auto"><label class="form-label mb-0">Month:</label></div>
        <div class="col-auto"><input type="month" class="form-control" name="month" value="<?= $month ?>"></div>
        <div class="col-auto"><button type="submit" class="btn btn-primary">View</button></div>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card success">
            <div class="stat-label">Revenue</div>
            <div class="stat-value"><?= formatCurrency($monthlySales) ?></div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card info">
            <div class="stat-label">Gross Profit</div>
            <div class="stat-value"><?= formatCurrency($grossProfit) ?></div>
            <small class="text-muted"><?= number_format($margin, 1) ?>% margin</small>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card warning">
            <div class="stat-label">Expenses</div>
            <div class="stat-value"><?= formatCurrency($monthlyExpenses) ?></div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card <?= $netProfit >= 0 ? 'success' : 'danger' ?>">
            <div class="stat-label">Net Profit</div>
            <div class="stat-value"><?= formatCurrency($netProfit) ?></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <h6>Revenue vs Cost - Last 12 Months</h6>
            <canvas id="profitChart" height="250"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6>Expenses Breakdown</h6>
            <?php if (empty($expenseCategories)): ?>
            <p class="text-muted text-center py-3">No expenses this month</p>
            <?php else: ?>
            <canvas id="expenseChart" height="250"></canvas>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3 mt-2">
    <div class="col-lg-6">
        <div class="card p-3">
            <h6>Quick Summary</h6>
            <table class="table table-sm">
                <tr><td>Total Sales Revenue</td><td class="text-end fw-semibold text-success"><?= formatCurrency($monthlySales) ?></td></tr>
                <tr><td>Cost of Goods Sold</td><td class="text-end text-danger">-<?= formatCurrency($monthlyCost) ?></td></tr>
                <tr class="table-light"><td class="fw-semibold">Gross Profit</td><td class="text-end fw-semibold"><?= formatCurrency($grossProfit) ?></td></tr>
                <tr><td>Operating Expenses</td><td class="text-end text-danger">-<?= formatCurrency($monthlyExpenses) ?></td></tr>
                <tr class="table-primary"><td class="fw-bold">Net Profit</td><td class="text-end fw-bold"><?= formatCurrency($netProfit) ?></td></tr>
                <tr><td>Purchases (Restocking)</td><td class="text-end"><?= formatCurrency($monthlyPurchases) ?></td></tr>
            </table>
        </div>
    </div>
</div>

<?php
$months12 = array_column($last12, 'm');
$sales12 = array_column($last12, 'sales');
$cost12 = array_column($last12, 'cost');
$ecLabels = array_map(function($e) { return ucfirst($e['category']); }, $expenseCategories);
$ecValues = array_column($expenseCategories, 'total');

$extraScripts = "<script>
new Chart(document.getElementById('profitChart'), {
    type: 'bar',
    data: {
        labels: " . json_encode($months12) . ",
        datasets: [
            { label: 'Revenue', data: " . json_encode($sales12) . ", backgroundColor: 'rgba(5,150,105,0.7)' },
            { label: 'Cost', data: " . json_encode($cost12) . ", backgroundColor: 'rgba(220,38,38,0.5)' }
        ]
    },
    options: { responsive: true, scales: { y: { beginAtZero: true } } }
});
" . (!empty($expenseCategories) ? "
new Chart(document.getElementById('expenseChart'), {
    type: 'doughnut',
    data: { labels: " . json_encode($ecLabels) . ", datasets: [{ data: " . json_encode($ecValues) . ", backgroundColor: ['#2563EB','#059669','#D97706','#DC2626','#8B5CF6','#EC4899','#F97316','#06B6D4','#84CC16'] }] },
    options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } } }
});" : "") . "
</script>";

require_once __DIR__ . '/../../includes/footer.php';
?>
