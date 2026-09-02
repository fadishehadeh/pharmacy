<?php
$pageTitle = 'Print Labels';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

$selectedIds = [];
if (isset($_GET['ids'])) {
    $selectedIds = array_map('intval', explode(',', $_GET['ids']));
} elseif (isset($_POST['medicine_ids'])) {
    $selectedIds = array_map('intval', $_POST['medicine_ids']);
}

$medicines = [];
if (!empty($selectedIds)) {
    $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
    $stmt = $db->prepare("SELECT m.*, c.name as category_name FROM medicines m LEFT JOIN categories c ON m.category_id = c.id WHERE m.id IN ($placeholders)");
    $stmt->execute($selectedIds);
    $medicines = $stmt->fetchAll();
}

$allMedicines = $db->query("SELECT id, name, barcode, sell_price FROM medicines WHERE is_active = 1 ORDER BY name")->fetchAll();

$labelSize = $_GET['size'] ?? 'medium';
$showPrice = isset($_GET['price']) ? true : (!isset($_GET['size']));
$showBarcode = isset($_GET['barcode']) ? true : (!isset($_GET['size']));
$showArabic = isset($_GET['arabic']) ? true : false;
$copies = intval($_GET['copies'] ?? 1);
$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');
$pharmacyNameAr = getSetting('pharmacy_name_ar', '');
?>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-tag me-2"></i>Label Settings</h6>
            <form method="GET">
                <div class="mb-2">
                    <label class="form-label">Select Medicines</label>
                    <select class="form-select form-select-sm" name="medicine_ids[]" id="labelMedSelect" multiple size="8">
                        <?php foreach ($allMedicines as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= in_array($m['id'], $selectedIds) ? 'selected' : '' ?>><?= sanitize($m['name']) ?> <?= $m['barcode'] ? '['.$m['barcode'].']' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Hold Ctrl/Cmd to select multiple</small>
                </div>
                <div class="mb-2">
                    <label class="form-label">Label Size</label>
                    <select class="form-select form-select-sm" name="size">
                        <option value="small" <?= $labelSize === 'small' ? 'selected' : '' ?>>Small (shelf tags)</option>
                        <option value="medium" <?= $labelSize === 'medium' ? 'selected' : '' ?>>Medium (standard)</option>
                        <option value="large" <?= $labelSize === 'large' ? 'selected' : '' ?>>Large (box labels)</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label">Copies per item</label>
                    <input type="number" class="form-control form-control-sm" name="copies" value="<?= $copies ?>" min="1" max="50">
                </div>
                <div class="form-check mb-1"><input type="checkbox" class="form-check-input" name="price" value="1" <?= $showPrice ? 'checked' : '' ?>><label class="form-check-label">Show Price</label></div>
                <div class="form-check mb-1"><input type="checkbox" class="form-check-input" name="barcode" value="1" <?= $showBarcode ? 'checked' : '' ?>><label class="form-check-label">Show Barcode</label></div>
                <div class="form-check mb-2"><input type="checkbox" class="form-check-input" name="arabic" value="1" <?= $showArabic ? 'checked' : '' ?>><label class="form-check-label">Show Arabic Name</label></div>
                <button type="submit" class="btn btn-primary btn-sm w-100 mb-2"><i class="bi bi-tag me-1"></i>Generate Labels</button>
            </form>
            <button onclick="window.print()" class="btn btn-outline-dark btn-sm w-100"><i class="bi bi-printer me-1"></i>Print Labels</button>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card p-3">
            <h6><i class="bi bi-eye me-2"></i>Label Preview</h6>
            <?php if (empty($medicines)): ?>
            <p class="text-muted text-center py-5">Select medicines and click "Generate Labels" to preview.</p>
            <?php else: ?>
            <div id="labelPreview" class="d-flex flex-wrap gap-2 justify-content-center">
                <?php
                $sizeStyles = [
                    'small' => 'width:180px;padding:8px;font-size:10px;',
                    'medium' => 'width:250px;padding:12px;font-size:12px;',
                    'large' => 'width:350px;padding:16px;font-size:14px;',
                ];
                $style = $sizeStyles[$labelSize] ?? $sizeStyles['medium'];
                ?>
                <?php foreach ($medicines as $med): ?>
                    <?php for ($c = 0; $c < $copies; $c++): ?>
                    <div class="border rounded" style="<?= $style ?>">
                        <div class="text-center mb-1"><strong><?= sanitize($pharmacyName) ?></strong></div>
                        <?php if ($showArabic && $pharmacyNameAr): ?>
                        <div class="text-center mb-1" dir="rtl"><small><?= sanitize($pharmacyNameAr) ?></small></div>
                        <?php endif; ?>
                        <hr class="my-1">
                        <div><strong><?= sanitize($med['name']) ?></strong></div>
                        <?php if ($showArabic && $med['name_ar']): ?>
                        <div dir="rtl"><small><?= sanitize($med['name_ar']) ?></small></div>
                        <?php endif; ?>
                        <?php if ($med['strength']): ?><div><small><?= sanitize($med['strength']) ?> - <?= ucfirst($med['form']) ?></small></div><?php endif; ?>
                        <?php if ($showBarcode && $med['barcode']): ?>
                        <div class="text-center my-1"><code style="font-size:1.2em;letter-spacing:2px"><?= sanitize($med['barcode']) ?></code></div>
                        <?php endif; ?>
                        <?php if ($showPrice): ?>
                        <div class="text-center mt-1">
                            <strong style="font-size:1.3em"><?= formatCurrency($med['sell_price']) ?></strong>
                            <?php if ($med['moph_price']): ?><br><small class="text-muted">MoPH: <?= formatCurrency($med['moph_price']) ?></small><?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($med['expiry_date']): ?><div class="text-center"><small class="text-muted">Exp: <?= formatDate($med['expiry_date'], 'M Y') ?></small></div><?php endif; ?>
                    </div>
                    <?php endfor; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
@media print {
    .sidebar, .topbar, .card:first-child, nav, .btn, form, h6, .col-lg-4 > .card { display: none !important; }
    .col-lg-8 { width: 100% !important; flex: 0 0 100% !important; max-width: 100% !important; }
    .col-lg-8 > .card { border: none !important; box-shadow: none !important; }
    #labelPreview { justify-content: flex-start !important; }
    #labelPreview > div { break-inside: avoid; page-break-inside: avoid; }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
