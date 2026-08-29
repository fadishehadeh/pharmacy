<?php
$pageTitle = 'Stock Count';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_count'])) {
    $countDate = date('Y-m-d H:i:s');
    $adjustments = 0;
    $userId = $_SESSION['user_id'];

    foreach ($_POST['counted'] as $medId => $counted) {
        $counted = intval($counted);
        $medId = intval($medId);
        if ($counted < 0) continue;

        $med = $db->prepare("SELECT quantity_in_stock, name FROM medicines WHERE id = ?");
        $med->execute([$medId]);
        $med = $med->fetch();
        if (!$med) continue;

        $systemQty = $med['quantity_in_stock'];
        $difference = $counted - $systemQty;

        $db->prepare("INSERT INTO stock_counts (medicine_id, system_quantity, counted_quantity, difference, counted_by, count_date, notes) VALUES (?,?,?,?,?,?,?)")->execute([
            $medId, $systemQty, $counted, $difference, $userId, $countDate, $_POST['notes'][$medId] ?? null
        ]);

        if ($difference !== 0) {
            updateStock($medId, $difference);
            addStockMovement($medId, 'adjustment', abs($difference), 'Stock count adjustment: ' . ($difference > 0 ? 'surplus' : 'shortage'));
            $adjustments++;
        }
    }

    addAuditLog('stock_count', 'medicines', 0);
    flashMessage("Stock count saved. $adjustments item(s) adjusted.");
    header('Location: stock_count.php');
    exit;
}

$categoryFilter = $_GET['category'] ?? '';
$shelfFilter = $_GET['shelf'] ?? '';

$where = "WHERE m.is_active = 1";
$params = [];
if ($categoryFilter) { $where .= " AND m.category_id = ?"; $params[] = $categoryFilter; }
if ($shelfFilter) { $where .= " AND m.shelf_id = ?"; $params[] = $shelfFilter; }

$medicines = $db->prepare("SELECT m.*, c.name as category_name,
    CONCAT(cab.name, ' - Shelf ', sh.shelf_number) as shelf_location
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    LEFT JOIN shelves sh ON m.shelf_id = sh.id
    LEFT JOIN cabinets cab ON sh.cabinet_id = cab.id
    $where
    ORDER BY cab.name, sh.shelf_number, m.name");
$medicines->execute($params);
$medicines = $medicines->fetchAll();

$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$shelves = $db->query("SELECT s.id, s.shelf_number, cab.name as cabinet_name FROM shelves s JOIN cabinets cab ON s.cabinet_id = cab.id ORDER BY cab.name, s.shelf_number")->fetchAll();

$recentCounts = [];
try {
    $recentCounts = $db->query("SELECT sc.*, m.name as med_name, u.full_name as user_name
        FROM stock_counts sc
        JOIN medicines m ON sc.medicine_id = m.id
        LEFT JOIN users u ON sc.counted_by = u.id
        WHERE sc.difference != 0
        ORDER BY sc.count_date DESC LIMIT 50")->fetchAll();
} catch (Exception $e) {}
?>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Physical Stock Count</h6>
                <form class="d-flex gap-2" method="GET">
                    <select class="form-select form-select-sm" name="category" style="width:auto">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $categoryFilter == $cat['id'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm" name="shelf" style="width:auto">
                        <option value="">All Shelves</option>
                        <?php foreach ($shelves as $sh): ?>
                        <option value="<?= $sh['id'] ?>" <?= $shelfFilter == $sh['id'] ? 'selected' : '' ?>><?= sanitize($sh['cabinet_name']) ?> - Shelf <?= $sh['shelf_number'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i></button>
                </form>
            </div>

            <form method="POST">
            <div class="table-responsive" style="max-height:600px;overflow-y:auto">
                <table class="table table-sm table-hover mb-0">
                    <thead class="sticky-top bg-white"><tr><th>Medicine</th><th>Location</th><th>System Qty</th><th>Counted</th><th>Diff</th><th>Notes</th></tr></thead>
                    <tbody>
                        <?php foreach ($medicines as $m): ?>
                        <tr>
                            <td>
                                <strong class="small"><?= sanitize($m['name']) ?></strong>
                                <?php if ($m['barcode']): ?><br><small class="text-muted"><?= sanitize($m['barcode']) ?></small><?php endif; ?>
                            </td>
                            <td><small><?= sanitize($m['shelf_location'] ?? '-') ?></small></td>
                            <td class="text-center"><span class="badge bg-secondary"><?= $m['quantity_in_stock'] ?></span></td>
                            <td style="width:100px"><input type="number" class="form-control form-control-sm count-input" name="counted[<?= $m['id'] ?>]" value="<?= $m['quantity_in_stock'] ?>" min="0" data-system="<?= $m['quantity_in_stock'] ?>"></td>
                            <td class="text-center diff-cell">0</td>
                            <td style="width:120px"><input type="text" class="form-control form-control-sm" name="notes[<?= $m['id'] ?>]" placeholder="Note"></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mt-3 d-flex justify-content-between align-items-center">
                <span class="text-muted small"><?= count($medicines) ?> items</span>
                <button type="submit" name="save_count" value="1" class="btn btn-primary" onclick="return confirm('Save stock count and apply adjustments?')"><i class="bi bi-check-lg me-1"></i>Save Stock Count</button>
            </div>
            </form>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-clock-history me-2"></i>Recent Adjustments</h6>
            <?php if (empty($recentCounts)): ?>
            <p class="text-muted small">No stock count adjustments yet</p>
            <?php else: ?>
            <div class="list-group list-group-flush" style="max-height:500px;overflow-y:auto">
                <?php foreach ($recentCounts as $sc): ?>
                <div class="list-group-item px-0 py-2">
                    <div class="d-flex justify-content-between">
                        <strong class="small"><?= sanitize($sc['med_name']) ?></strong>
                        <span class="badge bg-<?= $sc['difference'] > 0 ? 'success' : 'danger' ?>"><?= $sc['difference'] > 0 ? '+' : '' ?><?= $sc['difference'] ?></span>
                    </div>
                    <small class="text-muted">System: <?= $sc['system_quantity'] ?> | Counted: <?= $sc['counted_quantity'] ?></small><br>
                    <small class="text-muted"><?= formatDate($sc['count_date'], 'M d, H:i') ?> by <?= sanitize($sc['user_name'] ?? '-') ?></small>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<'SCRIPT'
<script>
document.querySelectorAll('.count-input').forEach(function(input) {
    input.addEventListener('input', function() {
        var system = parseInt(this.dataset.system) || 0;
        var counted = parseInt(this.value) || 0;
        var diff = counted - system;
        var cell = this.closest('tr').querySelector('.diff-cell');
        cell.textContent = diff === 0 ? '0' : (diff > 0 ? '+' + diff : diff);
        cell.className = 'text-center diff-cell ' + (diff > 0 ? 'text-success fw-bold' : (diff < 0 ? 'text-danger fw-bold' : ''));
    });
});
</script>
SCRIPT;
require_once __DIR__ . '/../../includes/footer.php';
?>
