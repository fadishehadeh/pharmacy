<?php
$pageTitle = 'Near-Expiry Deals';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { header('Location: ' . BASE_URL); exit; }
$db = getDB();

// Auto-create promotions table if not exists
$db->exec("CREATE TABLE IF NOT EXISTS promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id INT NOT NULL,
    discount_pct DECIMAL(5,2) NOT NULL,
    promo_price DECIMAL(10,2) NOT NULL,
    original_price DECIMAL(10,2) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason VARCHAR(100) DEFAULT 'near_expiry',
    status ENUM('active','ended','cancelled') DEFAULT 'active',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id)
)");

// Auto-end promotions past end date
$db->exec("UPDATE promotions SET status = 'ended' WHERE status = 'active' AND end_date < CURDATE()");

// POST: Create single promotion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_promo'])) {
    $medId = intval($_POST['medicine_id']);
    $discount = floatval($_POST['discount_pct']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];

    if ($discount < 10 || $discount > 50) {
        flashMessage('Discount must be between 10% and 50%', 'danger');
    } elseif (strtotime($endDate) <= strtotime($startDate)) {
        flashMessage('End date must be after start date', 'danger');
    } else {
        $med = $db->prepare("SELECT * FROM medicines WHERE id = ? AND is_active = 1");
        $med->execute([$medId]);
        $med = $med->fetch();

        if ($med) {
            $promoPrice = round($med['sell_price'] * (1 - $discount / 100), 2);
            $db->prepare("INSERT INTO promotions (medicine_id, discount_pct, promo_price, original_price, start_date, end_date, reason, status, created_by)
                VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$medId, $discount, $promoPrice, $med['sell_price'], $startDate, $endDate, 'near_expiry', 'active', $_SESSION['user_id']]);
            addAuditLog('create', 'promotions', $db->lastInsertId(), null, ['medicine' => $med['name'], 'discount' => $discount]);
            flashMessage('Promotion created: ' . sanitize($med['name']) . ' at ' . $discount . '% off');
        } else {
            flashMessage('Medicine not found', 'danger');
        }
    }
    header('Location: near_expiry_deals.php');
    exit;
}

// POST: Bulk promote
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_promote'])) {
    $medIds = $_POST['bulk_ids'] ?? [];
    $discount = floatval($_POST['bulk_discount']);
    $startDate = $_POST['bulk_start_date'];
    $endDate = $_POST['bulk_end_date'];
    $created = 0;

    if ($discount >= 10 && $discount <= 50 && strtotime($endDate) > strtotime($startDate) && !empty($medIds)) {
        foreach ($medIds as $medId) {
            $medId = intval($medId);
            $med = $db->prepare("SELECT * FROM medicines WHERE id = ? AND is_active = 1");
            $med->execute([$medId]);
            $med = $med->fetch();
            if ($med) {
                // Skip if already has active promotion
                $existing = $db->prepare("SELECT id FROM promotions WHERE medicine_id = ? AND status = 'active'");
                $existing->execute([$medId]);
                if ($existing->fetch()) continue;

                $promoPrice = round($med['sell_price'] * (1 - $discount / 100), 2);
                $db->prepare("INSERT INTO promotions (medicine_id, discount_pct, promo_price, original_price, start_date, end_date, reason, status, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$medId, $discount, $promoPrice, $med['sell_price'], $startDate, $endDate, 'near_expiry', 'active', $_SESSION['user_id']]);
                $created++;
            }
        }
        addAuditLog('bulk_promote', 'promotions', 0, null, ['count' => $created, 'discount' => $discount]);
        flashMessage("$created promotions created at {$discount}% off");
    } else {
        flashMessage('Invalid bulk promotion parameters', 'danger');
    }
    header('Location: near_expiry_deals.php');
    exit;
}

// POST: Cancel promotion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_promo'])) {
    $promoId = intval($_POST['promo_id']);
    $db->prepare("UPDATE promotions SET status = 'cancelled' WHERE id = ? AND status = 'active'")->execute([$promoId]);
    addAuditLog('cancel', 'promotions', $promoId);
    flashMessage('Promotion cancelled');
    header('Location: near_expiry_deals.php');
    exit;
}

// Fetch near-expiry medicines (1-6 months, stock > 0)
$nearExpiry = $db->query("SELECT m.*, c.name as category_name,
    DATEDIFF(m.expiry_date, CURDATE()) as days_left,
    (SELECT p.id FROM promotions p WHERE p.medicine_id = m.id AND p.status = 'active' LIMIT 1) as active_promo_id
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE m.is_active = 1 AND m.quantity_in_stock > 0
    AND m.expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 MONTH) AND DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
    ORDER BY m.expiry_date ASC")->fetchAll();

// Active promotions
$activePromos = $db->query("SELECT p.*, m.name as med_name, m.expiry_date, m.quantity_in_stock,
    DATEDIFF(m.expiry_date, CURDATE()) as days_to_expiry,
    DATEDIFF(p.end_date, CURDATE()) as promo_days_left,
    u.full_name as creator_name
    FROM promotions p
    JOIN medicines m ON p.medicine_id = m.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE p.status = 'active'
    ORDER BY m.expiry_date ASC")->fetchAll();

// Stats
$totalOnPromo = count($activePromos);
$potentialSavings = 0;
$expectedRecovery = 0;
foreach ($activePromos as $ap) {
    $potentialSavings += ($ap['original_price'] - $ap['promo_price']) * $ap['quantity_in_stock'];
    $expectedRecovery += $ap['promo_price'] * $ap['quantity_in_stock'];
}
?>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card info">
            <div class="stat-label">Active Promotions</div>
            <div class="stat-value"><?= $totalOnPromo ?></div>
            <small class="text-muted">items on deal</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="stat-label">Near-Expiry Items</div>
            <div class="stat-value"><?= count($nearExpiry) ?></div>
            <small class="text-muted">1-6 months remaining</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card success">
            <div class="stat-label">Customer Savings</div>
            <div class="stat-value"><?= formatCurrency($potentialSavings) ?></div>
            <small class="text-muted">potential discount value</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Expected Recovery</div>
            <div class="stat-value"><?= formatCurrency($expectedRecovery) ?></div>
            <small class="text-muted">revenue from promos</small>
        </div>
    </div>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#activeTab">Active Promotions (<?= $totalOnPromo ?>)</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#nearExpiryTab">Near-Expiry Stock (<?= count($nearExpiry) ?>)</a></li>
</ul>

<div class="tab-content">
    <!-- Active Promotions Tab -->
    <div class="tab-pane fade show active" id="activeTab">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-tag me-2"></i>Active Promotions</h6>
                <button class="btn btn-sm btn-primary no-print" data-bs-toggle="modal" data-bs-target="#newPromo"><i class="bi bi-plus me-1"></i>New Promotion</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 data-table">
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th class="text-end">Original</th>
                            <th class="text-end">Promo Price</th>
                            <th class="text-center">Discount</th>
                            <th>Expiry</th>
                            <th>Days Left</th>
                            <th>Promo Period</th>
                            <th>Stock</th>
                            <th class="no-print"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activePromos as $ap): ?>
                        <tr>
                            <td>
                                <strong class="small"><?= sanitize($ap['med_name']) ?></strong>
                                <br><small class="text-muted">by <?= sanitize($ap['creator_name'] ?? '-') ?></small>
                            </td>
                            <td class="text-end"><small class="text-decoration-line-through text-muted"><?= formatCurrency($ap['original_price']) ?></small></td>
                            <td class="text-end fw-semibold text-success"><?= formatCurrency($ap['promo_price']) ?></td>
                            <td class="text-center"><span class="badge bg-success"><?= $ap['discount_pct'] ?>% OFF</span></td>
                            <td>
                                <small><?= formatDate($ap['expiry_date'], 'M d, Y') ?></small>
                            </td>
                            <td>
                                <span class="badge bg-<?= $ap['days_to_expiry'] <= 30 ? 'danger' : ($ap['days_to_expiry'] <= 60 ? 'warning' : 'info') ?>">
                                    <?= $ap['days_to_expiry'] ?>d to expiry
                                </span>
                            </td>
                            <td>
                                <small><?= formatDate($ap['start_date'], 'M d') ?> - <?= formatDate($ap['end_date'], 'M d, Y') ?></small>
                                <br><small class="text-muted"><?= max(0, $ap['promo_days_left']) ?>d remaining</small>
                            </td>
                            <td><?= $ap['quantity_in_stock'] ?></td>
                            <td class="no-print">
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="promo_id" value="<?= $ap['id'] ?>">
                                    <button type="submit" name="cancel_promo" value="1" class="btn btn-sm btn-outline-danger" onclick="return confirm('Cancel this promotion?')"><i class="bi bi-x-lg"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($activePromos)): ?><tr><td colspan="9" class="text-center text-muted py-3">No active promotions</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Near-Expiry Stock Tab -->
    <div class="tab-pane fade" id="nearExpiryTab">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Near-Expiry Stock (1-6 Months)</h6>
                <button class="btn btn-sm btn-warning no-print" data-bs-toggle="modal" data-bs-target="#bulkPromo"><i class="bi bi-tags me-1"></i>Bulk Promote</button>
            </div>
            <form id="bulkForm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 data-table">
                    <thead>
                        <tr>
                            <th class="no-print"><input type="checkbox" id="selectAll"></th>
                            <th>Medicine</th>
                            <th>Category</th>
                            <th class="text-end">Price</th>
                            <th class="text-end">Stock Value</th>
                            <th>Expiry</th>
                            <th>Days Left</th>
                            <th>Stock</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($nearExpiry as $ne): ?>
                        <tr>
                            <td class="no-print">
                                <?php if (!$ne['active_promo_id']): ?>
                                <input type="checkbox" class="bulk-check" value="<?= $ne['id'] ?>">
                                <?php else: ?>
                                <i class="bi bi-check-circle text-success" title="Already on promotion"></i>
                                <?php endif; ?>
                            </td>
                            <td><strong class="small"><?= sanitize($ne['name']) ?></strong></td>
                            <td><small><?= sanitize($ne['category_name'] ?? '-') ?></small></td>
                            <td class="text-end"><?= formatCurrency($ne['sell_price']) ?></td>
                            <td class="text-end text-warning"><?= formatCurrency($ne['sell_price'] * $ne['quantity_in_stock']) ?></td>
                            <td><small><?= formatDate($ne['expiry_date'], 'M d, Y') ?></small></td>
                            <td>
                                <?php
                                $dl = $ne['days_left'];
                                if ($dl <= 30) $bg = 'danger';
                                elseif ($dl <= 60) $bg = 'warning';
                                elseif ($dl <= 90) $bg = 'info';
                                else $bg = 'secondary';
                                ?>
                                <span class="badge bg-<?= $bg ?>"><?= $dl ?>d</span>
                            </td>
                            <td><?= $ne['quantity_in_stock'] ?></td>
                            <td>
                                <?php if ($ne['active_promo_id']): ?>
                                <span class="badge bg-success">On Promo</span>
                                <?php else: ?>
                                <span class="badge bg-outline-secondary border">Available</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($nearExpiry)): ?><tr><td colspan="9" class="text-center text-muted py-3">No near-expiry items found</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            </form>
        </div>
    </div>
</div>

<!-- New Promotion Modal -->
<div class="modal fade" id="newPromo"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title"><i class="bi bi-tag me-2"></i>Create Promotion</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">Medicine</label>
            <select class="form-select" name="medicine_id" required>
                <option value="">Select near-expiry item...</option>
                <?php foreach ($nearExpiry as $ne): ?>
                <?php if (!$ne['active_promo_id']): ?>
                <option value="<?= $ne['id'] ?>"><?= sanitize($ne['name']) ?> - <?= formatCurrency($ne['sell_price']) ?> (Exp: <?= formatDate($ne['expiry_date'], 'M d, Y') ?>)</option>
                <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Discount Percentage</label>
            <div class="input-group">
                <input type="number" class="form-control" name="discount_pct" min="10" max="50" step="5" value="20" required id="discountPct">
                <span class="input-group-text">%</span>
            </div>
            <small class="text-muted">Allowed range: 10% - 50%</small>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Start Date</label>
                <input type="date" class="form-control" name="start_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">End Date</label>
                <input type="date" class="form-control" name="end_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="create_promo" value="1" class="btn btn-success"><i class="bi bi-tag me-1"></i>Create Promotion</button>
    </div>
    </form>
</div></div></div>

<!-- Bulk Promotion Modal -->
<div class="modal fade" id="bulkPromo"><div class="modal-dialog"><div class="modal-content">
    <form method="POST" id="bulkPromoForm">
    <div class="modal-header"><h6 class="modal-title"><i class="bi bi-tags me-2"></i>Bulk Promote</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="alert alert-info small">
            <i class="bi bi-info-circle me-1"></i>Select items from the near-expiry table first, then configure the promotion here.
        </div>
        <div id="selectedCount" class="mb-3 fw-semibold">0 items selected</div>
        <div id="bulkIdsContainer"></div>
        <div class="mb-3">
            <label class="form-label">Discount for All</label>
            <div class="input-group">
                <input type="number" class="form-control" name="bulk_discount" min="10" max="50" step="5" value="20" required>
                <span class="input-group-text">%</span>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Start Date</label>
                <input type="date" class="form-control" name="bulk_start_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">End Date</label>
                <input type="date" class="form-control" name="bulk_end_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="bulk_promote" value="1" class="btn btn-warning"><i class="bi bi-tags me-1"></i>Apply Bulk Promotion</button>
    </div>
    </form>
</div></div></div>

<?php
$extraScripts = "<script>
// Select all checkbox
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.bulk-check').forEach(cb => cb.checked = this.checked);
    updateBulkCount();
});

document.querySelectorAll('.bulk-check').forEach(cb => cb.addEventListener('change', updateBulkCount));

function updateBulkCount() {
    const checked = document.querySelectorAll('.bulk-check:checked');
    document.getElementById('selectedCount').textContent = checked.length + ' items selected';
    const container = document.getElementById('bulkIdsContainer');
    container.innerHTML = '';
    checked.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'bulk_ids[]';
        input.value = cb.value;
        container.appendChild(input);
    });
}

// Update count when modal opens
document.getElementById('bulkPromo')?.addEventListener('show.bs.modal', updateBulkCount);
</script>";

require_once __DIR__ . '/../../includes/footer.php';
?>
