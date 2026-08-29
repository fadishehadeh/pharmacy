<?php
$pageTitle = 'Data Cleanup';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('admin')) {
    flashMessage('Access denied', 'error');
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$db = getDB();

$action = $_POST['action'] ?? '';
$dryRun = isset($_POST['dry_run']);
$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {
    switch ($action) {

        // ---- Find and merge duplicate medicines ----
        case 'find_duplicates':
            $dupes = $db->query("SELECT LOWER(TRIM(name)) as norm_name, GROUP_CONCAT(id ORDER BY id) as ids, COUNT(*) as cnt
                FROM medicines
                WHERE is_active = 1
                GROUP BY LOWER(TRIM(name))
                HAVING cnt > 1
                ORDER BY cnt DESC
                LIMIT 50")->fetchAll();
            $results = ['type' => 'duplicates', 'data' => $dupes, 'count' => count($dupes)];
            break;

        case 'merge_duplicates':
            $keepId = intval($_POST['keep_id']);
            $removeId = intval($_POST['remove_id']);
            if ($keepId === $removeId || !$keepId || !$removeId) {
                flashMessage('Invalid merge selection', 'error');
                break;
            }

            $keep = $db->prepare("SELECT * FROM medicines WHERE id = ?");
            $keep->execute([$keepId]);
            $keep = $keep->fetch();
            $remove = $db->prepare("SELECT * FROM medicines WHERE id = ?");
            $remove->execute([$removeId]);
            $remove = $remove->fetch();

            if (!$keep || !$remove) {
                flashMessage('Medicine not found', 'error');
                break;
            }

            if ($dryRun) {
                // Count affected records
                $saleItems = $db->prepare("SELECT COUNT(*) FROM sale_items WHERE medicine_id = ?");
                $saleItems->execute([$removeId]);
                $rxItems = $db->prepare("SELECT COUNT(*) FROM prescription_items WHERE medicine_id = ?");
                $rxItems->execute([$removeId]);
                $movements = $db->prepare("SELECT COUNT(*) FROM stock_movements WHERE medicine_id = ?");
                $movements->execute([$removeId]);
                $results = ['type' => 'merge_preview', 'keep' => $keep, 'remove' => $remove,
                    'sale_items' => $saleItems->fetchColumn(),
                    'rx_items' => $rxItems->fetchColumn(),
                    'movements' => $movements->fetchColumn()
                ];
            } else {
                $db->beginTransaction();
                try {
                    // Move stock
                    $newStock = $keep['quantity_in_stock'] + $remove['quantity_in_stock'];
                    $db->prepare("UPDATE medicines SET quantity_in_stock = ? WHERE id = ?")->execute([$newStock, $keepId]);

                    // Re-point references
                    $db->prepare("UPDATE sale_items SET medicine_id = ? WHERE medicine_id = ?")->execute([$keepId, $removeId]);
                    $db->prepare("UPDATE prescription_items SET medicine_id = ? WHERE medicine_id = ?")->execute([$keepId, $removeId]);
                    $db->prepare("UPDATE stock_movements SET medicine_id = ? WHERE medicine_id = ?")->execute([$keepId, $removeId]);
                    try { $db->prepare("UPDATE purchase_order_items SET medicine_id = ? WHERE medicine_id = ?")->execute([$keepId, $removeId]); } catch (Exception $e) {}
                    try { $db->prepare("UPDATE medicine_interactions SET medicine_a_id = ? WHERE medicine_a_id = ?")->execute([$keepId, $removeId]); } catch (Exception $e) {}
                    try { $db->prepare("UPDATE medicine_interactions SET medicine_b_id = ? WHERE medicine_b_id = ?")->execute([$keepId, $removeId]); } catch (Exception $e) {}
                    try { $db->prepare("UPDATE medicine_alternatives SET medicine_id = ? WHERE medicine_id = ?")->execute([$keepId, $removeId]); } catch (Exception $e) {}
                    try { $db->prepare("UPDATE medicine_alternatives SET alternative_id = ? WHERE alternative_id = ?")->execute([$keepId, $removeId]); } catch (Exception $e) {}

                    // Deactivate duplicate
                    $db->prepare("UPDATE medicines SET is_active = 0, notes = CONCAT(COALESCE(notes,''), ' [Merged into ID: $keepId]') WHERE id = ?")->execute([$removeId]);

                    $db->commit();
                    addAuditLog('merge', 'medicines', $keepId, ['removed_id' => $removeId], ['new_stock' => $newStock]);
                    flashMessage("Merged medicine #{$removeId} into #{$keepId}. Stock updated to {$newStock}.");
                } catch (Exception $e) {
                    $db->rollBack();
                    flashMessage('Merge failed: ' . $e->getMessage(), 'error');
                }
            }
            break;

        // ---- Remove orphan records ----
        case 'find_orphans':
            $orphanSaleItems = $db->query("SELECT COUNT(*) FROM sale_items si LEFT JOIN sales s ON si.sale_id = s.id WHERE s.id IS NULL")->fetchColumn();
            $orphanMovements = $db->query("SELECT COUNT(*) FROM stock_movements sm LEFT JOIN medicines m ON sm.medicine_id = m.id WHERE m.id IS NULL")->fetchColumn();
            $orphanRxItems = $db->query("SELECT COUNT(*) FROM prescription_items pi LEFT JOIN prescriptions p ON pi.prescription_id = p.id WHERE p.id IS NULL")->fetchColumn();
            $results = ['type' => 'orphans',
                'sale_items' => intval($orphanSaleItems),
                'movements' => intval($orphanMovements),
                'rx_items' => intval($orphanRxItems),
                'total' => intval($orphanSaleItems) + intval($orphanMovements) + intval($orphanRxItems)
            ];
            break;

        case 'clean_orphans':
            if ($dryRun) {
                $orphanSaleItems = $db->query("SELECT COUNT(*) FROM sale_items si LEFT JOIN sales s ON si.sale_id = s.id WHERE s.id IS NULL")->fetchColumn();
                $orphanMovements = $db->query("SELECT COUNT(*) FROM stock_movements sm LEFT JOIN medicines m ON sm.medicine_id = m.id WHERE m.id IS NULL")->fetchColumn();
                $orphanRxItems = $db->query("SELECT COUNT(*) FROM prescription_items pi LEFT JOIN prescriptions p ON pi.prescription_id = p.id WHERE p.id IS NULL")->fetchColumn();
                $results = ['type' => 'orphan_preview', 'sale_items' => intval($orphanSaleItems), 'movements' => intval($orphanMovements), 'rx_items' => intval($orphanRxItems)];
            } else {
                $c1 = $db->exec("DELETE si FROM sale_items si LEFT JOIN sales s ON si.sale_id = s.id WHERE s.id IS NULL");
                $c2 = $db->exec("DELETE sm FROM stock_movements sm LEFT JOIN medicines m ON sm.medicine_id = m.id WHERE m.id IS NULL");
                $c3 = $db->exec("DELETE pi FROM prescription_items pi LEFT JOIN prescriptions p ON pi.prescription_id = p.id WHERE p.id IS NULL");
                addAuditLog('cleanup_orphans', 'system', 0, null, ['sale_items' => $c1, 'movements' => $c2, 'rx_items' => $c3]);
                flashMessage("Cleaned orphan records: {$c1} sale items, {$c2} movements, {$c3} prescription items.");
            }
            break;

        // ---- Archive old sales ----
        case 'archive_sales':
            $beforeDate = $_POST['archive_before'] ?? date('Y-m-d', strtotime('-1 year'));
            $count = $db->prepare("SELECT COUNT(*) FROM sales WHERE DATE(sale_date) < ?");
            $count->execute([$beforeDate]);
            $archiveCount = intval($count->fetchColumn());

            if ($dryRun) {
                $results = ['type' => 'archive_preview', 'count' => $archiveCount, 'before' => $beforeDate];
            } else {
                // Create archive tables if needed
                try {
                    $db->exec("CREATE TABLE IF NOT EXISTS sales_archive LIKE sales");
                    $db->exec("CREATE TABLE IF NOT EXISTS sale_items_archive LIKE sale_items");
                } catch (Exception $e) {}

                $db->beginTransaction();
                try {
                    $db->prepare("INSERT INTO sales_archive SELECT * FROM sales WHERE DATE(sale_date) < ?")->execute([$beforeDate]);
                    $db->prepare("INSERT INTO sale_items_archive SELECT si.* FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE DATE(s.sale_date) < ?")->execute([$beforeDate]);
                    $db->prepare("DELETE si FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE DATE(s.sale_date) < ?")->execute([$beforeDate]);
                    $db->prepare("DELETE FROM sales WHERE DATE(sale_date) < ?")->execute([$beforeDate]);
                    $db->commit();
                    addAuditLog('archive_sales', 'sales', 0, null, ['before' => $beforeDate, 'count' => $archiveCount]);
                    flashMessage("Archived {$archiveCount} sales records before {$beforeDate}.");
                } catch (Exception $e) {
                    $db->rollBack();
                    flashMessage('Archive failed: ' . $e->getMessage(), 'error');
                }
            }
            break;

        // ---- Clear expired promotions ----
        case 'clear_promotions':
            $expiredPromos = $db->query("SELECT COUNT(*) FROM medicines WHERE promotional_price IS NOT NULL AND promotional_end IS NOT NULL AND promotional_end < CURDATE()")->fetchColumn();
            if ($dryRun) {
                $results = ['type' => 'promo_preview', 'count' => intval($expiredPromos)];
            } else {
                $cleared = $db->exec("UPDATE medicines SET promotional_price = NULL, promotional_start = NULL, promotional_end = NULL WHERE promotional_price IS NOT NULL AND promotional_end IS NOT NULL AND promotional_end < CURDATE()");
                addAuditLog('clear_promotions', 'medicines', 0, null, ['cleared' => $cleared]);
                flashMessage("Cleared {$cleared} expired promotional prices.");
            }
            break;

        // ---- Reset demo data ----
        case 'reset_demo':
            if ($dryRun) {
                $results = ['type' => 'reset_preview'];
            } else {
                $confirm = $_POST['confirm_reset'] ?? '';
                if ($confirm !== 'RESET') {
                    flashMessage('Type RESET to confirm. Demo data not cleared.', 'error');
                } else {
                    $db->beginTransaction();
                    try {
                        $db->exec("DELETE FROM sale_returns");
                        $db->exec("DELETE FROM sale_items");
                        $db->exec("DELETE FROM sales");
                        $db->exec("DELETE FROM stock_movements");
                        $db->exec("DELETE FROM prescription_items");
                        $db->exec("DELETE FROM prescriptions");
                        try { $db->exec("DELETE FROM insurance_claims"); } catch (Exception $e) {}
                        try { $db->exec("DELETE FROM expenses"); } catch (Exception $e) {}
                        $db->exec("UPDATE medicines SET quantity_in_stock = 0");
                        $db->commit();
                        addAuditLog('reset_demo', 'system', 0);
                        flashMessage('Demo data has been reset. All transactional data cleared.');
                    } catch (Exception $e) {
                        $db->rollBack();
                        flashMessage('Reset failed: ' . $e->getMessage(), 'error');
                    }
                }
            }
            break;

        // ---- Recalculate stock from movements ----
        case 'recalc_stock':
            $medicines = $db->query("SELECT id, name, quantity_in_stock FROM medicines WHERE is_active = 1")->fetchAll();
            $mismatches = [];
            foreach ($medicines as $med) {
                $calc = $db->prepare("SELECT COALESCE(SUM(CASE WHEN type = 'in' THEN quantity WHEN type = 'out' THEN -quantity WHEN type = 'adjustment' THEN quantity ELSE 0 END), 0) as calc_stock FROM stock_movements WHERE medicine_id = ?");
                $calc->execute([$med['id']]);
                $calcStock = intval($calc->fetchColumn());
                if ($calcStock != $med['quantity_in_stock']) {
                    $mismatches[] = ['id' => $med['id'], 'name' => $med['name'], 'current' => $med['quantity_in_stock'], 'calculated' => $calcStock];
                }
            }

            if ($dryRun) {
                $results = ['type' => 'recalc_preview', 'mismatches' => $mismatches, 'count' => count($mismatches)];
            } else {
                $fixed = 0;
                foreach ($mismatches as $mm) {
                    $db->prepare("UPDATE medicines SET quantity_in_stock = ? WHERE id = ?")->execute([$mm['calculated'], $mm['id']]);
                    addStockMovement($mm['id'], 'adjustment', $mm['calculated'] - $mm['current'], 'Stock recalculation cleanup');
                    $fixed++;
                }
                addAuditLog('recalc_stock', 'medicines', 0, null, ['fixed' => $fixed]);
                flashMessage("Recalculated stock for {$fixed} medicines.");
            }
            break;

        // ---- Fix inconsistent data ----
        case 'fix_inconsistent':
            $negativeStock = $db->query("SELECT id, name, quantity_in_stock FROM medicines WHERE quantity_in_stock < 0 AND is_active = 1")->fetchAll();
            $missingPrices = $db->query("SELECT id, name FROM medicines WHERE (sell_price IS NULL OR sell_price = 0) AND is_active = 1")->fetchAll();
            $nullCosts = $db->query("SELECT id, name FROM medicines WHERE cost_price IS NULL AND is_active = 1")->fetchAll();

            if ($dryRun) {
                $results = ['type' => 'inconsistent_preview', 'negative_stock' => $negativeStock, 'missing_prices' => $missingPrices, 'null_costs' => $nullCosts];
            } else {
                $fixedNeg = 0;
                foreach ($negativeStock as $ns) {
                    $db->prepare("UPDATE medicines SET quantity_in_stock = 0 WHERE id = ?")->execute([$ns['id']]);
                    addStockMovement($ns['id'], 'adjustment', abs($ns['quantity_in_stock']), 'Fix negative stock');
                    $fixedNeg++;
                }
                $fixedCost = $db->exec("UPDATE medicines SET cost_price = 0 WHERE cost_price IS NULL AND is_active = 1");
                addAuditLog('fix_inconsistent', 'medicines', 0, null, ['negative_stock_fixed' => $fixedNeg, 'null_costs_fixed' => $fixedCost]);
                flashMessage("Fixed {$fixedNeg} negative stock entries, {$fixedCost} null cost prices.");
            }
            break;
    }

    if (!$results && !$dryRun) {
        header('Location: data_cleanup.php');
        exit;
    }
}

// Get current stats for overview
$totalMeds = $db->query("SELECT COUNT(*) FROM medicines WHERE is_active = 1")->fetchColumn();
$totalSales = $db->query("SELECT COUNT(*) FROM sales")->fetchColumn();
$negativeCount = $db->query("SELECT COUNT(*) FROM medicines WHERE quantity_in_stock < 0 AND is_active = 1")->fetchColumn();
$orphanCount = $db->query("SELECT COUNT(*) FROM sale_items si LEFT JOIN sales s ON si.sale_id = s.id WHERE s.id IS NULL")->fetchColumn();
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card"><div class="stat-label">Active Medicines</div><div class="stat-value"><?= number_format($totalMeds) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card info"><div class="stat-label">Total Sales</div><div class="stat-value"><?= number_format($totalSales) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card <?= $negativeCount > 0 ? 'danger' : 'success' ?>"><div class="stat-label">Negative Stock</div><div class="stat-value"><?= $negativeCount ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card <?= $orphanCount > 0 ? 'warning' : 'success' ?>"><div class="stat-label">Orphan Records</div><div class="stat-value"><?= $orphanCount ?></div></div></div>
</div>

<?php if ($results): ?>
<div class="card mb-3 border-primary">
    <div class="card-header bg-primary text-white">
        <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>
            <?= $dryRun ? 'Dry Run Results (no changes made)' : 'Results' ?>
        </h6>
    </div>
    <div class="card-body">
        <?php if ($results['type'] === 'duplicates'): ?>
            <?php if (empty($results['data'])): ?>
            <p class="text-success mb-0"><i class="bi bi-check-circle me-2"></i>No duplicate medicines found.</p>
            <?php else: ?>
            <p class="text-warning"><i class="bi bi-exclamation-triangle me-2"></i>Found <?= $results['count'] ?> groups of duplicate medicines:</p>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Name</th><th>Count</th><th>IDs</th><th class="no-print">Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($results['data'] as $d): ?>
                        <?php $ids = explode(',', $d['ids']); ?>
                        <tr>
                            <td><?= sanitize($d['norm_name']) ?></td>
                            <td><span class="badge bg-warning"><?= $d['cnt'] ?></span></td>
                            <td><?= sanitize($d['ids']) ?></td>
                            <td class="no-print">
                                <?php if (count($ids) >= 2): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="merge_duplicates">
                                    <input type="hidden" name="keep_id" value="<?= $ids[0] ?>">
                                    <input type="hidden" name="remove_id" value="<?= $ids[1] ?>">
                                    <button type="submit" name="dry_run" value="1" class="btn btn-sm btn-outline-info">Preview Merge</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        <?php elseif ($results['type'] === 'merge_preview'): ?>
            <h6>Merge Preview</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="card p-2 border-success">
                        <strong class="text-success">Keep: #<?= $results['keep']['id'] ?></strong>
                        <div><?= sanitize($results['keep']['name']) ?></div>
                        <small>Stock: <?= $results['keep']['quantity_in_stock'] ?> | Price: <?= formatCurrency($results['keep']['sell_price']) ?></small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card p-2 border-danger">
                        <strong class="text-danger">Remove: #<?= $results['remove']['id'] ?></strong>
                        <div><?= sanitize($results['remove']['name']) ?></div>
                        <small>Stock: <?= $results['remove']['quantity_in_stock'] ?> | Price: <?= formatCurrency($results['remove']['sell_price']) ?></small>
                    </div>
                </div>
            </div>
            <p>Records to re-point: <?= $results['sale_items'] ?> sale items, <?= $results['rx_items'] ?> prescription items, <?= $results['movements'] ?> stock movements</p>
            <form method="POST">
                <input type="hidden" name="action" value="merge_duplicates">
                <input type="hidden" name="keep_id" value="<?= $results['keep']['id'] ?>">
                <input type="hidden" name="remove_id" value="<?= $results['remove']['id'] ?>">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Confirm merge? This cannot be undone.')"><i class="bi bi-arrow-repeat me-1"></i>Execute Merge</button>
            </form>

        <?php elseif ($results['type'] === 'orphans'): ?>
            <?php if ($results['total'] === 0): ?>
            <p class="text-success mb-0"><i class="bi bi-check-circle me-2"></i>No orphan records found. Database is clean.</p>
            <?php else: ?>
            <p class="text-warning"><i class="bi bi-exclamation-triangle me-2"></i>Found <?= $results['total'] ?> orphan records:</p>
            <ul>
                <li>Sale items without parent sale: <strong><?= $results['sale_items'] ?></strong></li>
                <li>Stock movements for deleted medicines: <strong><?= $results['movements'] ?></strong></li>
                <li>Prescription items without parent prescription: <strong><?= $results['rx_items'] ?></strong></li>
            </ul>
            <?php endif; ?>

        <?php elseif ($results['type'] === 'orphan_preview'): ?>
            <p>Would remove: <?= $results['sale_items'] ?> orphan sale items, <?= $results['movements'] ?> orphan movements, <?= $results['rx_items'] ?> orphan prescription items.</p>
            <form method="POST">
                <input type="hidden" name="action" value="clean_orphans">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Delete all orphan records?')"><i class="bi bi-trash me-1"></i>Execute Cleanup</button>
            </form>

        <?php elseif ($results['type'] === 'archive_preview'): ?>
            <p>Would archive <strong><?= $results['count'] ?></strong> sales and their items from before <?= formatDate($results['before'], 'M d, Y') ?>.</p>
            <?php if ($results['count'] > 0): ?>
            <form method="POST">
                <input type="hidden" name="action" value="archive_sales">
                <input type="hidden" name="archive_before" value="<?= sanitize($results['before']) ?>">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Archive these sales? They will be moved to archive tables.')"><i class="bi bi-archive me-1"></i>Execute Archive</button>
            </form>
            <?php endif; ?>

        <?php elseif ($results['type'] === 'promo_preview'): ?>
            <p>Would clear <strong><?= $results['count'] ?></strong> expired promotional prices.</p>
            <?php if ($results['count'] > 0): ?>
            <form method="POST">
                <input type="hidden" name="action" value="clear_promotions">
                <button type="submit" class="btn btn-warning" onclick="return confirm('Clear expired promotions?')"><i class="bi bi-tag me-1"></i>Execute</button>
            </form>
            <?php endif; ?>

        <?php elseif ($results['type'] === 'reset_preview'): ?>
            <div class="alert alert-danger">
                <h6><i class="bi bi-exclamation-triangle-fill me-2"></i>Warning: This will delete ALL transactional data</h6>
                <p>This action will remove all sales, sale items, returns, stock movements, prescriptions, claims, and expenses. Medicine records will remain but stock will be reset to 0.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="reset_demo">
                    <div class="mb-2">
                        <label class="form-label">Type <strong>RESET</strong> to confirm:</label>
                        <input type="text" class="form-control" name="confirm_reset" autocomplete="off" style="max-width:200px">
                    </div>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Reset All Data</button>
                </form>
            </div>

        <?php elseif ($results['type'] === 'recalc_preview'): ?>
            <?php if (empty($results['mismatches'])): ?>
            <p class="text-success mb-0"><i class="bi bi-check-circle me-2"></i>All stock quantities match movement records.</p>
            <?php else: ?>
            <p class="text-warning"><i class="bi bi-exclamation-triangle me-2"></i>Found <?= $results['count'] ?> stock mismatches:</p>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>ID</th><th>Medicine</th><th>Current Stock</th><th>Calculated Stock</th><th>Difference</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($results['mismatches'], 0, 20) as $mm): ?>
                        <tr>
                            <td><?= $mm['id'] ?></td>
                            <td><?= sanitize($mm['name']) ?></td>
                            <td><?= $mm['current'] ?></td>
                            <td><strong><?= $mm['calculated'] ?></strong></td>
                            <td class="<?= $mm['calculated'] - $mm['current'] > 0 ? 'text-success' : 'text-danger' ?>"><?= $mm['calculated'] - $mm['current'] > 0 ? '+' : '' ?><?= $mm['calculated'] - $mm['current'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($results['count'] > 20): ?><p class="text-muted small">Showing first 20 of <?= $results['count'] ?> mismatches.</p><?php endif; ?>
            <form method="POST">
                <input type="hidden" name="action" value="recalc_stock">
                <button type="submit" class="btn btn-warning" onclick="return confirm('Recalculate stock for <?= $results['count'] ?> medicines?')"><i class="bi bi-calculator me-1"></i>Apply Recalculation</button>
            </form>
            <?php endif; ?>

        <?php elseif ($results['type'] === 'inconsistent_preview'): ?>
            <h6>Inconsistent Data Found:</h6>
            <ul>
                <li>Negative stock: <strong><?= count($results['negative_stock']) ?></strong> medicines</li>
                <li>Missing sell prices: <strong><?= count($results['missing_prices']) ?></strong> medicines</li>
                <li>Null cost prices: <strong><?= count($results['null_costs']) ?></strong> medicines</li>
            </ul>
            <?php if (!empty($results['negative_stock'])): ?>
            <p class="small text-muted">Negative stock: <?php foreach ($results['negative_stock'] as $ns): ?><?= sanitize($ns['name']) ?> (<?= $ns['quantity_in_stock'] ?>), <?php endforeach; ?></p>
            <?php endif; ?>
            <?php if (count($results['negative_stock']) + count($results['null_costs']) > 0): ?>
            <form method="POST">
                <input type="hidden" name="action" value="fix_inconsistent">
                <button type="submit" class="btn btn-warning" onclick="return confirm('Fix inconsistent data?')"><i class="bi bi-wrench me-1"></i>Fix Inconsistencies</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Cleanup Tools -->
<div class="row g-3">
    <!-- Find Duplicates -->
    <div class="col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body">
                <h6><i class="bi bi-files me-2 text-primary"></i>Find Duplicate Medicines</h6>
                <p class="small text-muted">Find medicines with identical names that may need to be merged.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="find_duplicates">
                    <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-search me-1"></i>Scan for Duplicates</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Remove Orphans -->
    <div class="col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body">
                <h6><i class="bi bi-link-45deg me-2 text-warning"></i>Remove Orphan Records</h6>
                <p class="small text-muted">Find and remove records that reference deleted parent entries.</p>
                <div class="d-flex gap-2">
                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="find_orphans"><button type="submit" class="btn btn-outline-warning btn-sm"><i class="bi bi-search me-1"></i>Scan</button></form>
                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="clean_orphans"><button type="submit" name="dry_run" value="1" class="btn btn-outline-info btn-sm"><i class="bi bi-eye me-1"></i>Dry Run</button></form>
                </div>
            </div>
        </div>
    </div>

    <!-- Archive Old Sales -->
    <div class="col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body">
                <h6><i class="bi bi-archive me-2 text-info"></i>Archive Old Sales</h6>
                <p class="small text-muted">Move old sales data to archive tables to improve performance.</p>
                <form method="POST" class="d-flex gap-2 align-items-end">
                    <input type="hidden" name="action" value="archive_sales">
                    <div>
                        <label class="form-label small">Before date:</label>
                        <input type="date" class="form-control form-control-sm" name="archive_before" value="<?= date('Y-m-d', strtotime('-1 year')) ?>">
                    </div>
                    <button type="submit" name="dry_run" value="1" class="btn btn-outline-info btn-sm"><i class="bi bi-eye me-1"></i>Preview</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Clear Expired Promotions -->
    <div class="col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body">
                <h6><i class="bi bi-tag me-2 text-success"></i>Clear Expired Promotions</h6>
                <p class="small text-muted">Remove promotional pricing that has passed its end date.</p>
                <div class="d-flex gap-2">
                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="clear_promotions"><button type="submit" name="dry_run" value="1" class="btn btn-outline-info btn-sm"><i class="bi bi-eye me-1"></i>Dry Run</button></form>
                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="clear_promotions"><button type="submit" class="btn btn-outline-success btn-sm" onclick="return confirm('Clear expired promotions?')"><i class="bi bi-tag me-1"></i>Execute</button></form>
                </div>
            </div>
        </div>
    </div>

    <!-- Recalculate Stock -->
    <div class="col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body">
                <h6><i class="bi bi-calculator me-2 text-primary"></i>Recalculate Stock Quantities</h6>
                <p class="small text-muted">Recalculate stock from movement records to fix discrepancies.</p>
                <div class="d-flex gap-2">
                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="recalc_stock"><button type="submit" name="dry_run" value="1" class="btn btn-outline-info btn-sm"><i class="bi bi-eye me-1"></i>Dry Run</button></form>
                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="recalc_stock"><button type="submit" class="btn btn-outline-primary btn-sm" onclick="return confirm('Recalculate all stock?')"><i class="bi bi-calculator me-1"></i>Execute</button></form>
                </div>
            </div>
        </div>
    </div>

    <!-- Fix Inconsistent Data -->
    <div class="col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body">
                <h6><i class="bi bi-wrench me-2 text-danger"></i>Fix Inconsistent Data</h6>
                <p class="small text-muted">Fix negative stock, missing prices, and other data inconsistencies.</p>
                <div class="d-flex gap-2">
                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="fix_inconsistent"><button type="submit" name="dry_run" value="1" class="btn btn-outline-info btn-sm"><i class="bi bi-eye me-1"></i>Dry Run</button></form>
                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="fix_inconsistent"><button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Fix inconsistent data?')"><i class="bi bi-wrench me-1"></i>Execute</button></form>
                </div>
            </div>
        </div>
    </div>

    <!-- Reset Demo Data -->
    <div class="col-12">
        <div class="card border-danger">
            <div class="card-body">
                <h6><i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>Reset Demo Data</h6>
                <p class="small text-muted">Remove ALL transactional data (sales, prescriptions, movements, expenses). Medicine records remain but stock is zeroed out. <strong>This cannot be undone.</strong></p>
                <form method="POST">
                    <input type="hidden" name="action" value="reset_demo">
                    <button type="submit" name="dry_run" value="1" class="btn btn-outline-danger btn-sm"><i class="bi bi-eye me-1"></i>Preview Reset</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
