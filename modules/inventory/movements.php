<?php
$pageTitle = 'Stock Movements';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$page = max(1, intval($_GET['page'] ?? 1));
$type = $_GET['type'] ?? '';
$search = $_GET['search'] ?? '';

$where = ['1=1'];
$params = [];
if ($type) { $where[] = 'sm.type = ?'; $params[] = $type; }
if ($search) { $where[] = 'm.name LIKE ?'; $params[] = "%$search%"; }
$whereStr = implode(' AND ', $where);

$result = paginate("SELECT sm.*, m.name as medicine_name FROM stock_movements sm JOIN medicines m ON sm.medicine_id = m.id WHERE $whereStr ORDER BY sm.created_at DESC", $params, $page, 30);
?>

<div class="card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <input type="text" class="form-control" name="search" placeholder="Search medicine..." value="<?= sanitize($search) ?>">
        </div>
        <div class="col-md-3">
            <select class="form-select" name="type">
                <option value="">All Types</option>
                <option value="in" <?= $type === 'in' ? 'selected' : '' ?>>Stock In</option>
                <option value="out" <?= $type === 'out' ? 'selected' : '' ?>>Stock Out (Sales)</option>
                <option value="adjustment" <?= $type === 'adjustment' ? 'selected' : '' ?>>Adjustment</option>
                <option value="return" <?= $type === 'return' ? 'selected' : '' ?>>Return</option>
                <option value="expired" <?= $type === 'expired' ? 'selected' : '' ?>>Expired</option>
                <option value="damaged" <?= $type === 'damaged' ? 'selected' : '' ?>>Damaged</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-outline-primary">Filter</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Date</th><th>Medicine</th><th>Type</th><th>Quantity</th><th>Notes</th></tr></thead>
            <tbody>
                <?php foreach ($result['data'] as $mov): ?>
                <tr>
                    <td><?= formatDate($mov['created_at'], 'M d, Y H:i') ?></td>
                    <td><a href="edit.php?id=<?= $mov['medicine_id'] ?>"><?= sanitize($mov['medicine_name']) ?></a></td>
                    <td>
                        <?php
                        $colors = ['in'=>'success','out'=>'danger','adjustment'=>'warning','return'=>'info','expired'=>'dark','damaged'=>'secondary'];
                        ?>
                        <span class="badge bg-<?= $colors[$mov['type']] ?? 'secondary' ?>"><?= strtoupper($mov['type']) ?></span>
                    </td>
                    <td class="fw-semibold <?= in_array($mov['type'], ['out','expired','damaged']) ? 'text-danger' : 'text-success' ?>">
                        <?= in_array($mov['type'], ['out','expired','damaged']) ? '-' : '+' ?><?= $mov['quantity'] ?>
                    </td>
                    <td><?= sanitize($mov['notes'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($result['data'])): ?><tr><td colspan="5" class="text-center text-muted py-3">No stock movements</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= renderPagination($result, 'movements.php?' . http_build_query(array_filter(['type' => $type, 'search' => $search]))) ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
