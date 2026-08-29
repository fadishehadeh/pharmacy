<?php
$pageTitle = 'Stocktake';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) {
    flashMessage('Access denied. Pharmacist role required.', 'danger');
    header('Location: ' . BASE_URL . '/modules/inventory/index.php');
    exit;
}
$db = getDB();
$exchangeRate = floatval(getSetting('exchange_rate', '89500'));

// Ensure stocktakes table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS stocktakes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reference VARCHAR(30) UNIQUE NOT NULL,
        type ENUM('full','partial') DEFAULT 'full',
        category_id INT,
        shelf_id INT,
        status ENUM('in_progress','finalized','cancelled') DEFAULT 'in_progress',
        total_items INT DEFAULT 0,
        counted_items INT DEFAULT 0,
        matches INT DEFAULT 0,
        overages INT DEFAULT 0,
        shortages INT DEFAULT 0,
        value_impact DECIMAL(12,2) DEFAULT 0,
        notes TEXT,
        started_by INT,
        finalized_by INT,
        started_at DATETIME NOT NULL,
        finalized_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_date (started_at)
    )");
} catch (Exception $e) {}

// ---- POST ACTIONS ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Create new stocktake session
    if (isset($_POST['create_stocktake'])) {
        $type = $_POST['type'] ?? 'full';
        $catId = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
        $shelfId = !empty($_POST['shelf_id']) ? intval($_POST['shelf_id']) : null;
        $notes = $_POST['notes'] ?? '';

        $nextId = $db->query("SELECT COALESCE(MAX(id),0)+1 FROM stocktakes")->fetchColumn();
        $ref = 'ST-' . date('Ymd') . '-' . str_pad($nextId, 3, '0', STR_PAD_LEFT);

        // Count applicable medicines
        $where = "WHERE m.is_active = 1";
        $params = [];
        if ($type === 'partial' && $catId) {
            $where .= " AND m.category_id = ?";
            $params[] = $catId;
        }
        if ($type === 'partial' && $shelfId) {
            $where .= " AND m.shelf_id = ?";
            $params[] = $shelfId;
        }
        $totalItems = $db->prepare("SELECT COUNT(*) FROM medicines m $where");
        $totalItems->execute($params);
        $totalItems = intval($totalItems->fetchColumn());

        $db->prepare("INSERT INTO stocktakes (reference, type, category_id, shelf_id, status, total_items, notes, started_by, started_at) VALUES (?,?,?,?,'in_progress',?,?,?,NOW())")
            ->execute([$ref, $type, $catId, $shelfId, $totalItems, $notes, $_SESSION['user_id']]);

        $stocktakeId = $db->lastInsertId();
        addAuditLog('create_stocktake', 'stocktakes', $stocktakeId);
        flashMessage("Stocktake $ref created with $totalItems items to count.");
        header('Location: stocktake.php?action=count&id=' . $stocktakeId);
        exit;
    }

    // Save counts
    if (isset($_POST['save_counts'])) {
        $stocktakeId = intval($_POST['stocktake_id']);
        $st = $db->prepare("SELECT * FROM stocktakes WHERE id = ? AND status = 'in_progress'");
        $st->execute([$stocktakeId]);
        $st = $st->fetch();
        if (!$st) {
            flashMessage('Stocktake not found or already finalized.', 'danger');
            header('Location: stocktake.php');
            exit;
        }

        $counted = 0;
        if (isset($_POST['counted']) && is_array($_POST['counted'])) {
            foreach ($_POST['counted'] as $medId => $qty) {
                $medId = intval($medId);
                $qty = intval($qty);
                if ($qty < 0) continue;

                $med = $db->prepare("SELECT quantity_in_stock FROM medicines WHERE id = ?");
                $med->execute([$medId]);
                $med = $med->fetch();
                if (!$med) continue;

                $diff = $qty - $med['quantity_in_stock'];
                $note = $_POST['count_notes'][$medId] ?? null;

                // Upsert into stock_counts
                $existing = $db->prepare("SELECT id FROM stock_counts WHERE medicine_id = ? AND count_date = (SELECT started_at FROM stocktakes WHERE id = ?)");
                $existing->execute([$medId, $stocktakeId]);
                if ($existing->fetch()) {
                    $db->prepare("UPDATE stock_counts SET counted_quantity = ?, difference = ?, notes = ? WHERE medicine_id = ? AND count_date = (SELECT started_at FROM stocktakes WHERE id = ?)")
                        ->execute([$qty, $diff, $note, $medId, $stocktakeId]);
                } else {
                    $db->prepare("INSERT INTO stock_counts (medicine_id, system_quantity, counted_quantity, difference, counted_by, count_date, notes) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$medId, $med['quantity_in_stock'], $qty, $diff, $_SESSION['user_id'], $st['started_at'], $note]);
                }
                $counted++;
            }
        }

        $db->prepare("UPDATE stocktakes SET counted_items = ? WHERE id = ?")->execute([$counted, $stocktakeId]);
        flashMessage("Saved counts for $counted items.");
        header('Location: stocktake.php?action=count&id=' . $stocktakeId);
        exit;
    }

    // Finalize stocktake - apply adjustments
    if (isset($_POST['finalize_stocktake'])) {
        $stocktakeId = intval($_POST['stocktake_id']);
        $st = $db->prepare("SELECT * FROM stocktakes WHERE id = ? AND status = 'in_progress'");
        $st->execute([$stocktakeId]);
        $st = $st->fetch();
        if (!$st) {
            flashMessage('Stocktake not found or already finalized.', 'danger');
            header('Location: stocktake.php');
            exit;
        }

        // Get all counts for this stocktake
        $counts = $db->prepare("SELECT sc.*, m.cost_price, m.name FROM stock_counts sc JOIN medicines m ON sc.medicine_id = m.id WHERE sc.count_date = ?");
        $counts->execute([$st['started_at']]);
        $counts = $counts->fetchAll();

        $matches = 0;
        $overages = 0;
        $shortages = 0;
        $valueImpact = 0;
        $adjustments = 0;

        foreach ($counts as $c) {
            if ($c['difference'] == 0) {
                $matches++;
            } elseif ($c['difference'] > 0) {
                $overages++;
            } else {
                $shortages++;
            }

            if ($c['difference'] != 0) {
                updateStock($c['medicine_id'], $c['difference']);
                addStockMovement(
                    $c['medicine_id'],
                    'adjustment',
                    abs($c['difference']),
                    'Stocktake adjustment (' . $st['reference'] . '): ' . ($c['difference'] > 0 ? 'surplus +' : 'shortage ') . $c['difference'],
                    'stocktake',
                    $stocktakeId
                );
                $valueImpact += $c['difference'] * $c['cost_price'];
                $adjustments++;
            }
        }

        $db->prepare("UPDATE stocktakes SET status = 'finalized', counted_items = ?, matches = ?, overages = ?, shortages = ?, value_impact = ?, finalized_by = ?, finalized_at = NOW() WHERE id = ?")
            ->execute([count($counts), $matches, $overages, $shortages, $valueImpact, $_SESSION['user_id'], $stocktakeId]);

        addAuditLog('finalize_stocktake', 'stocktakes', $stocktakeId, null, ['adjustments' => $adjustments, 'value_impact' => $valueImpact]);
        flashMessage("Stocktake finalized. $adjustments adjustment(s) applied. Value impact: " . formatCurrency($valueImpact));
        header('Location: stocktake.php?action=summary&id=' . $stocktakeId);
        exit;
    }

    // Cancel stocktake
    if (isset($_POST['cancel_stocktake'])) {
        $stocktakeId = intval($_POST['stocktake_id']);
        $db->prepare("UPDATE stocktakes SET status = 'cancelled' WHERE id = ? AND status = 'in_progress'")->execute([$stocktakeId]);
        addAuditLog('cancel_stocktake', 'stocktakes', $stocktakeId);
        flashMessage('Stocktake cancelled.', 'warning');
        header('Location: stocktake.php');
        exit;
    }
}

// ---- VIEW LOGIC ----
$action = $_GET['action'] ?? 'list';
$stocktakeId = intval($_GET['id'] ?? 0);

// List view - past stocktakes
$stocktakes = [];
try {
    $stocktakes = $db->query("SELECT st.*, u.full_name as started_by_name, uf.full_name as finalized_by_name,
        c.name as category_name
        FROM stocktakes st
        LEFT JOIN users u ON st.started_by = u.id
        LEFT JOIN users uf ON st.finalized_by = uf.id
        LEFT JOIN categories c ON st.category_id = c.id
        ORDER BY st.started_at DESC LIMIT 50")->fetchAll();
} catch (Exception $e) {}

// If viewing a specific stocktake (count or summary)
$currentST = null;
$stMedicines = [];
$stCounts = [];
if ($stocktakeId && in_array($action, ['count', 'summary'])) {
    $currentST = $db->prepare("SELECT st.*, u.full_name as started_by_name FROM stocktakes st LEFT JOIN users u ON st.started_by = u.id WHERE st.id = ?");
    $currentST->execute([$stocktakeId]);
    $currentST = $currentST->fetch();

    if ($currentST) {
        // Get medicines for this stocktake
        $where = "WHERE m.is_active = 1";
        $params = [];
        if ($currentST['type'] === 'partial' && $currentST['category_id']) {
            $where .= " AND m.category_id = ?";
            $params[] = $currentST['category_id'];
        }
        if ($currentST['type'] === 'partial' && $currentST['shelf_id']) {
            $where .= " AND m.shelf_id = ?";
            $params[] = $currentST['shelf_id'];
        }

        $stmt = $db->prepare("SELECT m.*, c.name as category_name,
            CONCAT(COALESCE(cab.name,''), ' - Shelf ', COALESCE(sh.shelf_number,'')) as shelf_location
            FROM medicines m
            LEFT JOIN categories c ON m.category_id = c.id
            LEFT JOIN shelves sh ON m.shelf_id = sh.id
            LEFT JOIN cabinets cab ON sh.cabinet_id = cab.id
            $where
            ORDER BY COALESCE(cab.name,''), COALESCE(sh.shelf_number,0), m.name");
        $stmt->execute($params);
        $stMedicines = $stmt->fetchAll();

        // Get existing counts
        $existingCounts = $db->prepare("SELECT medicine_id, counted_quantity, difference, notes FROM stock_counts WHERE count_date = ?");
        $existingCounts->execute([$currentST['started_at']]);
        foreach ($existingCounts->fetchAll() as $ec) {
            $stCounts[$ec['medicine_id']] = $ec;
        }
    }
}

$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$shelves = [];
try {
    $shelves = $db->query("SELECT s.id, s.shelf_number, cab.name as cabinet_name FROM shelves s JOIN cabinets cab ON s.cabinet_id = cab.id ORDER BY cab.name, s.shelf_number")->fetchAll();
} catch (Exception $e) {}
?>

<?php if ($action === 'list'): ?>
<!-- ==================== LIST VIEW ==================== -->
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-plus-circle me-2"></i>New Stocktake</h6>
            <form method="POST">
                <div class="mb-2">
                    <label class="form-label small">Type</label>
                    <select class="form-select form-select-sm" name="type" id="stType" onchange="document.getElementById('partialFilters').style.display=this.value==='partial'?'':'none'">
                        <option value="full">Full Stocktake (All Medicines)</option>
                        <option value="partial">Partial (By Category/Shelf)</option>
                    </select>
                </div>
                <div id="partialFilters" style="display:none">
                    <div class="mb-2">
                        <label class="form-label small">Category</label>
                        <select class="form-select form-select-sm" name="category_id">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= sanitize($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Shelf</label>
                        <select class="form-select form-select-sm" name="shelf_id">
                            <option value="">All Shelves</option>
                            <?php foreach ($shelves as $sh): ?>
                            <option value="<?= $sh['id'] ?>"><?= sanitize($sh['cabinet_name']) ?> - Shelf <?= $sh['shelf_number'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Notes</label>
                    <textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="Optional notes"></textarea>
                </div>
                <button type="submit" name="create_stocktake" value="1" class="btn btn-primary btn-sm w-100"><i class="bi bi-clipboard-check me-1"></i>Start Stocktake</button>
            </form>
        </div>

        <!-- Active stocktake alert -->
        <?php
        $activeCount = 0;
        foreach ($stocktakes as $s) { if ($s['status'] === 'in_progress') $activeCount++; }
        if ($activeCount > 0):
        ?>
        <div class="card p-3 mt-3 border-warning">
            <h6 class="text-warning"><i class="bi bi-exclamation-triangle me-2"></i>Active Stocktakes</h6>
            <?php foreach ($stocktakes as $s): if ($s['status'] !== 'in_progress') continue; ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <strong class="small"><?= sanitize($s['reference']) ?></strong>
                    <br><small class="text-muted"><?= formatDate($s['started_at'], 'M d, H:i') ?></small>
                </div>
                <a href="?action=count&id=<?= $s['id'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil me-1"></i>Continue</a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card p-3">
            <h6><i class="bi bi-clock-history me-2"></i>Stocktake History</h6>
            <div class="table-responsive">
                <table class="table table-sm table-hover data-table mb-0">
                    <thead><tr><th>Reference</th><th>Type</th><th>Date</th><th>Items</th><th>Matches</th><th>Discrepancies</th><th>Value Impact</th><th>Status</th><th>By</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($stocktakes as $s): ?>
                        <tr>
                            <td><strong class="small"><?= sanitize($s['reference']) ?></strong></td>
                            <td><span class="badge bg-<?= $s['type'] === 'full' ? 'primary' : 'info' ?>"><?= ucfirst($s['type']) ?></span>
                                <?php if ($s['category_name']): ?><br><small class="text-muted"><?= sanitize($s['category_name']) ?></small><?php endif; ?>
                            </td>
                            <td><small><?= formatDate($s['started_at'], 'M d, Y H:i') ?></small></td>
                            <td class="text-center"><?= $s['counted_items'] ?>/<?= $s['total_items'] ?></td>
                            <td class="text-center"><span class="text-success"><?= $s['matches'] ?></span></td>
                            <td class="text-center">
                                <?php if ($s['overages'] > 0): ?><span class="badge bg-info">+<?= $s['overages'] ?></span> <?php endif; ?>
                                <?php if ($s['shortages'] > 0): ?><span class="badge bg-danger">-<?= $s['shortages'] ?></span><?php endif; ?>
                                <?php if ($s['overages'] == 0 && $s['shortages'] == 0): ?>-<?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($s['value_impact'] != 0): ?>
                                <small class="fw-bold text-<?= $s['value_impact'] >= 0 ? 'success' : 'danger' ?>"><?= $s['value_impact'] >= 0 ? '+' : '' ?><?= formatCurrency($s['value_impact']) ?></small>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $statusColors = ['in_progress' => 'warning', 'finalized' => 'success', 'cancelled' => 'secondary'];
                                ?>
                                <span class="badge bg-<?= $statusColors[$s['status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_', ' ', $s['status'])) ?></span>
                            </td>
                            <td><small><?= sanitize($s['started_by_name'] ?? '-') ?></small></td>
                            <td>
                                <?php if ($s['status'] === 'in_progress'): ?>
                                <a href="?action=count&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                <?php elseif ($s['status'] === 'finalized'): ?>
                                <a href="?action=summary&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($stocktakes)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-3">No stocktakes recorded yet. Start your first one above.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'count' && $currentST): ?>
<!-- ==================== COUNT VIEW ==================== -->
<div class="card p-3 mb-3 no-print">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h6 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Stocktake: <?= sanitize($currentST['reference']) ?></h6>
            <small class="text-muted"><?= ucfirst($currentST['type']) ?> | Started: <?= formatDate($currentST['started_at'], 'M d, Y H:i') ?> by <?= sanitize($currentST['started_by_name'] ?? '-') ?></small>
            <?php if ($currentST['category_name'] ?? false): ?>
            <br><small class="text-muted">Category: <?= sanitize($currentST['category_name'] ?? '') ?></small>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <a href="stocktake.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <?php if ($currentST['status'] === 'in_progress'): ?>
            <form method="POST" class="d-inline">
                <input type="hidden" name="stocktake_id" value="<?= $currentST['id'] ?>">
                <button type="submit" name="cancel_stocktake" value="1" class="btn btn-sm btn-outline-danger" onclick="return confirm('Cancel this stocktake? No adjustments will be made.')"><i class="bi bi-x-lg me-1"></i>Cancel</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Progress -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card p-3 text-center">
            <small class="text-muted">Total Items</small>
            <div class="fs-4 fw-bold"><?= count($stMedicines) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3 text-center">
            <small class="text-muted">Counted</small>
            <div class="fs-4 fw-bold text-primary"><?= count($stCounts) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3 text-center">
            <small class="text-muted">Remaining</small>
            <div class="fs-4 fw-bold text-warning"><?= count($stMedicines) - count($stCounts) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3 text-center">
            <small class="text-muted">Discrepancies</small>
            <?php
            $discCount = 0;
            foreach ($stCounts as $sc) { if ($sc['difference'] != 0) $discCount++; }
            ?>
            <div class="fs-4 fw-bold <?= $discCount > 0 ? 'text-danger' : 'text-success' ?>"><?= $discCount ?></div>
        </div>
    </div>
</div>

<!-- Counting form -->
<div class="card p-3">
    <form method="POST">
        <input type="hidden" name="stocktake_id" value="<?= $currentST['id'] ?>">
        <div class="mb-2 no-print">
            <input type="text" class="form-control form-control-sm" id="countSearch" placeholder="Search medicines..." oninput="filterCountRows()">
        </div>
        <div class="table-responsive" style="max-height:500px;overflow-y:auto">
            <table class="table table-sm table-hover mb-0" id="countTable">
                <thead class="sticky-top bg-white">
                    <tr><th>Medicine</th><th>Location</th><th>Barcode</th><th class="text-center">System Qty</th><th style="width:100px">Counted</th><th class="text-center">Difference</th><th style="width:120px">Notes</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($stMedicines as $m):
                        $existing = $stCounts[$m['id']] ?? null;
                        $countedVal = $existing ? $existing['counted_quantity'] : $m['quantity_in_stock'];
                        $diff = $existing ? $existing['difference'] : 0;
                    ?>
                    <tr class="count-row" data-name="<?= strtolower($m['name']) ?>" data-barcode="<?= strtolower($m['barcode'] ?? '') ?>">
                        <td>
                            <strong class="small"><?= sanitize($m['name']) ?></strong>
                            <?php if ($m['strength']): ?><br><small class="text-muted"><?= sanitize($m['strength']) ?></small><?php endif; ?>
                        </td>
                        <td><small><?= sanitize($m['shelf_location'] ?? '-') ?></small></td>
                        <td><small class="text-muted"><?= sanitize($m['barcode'] ?? '-') ?></small></td>
                        <td class="text-center"><span class="badge bg-secondary"><?= $m['quantity_in_stock'] ?></span></td>
                        <td>
                            <input type="number" class="form-control form-control-sm count-input" name="counted[<?= $m['id'] ?>]" value="<?= $countedVal ?>" min="0" data-system="<?= $m['quantity_in_stock'] ?>">
                        </td>
                        <td class="text-center diff-cell <?= $diff > 0 ? 'text-success fw-bold' : ($diff < 0 ? 'text-danger fw-bold' : '') ?>">
                            <?= $diff === 0 ? '0' : ($diff > 0 ? '+' . $diff : $diff) ?>
                        </td>
                        <td>
                            <input type="text" class="form-control form-control-sm" name="count_notes[<?= $m['id'] ?>]" value="<?= sanitize($existing['notes'] ?? '') ?>" placeholder="Note">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-3 d-flex justify-content-between align-items-center no-print">
            <span class="text-muted small"><?= count($stMedicines) ?> items</span>
            <div class="d-flex gap-2">
                <button type="submit" name="save_counts" value="1" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Counts</button>
                <?php if ($currentST['status'] === 'in_progress'): ?>
                <button type="submit" name="finalize_stocktake" value="1" class="btn btn-success" onclick="return confirm('Finalize this stocktake? This will apply all adjustments to inventory. This cannot be undone.')"><i class="bi bi-check-circle me-1"></i>Finalize &amp; Apply</button>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<?php elseif ($action === 'summary' && $currentST): ?>
<!-- ==================== SUMMARY VIEW ==================== -->
<div class="card p-3 mb-3 no-print">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h6 class="mb-0"><i class="bi bi-clipboard-data me-2"></i>Stocktake Summary: <?= sanitize($currentST['reference']) ?></h6>
            <small class="text-muted"><?= ucfirst($currentST['type']) ?> | <?= formatDate($currentST['started_at'], 'M d, Y H:i') ?> - <?= $currentST['finalized_at'] ? formatDate($currentST['finalized_at'], 'M d, Y H:i') : 'In Progress' ?></small>
        </div>
        <div class="d-flex gap-2">
            <a href="stocktake.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <button onclick="window.print()" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer me-1"></i>Print</button>
        </div>
    </div>
</div>

<!-- Summary Stats -->
<div class="row g-3 mb-3">
    <div class="col-md-2">
        <div class="card p-3 text-center">
            <small class="text-muted">Total Items</small>
            <div class="fs-4 fw-bold"><?= $currentST['counted_items'] ?></div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card p-3 text-center">
            <small class="text-muted">Matches</small>
            <div class="fs-4 fw-bold text-success"><?= $currentST['matches'] ?></div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card p-3 text-center">
            <small class="text-muted">Overages</small>
            <div class="fs-4 fw-bold text-info"><?= $currentST['overages'] ?></div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card p-3 text-center">
            <small class="text-muted">Shortages</small>
            <div class="fs-4 fw-bold text-danger"><?= $currentST['shortages'] ?></div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card p-3 text-center">
            <small class="text-muted">Accuracy</small>
            <?php $accuracy = $currentST['counted_items'] > 0 ? round($currentST['matches'] / $currentST['counted_items'] * 100, 1) : 0; ?>
            <div class="fs-4 fw-bold"><?= $accuracy ?>%</div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card p-3 text-center">
            <small class="text-muted">Value Impact</small>
            <div class="fs-5 fw-bold text-<?= $currentST['value_impact'] >= 0 ? 'success' : 'danger' ?>">
                <?= $currentST['value_impact'] >= 0 ? '+' : '' ?><?= formatCurrency($currentST['value_impact']) ?>
            </div>
            <small class="text-muted"><?= formatCurrency(abs($currentST['value_impact']) * $exchangeRate, 'LBP') ?></small>
        </div>
    </div>
</div>

<!-- Discrepancies Table -->
<div class="card p-3">
    <h6><i class="bi bi-exclamation-diamond me-2"></i>Discrepancies</h6>
    <?php
    $discrepancies = $db->prepare("SELECT sc.*, m.name, m.barcode, m.cost_price, m.sell_price, u.full_name as counter_name
        FROM stock_counts sc
        JOIN medicines m ON sc.medicine_id = m.id
        LEFT JOIN users u ON sc.counted_by = u.id
        WHERE sc.count_date = ? AND sc.difference != 0
        ORDER BY ABS(sc.difference) DESC");
    $discrepancies->execute([$currentST['started_at']]);
    $discrepancies = $discrepancies->fetchAll();
    ?>
    <?php if (empty($discrepancies)): ?>
    <div class="text-center py-4 text-success">
        <i class="bi bi-check-circle" style="font-size:2rem"></i>
        <p class="mt-2">No discrepancies found. Perfect count!</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover data-table mb-0">
            <thead><tr><th>Medicine</th><th>Barcode</th><th class="text-center">System</th><th class="text-center">Counted</th><th class="text-center">Difference</th><th class="text-end">Cost Impact</th><th>Notes</th><th>Counted By</th></tr></thead>
            <tbody>
                <?php foreach ($discrepancies as $d): ?>
                <tr>
                    <td><strong class="small"><?= sanitize($d['name']) ?></strong></td>
                    <td><small class="text-muted"><?= sanitize($d['barcode'] ?? '-') ?></small></td>
                    <td class="text-center"><?= $d['system_quantity'] ?></td>
                    <td class="text-center"><?= $d['counted_quantity'] ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= $d['difference'] > 0 ? 'success' : 'danger' ?>"><?= $d['difference'] > 0 ? '+' : '' ?><?= $d['difference'] ?></span>
                    </td>
                    <td class="text-end">
                        <?php $impact = $d['difference'] * $d['cost_price']; ?>
                        <span class="text-<?= $impact >= 0 ? 'success' : 'danger' ?>"><?= $impact >= 0 ? '+' : '' ?><?= formatCurrency($impact) ?></span>
                    </td>
                    <td><small><?= sanitize($d['notes'] ?? '-') ?></small></td>
                    <td><small><?= sanitize($d['counter_name'] ?? '-') ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="table-light">
                    <td colspan="5" class="text-end fw-bold">Total Value Impact:</td>
                    <td class="text-end fw-bold text-<?= $currentST['value_impact'] >= 0 ? 'success' : 'danger' ?>">
                        <?= $currentST['value_impact'] >= 0 ? '+' : '' ?><?= formatCurrency($currentST['value_impact']) ?>
                    </td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<div class="alert alert-warning">Stocktake not found. <a href="stocktake.php">Back to list</a></div>
<?php endif; ?>

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

function filterCountRows() {
    var q = document.getElementById('countSearch').value.toLowerCase();
    document.querySelectorAll('.count-row').forEach(function(row) {
        var name = row.getAttribute('data-name') || '';
        var barcode = row.getAttribute('data-barcode') || '';
        row.style.display = (name.indexOf(q) !== -1 || barcode.indexOf(q) !== -1) ? '' : 'none';
    });
}
</script>
SCRIPT;
require_once __DIR__ . '/../../includes/footer.php';
?>
