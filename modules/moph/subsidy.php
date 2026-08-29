<?php
$pageTitle = 'Subsidy Tracking';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$subsidizedMeds = $db->query("SELECT m.*, c.name as category_name FROM medicines m LEFT JOIN categories c ON m.category_id = c.id WHERE m.is_subsidized = 1 AND m.is_active = 1 ORDER BY m.name")->fetchAll();

$month = $_GET['month'] ?? date('Y-m');
$subsidySales = $db->prepare("SELECT si.*, m.name as medicine_name, m.subsidy_percentage, s.sale_date, s.invoice_number FROM sale_items si JOIN medicines m ON si.medicine_id = m.id JOIN sales s ON si.sale_id = s.id WHERE si.is_subsidized = 1 AND DATE_FORMAT(s.sale_date, '%Y-%m') = ? AND s.status = 'completed' ORDER BY s.sale_date DESC");
$subsidySales->execute([$month]);
$subsidySales = $subsidySales->fetchAll();

$totalSubsidyAmount = array_sum(array_column($subsidySales, 'subsidy_amount'));
$totalSubsidySales = array_sum(array_column($subsidySales, 'total_price'));
?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="stat-label">Subsidized Medicines</div>
            <div class="stat-value"><?= count($subsidizedMeds) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card success">
            <div class="stat-label">Subsidized Sales (<?= $month ?>)</div>
            <div class="stat-value"><?= formatCurrency($totalSubsidySales) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card info">
            <div class="stat-label">Subsidy Amount</div>
            <div class="stat-value"><?= formatCurrency($totalSubsidyAmount) ?></div>
        </div>
    </div>
</div>

<div class="card p-3 mb-3">
    <form method="GET" class="d-flex gap-2">
        <input type="month" class="form-control" name="month" value="<?= $month ?>" style="width:200px">
        <button type="submit" class="btn btn-outline-primary">View</button>
    </form>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card p-3">
            <h6>Subsidized Inventory</h6>
            <div class="table-responsive" style="max-height:500px;overflow-y:auto">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Medicine</th><th>Subsidy %</th><th>Stock</th><th>Sell Price</th></tr></thead>
                    <tbody>
                        <?php foreach ($subsidizedMeds as $m): ?>
                        <tr>
                            <td><small><?= sanitize($m['name']) ?></small></td>
                            <td><span class="badge bg-info"><?= $m['subsidy_percentage'] ?>%</span></td>
                            <td><?= $m['quantity_in_stock'] ?></td>
                            <td><?= formatCurrency($m['sell_price']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="p-3 border-bottom"><h6 class="mb-0">Subsidized Sales Log - <?= $month ?></h6></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Date</th><th>Invoice</th><th>Medicine</th><th>Qty</th><th>Sale</th><th>Subsidy</th></tr></thead>
                    <tbody>
                        <?php foreach ($subsidySales as $ss): ?>
                        <tr>
                            <td><small><?= formatDate($ss['sale_date'], 'M d') ?></small></td>
                            <td><a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $ss['sale_id'] ?>"><?= sanitize($ss['invoice_number']) ?></a></td>
                            <td><?= sanitize($ss['medicine_name']) ?></td>
                            <td><?= $ss['quantity'] ?></td>
                            <td><?= formatCurrency($ss['total_price']) ?></td>
                            <td class="text-success"><?= formatCurrency($ss['subsidy_amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($subsidySales)): ?><tr><td colspan="6" class="text-center text-muted py-3">No subsidized sales this period</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
