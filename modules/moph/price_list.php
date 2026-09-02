<?php
$pageTitle = 'MoPH Price List';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add'])) {
        $db->prepare("INSERT INTO moph_price_list (medicine_name, barcode, public_price_usd, public_price_lbp, hospital_price_usd, agent_name, effective_date, is_subsidized, subsidy_category) VALUES (?,?,?,?,?,?,?,?,?)")->execute([
            $_POST['medicine_name'], $_POST['barcode'] ?: null, $_POST['public_price_usd'] ?: null,
            $_POST['public_price_lbp'] ?: null, $_POST['hospital_price_usd'] ?: null,
            $_POST['agent_name'] ?: null, $_POST['effective_date'] ?: null,
            isset($_POST['is_subsidized']) ? 1 : 0, $_POST['subsidy_category'] ?: null
        ]);
        flashMessage('Price entry added');
    } elseif (isset($_POST['sync_prices'])) {
        $priceList = $db->query("SELECT * FROM moph_price_list")->fetchAll();
        $updated = 0;
        foreach ($priceList as $entry) {
            $medicine = null;
            if ($entry['barcode']) {
                $stmt = $db->prepare("SELECT id FROM medicines WHERE barcode = ? AND is_active = 1");
                $stmt->execute([$entry['barcode']]);
                $medicine = $stmt->fetch();
            }
            if (!$medicine) {
                $stmt = $db->prepare("SELECT id FROM medicines WHERE name LIKE ? AND is_active = 1");
                $stmt->execute(['%' . $entry['medicine_name'] . '%']);
                $medicine = $stmt->fetch();
            }
            if ($medicine && $entry['public_price_usd']) {
                $db->prepare("UPDATE medicines SET moph_price = ? WHERE id = ?")->execute([$entry['public_price_usd'], $medicine['id']]);
                $updated++;
            }
        }
        flashMessage("Synced $updated medicine prices with MoPH list");
    }
    header('Location: price_list.php');
    exit;
}

$search = $_GET['search'] ?? '';
$subsidized = $_GET['subsidized'] ?? '';
$where = ['1=1'];
$params = [];
if ($search) { $where[] = 'medicine_name LIKE ?'; $params[] = "%$search%"; }
if ($subsidized === '1') { $where[] = 'is_subsidized = 1'; }

$page = max(1, intval($_GET['page'] ?? 1));
$result = paginate("SELECT * FROM moph_price_list WHERE " . implode(' AND ', $where) . " ORDER BY medicine_name", $params, $page, 30);

$priceViolations = $db->query("SELECT m.name, m.sell_price, m.moph_price, (m.sell_price - m.moph_price) as diff FROM medicines m WHERE m.moph_price IS NOT NULL AND m.sell_price > m.moph_price AND m.is_active = 1 ORDER BY diff DESC")->fetchAll();
?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card stat-card danger">
            <div class="stat-label">Price Violations</div>
            <div class="stat-value"><?= count($priceViolations) ?></div>
            <small class="text-muted">Selling above MoPH price</small>
        </div>
    </div>
    <div class="col-md-8 d-flex align-items-center justify-content-end gap-2">
        <form method="POST" class="d-inline">
            <button type="submit" name="sync_prices" value="1" class="btn btn-success"><i class="bi bi-arrow-repeat me-1"></i>Sync MoPH Prices to Inventory</button>
        </form>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPrice"><i class="bi bi-plus me-1"></i>Add Entry</button>
    </div>
</div>

<?php if (!empty($priceViolations)): ?>
<div class="card p-3 mb-3 border-danger">
    <h6 class="text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Price Violations - Selling Above MoPH Price</h6>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Medicine</th><th>Your Price</th><th>MoPH Price</th><th>Over By</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($priceViolations, 0, 10) as $v): ?>
                <tr class="table-danger">
                    <td><?= sanitize($v['name']) ?></td>
                    <td><?= formatCurrency($v['sell_price']) ?></td>
                    <td><?= formatCurrency($v['moph_price']) ?></td>
                    <td class="text-danger fw-bold">+<?= formatCurrency($v['diff']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card p-3 mb-3">
    <form method="GET" class="row g-2">
        <div class="col-md-4"><input type="text" class="form-control" name="search" placeholder="Search medicine..." value="<?= sanitize($search) ?>"></div>
        <div class="col-md-2">
            <select class="form-select" name="subsidized">
                <option value="">All</option>
                <option value="1" <?= $subsidized === '1' ? 'selected' : '' ?>>Subsidized Only</option>
            </select>
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-outline-primary">Filter</button></div>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Medicine</th><th>Barcode</th><th>Public Price ($)</th><th>Public Price (LBP)</th><th>Agent</th><th>Subsidized</th><th>Date</th></tr></thead>
            <tbody>
                <?php foreach ($result['data'] as $p): ?>
                <tr>
                    <td><?= sanitize($p['medicine_name']) ?></td>
                    <td><?= sanitize($p['barcode'] ?? '-') ?></td>
                    <td><?= $p['public_price_usd'] ? formatCurrency($p['public_price_usd']) : '-' ?></td>
                    <td><?= $p['public_price_lbp'] ? formatCurrency($p['public_price_lbp'], 'LBP') : '-' ?></td>
                    <td><?= sanitize($p['agent_name'] ?? '-') ?></td>
                    <td><?= $p['is_subsidized'] ? '<span class="badge bg-success">Yes</span>' : 'No' ?></td>
                    <td><?= $p['effective_date'] ? formatDate($p['effective_date'], 'M Y') : '-' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= renderPagination($result, 'price_list.php?' . http_build_query(array_filter(['search' => $search, 'subsidized' => $subsidized]))) ?>

<div class="modal fade" id="addPrice"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title">Add MoPH Price Entry</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-2"><input type="text" class="form-control" name="medicine_name" placeholder="Medicine name" required></div>
        <div class="mb-2"><input type="text" class="form-control" name="barcode" placeholder="Barcode"></div>
        <div class="mb-2"><input type="number" class="form-control" name="public_price_usd" step="0.01" placeholder="Public Price (USD)"></div>
        <div class="mb-2"><input type="number" class="form-control" name="public_price_lbp" step="1" placeholder="Public Price (LBP)"></div>
        <div class="mb-2"><input type="number" class="form-control" name="hospital_price_usd" step="0.01" placeholder="Hospital Price (USD)"></div>
        <div class="mb-2"><input type="text" class="form-control" name="agent_name" placeholder="Agent/Distributor"></div>
        <div class="mb-2"><input type="date" class="form-control" name="effective_date"></div>
        <div class="mb-2"><div class="form-check"><input type="checkbox" class="form-check-input" name="is_subsidized"><label class="form-check-label">Subsidized</label></div></div>
        <div><input type="text" class="form-control" name="subsidy_category" placeholder="Subsidy category"></div>
    </div>
    <div class="modal-footer"><button type="submit" name="add" value="1" class="btn btn-primary">Add</button></div>
    </form>
</div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
