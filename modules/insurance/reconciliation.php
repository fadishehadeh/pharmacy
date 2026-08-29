<?php
$pageTitle = 'Insurance Reconciliation';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { header('Location: ' . BASE_URL); exit; }
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));

// POST: Mark claims as paid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid'])) {
    $claimIds = $_POST['claim_ids'] ?? [];
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $paymentRef = $_POST['payment_reference'] ?? '';

    if (!empty($claimIds)) {
        $stmt = $db->prepare("UPDATE insurance_claims SET status = 'paid', payment_date = ?, payment_reference = ?, payment_amount = covered_amount WHERE id = ? AND status IN ('approved','submitted')");
        $count = 0;
        foreach ($claimIds as $claimId) {
            $claimId = intval($claimId);
            $stmt->execute([$paymentDate, $paymentRef, $claimId]);
            if ($stmt->rowCount() > 0) {
                addAuditLog('update', 'insurance_claims', $claimId, ['status' => 'approved'], ['status' => 'paid', 'payment_date' => $paymentDate, 'payment_reference' => $paymentRef]);
                $count++;
            }
        }
        flashMessage("$count claim(s) marked as paid with reference: " . ($paymentRef ?: 'N/A'));
    } else {
        flashMessage('No claims selected', 'error');
    }
    header('Location: reconciliation.php?' . http_build_query(array_filter(['provider' => $_POST['provider_id'] ?? '', 'status' => $_POST['current_status'] ?? ''])));
    exit;
}

// Filters
$statusFilter = $_GET['status'] ?? '';
$providerFilter = $_GET['provider'] ?? '';
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-90 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// All providers
$providers = $db->query("SELECT * FROM insurance_providers WHERE is_active = 1 ORDER BY name")->fetchAll();

// Provider summaries
$providerSummaries = $db->prepare("SELECT
    ip.id, ip.name, ip.type,
    COUNT(ic.id) as total_claims,
    COALESCE(SUM(ic.total_amount), 0) as total_amount,
    COALESCE(SUM(CASE WHEN ic.status = 'approved' THEN ic.covered_amount ELSE 0 END), 0) as approved_amount,
    COALESCE(SUM(CASE WHEN ic.status = 'paid' THEN ic.payment_amount ELSE 0 END), 0) as paid_amount,
    COALESCE(SUM(CASE WHEN ic.status IN ('submitted','approved') THEN ic.covered_amount ELSE 0 END), 0) as outstanding
    FROM insurance_providers ip
    LEFT JOIN insurance_claims ic ON ic.insurance_provider_id = ip.id AND DATE(ic.claim_date) BETWEEN ? AND ?
    WHERE ip.is_active = 1
    GROUP BY ip.id
    ORDER BY outstanding DESC");
$providerSummaries->execute([$startDate, $endDate]);
$providerSummaries = $providerSummaries->fetchAll();

// Total outstanding
$totalOutstanding = 0;
$totalClaimed = 0;
$totalPaid = 0;
$totalApproved = 0;
foreach ($providerSummaries as $ps) {
    $totalOutstanding += $ps['outstanding'];
    $totalClaimed += $ps['total_amount'];
    $totalPaid += $ps['paid_amount'];
    $totalApproved += $ps['approved_amount'];
}

// Claims list with filters
$claimWhere = ['DATE(ic.claim_date) BETWEEN ? AND ?'];
$claimParams = [$startDate, $endDate];
if ($statusFilter) { $claimWhere[] = 'ic.status = ?'; $claimParams[] = $statusFilter; }
if ($providerFilter) { $claimWhere[] = 'ic.insurance_provider_id = ?'; $claimParams[] = intval($providerFilter); }
$claimWhereStr = implode(' AND ', $claimWhere);

$claims = $db->prepare("SELECT ic.*, ip.name as provider_name, ip.type as provider_type,
    c.name as customer_name, s.invoice_number
    FROM insurance_claims ic
    JOIN insurance_providers ip ON ic.insurance_provider_id = ip.id
    LEFT JOIN customers c ON ic.customer_id = c.id
    LEFT JOIN sales s ON ic.sale_id = s.id
    WHERE $claimWhereStr
    ORDER BY ic.claim_date DESC");
$claims->execute($claimParams);
$claims = $claims->fetchAll();

// Aging report
$agingStmt = $db->query("SELECT
    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), ic.claim_date) BETWEEN 0 AND 30 THEN ic.covered_amount ELSE 0 END), 0) as age_0_30,
    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), ic.claim_date) BETWEEN 31 AND 60 THEN ic.covered_amount ELSE 0 END), 0) as age_31_60,
    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), ic.claim_date) BETWEEN 61 AND 90 THEN ic.covered_amount ELSE 0 END), 0) as age_61_90,
    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), ic.claim_date) > 90 THEN ic.covered_amount ELSE 0 END), 0) as age_over_90
    FROM insurance_claims ic
    WHERE ic.status IN ('submitted','approved')");
$aging = $agingStmt->fetch();

// Chart data: Claims by provider
$chartProviderLabels = array_map(function($p) { return $p['name']; }, $providerSummaries);
$chartProviderClaimed = array_map(function($p) { return round(floatval($p['total_amount']), 2); }, $providerSummaries);
$chartProviderPaid = array_map(function($p) { return round(floatval($p['paid_amount']), 2); }, $providerSummaries);

// Chart data: Claims by status
$statusData = $db->prepare("SELECT ic.status, COUNT(*) as cnt, COALESCE(SUM(ic.total_amount), 0) as total
    FROM insurance_claims ic
    WHERE DATE(ic.claim_date) BETWEEN ? AND ?
    GROUP BY ic.status ORDER BY total DESC");
$statusData->execute([$startDate, $endDate]);
$statusData = $statusData->fetchAll();

$statusLabels = array_map(function($s) { return ucfirst($s['status']); }, $statusData);
$statusValues = array_map(function($s) { return round(floatval($s['total']), 2); }, $statusData);

// Chart data: Monthly trend
$monthlyTrend = $db->prepare("SELECT DATE_FORMAT(ic.claim_date, '%Y-%m') as month,
    COALESCE(SUM(ic.total_amount), 0) as claimed,
    COALESCE(SUM(CASE WHEN ic.status = 'paid' THEN ic.payment_amount ELSE 0 END), 0) as paid
    FROM insurance_claims ic
    WHERE DATE(ic.claim_date) BETWEEN ? AND ?
    GROUP BY DATE_FORMAT(ic.claim_date, '%Y-%m')
    ORDER BY month");
$monthlyTrend->execute([$startDate, $endDate]);
$monthlyTrend = $monthlyTrend->fetchAll();

$monthLabels = array_map(function($m) { return formatDate($m['month'] . '-01', 'M Y'); }, $monthlyTrend);
$monthClaimed = array_map(function($m) { return round(floatval($m['claimed']), 2); }, $monthlyTrend);
$monthPaid = array_map(function($m) { return round(floatval($m['paid']), 2); }, $monthlyTrend);
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h6 class="mb-0"><i class="bi bi-check2-square me-2"></i>Insurance Reconciliation</h6>
    <button onclick="window.print()" class="btn btn-sm btn-outline-dark no-print"><i class="bi bi-printer me-1"></i>Print</button>
</div>

<!-- Filters -->
<div class="card p-3 mb-3 no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label small mb-0">From</label>
            <input type="date" class="form-control form-control-sm" name="start_date" value="<?= sanitize($startDate) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-0">To</label>
            <input type="date" class="form-control form-control-sm" name="end_date" value="<?= sanitize($endDate) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-0">Provider</label>
            <select class="form-select form-select-sm" name="provider">
                <option value="">All Providers</option>
                <?php foreach ($providers as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $providerFilter == $p['id'] ? 'selected' : '' ?>><?= sanitize($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-0">Status</label>
            <select class="form-select form-select-sm" name="status">
                <option value="">All</option>
                <option value="submitted" <?= $statusFilter === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
            </select>
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card info">
            <div class="stat-label">Total Claimed</div>
            <div class="stat-value"><?= formatCurrency($totalClaimed) ?></div>
            <small class="text-muted"><?= formatCurrency($totalClaimed * $exchangeRate, 'LBP') ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card success">
            <div class="stat-label">Total Paid</div>
            <div class="stat-value"><?= formatCurrency($totalPaid) ?></div>
            <small class="text-muted"><?= formatCurrency($totalPaid * $exchangeRate, 'LBP') ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="stat-label">Outstanding</div>
            <div class="stat-value"><?= formatCurrency($totalOutstanding) ?></div>
            <small class="text-muted"><?= formatCurrency($totalOutstanding * $exchangeRate, 'LBP') ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Collection Rate</div>
            <div class="stat-value"><?= $totalClaimed > 0 ? round($totalPaid / $totalClaimed * 100, 1) : 0 ?>%</div>
            <small class="text-muted">Paid / Claimed</small>
        </div>
    </div>
</div>

<!-- Aging Report -->
<div class="card p-3 mb-3">
    <h6 class="mb-3"><i class="bi bi-hourglass-split me-2"></i>Aging Report (Outstanding Claims)</h6>
    <div class="row g-3">
        <div class="col-md-3">
            <div class="border rounded p-2 text-center">
                <small class="text-muted d-block">0-30 Days</small>
                <strong class="text-success"><?= formatCurrency($aging['age_0_30']) ?></strong>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded p-2 text-center">
                <small class="text-muted d-block">31-60 Days</small>
                <strong class="text-warning"><?= formatCurrency($aging['age_31_60']) ?></strong>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded p-2 text-center">
                <small class="text-muted d-block">61-90 Days</small>
                <strong class="text-orange"><?= formatCurrency($aging['age_61_90']) ?></strong>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded p-2 text-center">
                <small class="text-muted d-block">&gt;90 Days</small>
                <strong class="text-danger"><?= formatCurrency($aging['age_over_90']) ?></strong>
            </div>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-bar-chart me-2"></i>Claims by Provider</h6>
            <canvas id="providerChart" height="200"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-pie-chart me-2"></i>Claims by Status</h6>
            <canvas id="statusChart" height="200"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-graph-up me-2"></i>Monthly Trend</h6>
            <canvas id="trendChart" height="200"></canvas>
        </div>
    </div>
</div>

<!-- Provider Summary Table -->
<div class="card mb-3">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-building me-2"></i>Summary by Provider</h6></div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead>
                <tr>
                    <th>Provider</th>
                    <th>Type</th>
                    <th class="text-end">Claims</th>
                    <th class="text-end">Total Amount</th>
                    <th class="text-end">Approved</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Outstanding</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($providerSummaries as $ps): ?>
                <?php if ($ps['total_claims'] == 0) continue; ?>
                <tr>
                    <td>
                        <a href="?provider=<?= $ps['id'] ?>&start_date=<?= sanitize($startDate) ?>&end_date=<?= sanitize($endDate) ?>">
                            <strong><?= sanitize($ps['name']) ?></strong>
                        </a>
                    </td>
                    <td><span class="badge bg-primary"><?= strtoupper(sanitize($ps['type'])) ?></span></td>
                    <td class="text-end"><?= $ps['total_claims'] ?></td>
                    <td class="text-end"><?= formatCurrency($ps['total_amount']) ?></td>
                    <td class="text-end"><?= formatCurrency($ps['approved_amount']) ?></td>
                    <td class="text-end text-success"><?= formatCurrency($ps['paid_amount']) ?></td>
                    <td class="text-end fw-semibold <?= $ps['outstanding'] > 0 ? 'text-danger' : '' ?>"><?= formatCurrency($ps['outstanding']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="table-active">
                    <td colspan="3"><strong>Total</strong></td>
                    <td class="text-end"><strong><?= formatCurrency($totalClaimed) ?></strong></td>
                    <td class="text-end"><strong><?= formatCurrency($totalApproved) ?></strong></td>
                    <td class="text-end text-success"><strong><?= formatCurrency($totalPaid) ?></strong></td>
                    <td class="text-end text-danger"><strong><?= formatCurrency($totalOutstanding) ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Claims Detail with Reconciliation -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Claims Detail</h6>
        <small class="text-muted"><?= count($claims) ?> claim(s)</small>
    </div>
    <form method="POST">
        <input type="hidden" name="provider_id" value="<?= sanitize($providerFilter) ?>">
        <input type="hidden" name="current_status" value="<?= sanitize($statusFilter) ?>">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 data-table">
                <thead>
                    <tr>
                        <th class="no-print"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                        <th>Claim #</th>
                        <th>Provider</th>
                        <th>Customer</th>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end">Covered</th>
                        <th>Status</th>
                        <th>Payment Date</th>
                        <th>Ref #</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($claims as $cl): ?>
                    <?php $statusColors = ['pending'=>'warning','submitted'=>'info','approved'=>'primary','rejected'=>'danger','paid'=>'success','partial'=>'secondary']; ?>
                    <tr>
                        <td class="no-print">
                            <?php if (in_array($cl['status'], ['submitted','approved'])): ?>
                            <input type="checkbox" name="claim_ids[]" value="<?= $cl['id'] ?>" class="form-check-input claim-checkbox">
                            <?php endif; ?>
                        </td>
                        <td><strong><?= sanitize($cl['claim_number']) ?></strong></td>
                        <td><small><?= sanitize($cl['provider_name']) ?></small></td>
                        <td><small><?= sanitize($cl['customer_name'] ?? '-') ?></small></td>
                        <td>
                            <?php if ($cl['invoice_number']): ?>
                            <a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $cl['sale_id'] ?>"><?= sanitize($cl['invoice_number']) ?></a>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td><small><?= formatDate($cl['claim_date'], 'M d, Y') ?></small></td>
                        <td class="text-end"><?= formatCurrency($cl['total_amount']) ?></td>
                        <td class="text-end"><?= formatCurrency($cl['covered_amount']) ?></td>
                        <td><span class="badge bg-<?= $statusColors[$cl['status']] ?? 'secondary' ?>"><?= ucfirst(sanitize($cl['status'])) ?></span></td>
                        <td><small><?= $cl['payment_date'] ? formatDate($cl['payment_date'], 'M d, Y') : '-' ?></small></td>
                        <td><small><?= sanitize($cl['payment_reference'] ?? '-') ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($claims)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-3">No claims found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($claims)): ?>
        <div class="card-footer no-print">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-0">Payment Date</label>
                    <input type="date" class="form-control form-control-sm" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-0">Payment Reference</label>
                    <input type="text" class="form-control form-control-sm" name="payment_reference" placeholder="Check/transfer #">
                </div>
                <div class="col-md-3">
                    <button type="submit" name="mark_paid" value="1" class="btn btn-success btn-sm" id="markPaidBtn" disabled>
                        <i class="bi bi-check-circle me-1"></i>Mark Selected as Paid
                    </button>
                </div>
                <div class="col-md-3 text-end">
                    <small class="text-muted">Selected: <strong id="selectedCount">0</strong> claim(s)</small>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php
$statusChartColors = ['#ffc107','#0dcaf0','#0d6efd','#dc3545','#198754','#6c757d'];
$providerColors = ['#0d6efd','#198754','#ffc107','#dc3545','#6f42c1','#0dcaf0','#fd7e14','#20c997','#d63384','#6610f2'];

$extraScripts = "<script>
// Select all / count
$('#selectAll').on('change', function() {
    $('.claim-checkbox').prop('checked', this.checked).trigger('change');
});
$('.claim-checkbox').on('change', function() {
    var count = $('.claim-checkbox:checked').length;
    $('#selectedCount').text(count);
    $('#markPaidBtn').prop('disabled', count === 0);
});

// Claims by Provider
new Chart(document.getElementById('providerChart'), {
    type: 'bar',
    data: {
        labels: " . json_encode($chartProviderLabels) . ",
        datasets: [
            { label: 'Claimed', data: " . json_encode($chartProviderClaimed) . ", backgroundColor: '#0d6efd' },
            { label: 'Paid', data: " . json_encode($chartProviderPaid) . ", backgroundColor: '#198754' }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true } }
    }
});

// Claims by Status
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: " . json_encode($statusLabels) . ",
        datasets: [{
            data: " . json_encode($statusValues) . ",
            backgroundColor: " . json_encode(array_slice($statusChartColors, 0, count($statusLabels))) . "
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } }
    }
});

// Monthly Trend
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: " . json_encode($monthLabels) . ",
        datasets: [
            {
                label: 'Claimed',
                data: " . json_encode($monthClaimed) . ",
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13,110,253,0.1)',
                fill: true,
                tension: 0.3
            },
            {
                label: 'Paid',
                data: " . json_encode($monthPaid) . ",
                borderColor: '#198754',
                backgroundColor: 'rgba(25,135,84,0.1)',
                fill: true,
                tension: 0.3
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true } }
    }
});
</script>";

require_once __DIR__ . '/../../includes/footer.php';
?>
