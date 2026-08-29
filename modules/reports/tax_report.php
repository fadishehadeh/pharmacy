<?php
$pageTitle = 'Tax Report';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));
$vatRate = floatval(getSetting('vat_rate', 11));
$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');
$pharmacyAddress = getSetting('pharmacy_address', '');
$pharmacyTIN = getSetting('pharmacy_tin', '');

// Period handling
$periodType = $_GET['period_type'] ?? 'monthly';
$year = intval($_GET['year'] ?? date('Y'));
$month = intval($_GET['month'] ?? date('m'));
$quarter = intval($_GET['quarter'] ?? ceil(date('m') / 3));

if ($periodType === 'monthly') {
    $dateFrom = date('Y-m-01', mktime(0, 0, 0, $month, 1, $year));
    $dateTo = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
    $periodLabel = formatDate($dateFrom, 'F Y');
} elseif ($periodType === 'quarterly') {
    $qStartMonth = ($quarter - 1) * 3 + 1;
    $dateFrom = date('Y-m-01', mktime(0, 0, 0, $qStartMonth, 1, $year));
    $dateTo = date('Y-m-t', mktime(0, 0, 0, $qStartMonth + 2, 1, $year));
    $periodLabel = "Q{$quarter} {$year}";
} else {
    $dateFrom = "{$year}-01-01";
    $dateTo = "{$year}-12-31";
    $periodLabel = "Year {$year}";
}

// Total sales (completed)
$salesStmt = $db->prepare("SELECT
    COALESCE(SUM(s.total_amount), 0) as total_sales,
    COALESCE(SUM(s.tax_amount), 0) as vat_collected,
    COUNT(s.id) as sale_count
    FROM sales s
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?");
$salesStmt->execute([$dateFrom, $dateTo]);
$salesData = $salesStmt->fetch();
$totalSales = floatval($salesData['total_sales']);
$vatCollected = floatval($salesData['vat_collected']);
$saleCount = intval($salesData['sale_count']);

// Taxable vs exempt sales
$taxableStmt = $db->prepare("SELECT
    COALESCE(SUM(CASE WHEN s.tax_amount > 0 THEN s.total_amount ELSE 0 END), 0) as taxable_sales,
    COALESCE(SUM(CASE WHEN s.tax_amount = 0 THEN s.total_amount ELSE 0 END), 0) as exempt_sales
    FROM sales s
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?");
$taxableStmt->execute([$dateFrom, $dateTo]);
$taxBreak = $taxableStmt->fetch();
$taxableSales = floatval($taxBreak['taxable_sales']);
$exemptSales = floatval($taxBreak['exempt_sales']);

// VAT paid on purchases
$purchaseStmt = $db->prepare("SELECT
    COALESCE(SUM(po.total), 0) as total_purchases
    FROM purchase_orders po
    WHERE po.status != 'cancelled' AND DATE(po.order_date) BETWEEN ? AND ?");
$purchaseStmt->execute([$dateFrom, $dateTo]);
$totalPurchases = floatval($purchaseStmt->fetchColumn());
$vatPaid = $totalPurchases * $vatRate / 100;

// Net VAT payable
$netVat = $vatCollected - $vatPaid;

// Monthly breakdown for the period
$monthlyStmt = $db->prepare("SELECT
    DATE_FORMAT(s.sale_date, '%Y-%m') as month_key,
    MIN(DATE(s.sale_date)) as month_date,
    COALESCE(SUM(s.total_amount), 0) as total_sales,
    COALESCE(SUM(s.tax_amount), 0) as vat_collected,
    COALESCE(SUM(CASE WHEN s.tax_amount > 0 THEN s.total_amount ELSE 0 END), 0) as taxable_sales,
    COALESCE(SUM(CASE WHEN s.tax_amount = 0 THEN s.total_amount ELSE 0 END), 0) as exempt_sales,
    COUNT(s.id) as sale_count
    FROM sales s
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY month_key ORDER BY month_key");
$monthlyStmt->execute([$dateFrom, $dateTo]);
$monthlySales = $monthlyStmt->fetchAll();

// Monthly purchase tax
$monthlyPurchStmt = $db->prepare("SELECT
    DATE_FORMAT(po.order_date, '%Y-%m') as month_key,
    COALESCE(SUM(po.total), 0) as total_purchases
    FROM purchase_orders po
    WHERE po.status != 'cancelled' AND DATE(po.order_date) BETWEEN ? AND ?
    GROUP BY month_key ORDER BY month_key");
$monthlyPurchStmt->execute([$dateFrom, $dateTo]);
$monthlyPurchases = [];
foreach ($monthlyPurchStmt->fetchAll() as $mp) {
    $monthlyPurchases[$mp['month_key']] = floatval($mp['total_purchases']);
}

// Exempt items detail (subsidized medicines sold)
$exemptItemsStmt = $db->prepare("SELECT m.name, SUM(si.quantity) as qty_sold, SUM(si.total_price) as revenue
    FROM sale_items si
    JOIN medicines m ON si.medicine_id = m.id
    JOIN sales s ON si.sale_id = s.id
    WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
    AND (m.is_subsidized = 1 OR s.tax_amount = 0)
    GROUP BY m.id ORDER BY revenue DESC LIMIT 20");
try {
    $exemptItemsStmt->execute([$dateFrom, $dateTo]);
    $exemptItems = $exemptItemsStmt->fetchAll();
} catch (Exception $e) {
    // is_subsidized column may not exist
    $exemptItems = [];
}

// Chart data
$chartLabels = [];
$chartVatCollected = [];
$chartVatPaid = [];
$chartNetVat = [];
foreach ($monthlySales as $ms) {
    $chartLabels[] = formatDate($ms['month_date'], 'M Y');
    $collected = floatval($ms['vat_collected']);
    $purchTotal = $monthlyPurchases[$ms['month_key']] ?? 0;
    $paid = $purchTotal * $vatRate / 100;
    $chartVatCollected[] = round($collected, 2);
    $chartVatPaid[] = round($paid, 2);
    $chartNetVat[] = round($collected - $paid, 2);
}

// Tax collection breakdown (pie)
$pieLabels = ['VAT Collected (Sales)', 'VAT Paid (Purchases)', 'Exempt Sales'];
$pieValues = [round($vatCollected, 2), round($vatPaid, 2), round($exemptSales, 2)];
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <form class="d-flex gap-2 align-items-end flex-wrap" method="GET">
        <div>
            <label class="form-label small mb-0">Period</label>
            <select class="form-select form-select-sm" name="period_type" onchange="togglePeriodFields(this.value)">
                <option value="monthly" <?= $periodType === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                <option value="quarterly" <?= $periodType === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                <option value="yearly" <?= $periodType === 'yearly' ? 'selected' : '' ?>>Yearly</option>
            </select>
        </div>
        <div>
            <label class="form-label small mb-0">Year</label>
            <select class="form-select form-select-sm" name="year">
                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div id="monthField" <?= $periodType !== 'monthly' ? 'style="display:none"' : '' ?>>
            <label class="form-label small mb-0">Month</label>
            <select class="form-select form-select-sm" name="month">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div id="quarterField" <?= $periodType !== 'quarterly' ? 'style="display:none"' : '' ?>>
            <label class="form-label small mb-0">Quarter</label>
            <select class="form-select form-select-sm" name="quarter">
                <?php for ($q = 1; $q <= 4; $q++): ?>
                <option value="<?= $q ?>" <?= $quarter === $q ? 'selected' : '' ?>>Q<?= $q ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Generate</button>
    </form>
    <button onclick="window.print()" class="btn btn-sm btn-outline-dark no-print"><i class="bi bi-printer me-1"></i>Print</button>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-3">
    <div class="col-md-4 col-lg-2">
        <div class="card stat-card success">
            <div class="stat-label">Total Sales</div>
            <div class="stat-value"><?= formatCurrency($totalSales) ?></div>
            <small class="text-muted"><?= $saleCount ?> transactions</small>
        </div>
    </div>
    <div class="col-md-4 col-lg-2">
        <div class="card stat-card info">
            <div class="stat-label">VAT Collected</div>
            <div class="stat-value"><?= formatCurrency($vatCollected) ?></div>
            <small class="text-muted">From sales (<?= $vatRate ?>%)</small>
        </div>
    </div>
    <div class="col-md-4 col-lg-2">
        <div class="card stat-card warning">
            <div class="stat-label">VAT Paid</div>
            <div class="stat-value"><?= formatCurrency($vatPaid) ?></div>
            <small class="text-muted">On purchases</small>
        </div>
    </div>
    <div class="col-md-4 col-lg-2">
        <div class="card stat-card <?= $netVat >= 0 ? 'danger' : 'success' ?>">
            <div class="stat-label">Net VAT Payable</div>
            <div class="stat-value"><?= formatCurrency(abs($netVat)) ?></div>
            <small class="text-muted"><?= $netVat >= 0 ? 'Owed to authority' : 'Credit/refund' ?></small>
        </div>
    </div>
    <div class="col-md-4 col-lg-2">
        <div class="card stat-card">
            <div class="stat-label">Taxable Sales</div>
            <div class="stat-value"><?= formatCurrency($taxableSales) ?></div>
            <small class="text-muted"><?= $totalSales > 0 ? round($taxableSales / $totalSales * 100, 1) : 0 ?>% of total</small>
        </div>
    </div>
    <div class="col-md-4 col-lg-2">
        <div class="card stat-card">
            <div class="stat-label">Exempt Sales</div>
            <div class="stat-value"><?= formatCurrency($exemptSales) ?></div>
            <small class="text-muted">Subsidized items</small>
        </div>
    </div>
</div>

<!-- Printable Tax Filing Header -->
<div class="card p-4 mb-3" id="printableReport">
    <div class="text-center mb-4">
        <h4><?= sanitize($pharmacyName) ?></h4>
        <?php if ($pharmacyAddress): ?><p class="text-muted mb-1"><?= sanitize($pharmacyAddress) ?></p><?php endif; ?>
        <?php if ($pharmacyTIN): ?><p class="text-muted mb-1">TIN: <?= sanitize($pharmacyTIN) ?></p><?php endif; ?>
        <h5 class="text-primary">Tax Report (TVA) - <?= sanitize($periodLabel) ?></h5>
        <p class="text-muted mb-0">Period: <?= formatDate($dateFrom, 'M d, Y') ?> - <?= formatDate($dateTo, 'M d, Y') ?></p>
        <p class="text-muted">Lebanese VAT Rate: <?= $vatRate ?>% | Exchange Rate: <?= number_format($exchangeRate, 0, '.', ',') ?> LBP/USD</p>
    </div>

    <!-- Tax Liability Summary -->
    <h6 class="text-primary border-bottom pb-2 mb-3"><i class="bi bi-calculator me-2"></i>Tax Liability Summary</h6>
    <div class="row mb-4">
        <div class="col-md-8">
            <table class="table table-bordered">
                <thead class="table-light">
                    <tr><th>Description</th><th class="text-end">Amount (USD)</th><th class="text-end">Amount (LBP)</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Total Gross Sales</td>
                        <td class="text-end"><?= formatCurrency($totalSales) ?></td>
                        <td class="text-end"><?= formatCurrency($totalSales * $exchangeRate, 'LBP') ?></td>
                    </tr>
                    <tr>
                        <td class="ps-4">- Taxable Sales (<?= $vatRate ?>%)</td>
                        <td class="text-end"><?= formatCurrency($taxableSales) ?></td>
                        <td class="text-end"><?= formatCurrency($taxableSales * $exchangeRate, 'LBP') ?></td>
                    </tr>
                    <tr>
                        <td class="ps-4">- Exempt Sales (Subsidized)</td>
                        <td class="text-end"><?= formatCurrency($exemptSales) ?></td>
                        <td class="text-end"><?= formatCurrency($exemptSales * $exchangeRate, 'LBP') ?></td>
                    </tr>
                    <tr class="table-success">
                        <td><strong>VAT Collected (Output Tax)</strong></td>
                        <td class="text-end fw-bold"><?= formatCurrency($vatCollected) ?></td>
                        <td class="text-end fw-bold"><?= formatCurrency($vatCollected * $exchangeRate, 'LBP') ?></td>
                    </tr>
                    <tr>
                        <td>Total Purchases</td>
                        <td class="text-end"><?= formatCurrency($totalPurchases) ?></td>
                        <td class="text-end"><?= formatCurrency($totalPurchases * $exchangeRate, 'LBP') ?></td>
                    </tr>
                    <tr class="table-warning">
                        <td><strong>VAT Paid (Input Tax)</strong></td>
                        <td class="text-end fw-bold"><?= formatCurrency($vatPaid) ?></td>
                        <td class="text-end fw-bold"><?= formatCurrency($vatPaid * $exchangeRate, 'LBP') ?></td>
                    </tr>
                    <tr class="table-danger">
                        <td><strong>Net VAT Payable</strong></td>
                        <td class="text-end fw-bold fs-5"><?= formatCurrency(abs($netVat)) ?></td>
                        <td class="text-end fw-bold fs-5"><?= formatCurrency(abs($netVat) * $exchangeRate, 'LBP') ?></td>
                    </tr>
                    <tr>
                        <td colspan="3" class="text-center">
                            <?php if ($netVat >= 0): ?>
                            <span class="badge bg-danger">Amount owed to Lebanese Tax Authority</span>
                            <?php else: ?>
                            <span class="badge bg-success">VAT Credit - Carry forward or request refund</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="col-md-4">
            <div class="card bg-light p-3">
                <h6 class="mb-2"><i class="bi bi-info-circle me-1"></i>Tax Rate Breakdown</h6>
                <table class="table table-sm mb-0">
                    <tr><td>Standard Rate (TVA)</td><td class="text-end fw-bold"><?= $vatRate ?>%</td></tr>
                    <tr><td>Reduced Rate</td><td class="text-end">N/A</td></tr>
                    <tr><td>Zero Rate (Exempt)</td><td class="text-end">0%</td></tr>
                </table>
                <hr>
                <small class="text-muted">Lebanese VAT (TVA) is governed by Law No. 379 of December 14, 2001. Subsidized medicines are exempt from VAT per MoPH directives.</small>
            </div>
        </div>
    </div>

    <!-- Monthly Sales Tax Breakdown -->
    <h6 class="text-primary border-bottom pb-2 mb-3"><i class="bi bi-calendar3 me-2"></i>Monthly Sales Tax Summary</h6>
    <div class="table-responsive mb-4">
        <table class="table table-bordered table-sm">
            <thead class="table-light">
                <tr>
                    <th>Month</th>
                    <th class="text-end">Gross Sales</th>
                    <th class="text-end">Taxable Sales</th>
                    <th class="text-end">Exempt Sales</th>
                    <th class="text-end">VAT Collected</th>
                    <th class="text-end">VAT Collected (LBP)</th>
                    <th class="text-end">Transactions</th>
                </tr>
            </thead>
            <tbody>
                <?php $sumSales = 0; $sumTaxable = 0; $sumExempt = 0; $sumVat = 0; $sumTx = 0; ?>
                <?php foreach ($monthlySales as $ms): ?>
                <?php
                    $sumSales += floatval($ms['total_sales']);
                    $sumTaxable += floatval($ms['taxable_sales']);
                    $sumExempt += floatval($ms['exempt_sales']);
                    $sumVat += floatval($ms['vat_collected']);
                    $sumTx += intval($ms['sale_count']);
                ?>
                <tr>
                    <td><?= formatDate($ms['month_date'], 'F Y') ?></td>
                    <td class="text-end"><?= formatCurrency($ms['total_sales']) ?></td>
                    <td class="text-end"><?= formatCurrency($ms['taxable_sales']) ?></td>
                    <td class="text-end"><?= formatCurrency($ms['exempt_sales']) ?></td>
                    <td class="text-end text-success fw-bold"><?= formatCurrency($ms['vat_collected']) ?></td>
                    <td class="text-end"><small><?= formatCurrency(floatval($ms['vat_collected']) * $exchangeRate, 'LBP') ?></small></td>
                    <td class="text-end"><?= $ms['sale_count'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($monthlySales)): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">No sales data for this period</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot class="table-light">
                <tr class="fw-bold">
                    <td>Total</td>
                    <td class="text-end"><?= formatCurrency($sumSales) ?></td>
                    <td class="text-end"><?= formatCurrency($sumTaxable) ?></td>
                    <td class="text-end"><?= formatCurrency($sumExempt) ?></td>
                    <td class="text-end text-success"><?= formatCurrency($sumVat) ?></td>
                    <td class="text-end"><small><?= formatCurrency($sumVat * $exchangeRate, 'LBP') ?></small></td>
                    <td class="text-end"><?= $sumTx ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Monthly Purchase Tax Breakdown -->
    <h6 class="text-primary border-bottom pb-2 mb-3"><i class="bi bi-cart3 me-2"></i>Monthly Purchase Tax Summary</h6>
    <div class="table-responsive mb-4">
        <table class="table table-bordered table-sm">
            <thead class="table-light">
                <tr>
                    <th>Month</th>
                    <th class="text-end">Total Purchases</th>
                    <th class="text-end">VAT Paid (<?= $vatRate ?>%)</th>
                    <th class="text-end">VAT Paid (LBP)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $sumPurch = 0;
                $sumPVat = 0;
                // Build month list from sales months + purchase months
                $allMonths = array_unique(array_merge(
                    array_column($monthlySales, 'month_key'),
                    array_keys($monthlyPurchases)
                ));
                sort($allMonths);
                foreach ($allMonths as $mk):
                    $pTotal = $monthlyPurchases[$mk] ?? 0;
                    $pVat = $pTotal * $vatRate / 100;
                    $sumPurch += $pTotal;
                    $sumPVat += $pVat;
                ?>
                <tr>
                    <td><?= formatDate($mk . '-01', 'F Y') ?></td>
                    <td class="text-end"><?= formatCurrency($pTotal) ?></td>
                    <td class="text-end text-danger fw-bold"><?= formatCurrency($pVat) ?></td>
                    <td class="text-end"><small><?= formatCurrency($pVat * $exchangeRate, 'LBP') ?></small></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($allMonths)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No purchase data for this period</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot class="table-light">
                <tr class="fw-bold">
                    <td>Total</td>
                    <td class="text-end"><?= formatCurrency($sumPurch) ?></td>
                    <td class="text-end text-danger"><?= formatCurrency($sumPVat) ?></td>
                    <td class="text-end"><small><?= formatCurrency($sumPVat * $exchangeRate, 'LBP') ?></small></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Exempt Items -->
    <?php if (!empty($exemptItems)): ?>
    <h6 class="text-primary border-bottom pb-2 mb-3"><i class="bi bi-shield-check me-2"></i>VAT-Exempt Items (Subsidized Medicines)</h6>
    <div class="table-responsive mb-4">
        <table class="table table-bordered table-sm">
            <thead class="table-light">
                <tr><th>#</th><th>Medicine</th><th class="text-end">Qty Sold</th><th class="text-end">Revenue (USD)</th><th class="text-end">Revenue (LBP)</th></tr>
            </thead>
            <tbody>
                <?php foreach ($exemptItems as $i => $ei): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= sanitize($ei['name']) ?></td>
                    <td class="text-end"><?= number_format($ei['qty_sold']) ?></td>
                    <td class="text-end"><?= formatCurrency($ei['revenue']) ?></td>
                    <td class="text-end"><small><?= formatCurrency(floatval($ei['revenue']) * $exchangeRate, 'LBP') ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="text-center text-muted mt-4 pt-3 border-top">
        <small>Report generated on <?= date('M d, Y H:i:s') ?> | <?= sanitize($pharmacyName) ?></small><br>
        <small>This report is prepared in accordance with Lebanese TVA regulations (Law No. 379/2001)</small>
    </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-graph-up me-2"></i>Monthly VAT Trend</h6>
            <canvas id="vatTrendChart" height="100"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-pie-chart me-2"></i>Tax Collection Breakdown</h6>
            <canvas id="taxPieChart" height="200"></canvas>
        </div>
    </div>
</div>

<?php
$extraScripts = "<script>
function togglePeriodFields(type) {
    document.getElementById('monthField').style.display = type === 'monthly' ? '' : 'none';
    document.getElementById('quarterField').style.display = type === 'quarterly' ? '' : 'none';
}

// Monthly VAT Trend
new Chart(document.getElementById('vatTrendChart'), {
    type: 'bar',
    data: {
        labels: " . json_encode($chartLabels) . ",
        datasets: [
            {
                label: 'VAT Collected',
                data: " . json_encode($chartVatCollected) . ",
                backgroundColor: 'rgba(25,135,84,0.7)',
                borderColor: '#198754',
                borderWidth: 1
            },
            {
                label: 'VAT Paid',
                data: " . json_encode($chartVatPaid) . ",
                backgroundColor: 'rgba(220,53,69,0.7)',
                borderColor: '#dc3545',
                borderWidth: 1
            },
            {
                label: 'Net VAT',
                data: " . json_encode($chartNetVat) . ",
                type: 'line',
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13,110,253,0.1)',
                fill: true,
                tension: 0.3,
                pointBackgroundColor: '#0d6efd'
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true, ticks: { callback: function(v) { return '\$' + v.toLocaleString(); } } } }
    }
});

// Tax Collection Pie
new Chart(document.getElementById('taxPieChart'), {
    type: 'doughnut',
    data: {
        labels: " . json_encode($pieLabels) . ",
        datasets: [{
            data: " . json_encode($pieValues) . ",
            backgroundColor: ['#198754', '#dc3545', '#6c757d']
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } }
    }
});
</script>";

require_once __DIR__ . '/../../includes/footer.php';
?>
