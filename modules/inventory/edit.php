<?php
$pageTitle = 'Edit Medicine';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }

$db = getDB();
$id = intval($_GET['id'] ?? 0);
$med = $db->prepare("SELECT * FROM medicines WHERE id = ?");
$med->execute([$id]);
$med = $med->fetch();

if (!$med) {
    flashMessage('Medicine not found', 'error');
    header('Location: index.php');
    exit;
}

$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$shelves = $db->query("SELECT s.*, cab.name as cabinet_name FROM shelves s JOIN cabinets cab ON s.cabinet_id = cab.id ORDER BY cab.name, s.shelf_number")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['adjust_stock'])) {
        $adjustment = intval($_POST['stock_adjustment']);
        $type = $adjustment > 0 ? 'in' : 'adjustment';
        $reason = $_POST['adjustment_reason'] ?? '';
        updateStock($id, $adjustment);
        addStockMovement($id, $type, abs($adjustment), $reason);
        flashMessage("Stock adjusted by $adjustment");
        header("Location: edit.php?id=$id");
        exit;
    }

    $categoryId = $_POST['category_id'] ?: null;
    $shelfId = $_POST['shelf_id'] ?: null;
    $expiryDate = $_POST['expiry_date'] ?: null;
    $mophPrice = $_POST['moph_price'] ?: null;

    $stmt = $db->prepare("UPDATE medicines SET barcode=?, name=?, name_ar=?, generic_name=?, strength=?, form=?, category_id=?, shelf_id=?, manufacturer=?, country_of_origin=?, requires_prescription=?, is_controlled=?, controlled_schedule=?, controlled_category=?, is_subsidized=?, subsidy_percentage=?, unit=?, units_per_box=?, cost_price=?, sell_price=?, moph_price=?, min_stock_level=?, max_stock_level=?, expiry_date=?, batch_number=?, storage_conditions=?, notes=? WHERE id=?");

    $stmt->execute([
        $_POST['barcode'] ?: null,
        $_POST['name'],
        $_POST['name_ar'] ?: null,
        $_POST['generic_name'] ?: null,
        $_POST['strength'] ?: null,
        $_POST['form'],
        $categoryId,
        $shelfId,
        $_POST['manufacturer'] ?: null,
        $_POST['country_of_origin'] ?: null,
        isset($_POST['requires_prescription']) ? 1 : 0,
        isset($_POST['is_controlled']) ? 1 : 0,
        $_POST['controlled_schedule'] ?: null,
        $_POST['controlled_category'] ?: null,
        isset($_POST['is_subsidized']) ? 1 : 0,
        $_POST['subsidy_percentage'] ?: 0,
        $_POST['unit'] ?: 'box',
        $_POST['units_per_box'] ?: 1,
        $_POST['cost_price'] ?: 0,
        $_POST['sell_price'] ?: 0,
        $mophPrice,
        $_POST['min_stock_level'] ?: 5,
        $_POST['max_stock_level'] ?: 100,
        $expiryDate,
        $_POST['batch_number'] ?: null,
        $_POST['storage_conditions'] ?: null,
        $_POST['notes'] ?: null,
        $id
    ]);

    addAuditLog('update', 'medicines', $id);
    flashMessage('Medicine updated successfully');
    header("Location: edit.php?id=$id");
    exit;
}

$movements = $db->prepare("SELECT * FROM stock_movements WHERE medicine_id = ? ORDER BY created_at DESC LIMIT 20");
$movements->execute([$id]);
$movements = $movements->fetchAll();
?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-4">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-12"><h6 class="text-primary border-bottom pb-2">Basic Information</h6></div>
                    <div class="col-md-4">
                        <label class="form-label">Medicine Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" value="<?= sanitize($med['name']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Name (Arabic)</label>
                        <input type="text" class="form-control" name="name_ar" value="<?= sanitize($med['name_ar'] ?? '') ?>" dir="rtl">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Generic Name</label>
                        <input type="text" class="form-control" name="generic_name" value="<?= sanitize($med['generic_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Barcode</label>
                        <input type="text" class="form-control" name="barcode" value="<?= sanitize($med['barcode'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Strength</label>
                        <input type="text" class="form-control" name="strength" value="<?= sanitize($med['strength'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Form</label>
                        <select class="form-select" name="form">
                            <?php foreach (['tablet','capsule','syrup','injection','cream','ointment','drops','inhaler','suppository','powder','gel','spray','patch','solution','suspension','other'] as $f): ?>
                            <option value="<?= $f ?>" <?= $med['form'] === $f ? 'selected' : '' ?>><?= ucfirst($f) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category_id">
                            <option value="">-- Select --</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $med['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12"><h6 class="text-primary border-bottom pb-2 mt-2">Location & Manufacturer</h6></div>
                    <div class="col-md-4">
                        <label class="form-label">Shelf Location</label>
                        <select class="form-select" name="shelf_id">
                            <option value="">-- Select --</option>
                            <?php foreach ($shelves as $sh): ?>
                            <option value="<?= $sh['id'] ?>" <?= $med['shelf_id'] == $sh['id'] ? 'selected' : '' ?>><?= sanitize($sh['cabinet_name']) ?> - Shelf <?= $sh['shelf_number'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Manufacturer</label>
                        <input type="text" class="form-control" name="manufacturer" value="<?= sanitize($med['manufacturer'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Country</label>
                        <input type="text" class="form-control" name="country_of_origin" value="<?= sanitize($med['country_of_origin'] ?? '') ?>">
                    </div>

                    <div class="col-12"><h6 class="text-primary border-bottom pb-2 mt-2">Pricing</h6></div>
                    <div class="col-md-3">
                        <label class="form-label">Cost Price ($)</label>
                        <input type="number" class="form-control" name="cost_price" step="0.01" value="<?= $med['cost_price'] ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Sell Price ($)</label>
                        <input type="number" class="form-control" name="sell_price" step="0.01" value="<?= $med['sell_price'] ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">MoPH Price ($)</label>
                        <input type="number" class="form-control" name="moph_price" step="0.01" value="<?= $med['moph_price'] ?? '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Margin</label>
                        <input type="text" class="form-control" readonly value="<?= $med['cost_price'] > 0 ? round(($med['sell_price'] - $med['cost_price']) / $med['cost_price'] * 100, 1) . '%' : '-' ?>">
                    </div>

                    <div class="col-12"><h6 class="text-primary border-bottom pb-2 mt-2">Batch & Expiry</h6></div>
                    <div class="col-md-3">
                        <label class="form-label">Batch Number</label>
                        <input type="text" class="form-control" name="batch_number" value="<?= sanitize($med['batch_number'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Expiry Date</label>
                        <input type="date" class="form-control" name="expiry_date" value="<?= $med['expiry_date'] ?? '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Storage</label>
                        <input type="text" class="form-control" name="storage_conditions" value="<?= sanitize($med['storage_conditions'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Min Stock</label>
                        <input type="number" class="form-control" name="min_stock_level" value="<?= $med['min_stock_level'] ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Max Stock</label>
                        <input type="number" class="form-control" name="max_stock_level" value="<?= $med['max_stock_level'] ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Unit</label>
                        <input type="text" class="form-control" name="unit" value="<?= sanitize($med['unit']) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Per Box</label>
                        <input type="number" class="form-control" name="units_per_box" value="<?= $med['units_per_box'] ?>">
                    </div>

                    <div class="col-12"><h6 class="text-primary border-bottom pb-2 mt-2">Regulations</h6></div>
                    <div class="col-md-3">
                        <div class="form-check"><input type="checkbox" class="form-check-input" name="requires_prescription" <?= $med['requires_prescription'] ? 'checked' : '' ?>><label class="form-check-label">Prescription</label></div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-check mt-4"><input type="checkbox" class="form-check-input" id="chkControlled" name="is_controlled" <?= $med['is_controlled'] ? 'checked' : '' ?>><label class="form-check-label" for="chkControlled"><i class="bi bi-shield-lock text-danger me-1"></i>Controlled</label></div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Schedule</label>
                        <input type="text" class="form-control" name="controlled_schedule" value="<?= sanitize($med['controlled_schedule'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Controlled Category</label>
                        <select class="form-select" name="controlled_category">
                            <option value="">— None —</option>
                            <?php foreach (['narcotic','psychotropic','precursor','other'] as $cc): ?>
                            <option value="<?= $cc ?>" <?= ($med['controlled_category'] ?? '') === $cc ? 'selected' : '' ?>><?= ucfirst($cc) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check"><input type="checkbox" class="form-check-input" name="is_subsidized" <?= $med['is_subsidized'] ? 'checked' : '' ?>><label class="form-check-label">Subsidized</label></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Subsidy %</label>
                        <input type="number" class="form-control" name="subsidy_percentage" step="0.01" value="<?= $med['subsidy_percentage'] ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"><?= sanitize($med['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="col-12 d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button>
                        <a href="index.php" class="btn btn-outline-secondary">Back</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-4">
        <?php if (!empty($med['image'])): ?>
        <div class="card p-3 mb-3 text-center">
            <img src="<?= BASE_URL ?>/assets/uploads/<?= sanitize($med['image']) ?>" class="img-fluid rounded mb-2" style="max-height:200px">
            <small class="text-muted">Product Photo</small>
        </div>
        <?php endif; ?>

        <div class="card p-3 mb-3">
            <h6><i class="bi bi-camera me-2"></i>Product Photo</h6>
            <form method="POST" action="<?= BASE_URL ?>/api/barcode.php?action=upload_image" enctype="multipart/form-data" id="photoUploadForm">
                <input type="hidden" name="medicine_id" value="<?= $med['id'] ?>">
                <input type="file" class="form-control form-control-sm mb-2" name="image" accept="image/*">
                <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-upload me-1"></i>Upload Photo</button>
            </form>
        </div>

        <div class="card p-3 mb-3">
            <h6><i class="bi bi-box me-2"></i>Stock: <?= $med['quantity_in_stock'] ?> <?= sanitize($med['unit']) ?></h6>
            <div class="progress mb-3" style="height:8px">
                <?php $pct = $med['max_stock_level'] > 0 ? min(100, $med['quantity_in_stock'] / $med['max_stock_level'] * 100) : 0; ?>
                <div class="progress-bar bg-<?= $pct <= 20 ? 'danger' : ($pct <= 50 ? 'warning' : 'success') ?>" style="width:<?= $pct ?>%"></div>
            </div>
            <form method="POST" class="d-flex gap-2">
                <input type="number" class="form-control form-control-sm" name="stock_adjustment" placeholder="+/- qty" required>
                <input type="text" class="form-control form-control-sm" name="adjustment_reason" placeholder="Reason">
                <button type="submit" name="adjust_stock" value="1" class="btn btn-sm btn-outline-primary">Adjust</button>
            </form>
        </div>

        <div class="card p-3">
            <h6><i class="bi bi-clock-history me-2"></i>Stock History</h6>
            <?php if (empty($movements)): ?>
            <p class="text-muted small">No movements yet</p>
            <?php else: ?>
            <div class="list-group list-group-flush" style="max-height:400px;overflow-y:auto">
                <?php foreach ($movements as $mov): ?>
                <div class="list-group-item px-0 py-2">
                    <div class="d-flex justify-content-between">
                        <span class="badge bg-<?= $mov['type'] === 'in' ? 'success' : ($mov['type'] === 'out' ? 'danger' : 'warning') ?>">
                            <?= strtoupper($mov['type']) ?>
                        </span>
                        <strong><?= $mov['type'] === 'out' ? '-' : '+' ?><?= $mov['quantity'] ?></strong>
                    </div>
                    <small class="text-muted"><?= formatDate($mov['created_at'], 'M d, H:i') ?></small>
                    <?php if ($mov['notes']): ?><br><small><?= sanitize($mov['notes']) ?></small><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
