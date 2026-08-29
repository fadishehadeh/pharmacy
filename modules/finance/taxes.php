<?php
$pageTitle = 'Tax Management';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$vatRate = floatval(getSetting('vat_rate', 11));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $periodStart = $_POST['period_start'];
    $periodEnd = $_POST['period_end'];

    $totalSales = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sale_date) BETWEEN ? AND ? AND status = 'completed'");
    $totalSales->execute([$periodStart, $periodEnd]);
    $totalSales = $totalSales->fetchColumn();

    $totalPurchases = $db->prepare("SELECT COALESCE(SUM(total),0) FROM purchase_orders WHERE DATE(order_date) BETWEEN ? AND ? AND status != 'cancelled'");
    $totalPurchases->execute([$periodStart, $periodEnd]);
    $totalPurchases = $totalPurchases->fetchColumn();

    $vatCollected = $totalSales * $vatRate / 100;
    $vatPaid = $totalPurchases * $vatRate / 100;
    $netVat = $vatCollected - $vatPaid;

    $db->prepare("INSERT INTO tax_records (period_start, period_end, total_sales, total_purchases, vat_collected, vat_paid, net_vat, notes) VALUES (?,?,?,?,?,?,?,?)")->execute([
        $periodStart, $periodEnd, $totalSales, $totalPurchases, $vatCollected, $vatPaid, $netVat, $_POST['notes'] ?: null
    ]);
    flashMessage('Tax record generated');
    header('Location: taxes.php');
    exit;
}

$records = $db->query("SELECT * FROM tax_records ORDER BY period_start DESC")->fetchAll();
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-calculator me-2"></i>Generate Tax Report</h6>
            <form method="POST">
                <div class="mb-2"><label class="form-label small">Period Start</label><input type="date" class="form-control" name="period_start" required></div>
                <div class="mb-2"><label class="form-label small">Period End</label><input type="date" class="form-control" name="period_end" required></div>
                <div class="mb-3"><textarea class="form-control" name="notes" placeholder="Notes" rows="2"></textarea></div>
                <button type="submit" name="generate" value="1" class="btn btn-primary w-100">Generate</button>
            </form>
            <hr>
            <small class="text-muted">Current VAT Rate: <?= $vatRate ?>%</small><br>
            <small class="text-muted">Lebanese VAT (TVA) is applied at <?= $vatRate ?>% on pharmaceutical sales.</small>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Period</th><th>Sales</th><th>VAT Collected</th><th>VAT Paid</th><th>Net VAT</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                        <tr>
                            <td><?= formatDate($r['period_start'], 'M d') ?> - <?= formatDate($r['period_end'], 'M d, Y') ?></td>
                            <td><?= formatCurrency($r['total_sales']) ?></td>
                            <td class="text-success"><?= formatCurrency($r['vat_collected']) ?></td>
                            <td class="text-danger"><?= formatCurrency($r['vat_paid']) ?></td>
                            <td class="fw-bold"><?= formatCurrency($r['net_vat']) ?></td>
                            <td><span class="badge bg-<?= $r['status'] === 'paid' ? 'success' : ($r['status'] === 'filed' ? 'primary' : 'warning') ?>"><?= ucfirst($r['status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($records)): ?><tr><td colspan="6" class="text-center text-muted py-3">No tax records</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
