<?php
$pageTitle = 'Customer Analytics';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));

// Period selector
$period = intval($_GET['period'] ?? 90);
if (!in_array($period, [30, 60, 90, 180, 365])) $period = 90;
$dateFrom = date('Y-m-d', strtotime("-{$period} days"));
$dateTo = date('Y-m-d');

// Total customers
$totalCustomers = intval($db->query("SELECT COUNT(*) FROM customers")->fetchColumn());

// New customers in period
$newCustomers = $db->prepare("SELECT COUNT(*) FROM customers WHERE DATE(created_at) BETWEEN ? AND ?");
$newCustomers->execute([$dateFrom, $dateTo]);
$newCustomersCount = intval($newCustomers->fetchColumn());

// Customers who purchased in period
$activeCustomers = $db->prepare("SELECT COUNT(DISTINCT s.customer_id) FROM sales s
    WHERE s.status = 'completed' AND s.customer_id IS NOT NULL AND DATE(s.sale_date) BETWEEN ? AND ?");
$activeCustomers->execute([$dateFrom, $dateTo]);
$activeCustomersCount = intval($activeCustomers->fetchColumn());

// Returning customers (purchased more than once in period)
$returningCustomers = $db->prepare("SELECT COUNT(*) FROM (
    SELECT s.customer_id, COUNT(*) as visits
    FROM sales s
    WHERE s.status = 'completed' AND s.customer_id IS NOT NULL AND DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY s.customer_id HAVING visits > 1
) as returning_tbl");
$returningCustomers->execute([$dateFrom, $dateTo]);
$returningCount = intval($returningCustomers->fetchColumn());
$returningRate = $activeCustomersCount > 0 ? round($returningCount / $activeCustomersCount * 100, 1) : 0;

// Avg customer value in period
$avgCustomerValue = $db->prepare("SELECT AVG(customer_total) FROM (
    SELECT s.customer_id, SUM(s.total_amount) as customer_total
    FROM sales s
    WHERE s.status = 'completed' AND s.customer_id IS NOT NULL AND DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY s.customer_id
) as cv_tbl");
$avgCustomerValue->execute([$dateFrom, $dateTo]);
$avgCustVal = floatval($avgCustomerValue->fetchColumn());

// Top customer
$topCust = $db->prepare("SELECT c.name, SUM(s.total_amount) as total_spent
    FROM sales s
    JOIN customers c ON s.customer_id = c.id
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY s.customer_id ORDER BY total_spent DESC LIMIT 1");
$topCust->execute([$dateFrom, $dateTo]);
$topCustomer = $topCust->fetch();

// New vs returning trend (monthly)
$trendData = $db->prepare("SELECT DATE_FORMAT(s.sale_date, '%Y-%m') as month_key,
    MIN(DATE(s.sale_date)) as month_date,
    COUNT(DISTINCT s.customer_id) as total_customers,
    COUNT(DISTINCT CASE WHEN c.created_at IS NOT NULL AND DATE_FORMAT(c.created_at, '%Y-%m') = DATE_FORMAT(s.sale_date, '%Y-%m') THEN s.customer_id END) as new_customers
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE s.status = 'completed' AND s.customer_id IS NOT NULL AND DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY month_key ORDER BY month_key");
$trendData->execute([$dateFrom, $dateTo]);
$trendData = $trendData->fetchAll();

$trendLabels = [];
$trendNew = [];
$trendReturning = [];
foreach ($trendData as $t) {
    $trendLabels[] = formatDate($t['month_date'], 'M Y');
    $newCount = intval($t['new_customers']);
    $trendNew[] = $newCount;
    $trendReturning[] = intval($t['total_customers']) - $newCount;
}

// Top 10 customers by spending
$topCustomers = $db->prepare("SELECT c.name, c.phone,
    COUNT(s.id) as purchase_count,
    SUM(s.total_amount) as total_spent,
    AVG(s.total_amount) as avg_ticket,
    MAX(s.sale_date) as last_visit,
    MIN(s.sale_date) as first_visit
    FROM sales s
    JOIN customers c ON s.customer_id = c.id
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY s.customer_id ORDER BY total_spent DESC LIMIT 10");
$topCustomers->execute([$dateFrom, $dateTo]);
$topCustomers = $topCustomers->fetchAll();

$topCustLabels = array_map(function($c) { return $c['name']; }, $topCustomers);
$topCustValues = array_map(function($c) { return round(floatval($c['total_spent']), 2); }, $topCustomers);

// Customer segments
$segments = $db->prepare("SELECT
    SUM(CASE WHEN customer_total >= 500 THEN 1 ELSE 0 END) as vip,
    SUM(CASE WHEN customer_total >= 100 AND customer_total < 500 THEN 1 ELSE 0 END) as regular,
    SUM(CASE WHEN customer_total < 100 THEN 1 ELSE 0 END) as occasional
    FROM (
        SELECT s.customer_id, SUM(s.total_amount) as customer_total
        FROM sales s
        WHERE s.status = 'completed' AND s.customer_id IS NOT NULL AND DATE(s.sale_date) BETWEEN ? AND ?
        GROUP BY s.customer_id
    ) as seg_tbl");
$segments->execute([$dateFrom, $dateTo]);
$segments = $segments->fetch();

// Full customer ranking table
$customerRanking = $db->prepare("SELECT c.id, c.name, c.phone,
    COUNT(s.id) as purchase_count,
    SUM(s.total_amount) as total_spent,
    AVG(s.total_amount) as avg_ticket,
    MAX(s.sale_date) as last_visit,
    DATEDIFF(CURDATE(), MAX(s.sale_date)) as days_since_last
    FROM sales s
    JOIN customers c ON s.customer_id = c.id
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY s.customer_id ORDER BY total_spent DESC");
$customerRanking->execute([$dateFrom, $dateTo]);
$customerRanking = $customerRanking->fetchAll();

// Retention: customers who haven't returned
$retention30 = $db->prepare("SELECT c.name, c.phone, MAX(s.sale_date) as last_visit,
    DATEDIFF(CURDATE(), MAX(s.sale_date)) as days_absent,
    SUM(s.total_amount) as total_spent, COUNT(s.id) as visits
    FROM sales s JOIN customers c ON s.customer_id = c.id
    WHERE s.status = 'completed' AND s.customer_id IS NOT NULL
    GROUP BY s.customer_id
    HAVING days_absent BETWEEN 30 AND 59
    ORDER BY total_spent DESC LIMIT 20");
$retention30->execute();
$retention30 = $retention30->fetchAll();

$retention60 = $db->prepare("SELECT c.name, c.phone, MAX(s.sale_date) as last_visit,
    DATEDIFF(CURDATE(), MAX(s.sale_date)) as days_absent,
    SUM(s.total_amount) as total_spent, COUNT(s.id) as visits
    FROM sales s JOIN customers c ON s.customer_id = c.id
    WHERE s.status = 'completed' AND s.customer_id IS NOT NULL
    GROUP BY s.customer_id
    HAVING days_absent BETWEEN 60 AND 89
    ORDER BY total_spent DESC LIMIT 20");
$retention60->execute();
$retention60 = $retention60->fetchAll();

$retention90 = $db->prepare("SELECT c.name, c.phone, MAX(s.sale_date) as last_visit,
    DATEDIFF(CURDATE(), MAX(s.sale_date)) as days_absent,
    SUM(s.total_amount) as total_spent, COUNT(s.id) as visits
    FROM sales s JOIN customers c ON s.customer_id = c.id
    WHERE s.status = 'completed' AND s.customer_id IS NOT NULL
    GROUP BY s.customer_id
    HAVING days_absent >= 90
    ORDER BY total_spent DESC LIMIT 20");
$retention90->execute();
$retention90 = $retention90->fetchAll();

// Loyalty points summary
$loyaltyEnabled = false;
$loyaltySummary = null;
try {
    $loyaltyCheck = $db->query("SELECT COUNT(*) FROM loyalty_points")->fetchColumn();
    if ($loyaltyCheck > 0) {
        $loyaltyEnabled = true;
        $loyaltySummary = $db->query("SELECT
            COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) as total_earned,
            COALESCE(SUM(CASE WHEN type = 'redeemed' THEN ABS(points) ELSE 0 END), 0) as total_redeemed,
            COUNT(DISTINCT customer_id) as members
        FROM loyalty_points")->fetch();
    }
} catch (Exception $e) {}
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="btn-group btn-group-sm">
        <?php foreach ([30, 60, 90, 180, 365] as $p): ?>
        <a href="?period=<?= $p ?>" class="btn btn-<?= $period === $p ? 'primary' : 'outline-primary' ?>"><?= $p === 365 ? '1 Year' : $p . ' Days' ?></a>
        <?php endforeach; ?>
    </div>
    <button onclick="window.print()" class="btn btn-sm btn-outline-dark no-print"><i class="bi bi-printer me-1"></i>Print</button>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-3">
    <div class="col-md-4 col-lg">
        <div class="card stat-card">
            <div class="stat-label">Total Customers</div>
            <div class="stat-value"><?= number_format($totalCustomers) ?></div>
        </div>
    </div>
    <div class="col-md-4 col-lg">
        <div class="card stat-card success">
            <div class="stat-label">New Customers</div>
            <div class="stat-value"><?= number_format($newCustomersCount) ?></div>
            <small class="text-muted">Last <?= $period ?> days</small>
        </div>
    </div>
    <div class="col-md-4 col-lg">
        <div class="card stat-card info">
            <div class="stat-label">Returning Rate</div>
            <div class="stat-value"><?= $returningRate ?>%</div>
            <small class="text-muted"><?= $returningCount ?> of <?= $activeCustomersCount ?> active</small>
        </div>
    </div>
    <div class="col-md-4 col-lg">
        <div class="card stat-card warning">
            <div class="stat-label">Avg Customer Value</div>
            <div class="stat-value"><?= formatCurrency($avgCustVal) ?></div>
            <small class="text-muted"><?= formatCurrency($avgCustVal * $exchangeRate, 'LBP') ?></small>
        </div>
    </div>
    <div class="col-md-4 col-lg">
        <div class="card stat-card">
            <div class="stat-label">Top Customer</div>
            <div class="stat-value small"><?= sanitize($topCustomer['name'] ?? 'N/A') ?></div>
            <small class="text-muted"><?= $topCustomer ? formatCurrency($topCustomer['total_spent']) : '' ?></small>
        </div>
    </div>
</div>

<?php if ($loyaltyEnabled && $loyaltySummary): ?>
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card stat-card success">
            <div class="stat-label">Loyalty Members</div>
            <div class="stat-value"><?= number_format($loyaltySummary['members']) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card info">
            <div class="stat-label">Points Earned</div>
            <div class="stat-value"><?= number_format($loyaltySummary['total_earned']) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card warning">
            <div class="stat-label">Points Redeemed</div>
            <div class="stat-value"><?= number_format($loyaltySummary['total_redeemed']) ?></div>
            <small class="text-muted">Balance: <?= number_format($loyaltySummary['total_earned'] - $loyaltySummary['total_redeemed']) ?></small>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Charts -->
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-graph-up me-2"></i>New vs Returning Customers</h6>
            <canvas id="trendChart" height="100"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-pie-chart me-2"></i>Customer Segments</h6>
            <canvas id="segmentChart" height="200"></canvas>
            <div class="mt-3">
                <div class="d-flex justify-content-between small"><span><i class="bi bi-circle-fill text-warning me-1"></i>VIP ($500+)</span><span class="fw-bold"><?= intval($segments['vip'] ?? 0) ?></span></div>
                <div class="d-flex justify-content-between small"><span><i class="bi bi-circle-fill text-primary me-1"></i>Regular ($100-500)</span><span class="fw-bold"><?= intval($segments['regular'] ?? 0) ?></span></div>
                <div class="d-flex justify-content-between small"><span><i class="bi bi-circle-fill text-secondary me-1"></i>Occasional (&lt;$100)</span><span class="fw-bold"><?= intval($segments['occasional'] ?? 0) ?></span></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-bar-chart me-2"></i>Top 10 Customers by Spending</h6>
            <canvas id="topCustChart" height="200"></canvas>
        </div>
    </div>
</div>

<!-- Customer Ranking Table -->
<div class="card mb-3">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-trophy me-2"></i>Customer Ranking</h6></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Phone</th>
                    <th class="text-end">Purchases</th>
                    <th class="text-end">Total Spent (USD)</th>
                    <th class="text-end">Total Spent (LBP)</th>
                    <th class="text-end">Avg Ticket</th>
                    <th>Last Visit</th>
                    <th>Segment</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customerRanking as $i => $c): ?>
                <?php
                $spent = floatval($c['total_spent']);
                $segment = $spent >= 500 ? 'VIP' : ($spent >= 100 ? 'Regular' : 'Occasional');
                $segBadge = $spent >= 500 ? 'warning' : ($spent >= 100 ? 'primary' : 'secondary');
                ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= sanitize($c['name']) ?></strong></td>
                    <td><small><?= sanitize($c['phone'] ?? '-') ?></small></td>
                    <td class="text-end"><?= $c['purchase_count'] ?></td>
                    <td class="text-end fw-semibold"><?= formatCurrency($c['total_spent']) ?></td>
                    <td class="text-end"><small class="text-muted"><?= formatCurrency($c['total_spent'] * $exchangeRate, 'LBP') ?></small></td>
                    <td class="text-end"><?= formatCurrency($c['avg_ticket']) ?></td>
                    <td><?= formatDate($c['last_visit'], 'M d, Y') ?></td>
                    <td><span class="badge bg-<?= $segBadge ?>"><?= $segment ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($customerRanking)): ?><tr><td colspan="9" class="text-center text-muted py-3">No customer data for this period</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Retention Tables -->
<div class="card mb-3">
    <div class="card-header bg-warning bg-opacity-10">
        <h6 class="mb-0"><i class="bi bi-person-dash me-2"></i>Customer Retention - At Risk</h6>
    </div>
    <div class="card-body">
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#ret30">30-59 Days (<?= count($retention30) ?>)</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#ret60">60-89 Days (<?= count($retention60) ?>)</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#ret90">90+ Days (<?= count($retention90) ?>)</a></li>
        </ul>
        <div class="tab-content mt-3">
            <?php foreach ([['ret30', $retention30], ['ret60', $retention60], ['ret90', $retention90]] as $idx => $tab): ?>
            <div class="tab-pane fade <?= $idx === 0 ? 'show active' : '' ?>" id="<?= $tab[0] ?>">
                <?php if (!empty($tab[1])): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Customer</th><th>Phone</th><th>Last Visit</th><th>Days Absent</th><th class="text-end">Total Spent</th><th>Visits</th></tr></thead>
                        <tbody>
                            <?php foreach ($tab[1] as $r): ?>
                            <tr>
                                <td><strong><?= sanitize($r['name']) ?></strong></td>
                                <td><small><?= sanitize($r['phone'] ?? '-') ?></small></td>
                                <td><?= formatDate($r['last_visit'], 'M d, Y') ?></td>
                                <td><span class="badge bg-<?= $r['days_absent'] >= 90 ? 'danger' : ($r['days_absent'] >= 60 ? 'warning' : 'info') ?>"><?= $r['days_absent'] ?> days</span></td>
                                <td class="text-end"><?= formatCurrency($r['total_spent']) ?></td>
                                <td><?= $r['visits'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-muted text-center py-3">No customers in this category</p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
$extraScripts = "<script>
// New vs Returning Trend
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: " . json_encode($trendLabels) . ",
        datasets: [
            {
                label: 'New Customers',
                data: " . json_encode($trendNew) . ",
                borderColor: '#198754',
                backgroundColor: 'rgba(25,135,84,0.1)',
                fill: true,
                tension: 0.3
            },
            {
                label: 'Returning Customers',
                data: " . json_encode($trendReturning) . ",
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13,110,253,0.1)',
                fill: true,
                tension: 0.3
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});

// Customer Segments
new Chart(document.getElementById('segmentChart'), {
    type: 'pie',
    data: {
        labels: ['VIP (\$500+)', 'Regular (\$100-500)', 'Occasional (<\$100)'],
        datasets: [{
            data: [" . intval($segments['vip'] ?? 0) . ", " . intval($segments['regular'] ?? 0) . ", " . intval($segments['occasional'] ?? 0) . "],
            backgroundColor: ['#ffc107', '#0d6efd', '#adb5bd']
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } }
    }
});

// Top 10 Customers
new Chart(document.getElementById('topCustChart'), {
    type: 'bar',
    data: {
        labels: " . json_encode($topCustLabels) . ",
        datasets: [{
            label: 'Total Spent (\$)',
            data: " . json_encode($topCustValues) . ",
            backgroundColor: '#198754'
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true } }
    }
});
</script>";

require_once __DIR__ . '/../../includes/footer.php';
?>
