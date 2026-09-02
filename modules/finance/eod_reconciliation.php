<?php
$pageTitle = 'End-of-Day Reconciliation';
require_once __DIR__ . '/../../includes/header.php';

if (!hasRole('pharmacist')) {
    echo '<div class="alert alert-danger">Access denied. Pharmacist or Admin role required.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$db = getDB();
$user = currentUser();
$today = date('Y-m-d');
$exchangeRate = floatval(getSetting('exchange_rate', '89500'));
$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');

// ---- Auto-calculated summary ----
$totalSalesCount = 0;
$totalUSDCash    = 0;
$totalLBPCash    = 0;
$totalCard       = 0;
$totalCredit     = 0;
$totalDiscounts  = 0;
$top5Medicines   = [];

try {
    $totalSalesCount = (int)$db->query("SELECT COUNT(*) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed'")->fetchColumn();

    // Cash in USD
    $totalUSDCash = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed' AND payment_method = 'cash' AND currency = 'USD'")->fetchColumn();

    // Cash in LBP (convert each sale using its own exchange_rate)
    $totalLBPCash = (float)$db->query("SELECT COALESCE(SUM(total_amount / NULLIF(exchange_rate,0)),0) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed' AND payment_method = 'cash' AND currency = 'LBP'")->fetchColumn();

    // Card (any currency → USD equivalent)
    $cardUSD = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed' AND payment_method = 'card' AND currency = 'USD'")->fetchColumn();
    $cardLBP = (float)$db->query("SELECT COALESCE(SUM(total_amount / NULLIF(exchange_rate,0)),0) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed' AND payment_method = 'card' AND currency = 'LBP'")->fetchColumn();
    $totalCard = $cardUSD + $cardLBP;

    // Credit / unpaid
    $creditUSD = (float)$db->query("SELECT COALESCE(SUM(total_amount - amount_paid),0) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed' AND payment_method = 'credit' AND currency = 'USD'")->fetchColumn();
    $creditLBP = (float)$db->query("SELECT COALESCE(SUM((total_amount - amount_paid) / NULLIF(exchange_rate,0)),0) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed' AND payment_method = 'credit' AND currency = 'LBP'")->fetchColumn();
    $totalCredit = $creditUSD + $creditLBP;

    // Discounts
    $totalDiscounts = (float)$db->query("SELECT COALESCE(SUM(discount_amount / NULLIF(exchange_rate,0)),0) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed' AND currency = 'LBP'")->fetchColumn();
    $totalDiscounts += (float)$db->query("SELECT COALESCE(SUM(discount_amount),0) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed' AND currency = 'USD'")->fetchColumn();

    // Top 5 medicines
    $top5Medicines = $db->query("SELECT m.name, SUM(si.quantity) as qty_sold
        FROM sale_items si
        JOIN medicines m ON si.medicine_id = m.id
        JOIN sales s ON si.sale_id = s.id
        WHERE DATE(s.sale_date) = CURDATE() AND s.status = 'completed'
        GROUP BY m.id, m.name
        ORDER BY qty_sold DESC
        LIMIT 5")->fetchAll();
} catch (Exception $e) {}

$expectedCashUSD = $totalUSDCash + $totalLBPCash; // LBP cash already converted to USD

// ---- Handle POST: save EOD record ----
$saveSuccess = false;
$saveError   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_eod') {
    try {
        // Create table if not exists
        $db->exec("CREATE TABLE IF NOT EXISTS eod_records (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            `date`       DATE NOT NULL,
            total_sales  INT DEFAULT 0,
            total_usd    DECIMAL(12,2) DEFAULT 0,
            total_lbp    DECIMAL(14,0) DEFAULT 0,
            counted_usd  DECIMAL(12,2) DEFAULT 0,
            counted_lbp  DECIMAL(14,0) DEFAULT 0,
            difference_usd DECIMAL(12,2) DEFAULT 0,
            notes        TEXT,
            created_by   INT,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $cUSD = floatval($_POST['counted_usd'] ?? 0);
        $cLBP = floatval($_POST['counted_lbp'] ?? 0);
        $cUSDtotal = $cUSD + ($cLBP / $exchangeRate);
        $diff = $cUSDtotal - $expectedCashUSD;

        $stmt = $db->prepare("INSERT INTO eod_records (`date`, total_sales, total_usd, total_lbp, counted_usd, counted_lbp, difference_usd, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $today,
            $totalSalesCount,
            $expectedCashUSD,
            $totalLBPCash * $exchangeRate,
            $cUSD,
            $cLBP,
            $diff,
            trim($_POST['notes'] ?? ''),
            $user['id']
        ]);

        $saveSuccess = true;
        // Pass data to print via session
        $_SESSION['eod_print'] = [
            'date'           => $today,
            'pharmacy_name'  => $pharmacyName,
            'total_sales'    => $totalSalesCount,
            'total_usd_cash' => $totalUSDCash,
            'total_lbp_cash' => $totalLBPCash,
            'total_card'     => $totalCard,
            'total_credit'   => $totalCredit,
            'total_discounts'=> $totalDiscounts,
            'expected_usd'   => $expectedCashUSD,
            'counted_usd'    => $cUSD,
            'counted_lbp'    => $cLBP,
            'counted_total'  => $cUSDtotal,
            'difference'     => $diff,
            'notes'          => trim($_POST['notes'] ?? ''),
            'created_by'     => $user['full_name'],
            'top5'           => $top5Medicines,
            'usd_denoms'     => $_POST['usd_denoms'] ?? [],
            'lbp_denoms'     => $_POST['lbp_denoms'] ?? [],
        ];
    } catch (Exception $e) {
        $saveError = $e->getMessage();
    }
}

// ---- Handle print view ----
if (isset($_GET['print']) && isset($_SESSION['eod_print'])) {
    $p = $_SESSION['eod_print'];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>EOD Report – <?= htmlspecialchars($p['date']) ?></title>
<style>
body { font-family: Arial, sans-serif; font-size: 12px; color: #111; margin: 20px; }
h1 { font-size: 18px; margin: 0 0 4px; }
h2 { font-size: 14px; margin: 16px 0 6px; border-bottom: 1px solid #999; padding-bottom: 3px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
th, td { border: 1px solid #ccc; padding: 4px 8px; text-align: left; }
th { background: #f0f0f0; }
.right { text-align: right; }
.sig { margin-top: 50px; display: flex; gap: 80px; }
.sig-line { border-top: 1px solid #333; width: 200px; padding-top: 4px; }
.over { color: green; } .short { color: red; }
@media print { button { display: none; } }
</style>
</head>
<body>
<div style="text-align:center;margin-bottom:12px">
    <h1><?= htmlspecialchars($p['pharmacy_name']) ?></h1>
    <div>End-of-Day Reconciliation Report</div>
    <div><?= date('l, F d, Y', strtotime($p['date'])) ?></div>
    <div style="font-size:10px;color:#666">Prepared by: <?= htmlspecialchars($p['created_by']) ?> &nbsp;|&nbsp; Printed: <?= date('H:i') ?></div>
</div>
<button onclick="window.print()" style="margin-bottom:12px;padding:6px 18px">Print</button>

<h2>Sales Summary</h2>
<table>
<tr><th>Metric</th><th class="right">Amount</th></tr>
<tr><td>Total Sales (transactions)</td><td class="right"><?= (int)$p['total_sales'] ?></td></tr>
<tr><td>Cash Collected (USD)</td><td class="right">$<?= number_format($p['total_usd_cash'],2) ?></td></tr>
<tr><td>Cash Collected (LBP equivalent USD)</td><td class="right">$<?= number_format($p['total_lbp_cash'],2) ?></td></tr>
<tr><td>Card Payments (USD equiv.)</td><td class="right">$<?= number_format($p['total_card'],2) ?></td></tr>
<tr><td>Credit / Unpaid (USD equiv.)</td><td class="right">$<?= number_format($p['total_credit'],2) ?></td></tr>
<tr><td>Total Discounts Given (USD equiv.)</td><td class="right">$<?= number_format($p['total_discounts'],2) ?></td></tr>
</table>

<h2>Top 5 Medicines Sold Today</h2>
<table>
<tr><th>#</th><th>Medicine</th><th class="right">Qty Sold</th></tr>
<?php foreach ($p['top5'] as $i => $m): ?>
<tr><td><?= $i+1 ?></td><td><?= htmlspecialchars($m['name']) ?></td><td class="right"><?= (int)$m['qty_sold'] ?></td></tr>
<?php endforeach; ?>
<?php if (empty($p['top5'])): ?><tr><td colspan="3" style="color:#999;text-align:center">No sales today</td></tr><?php endif; ?>
</table>

<h2>Cash Count</h2>
<table>
<tr><th>Denomination</th><th class="right">Qty</th><th class="right">Subtotal</th></tr>
<?php
$usdDenoms = [100,50,20,10,5,1];
foreach ($usdDenoms as $d):
    $qty = intval($p['usd_denoms'][$d] ?? 0);
    if ($qty > 0):
?>
<tr><td>$<?= $d ?></td><td class="right"><?= $qty ?></td><td class="right">$<?= number_format($qty * $d, 2) ?></td></tr>
<?php endif; endforeach; ?>
<?php
$lbpDenoms = [500000,100000,50000,20000,10000,5000,1000];
foreach ($lbpDenoms as $d):
    $qty = intval($p['lbp_denoms'][$d] ?? 0);
    if ($qty > 0):
?>
<tr><td><?= number_format($d) ?> L.L.</td><td class="right"><?= $qty ?></td><td class="right"><?= number_format($qty * $d) ?> L.L.</td></tr>
<?php endif; endforeach; ?>
</table>

<table>
<tr><th>Expected Cash (USD)</th><td class="right">$<?= number_format($p['expected_usd'],2) ?></td></tr>
<tr><th>Counted Cash (USD equiv.)</th><td class="right">$<?= number_format($p['counted_total'],2) ?></td></tr>
<tr>
    <th>Difference</th>
    <td class="right <?= $p['difference'] >= 0 ? 'over' : 'short' ?>">
        <?= $p['difference'] >= 0 ? 'OVER' : 'SHORT' ?> $<?= number_format(abs($p['difference']),2) ?>
    </td>
</tr>
</table>

<?php if (!empty($p['notes'])): ?>
<h2>Notes</h2>
<div style="border:1px solid #ccc;padding:8px;border-radius:4px"><?= nl2br(htmlspecialchars($p['notes'])) ?></div>
<?php endif; ?>

<div class="sig">
    <div><div class="sig-line">Pharmacist Signature</div></div>
    <div><div class="sig-line">Manager Signature</div></div>
</div>
</body>
</html>
<?php
    exit;
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-cash-stack me-2 text-success"></i>End-of-Day Reconciliation</h4>
    <div class="text-muted small"><?= date('l, F d, Y') ?></div>
</div>

<?php if ($saveSuccess): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i>EOD record saved successfully.
    <a href="?print=1" target="_blank" class="btn btn-sm btn-outline-success ms-3"><i class="bi bi-printer me-1"></i>Print Report</a>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($saveError): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= sanitize($saveError) ?></div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════ SUMMARY SECTION -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="text-muted small mb-1"><i class="bi bi-receipt me-1"></i>Total Sales</div>
                <div class="fs-2 fw-bold text-primary"><?= $totalSalesCount ?></div>
                <div class="text-muted" style="font-size:.75rem">transactions today</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="text-muted small mb-1"><i class="bi bi-currency-dollar me-1"></i>USD Cash</div>
                <div class="fs-3 fw-bold text-success"><?= formatCurrency($totalUSDCash) ?></div>
                <div class="text-muted" style="font-size:.75rem">LBP equiv. <?= formatCurrency($totalLBPCash) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="text-muted small mb-1"><i class="bi bi-credit-card me-1"></i>Card Payments</div>
                <div class="fs-3 fw-bold text-info"><?= formatCurrency($totalCard) ?></div>
                <div class="text-muted" style="font-size:.75rem">Credit: <?= formatCurrency($totalCredit) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="text-muted small mb-1"><i class="bi bi-tag me-1"></i>Discounts Given</div>
                <div class="fs-3 fw-bold text-warning"><?= formatCurrency($totalDiscounts) ?></div>
                <div class="text-muted" style="font-size:.75rem">total discounts today</div>
            </div>
        </div>
    </div>
</div>

<!-- Top 5 medicines -->
<?php if (!empty($top5Medicines)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-trophy me-2 text-warning"></i>Top 5 Medicines Sold Today</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>#</th><th>Medicine</th><th class="text-end">Qty Sold</th></tr></thead>
            <tbody>
            <?php foreach ($top5Medicines as $i => $med): ?>
            <tr>
                <td><span class="badge bg-warning text-dark"><?= $i+1 ?></span></td>
                <td><?= sanitize($med['name']) ?></td>
                <td class="text-end fw-semibold"><?= (int)$med['qty_sold'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════ CASH COUNT FORM -->
<form method="POST" id="eodForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_eod">

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-cash-coin me-2 text-success"></i>Cash Count</div>
        <div class="card-body">
            <div class="row g-4">
                <!-- USD Denominations -->
                <div class="col-md-6">
                    <h6 class="mb-3 text-success"><i class="bi bi-currency-dollar me-1"></i>USD Bills</h6>
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr><th>Denomination</th><th style="width:110px">Quantity</th><th class="text-end">Subtotal</th></tr>
                        </thead>
                        <tbody id="usdTable">
                        <?php foreach ([100,50,20,10,5,1] as $denom): ?>
                        <tr>
                            <td class="fw-semibold">$<?= $denom ?></td>
                            <td><input type="number" class="form-control form-control-sm usd-qty" name="usd_denoms[<?= $denom ?>]" data-val="<?= $denom ?>" min="0" value="0" placeholder="0"></td>
                            <td class="text-end usd-sub" id="usub_<?= $denom ?>">$0.00</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-success fw-bold">
                                <td colspan="2">USD Total</td>
                                <td class="text-end" id="usdTotal">$0.00</td>
                            </tr>
                        </tfoot>
                    </table>
                    <input type="hidden" name="counted_usd" id="hiddenCountedUSD" value="0">
                </div>

                <!-- LBP Denominations -->
                <div class="col-md-6">
                    <h6 class="mb-3 text-danger"><i class="bi bi-bank me-1"></i>LBP Notes</h6>
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr><th>Denomination</th><th style="width:110px">Quantity</th><th class="text-end">Subtotal</th></tr>
                        </thead>
                        <tbody id="lbpTable">
                        <?php foreach ([500000,100000,50000,20000,10000,5000,1000] as $denom): ?>
                        <tr>
                            <td class="fw-semibold"><?= number_format($denom) ?> L.L.</td>
                            <td><input type="number" class="form-control form-control-sm lbp-qty" name="lbp_denoms[<?= $denom ?>]" data-val="<?= $denom ?>" min="0" value="0" placeholder="0"></td>
                            <td class="text-end lbp-sub" id="lsub_<?= $denom ?>">0 L.L.</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-danger fw-bold">
                                <td colspan="2">LBP Total</td>
                                <td class="text-end" id="lbpTotal">0 L.L.</td>
                            </tr>
                        </tfoot>
                    </table>
                    <input type="hidden" name="counted_lbp" id="hiddenCountedLBP" value="0">
                </div>
            </div>

            <!-- Over/Short summary -->
            <div class="row g-3 mt-1">
                <div class="col-md-4">
                    <div class="card bg-light border-0">
                        <div class="card-body py-2 px-3 text-center">
                            <div class="text-muted small">Expected Cash (USD)</div>
                            <div class="fs-5 fw-bold text-secondary"><?= formatCurrency($expectedCashUSD) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-light border-0">
                        <div class="card-body py-2 px-3 text-center">
                            <div class="text-muted small">Counted Cash (USD equiv.)</div>
                            <div class="fs-5 fw-bold text-primary" id="countedTotal">$0.00</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0" id="diffCard">
                        <div class="card-body py-2 px-3 text-center">
                            <div class="text-muted small">Difference (Over/Short)</div>
                            <div class="fs-5 fw-bold" id="diffDisplay">—</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notes -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-journal-text me-2"></i>End-of-Day Notes</div>
        <div class="card-body">
            <textarea class="form-control" name="notes" rows="3" placeholder="Any remarks, incidents, or notes for the day..."></textarea>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-success px-4"><i class="bi bi-save me-2"></i>Save & Print</button>
        <a href="<?= BASE_URL ?>/modules/finance/daily_summary.php" class="btn btn-outline-secondary">View Daily Summary</a>
    </div>
</form>

<script>
const exchangeRate = <?= $exchangeRate ?>;
const expected = <?= $expectedCashUSD ?>;

function formatUSD(n) {
    return '$' + n.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function formatLBP(n) {
    return Math.round(n).toLocaleString('en-US') + ' L.L.';
}

function recalc() {
    let usdSum = 0;
    document.querySelectorAll('.usd-qty').forEach(inp => {
        const val = parseFloat(inp.dataset.val);
        const qty = parseInt(inp.value) || 0;
        const sub = val * qty;
        usdSum += sub;
        document.getElementById('usub_' + val).textContent = formatUSD(sub);
    });

    let lbpSum = 0;
    document.querySelectorAll('.lbp-qty').forEach(inp => {
        const val = parseFloat(inp.dataset.val);
        const qty = parseInt(inp.value) || 0;
        const sub = val * qty;
        lbpSum += sub;
        document.getElementById('lsub_' + val).textContent = formatLBP(sub);
    });

    document.getElementById('usdTotal').textContent = formatUSD(usdSum);
    document.getElementById('lbpTotal').textContent = formatLBP(lbpSum);

    const lbpInUSD = lbpSum / exchangeRate;
    const countedTotal = usdSum + lbpInUSD;
    document.getElementById('countedTotal').textContent = formatUSD(countedTotal);

    document.getElementById('hiddenCountedUSD').value = usdSum.toFixed(2);
    document.getElementById('hiddenCountedLBP').value = Math.round(lbpSum);

    const diff = countedTotal - expected;
    const diffEl = document.getElementById('diffDisplay');
    const diffCard = document.getElementById('diffCard');
    if (diff >= 0) {
        diffEl.textContent = 'OVER ' + formatUSD(Math.abs(diff));
        diffEl.className = 'fs-5 fw-bold text-success';
        diffCard.className = 'card border-0 bg-light';
    } else {
        diffEl.textContent = 'SHORT ' + formatUSD(Math.abs(diff));
        diffEl.className = 'fs-5 fw-bold text-danger';
        diffCard.className = 'card border-0 bg-danger bg-opacity-10';
    }
}

document.querySelectorAll('.usd-qty, .lbp-qty').forEach(inp => {
    inp.addEventListener('input', recalc);
});

// On save+print, open print window after submit
document.getElementById('eodForm').addEventListener('submit', function() {
    setTimeout(() => window.open('?print=1', '_blank'), 800);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
