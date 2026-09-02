<?php
$pageTitle = 'All Medicines';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();

$db = getDB();

$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$stockFilter = $_GET['stock'] ?? '';
$shelf = $_GET['shelf'] ?? '';
$controlledFilter = $_GET['controlled'] ?? '';

$where = ['m.is_active = 1'];
$params = [];

if ($search) {
    $where[] = '(m.name LIKE ? OR m.generic_name LIKE ? OR m.barcode LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($category) {
    $where[] = 'm.category_id = ?';
    $params[] = $category;
}
if ($stockFilter === 'low') {
    $where[] = 'm.quantity_in_stock > 0 AND m.quantity_in_stock <= m.min_stock_level';
} elseif ($stockFilter === 'out') {
    $where[] = 'm.quantity_in_stock = 0';
} elseif ($stockFilter === 'ok') {
    $where[] = 'm.quantity_in_stock > m.min_stock_level';
}
if ($shelf) {
    $where[] = 'm.shelf_id = ?';
    $params[] = $shelf;
}
if ($controlledFilter === '1') {
    $where[] = 'm.is_controlled = 1';
} elseif ($controlledFilter === '0') {
    $where[] = 'm.is_controlled = 0';
}

$whereStr = implode(' AND ', $where);
$page = max(1, intval($_GET['page'] ?? 1));

$query = "SELECT m.*, c.name as category_name, c.color as category_color,
          s.shelf_number, cab.name as cabinet_name
          FROM medicines m
          LEFT JOIN categories c ON m.category_id = c.id
          LEFT JOIN shelves s ON m.shelf_id = s.id
          LEFT JOIN cabinets cab ON s.cabinet_id = cab.id
          WHERE $whereStr ORDER BY m.name ASC";

$result = paginate($query, $params, $page, 25);
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$allShelves = $db->query("SELECT s.*, cab.name as cabinet_name FROM shelves s JOIN cabinets cab ON s.cabinet_id = cab.id ORDER BY cab.name, s.shelf_number")->fetchAll();

if (isset($_GET['delete']) && hasRole('admin')) {
    $db->prepare("UPDATE medicines SET is_active = 0 WHERE id = ?")->execute([$_GET['delete']]);
    flashMessage('Medicine deactivated successfully');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <span class="text-muted"><?= number_format($result['total']) ?> medicines</span>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/inventory/add.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Medicine</a>
        <a href="<?= BASE_URL ?>/modules/inventory/export.php" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i>Export</a>
    </div>
</div>

<div class="card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <input type="text" class="form-control" name="search" placeholder="Search name, generic, barcode..." value="<?= sanitize($search) ?>">
        </div>
        <div class="col-md-2">
            <select class="form-select" name="category">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $category == $cat['id'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select class="form-select" name="stock">
                <option value="">All Stock</option>
                <option value="ok" <?= $stockFilter === 'ok' ? 'selected' : '' ?>>In Stock</option>
                <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low Stock</option>
                <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of Stock</option>
            </select>
        </div>
        <div class="col-md-2">
            <select class="form-select" name="shelf">
                <option value="">All Shelves</option>
                <?php foreach ($allShelves as $sh): ?>
                <option value="<?= $sh['id'] ?>" <?= $shelf == $sh['id'] ? 'selected' : '' ?>><?= sanitize($sh['cabinet_name']) ?> - Shelf <?= $sh['shelf_number'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select class="form-select" name="controlled">
                <option value="">All Types</option>
                <option value="1" <?= $controlledFilter === '1' ? 'selected' : '' ?>>
                    <i class="bi bi-shield-lock"></i> Controlled Only
                </option>
                <option value="0" <?= $controlledFilter === '0' ? 'selected' : '' ?>>Non-Controlled</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Filter</button>
            <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Location</th>
                    <th>Stock</th>
                    <th>Cost</th>
                    <th>Sell Price</th>
                    <th>Expiry</th>
                    <th width="120">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($result['data'])): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No medicines found</td></tr>
                <?php endif; ?>
                <?php foreach ($result['data'] as $med): ?>
                <?php
                    $stockClass = 'badge-stock-ok';
                    if ($med['quantity_in_stock'] == 0) $stockClass = 'badge-stock-out';
                    elseif ($med['quantity_in_stock'] <= $med['min_stock_level']) $stockClass = 'badge-stock-low';

                    $isExpired = $med['expiry_date'] && strtotime($med['expiry_date']) < time();
                    $isExpiring = $med['expiry_date'] && !$isExpired && strtotime($med['expiry_date']) < strtotime('+90 days');
                ?>
                <tr class="<?= $isExpired ? 'table-danger' : '' ?>">
                    <td>
                        <strong><?= sanitize($med['name']) ?></strong>
                        <?php if (!empty($med['is_controlled'])): ?><span class="badge bg-danger ms-1 small" title="Controlled Substance"><i class="bi bi-shield-lock"></i></span><?php endif; ?>
                        <?php if ($med['strength']): ?><br><small class="text-muted"><?= sanitize($med['strength']) ?> - <?= ucfirst($med['form']) ?></small><?php endif; ?>
                        <?php if ($med['generic_name']): ?><br><small class="text-muted fst-italic"><?= sanitize($med['generic_name']) ?></small><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($med['category_name']): ?>
                        <span class="category-badge" style="background:<?= $med['category_color'] ?>22;color:<?= $med['category_color'] ?>"><?= sanitize($med['category_name']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($med['cabinet_name']): ?>
                        <small><?= sanitize($med['cabinet_name']) ?><br>Shelf <?= $med['shelf_number'] ?></small>
                        <?php else: ?>
                        <small class="text-muted">-</small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $stockClass ?>"><?= $med['quantity_in_stock'] ?></span></td>
                    <td><?= formatCurrency($med['cost_price']) ?></td>
                    <td><?= formatCurrency($med['sell_price']) ?></td>
                    <td>
                        <?php if ($isExpired): ?>
                        <span class="badge bg-danger expiry-warning">EXPIRED</span>
                        <?php elseif ($isExpiring): ?>
                        <span class="badge bg-warning"><?= formatDate($med['expiry_date'], 'M Y') ?></span>
                        <?php elseif ($med['expiry_date']): ?>
                        <small><?= formatDate($med['expiry_date'], 'M Y') ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="edit.php?id=<?= $med['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <a href="?delete=<?= $med['id'] ?>" class="btn btn-outline-danger" title="Delete" data-confirm="Deactivate this medicine?"><i class="bi bi-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?= renderPagination($result, $_SERVER['PHP_SELF'] . '?' . http_build_query(array_filter(['search' => $search, 'category' => $category, 'stock' => $stockFilter, 'shelf' => $shelf]))) ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
