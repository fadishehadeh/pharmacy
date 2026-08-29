<?php
$pageTitle = 'Sales Reports';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$period = $_GET['period'] ?? 'daily';
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');

if ($period === 'daily') {
    $groupBy = 'DATE(s.sale_date)';
    $labelFormat = '%Y-%m-%d';
} elseif ($period === 'weekly') {
    $groupBy = 'YEARWEEK(s.sale_date)';
    $labelFormat = '%x-W%v';
} else {
    $groupBy = 'DATE_FORMAT(s.sale_date, "%Y-%m")';
    $labelFormat = '%Y-%m';
}

$salesData = $db->prepare("SELECT $groupBy as period_label, COUNT(*) as transactions, COALESCE(SUM(total_amount),0) as revenue, COALESCE(SUM(discount_amount),0) as discounts FROM sales s WHERE DATE(s.sale_date) BETWEEN ? AND ? AND s.status = 'completed' GROUP BY period_label ORDER BY period_label");
$salesData->execute([$dateFrom, $dateTo]);
$salesData = $salesData->fetchAll();

$topMedicines = $db->prepare("SELECT m.name, SUM(si.quantity) as qty_sold, SUM(si.total_price) as revenue FROM sale_items si JOIN medicines m ON si.medicine_id = m.id JOIN sales s ON si.sale_id = s.id WHERE DATE(s.sale_date) BETWEEN ? AND ? AND s.status = 'completed' GROUP BY m.id ORDER BY revenue DESC LIMIT 15");
$topMedicines->execute([$dateFrom, $dateTo]);
$topMedicines = $topMedicines->fetchAll();

$paymentBreakdown = $db->prepare("SELECT payment_method, COUNT(*) as count, SUM(total_amount) as total FROM sales WHERE DATE(sale_date) BETWEEN ? AND ? AND status = 'completed' GROUP BY payment_method");
$paymentBreakdown->execute([$dateFrom, $dateTo]);
$paymentBreakdown = $paymentBreakdown->fetchAll();

$totalRevenue = array_sum(array_column($salesData, 'revenue'));
$totalTransactions = array_sum(array_column($salesData, 'transactions'));
?>

<div class="card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label small">Period</label>
            <select class="form-select" name="period">
                <option value="daily" <?= $period === 'daily' ? 'selected' : '' ?>>Daily</option>
                <option value="weekly" <?= $period === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                <option value="monthly" <?= $period === 'monthly' ? 'selected' : '' ?>>Monthly</option>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label small">From</label><input type="date" class="form-control" name="from" value="<?= $dateFrom ?>"></div>
        <div class="col-md-2"><label class="form-label small">To</label><input type="date" class="form-control" name="to" value="<?= $dateTo ?>"></div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary">Generate</button></div>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card stat-card success"><div class="stat-label">Total Revenue</div><div class="stat-value"><?= formatCurrency($totalRevenue) ?></div></div></div>
    <div class="col-md-4"><div class="card stat-card"><div class="stat-label">Transactions</div><div class="stat-value"><?= number_format($totalTransactions) ?></div></div></div>
    <div class="col-md-4"><div class="card stat-card info"><div class="stat-label">Avg per Transaction</div><div class="stat-value"><?= $totalTransactions > 0 ? formatCurrency($totalRevenue / $totalTransactions) : '$0' ?></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <h6>Revenue Over Time</h6>
            <canvas id="revenueChart" height="250"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6>Payment Methods</h6>
            <canvas id="paymentChart" height="250"></canvas>
        </div>
    </div>
</div>

<div class="row g-3 mt-2">
    <div class="col-12">
        <div class="card p-3">
            <h6>Top Selling Medicines</h6>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>#</th><th>Medicine</th><th>Qty Sold</th><th class="text-end">Revenue</th></tr></thead>
                    <tbody>
                        <?php foreach ($topMedicines as $i => $med): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= sanitize($med['name']) ?></td>
                            <td><?= number_format($med['qty_sold']) ?></td>
                            <td class="text-end"><?= formatCurrency($med['revenue']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$labels = array_column($salesData, 'period_label');
$revenues = array_column($salesData, 'revenue');
$payLabels = array_map(function($p) { return ucfirst($p['payment_method']); }, $paymentBreakdown);
$payTotals = array_column($paymentBreakdown, 'total');

$extraScripts = "<script>
new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: { labels: " . json_encode($labels) . ", datasets: [{ label: 'Revenue (USD)', data: " . json_encode($revenues) . ", borderColor: '#2563EB', backgroundColor: 'rgba(37,99,235,0.1)', fill: true, tension: 0.3 }] },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
new Chart(document.getElementById('paymentChart'), {
    type: 'doughnut',
    data: { labels: " . json_encode($payLabels) . ", datasets: [{ data: " . json_encode($payTotals) . ", backgroundColor: ['#2563EB','#059669','#D97706','#DC2626'] }] },
    options: { responsive: true }
});
</script>";

require_once __DIR__ . '/../../includes/footer.php';
?>
