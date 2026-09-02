<?php
$pageTitle = 'Supplier Price Comparison';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

// Ensure supplier_prices table exists
$db->exec("CREATE TABLE IF NOT EXISTS supplier_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id INT NOT NULL,
    supplier_id INT NOT NULL,
    unit_cost DECIMAL(10,2) NOT NULL,
    last_updated DATE,
    notes VARCHAR(500),
    UNIQUE KEY unique_med_sup (medicine_id, supplier_id),
    FOREIGN KEY (medicine_id) REFERENCES medicines(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
)");

// ── POST: upsert supplier price ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $medicineId = intval($_POST['medicine_id']);
    $supplierId = intval($_POST['supplier_id']);
    $unitCost   = floatval($_POST['unit_cost']);
    $notes      = trim($_POST['notes'] ?? '');

    if ($action === 'upsert' && $medicineId && $supplierId && $unitCost >= 0) {
        $db->prepare("INSERT INTO supplier_prices (medicine_id, supplier_id, unit_cost, last_updated, notes)
            VALUES (?,?,?,CURDATE(),?)
            ON DUPLICATE KEY UPDATE unit_cost=VALUES(unit_cost), last_updated=CURDATE(), notes=VALUES(notes)")
            ->execute([$medicineId, $supplierId, $unitCost, $notes]);
        flashMessage('Price updated for supplier');
    } elseif ($action === 'delete') {
        $priceId = intval($_POST['price_id']);
        $db->prepare("DELETE FROM supplier_prices WHERE id = ?")->execute([$priceId]);
        flashMessage('Price record removed');
    }
    $qs = http_build_query(array_filter(['q' => $_POST['search_q'] ?? '', 'med_id' => $_POST['med_id'] ?? '']));
    header('Location: price_compare.php' . ($qs ? "?$qs" : ''));
    exit;
}

// ── Search ───────────────────────────────────────────────────────────────────
$search    = trim($_GET['q'] ?? '');
$medIdFilter = intval($_GET['med_id'] ?? 0);
$medicines = [];
$selected  = null;
$priceRows = [];

if ($search || $medIdFilter) {
    $sql    = "SELECT * FROM medicines WHERE is_active = 1";
    $params = [];
    if ($medIdFilter) {
        $sql    .= " AND id = ?";
        $params[] = $medIdFilter;
    } elseif ($search) {
        $sql    .= " AND (name LIKE ? OR barcode = ?)";
        $params[] = "%$search%";
        $params[] = $search;
    }
    $sql .= " ORDER BY name LIMIT 30";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $medicines = $stmt->fetchAll();

    if ($medIdFilter || count($medicines) === 1) {
        $selected = $medIdFilter ? array_filter($medicines, fn($m) => $m['id'] === $medIdFilter) : $medicines;
        $selected = array_values($selected)[0] ?? null;

        if ($selected) {
            $priceRows = $db->prepare("SELECT sp.*, s.name as supplier_name, s.phone as supplier_phone
                FROM supplier_prices sp
                JOIN suppliers s ON sp.supplier_id = s.id
                WHERE sp.medicine_id = ?
                ORDER BY sp.unit_cost ASC")
                ->execute([$selected['id']]) ? null : null; // placeholder
            $stmt2 = $db->prepare("SELECT sp.*, s.name as supplier_name, s.phone as supplier_phone
                FROM supplier_prices sp
                JOIN suppliers s ON sp.supplier_id = s.id
                WHERE sp.medicine_id = ?
                ORDER BY sp.unit_cost ASC");
            $stmt2->execute([$selected['id']]);
            $priceRows = $stmt2->fetchAll();
        }
    }
}

$allSuppliers = $db->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();

// Summary stats: all medicines with supplier prices
$topSavings = $db->query("SELECT m.name, m.cost_price, MIN(sp.unit_cost) as cheapest_supplier_cost,
    m.cost_price - MIN(sp.unit_cost) as saving_per_unit,
    COUNT(DISTINCT sp.supplier_id) as supplier_count
    FROM supplier_prices sp
    JOIN medicines m ON sp.medicine_id = m.id
    WHERE m.is_active = 1
    GROUP BY m.id, m.name, m.cost_price
    HAVING saving_per_unit > 0
    ORDER BY saving_per_unit DESC
    LIMIT 5")->fetchAll();
?>

<!-- Search bar -->
<div class="card p-3 mb-3 no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-6">
            <label class="form-label small fw-semibold">Search Medicine</label>
            <input type="text" class="form-control" name="q" value="<?= sanitize($search) ?>"
                placeholder="Type medicine name or barcode…" autofocus>
        </div>
        <div class="col-md-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Search</button>
            <?php if ($search || $medIdFilter): ?>
            <a href="price_compare.php" class="btn btn-outline-secondary ms-2">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($search && empty($medicines)): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>No medicines found for "<?= sanitize($search) ?>".</div>

<?php elseif (!empty($medicines) && !$selected && count($medicines) > 1): ?>
<!-- Multiple results: list to pick from -->
<div class="card">
    <div class="card-header"><h6 class="mb-0">Results for "<?= sanitize($search) ?>" — select a medicine</h6></div>
    <div class="list-group list-group-flush">
        <?php foreach ($medicines as $m): ?>
        <a href="?med_id=<?= $m['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between">
            <span><strong><?= sanitize($m['name']) ?></strong>
                <?php if (!empty($m['strength'])): ?><small class="text-muted ms-2"><?= sanitize($m['strength']) ?></small><?php endif; ?>
            </span>
            <span class="small text-muted">Cost: <?= formatCurrency($m['cost_price']) ?> | Sell: <?= formatCurrency($m['sell_price']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php elseif ($selected): ?>
<!-- ── Price comparison for selected medicine ──────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h6 class="mb-0"><i class="bi bi-capsule me-2"></i><?= sanitize($selected['name']) ?>
                <?php if (!empty($selected['strength'])): ?><span class="text-muted fw-normal"><?= sanitize($selected['strength']) ?></span><?php endif; ?>
            </h6>
            <small class="text-muted">
                Our cost price: <strong><?= formatCurrency($selected['cost_price']) ?></strong> &nbsp;|&nbsp;
                Sell price: <strong><?= formatCurrency($selected['sell_price']) ?></strong> &nbsp;|&nbsp;
                Stock: <strong><?= $selected['quantity_in_stock'] ?></strong>
            </small>
        </div>
        <button class="btn btn-sm btn-success no-print" data-bs-toggle="modal" data-bs-target="#addPriceModal">
            <i class="bi bi-plus me-1"></i>Add Supplier Price
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Supplier</th>
                    <th class="text-end">Supplier Price</th>
                    <th class="text-end">vs Our Cost</th>
                    <th class="text-end">vs Sell Price</th>
                    <th class="text-end">Potential Saving</th>
                    <th>Last Updated</th>
                    <th>Notes</th>
                    <th class="no-print"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($priceRows)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">
                    No supplier prices recorded yet. <a href="#" data-bs-toggle="modal" data-bs-target="#addPriceModal">Add the first one.</a>
                </td></tr>
                <?php else: ?>
                <?php $cheapest = $priceRows[0]['unit_cost']; ?>
                <?php foreach ($priceRows as $i => $row): ?>
                <?php
                    $isCheapest  = ($row['unit_cost'] == $cheapest);
                    $vsCost      = $selected['cost_price'] - $row['unit_cost'];
                    $vsSell      = $selected['sell_price'] - $row['unit_cost'];
                    $saving      = $vsCost * $selected['quantity_in_stock'];
                    $rowClass    = $isCheapest ? 'table-success' : '';
                ?>
                <tr class="<?= $rowClass ?>">
                    <td>
                        <?php if ($isCheapest): ?>
                        <span class="badge bg-success"><i class="bi bi-trophy-fill me-1"></i>Cheapest</span>
                        <?php else: ?>
                        <span class="text-muted small">#<?= $i+1 ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= sanitize($row['supplier_name']) ?></strong>
                        <?php if ($row['supplier_phone']): ?>
                        <br><small class="text-muted"><?= sanitize($row['supplier_phone']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-semibold <?= $isCheapest ? 'text-success' : '' ?>">
                        <?= formatCurrency($row['unit_cost']) ?>
                    </td>
                    <td class="text-end">
                        <?php if ($vsCost > 0): ?>
                        <span class="text-success small"><i class="bi bi-arrow-down me-1"></i><?= formatCurrency($vsCost) ?> cheaper</span>
                        <?php elseif ($vsCost < 0): ?>
                        <span class="text-danger small"><i class="bi bi-arrow-up me-1"></i><?= formatCurrency(abs($vsCost)) ?> more</span>
                        <?php else: ?>
                        <span class="text-muted small">Same</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <span class="small <?= $vsSell > 0 ? 'text-success' : 'text-danger' ?>">
                            <?= $vsSell >= 0 ? '+' : '' ?><?= formatCurrency($vsSell) ?> margin
                        </span>
                    </td>
                    <td class="text-end">
                        <?php if ($vsCost > 0): ?>
                        <span class="text-success small fw-semibold"><?= formatCurrency($saving) ?></span>
                        <br><small class="text-muted">(<?= $selected['quantity_in_stock'] ?> units)</small>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td><small class="text-muted"><?= $row['last_updated'] ? formatDate($row['last_updated'], 'M d, Y') : '—' ?></small></td>
                    <td><small class="text-muted"><?= sanitize($row['notes'] ?? '') ?></small></td>
                    <td class="no-print">
                        <button class="btn btn-xs btn-outline-primary btn-sm me-1"
                            onclick="openEdit(<?= htmlspecialchars(json_encode($row)) ?>)">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this price record?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="price_id" value="<?= $row['id'] ?>">
                            <input type="hidden" name="med_id" value="<?= $selected['id'] ?>">
                            <input type="hidden" name="search_q" value="<?= sanitize($search) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Add / Edit Supplier Price Modal ──────────────────────────────────────── -->
<div class="modal fade" id="addPriceModal"><div class="modal-dialog"><div class="modal-content">
    <form method="POST" id="priceForm">
        <input type="hidden" name="action" value="upsert">
        <input type="hidden" name="medicine_id" value="<?= $selected['id'] ?>">
        <input type="hidden" name="med_id" value="<?= $selected['id'] ?>">
        <input type="hidden" name="search_q" value="<?= sanitize($search) ?>">
        <div class="modal-header">
            <h6 class="modal-title" id="priceModalTitle"><i class="bi bi-plus me-2"></i>Add Supplier Price</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <p class="small text-muted mb-3">Medicine: <strong><?= sanitize($selected['name']) ?></strong>
                &nbsp;|&nbsp; Current cost: <strong><?= formatCurrency($selected['cost_price']) ?></strong></p>
            <div class="mb-3">
                <label class="form-label">Supplier</label>
                <select class="form-select" name="supplier_id" id="fSupplier" required>
                    <option value="">Select supplier…</option>
                    <?php foreach ($allSuppliers as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= sanitize($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Unit Cost</label>
                <div class="input-group">
                    <span class="input-group-text">$</span>
                    <input type="number" class="form-control" name="unit_cost" id="fUnitCost" step="0.01" min="0" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
                <input type="text" class="form-control" name="notes" id="fNotes" placeholder="e.g. includes delivery, minimum order 10">
            </div>
        </div>
        <div class="modal-footer">
            <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i>Save Price</button>
        </div>
    </form>
</div></div></div>

<?php endif; ?>

<!-- ── Top Savings Summary ───────────────────────────────────────────────────── -->
<?php if (!empty($topSavings) && !$selected): ?>
<div class="card mt-3">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-trophy me-2"></i>Top Saving Opportunities (vs Current Cost)</h6></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead><tr><th>Medicine</th><th class="text-end">Our Cost</th><th class="text-end">Best Supplier Price</th><th class="text-end">Saving / Unit</th><th class="text-center">Suppliers</th></tr></thead>
            <tbody>
                <?php foreach ($topSavings as $ts): ?>
                <tr>
                    <td><?= sanitize($ts['name']) ?></td>
                    <td class="text-end"><?= formatCurrency($ts['cost_price']) ?></td>
                    <td class="text-end text-success fw-semibold"><?= formatCurrency($ts['cheapest_supplier_cost']) ?></td>
                    <td class="text-end text-success"><?= formatCurrency($ts['saving_per_unit']) ?></td>
                    <td class="text-center"><?= $ts['supplier_count'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php elseif (!$search && !$medIdFilter): ?>
<div class="alert alert-info mt-3">
    <i class="bi bi-info-circle me-2"></i>Search for a medicine above to compare supplier prices.
    After recording prices, top saving opportunities will appear here.
</div>
<?php endif; ?>

<?php
$extraScripts = <<<'JS'
<script>
function openEdit(row) {
    document.getElementById('priceModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Update Supplier Price';
    document.getElementById('fSupplier').value  = row.supplier_id;
    document.getElementById('fUnitCost').value  = row.unit_cost;
    document.getElementById('fNotes').value     = row.notes || '';
    new bootstrap.Modal(document.getElementById('addPriceModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
