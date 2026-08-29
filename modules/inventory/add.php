<?php
$pageTitle = 'Add Medicine';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();

$db = getDB();
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$shelves = $db->query("SELECT s.*, cab.name as cabinet_name FROM shelves s JOIN cabinets cab ON s.cabinet_id = cab.id ORDER BY cab.name, s.shelf_number")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("INSERT INTO medicines (barcode, name, name_ar, generic_name, strength, form, category_id, shelf_id, manufacturer, country_of_origin, requires_prescription, is_controlled, controlled_schedule, is_subsidized, subsidy_percentage, unit, units_per_box, cost_price, sell_price, moph_price, quantity_in_stock, min_stock_level, max_stock_level, expiry_date, batch_number, storage_conditions, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $categoryId = $_POST['category_id'] ?: null;
    $shelfId = $_POST['shelf_id'] ?: null;
    $expiryDate = $_POST['expiry_date'] ?: null;
    $mophPrice = $_POST['moph_price'] ?: null;

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
        isset($_POST['is_subsidized']) ? 1 : 0,
        $_POST['subsidy_percentage'] ?: 0,
        $_POST['unit'] ?: 'box',
        $_POST['units_per_box'] ?: 1,
        $_POST['cost_price'] ?: 0,
        $_POST['sell_price'] ?: 0,
        $mophPrice,
        $_POST['quantity_in_stock'] ?: 0,
        $_POST['min_stock_level'] ?: 5,
        $_POST['max_stock_level'] ?: 100,
        $expiryDate,
        $_POST['batch_number'] ?: null,
        $_POST['storage_conditions'] ?: null,
        $_POST['notes'] ?: null,
    ]);

    $medicineId = $db->lastInsertId();
    $qty = intval($_POST['quantity_in_stock'] ?? 0);
    if ($qty > 0) {
        addStockMovement($medicineId, 'in', $qty, 'Initial stock');
    }
    addAuditLog('create', 'medicines', $medicineId);

    flashMessage('Medicine added successfully');
    if (isset($_POST['save_and_new'])) {
        header('Location: add.php');
    } else {
        header('Location: index.php');
    }
    exit;
}
?>

<div class="card p-4">
    <form method="POST">
        <div class="row g-3">
            <div class="col-12"><h6 class="text-primary border-bottom pb-2"><i class="bi bi-info-circle me-2"></i>Basic Information</h6></div>

            <div class="col-md-4">
                <label class="form-label">Medicine Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="name" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Name (Arabic)</label>
                <input type="text" class="form-control" name="name_ar" dir="rtl">
            </div>
            <div class="col-md-4">
                <label class="form-label">Generic Name</label>
                <input type="text" class="form-control" name="generic_name">
            </div>
            <div class="col-md-3">
                <label class="form-label">Barcode</label>
                <input type="text" class="form-control" name="barcode" id="barcodeInput">
            </div>
            <div class="col-md-3">
                <label class="form-label">Strength/Dosage</label>
                <input type="text" class="form-control" name="strength" placeholder="e.g. 500mg">
            </div>
            <div class="col-md-3">
                <label class="form-label">Form</label>
                <select class="form-select" name="form">
                    <option value="tablet">Tablet</option>
                    <option value="capsule">Capsule</option>
                    <option value="syrup">Syrup</option>
                    <option value="injection">Injection</option>
                    <option value="cream">Cream</option>
                    <option value="ointment">Ointment</option>
                    <option value="drops">Drops</option>
                    <option value="inhaler">Inhaler</option>
                    <option value="suppository">Suppository</option>
                    <option value="powder">Powder</option>
                    <option value="gel">Gel</option>
                    <option value="spray">Spray</option>
                    <option value="patch">Patch</option>
                    <option value="solution">Solution</option>
                    <option value="suspension">Suspension</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Category</label>
                <select class="form-select" name="category_id">
                    <option value="">-- Select --</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= sanitize($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12"><h6 class="text-primary border-bottom pb-2 mt-3"><i class="bi bi-geo-alt me-2"></i>Location & Manufacturer</h6></div>

            <div class="col-md-4">
                <label class="form-label">Shelf Location</label>
                <select class="form-select" name="shelf_id">
                    <option value="">-- Select --</option>
                    <?php foreach ($shelves as $sh): ?>
                    <option value="<?= $sh['id'] ?>"><?= sanitize($sh['cabinet_name']) ?> - Shelf <?= $sh['shelf_number'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Manufacturer</label>
                <input type="text" class="form-control" name="manufacturer">
            </div>
            <div class="col-md-4">
                <label class="form-label">Country of Origin</label>
                <input type="text" class="form-control" name="country_of_origin">
            </div>

            <div class="col-12"><h6 class="text-primary border-bottom pb-2 mt-3"><i class="bi bi-currency-dollar me-2"></i>Pricing & Stock</h6></div>

            <div class="col-md-2">
                <label class="form-label">Cost Price ($)</label>
                <input type="number" class="form-control" name="cost_price" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">Sell Price ($)</label>
                <input type="number" class="form-control" name="sell_price" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">MoPH Price ($)</label>
                <input type="number" class="form-control" name="moph_price" step="0.01" min="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">Current Stock</label>
                <input type="number" class="form-control" name="quantity_in_stock" min="0" value="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">Min Stock Level</label>
                <input type="number" class="form-control" name="min_stock_level" min="0" value="5">
            </div>
            <div class="col-md-2">
                <label class="form-label">Max Stock Level</label>
                <input type="number" class="form-control" name="max_stock_level" min="0" value="100">
            </div>
            <div class="col-md-2">
                <label class="form-label">Unit</label>
                <input type="text" class="form-control" name="unit" value="box">
            </div>
            <div class="col-md-2">
                <label class="form-label">Units per Box</label>
                <input type="number" class="form-control" name="units_per_box" min="1" value="1">
            </div>

            <div class="col-12"><h6 class="text-primary border-bottom pb-2 mt-3"><i class="bi bi-calendar me-2"></i>Batch & Expiry</h6></div>

            <div class="col-md-3">
                <label class="form-label">Batch Number</label>
                <input type="text" class="form-control" name="batch_number">
            </div>
            <div class="col-md-3">
                <label class="form-label">Expiry Date</label>
                <input type="date" class="form-control" name="expiry_date">
            </div>
            <div class="col-md-3">
                <label class="form-label">Storage Conditions</label>
                <input type="text" class="form-control" name="storage_conditions" placeholder="e.g. Room temp, 2-8°C">
            </div>

            <div class="col-12"><h6 class="text-primary border-bottom pb-2 mt-3"><i class="bi bi-shield me-2"></i>Regulations</h6></div>

            <div class="col-md-3">
                <div class="form-check mt-2">
                    <input type="checkbox" class="form-check-input" name="requires_prescription" id="rxCheck">
                    <label class="form-check-label" for="rxCheck">Requires Prescription</label>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-check mt-2">
                    <input type="checkbox" class="form-check-input" name="is_controlled" id="controlledCheck">
                    <label class="form-check-label" for="controlledCheck">Controlled Substance</label>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Schedule</label>
                <input type="text" class="form-control" name="controlled_schedule" placeholder="e.g. II, III, IV">
            </div>
            <div class="col-md-3">
                <div class="form-check mt-2">
                    <input type="checkbox" class="form-check-input" name="is_subsidized" id="subsidyCheck">
                    <label class="form-check-label" for="subsidyCheck">Subsidized</label>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Subsidy %</label>
                <input type="number" class="form-control" name="subsidy_percentage" step="0.01" min="0" max="100" value="0">
            </div>

            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="2"></textarea>
            </div>

            <div class="col-12 d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Medicine</button>
                <button type="submit" name="save_and_new" value="1" class="btn btn-outline-primary"><i class="bi bi-plus me-1"></i>Save & Add Another</button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
