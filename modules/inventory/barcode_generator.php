<?php
$pageTitle = 'Barcode Generator';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));
$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');

// Code128 character set B encoding
function code128Encode($data) {
    $code128B = [
        ' ' => 0, '!' => 1, '"' => 2, '#' => 3, '$' => 4, '%' => 5, '&' => 6, "'" => 7,
        '(' => 8, ')' => 9, '*' => 10, '+' => 11, ',' => 12, '-' => 13, '.' => 14, '/' => 15,
        '0' => 16, '1' => 17, '2' => 18, '3' => 19, '4' => 20, '5' => 21, '6' => 22, '7' => 23,
        '8' => 24, '9' => 25, ':' => 26, ';' => 27, '<' => 28, '=' => 29, '>' => 30, '?' => 31,
        '@' => 32, 'A' => 33, 'B' => 34, 'C' => 35, 'D' => 36, 'E' => 37, 'F' => 38, 'G' => 39,
        'H' => 40, 'I' => 41, 'J' => 42, 'K' => 43, 'L' => 44, 'M' => 45, 'N' => 46, 'O' => 47,
        'P' => 48, 'Q' => 49, 'R' => 50, 'S' => 51, 'T' => 52, 'U' => 53, 'V' => 54, 'W' => 55,
        'X' => 56, 'Y' => 57, 'Z' => 58, '[' => 59, '\\' => 60, ']' => 61, '^' => 62, '_' => 63,
        '`' => 64, 'a' => 65, 'b' => 66, 'c' => 67, 'd' => 68, 'e' => 69, 'f' => 70, 'g' => 71,
        'h' => 72, 'i' => 73, 'j' => 74, 'k' => 75, 'l' => 76, 'm' => 77, 'n' => 78, 'o' => 79,
        'p' => 80, 'q' => 81, 'r' => 82, 's' => 83, 't' => 84, 'u' => 85, 'v' => 86, 'w' => 87,
        'x' => 88, 'y' => 89, 'z' => 90, '{' => 91, '|' => 92, '}' => 93, '~' => 94
    ];

    // Code128 bar patterns (each value = 6 alternating bar/space widths)
    $patterns = [
        [2,1,2,2,2,2],[2,2,2,1,2,2],[2,2,2,2,2,1],[1,2,1,2,2,3],[1,2,1,3,2,2],
        [1,3,1,2,2,2],[1,2,2,2,1,3],[1,2,2,3,1,2],[1,3,2,2,1,2],[2,2,1,2,1,3],
        [2,2,1,3,1,2],[2,3,1,2,1,2],[1,1,2,2,3,2],[1,2,2,1,3,2],[1,2,2,2,3,1],
        [1,1,3,2,2,2],[1,2,3,1,2,2],[1,2,3,2,2,1],[2,2,3,2,1,1],[2,2,1,1,3,2],
        [2,2,1,2,3,1],[2,1,3,2,1,2],[2,2,3,1,1,2],[3,1,2,1,3,1],[3,1,1,2,2,2],
        [3,2,1,1,2,2],[3,2,1,2,2,1],[3,1,2,2,1,2],[3,2,2,1,1,2],[3,2,2,2,1,1],
        [2,1,2,1,2,3],[2,1,2,3,2,1],[2,3,2,1,2,1],[1,1,1,3,2,3],[1,3,1,1,2,3],
        [1,3,1,3,2,1],[1,1,2,3,1,3],[1,3,2,1,1,3],[1,3,2,3,1,1],[2,1,1,3,1,3],
        [2,3,1,1,1,3],[2,3,1,3,1,1],[1,1,2,1,3,3],[1,1,2,3,3,1],[1,3,2,1,3,1],
        [1,1,3,1,2,3],[1,1,3,3,2,1],[1,3,3,1,2,1],[3,1,3,1,2,1],[2,1,1,3,3,1],
        [2,3,1,1,3,1],[2,1,3,1,1,3],[2,1,3,3,1,1],[2,1,3,1,3,1],[3,1,1,1,2,3],
        [3,1,1,3,2,1],[3,3,1,1,2,1],[3,1,2,1,1,3],[3,1,2,3,1,1],[3,3,2,1,1,1],
        [3,1,4,1,1,1],[2,2,1,4,1,1],[4,3,1,1,1,1],[1,1,1,2,2,4],[1,1,1,4,2,2],
        [1,2,1,1,2,4],[1,2,1,4,2,1],[1,4,1,1,2,2],[1,4,1,2,2,1],[1,1,2,2,1,4],
        [1,1,2,4,1,2],[1,2,2,1,1,4],[1,2,2,4,1,1],[1,4,2,1,1,2],[1,4,2,2,1,1],
        [2,4,1,2,1,1],[2,2,1,1,1,4],[4,1,3,1,1,1],[2,4,1,1,1,2],[1,3,4,1,1,1],
        [1,1,1,2,4,2],[1,2,1,1,4,2],[1,2,1,2,4,1],[1,1,4,2,1,2],[1,2,4,1,1,2],
        [1,2,4,2,1,1],[4,1,1,2,1,2],[4,2,1,1,1,2],[4,2,1,2,1,1],[2,1,2,1,4,1],
        [2,1,4,1,2,1],[4,1,2,1,2,1],[1,1,1,1,4,3],[1,1,1,3,4,1],[1,3,1,1,4,1],
        [1,1,4,1,1,3],[1,1,4,3,1,1],[4,1,1,1,1,3],[4,1,1,3,1,1],[1,1,3,1,4,1],
        [1,1,4,1,3,1],[3,1,1,1,4,1],[4,1,1,1,3,1],[2,1,1,4,1,2],[2,1,1,2,1,4],
        [2,1,1,2,3,2],[2,3,3,1,1,1,2]
    ];

    $startB = 104;
    $stop = 106;

    $values = [$startB];
    $checksum = $startB;

    for ($i = 0; $i < strlen($data); $i++) {
        $ch = $data[$i];
        $val = $code128B[$ch] ?? 0;
        $values[] = $val;
        $checksum += $val * ($i + 1);
    }

    $checksum = $checksum % 103;
    $values[] = $checksum;
    $values[] = $stop;

    return ['values' => $values, 'patterns' => $patterns];
}

function generateBarcodeSVG($data, $width = 200, $height = 50) {
    if (empty($data)) return '';
    $encoded = code128Encode($data);
    $values = $encoded['values'];
    $patterns = $encoded['patterns'];

    // Calculate total modules
    $totalModules = 0;
    foreach ($values as $val) {
        $pattern = $patterns[$val] ?? $patterns[0];
        foreach ($pattern as $w) {
            $totalModules += $w;
        }
    }
    // Add quiet zones
    $totalModules += 20;

    $moduleWidth = $width / $totalModules;
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
    $svg .= '<rect width="100%" height="100%" fill="white"/>';

    $x = 10 * $moduleWidth; // quiet zone
    foreach ($values as $val) {
        $pattern = $patterns[$val] ?? $patterns[0];
        $isBar = true;
        foreach ($pattern as $w) {
            if ($isBar) {
                $barWidth = $w * $moduleWidth;
                $svg .= '<rect x="' . round($x, 2) . '" y="0" width="' . round($barWidth, 2) . '" height="' . $height . '" fill="black"/>';
            }
            $x += $w * $moduleWidth;
            $isBar = !$isBar;
        }
    }

    $svg .= '</svg>';
    return $svg;
}

function generateEAN13SVG($data, $width = 200, $height = 50) {
    // Pad or truncate to 12 digits, then calculate check digit
    $data = preg_replace('/[^0-9]/', '', $data);
    $data = str_pad(substr($data, 0, 12), 12, '0', STR_PAD_LEFT);

    // Calculate check digit
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += intval($data[$i]) * ($i % 2 === 0 ? 1 : 3);
    }
    $check = (10 - ($sum % 10)) % 10;
    $ean = $data . $check;

    // EAN-13 encoding patterns
    $lPatterns = [
        '0001101','0011001','0010011','0111101','0100011',
        '0110001','0101111','0111011','0110111','0001011'
    ];
    $gPatterns = [
        '0100111','0110011','0011011','0100001','0011101',
        '0111001','0000101','0010001','0001001','0010111'
    ];
    $rPatterns = [
        '1110010','1100110','1101100','1000010','1011100',
        '1001110','1010000','1000100','1001000','1110100'
    ];
    $parityPatterns = [
        'LLLLLL','LLGLGG','LLGGLG','LLGGGL','LGLLGG',
        'LGGLLG','LGGGLL','LGLGLG','LGLGGL','LGGLGL'
    ];

    $firstDigit = intval($ean[0]);
    $parity = $parityPatterns[$firstDigit];

    // Build binary string
    $binary = '101'; // start guard
    for ($i = 1; $i <= 6; $i++) {
        $digit = intval($ean[$i]);
        if ($parity[$i - 1] === 'L') {
            $binary .= $lPatterns[$digit];
        } else {
            $binary .= $gPatterns[$digit];
        }
    }
    $binary .= '01010'; // center guard
    for ($i = 7; $i <= 12; $i++) {
        $digit = intval($ean[$i]);
        $binary .= $rPatterns[$digit];
    }
    $binary .= '101'; // end guard

    $totalModules = strlen($binary) + 14; // quiet zones
    $moduleWidth = $width / $totalModules;

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
    $svg .= '<rect width="100%" height="100%" fill="white"/>';

    $x = 7 * $moduleWidth;
    for ($i = 0; $i < strlen($binary); $i++) {
        if ($binary[$i] === '1') {
            $svg .= '<rect x="' . round($x, 2) . '" y="0" width="' . round($moduleWidth, 2) . '" height="' . $height . '" fill="black"/>';
        }
        $x += $moduleWidth;
    }

    $svg .= '</svg>';
    return $ean;
}

// Selection mode
$selectionMode = $_GET['mode'] ?? 'manual';
$selectedIds = [];
$format = $_GET['format'] ?? 'code128';
$labelSize = $_GET['label_size'] ?? 'medium';
$labelQty = max(1, intval($_GET['qty'] ?? 1));
$showBarcode = isset($_GET['generate']) ? isset($_GET['show_barcode']) : true;
$showName = isset($_GET['generate']) ? isset($_GET['show_name']) : true;
$showPrice = isset($_GET['generate']) ? isset($_GET['show_price']) : true;
$showExpiry = isset($_GET['generate']) ? isset($_GET['show_expiry']) : false;

if (isset($_GET['ids'])) {
    $selectedIds = array_filter(array_map('intval', explode(',', $_GET['ids'])));
} elseif (isset($_GET['medicine_ids']) && is_array($_GET['medicine_ids'])) {
    $selectedIds = array_filter(array_map('intval', $_GET['medicine_ids']));
}

// Batch selection modes
$alertDays = intval(getSetting('expiry_warning_days', '90'));
$categoryFilter = $_GET['category_id'] ?? '';

if ($selectionMode === 'low_stock') {
    $rows = $db->query("SELECT id FROM medicines WHERE is_active = 1 AND quantity_in_stock <= min_stock_level")->fetchAll();
    $selectedIds = array_column($rows, 'id');
} elseif ($selectionMode === 'expiring') {
    $stmt = $db->prepare("SELECT id FROM medicines WHERE is_active = 1 AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)");
    $stmt->execute([$alertDays]);
    $selectedIds = array_column($stmt->fetchAll(), 'id');
} elseif ($selectionMode === 'category' && $categoryFilter) {
    $stmt = $db->prepare("SELECT id FROM medicines WHERE is_active = 1 AND category_id = ?");
    $stmt->execute([$categoryFilter]);
    $selectedIds = array_column($stmt->fetchAll(), 'id');
}

// Fetch selected medicines
$medicines = [];
if (!empty($selectedIds) && isset($_GET['generate'])) {
    $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
    $stmt = $db->prepare("SELECT m.*, c.name as category_name FROM medicines m LEFT JOIN categories c ON m.category_id = c.id WHERE m.id IN ($placeholders) AND m.is_active = 1 ORDER BY m.name");
    $stmt->execute($selectedIds);
    $medicines = $stmt->fetchAll();
}

// Per-medicine quantities
$perMedQty = [];
if (isset($_GET['med_qty']) && is_array($_GET['med_qty'])) {
    foreach ($_GET['med_qty'] as $mid => $q) {
        $perMedQty[intval($mid)] = max(1, intval($q));
    }
}

$allMedicines = $db->query("SELECT id, name, barcode, sell_price, quantity_in_stock, min_stock_level FROM medicines WHERE is_active = 1 ORDER BY name")->fetchAll();
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

// Label dimensions
$labelSizes = [
    'small'  => ['w' => '1in', 'h' => '0.5in', 'fontSize' => '7px', 'barcodeW' => 100, 'barcodeH' => 25],
    'medium' => ['w' => '2in', 'h' => '1in', 'fontSize' => '9px', 'barcodeW' => 160, 'barcodeH' => 35],
    'large'  => ['w' => '3in', 'h' => '1.5in', 'fontSize' => '11px', 'barcodeW' => 220, 'barcodeH' => 45],
];
$sizeConfig = $labelSizes[$labelSize] ?? $labelSizes['medium'];
?>

<div class="row g-3 mb-3">
    <div class="col-lg-4 no-print">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-upc-scan me-2"></i>Barcode Label Settings</h6>
            <form method="GET" id="barcodeForm">
                <input type="hidden" name="generate" value="1">

                <!-- Selection Mode -->
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Selection Mode</label>
                    <select class="form-select form-select-sm" name="mode" id="selectionMode" onchange="toggleSelectionMode()">
                        <option value="manual" <?= $selectionMode === 'manual' ? 'selected' : '' ?>>Manual Selection</option>
                        <option value="low_stock" <?= $selectionMode === 'low_stock' ? 'selected' : '' ?>>All Low Stock</option>
                        <option value="expiring" <?= $selectionMode === 'expiring' ? 'selected' : '' ?>>All Expiring Soon</option>
                        <option value="category" <?= $selectionMode === 'category' ? 'selected' : '' ?>>By Category</option>
                    </select>
                </div>

                <!-- Category filter (shown when mode=category) -->
                <div class="mb-2" id="categorySection" style="<?= $selectionMode === 'category' ? '' : 'display:none' ?>">
                    <label class="form-label small">Category</label>
                    <select class="form-select form-select-sm" name="category_id">
                        <option value="">-- Select --</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $categoryFilter == $cat['id'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Manual medicine selection -->
                <div class="mb-2" id="manualSection" style="<?= $selectionMode === 'manual' ? '' : 'display:none' ?>">
                    <label class="form-label small">Select Medicines</label>
                    <input type="text" class="form-control form-control-sm mb-1" id="medSearchInput" placeholder="Search medicines..." oninput="filterMedicineList()">
                    <div style="max-height:200px;overflow-y:auto;border:1px solid #dee2e6;border-radius:4px;padding:4px">
                        <?php foreach ($allMedicines as $m): ?>
                        <div class="form-check med-check-item" data-name="<?= strtolower($m['name']) ?>">
                            <input class="form-check-input" type="checkbox" name="medicine_ids[]" value="<?= $m['id'] ?>" id="med_<?= $m['id'] ?>" <?= in_array($m['id'], $selectedIds) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="med_<?= $m['id'] ?>"><?= sanitize($m['name']) ?> <?= $m['barcode'] ? '<span class="text-muted">['.$m['barcode'].']</span>' : '' ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-1">
                        <a href="#" class="small" onclick="document.querySelectorAll('.med-check-item input').forEach(c=>c.checked=true);return false">Select All</a>
                        <span class="mx-1">|</span>
                        <a href="#" class="small" onclick="document.querySelectorAll('.med-check-item input').forEach(c=>c.checked=false);return false">Clear All</a>
                    </div>
                </div>

                <hr>

                <!-- Barcode Format -->
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Barcode Format</label>
                    <select class="form-select form-select-sm" name="format">
                        <option value="code128" <?= $format === 'code128' ? 'selected' : '' ?>>Code 128</option>
                        <option value="ean13" <?= $format === 'ean13' ? 'selected' : '' ?>>EAN-13</option>
                    </select>
                </div>

                <!-- Label Size -->
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Label Size</label>
                    <select class="form-select form-select-sm" name="label_size">
                        <option value="small" <?= $labelSize === 'small' ? 'selected' : '' ?>>Small (1" x 0.5")</option>
                        <option value="medium" <?= $labelSize === 'medium' ? 'selected' : '' ?>>Medium (2" x 1")</option>
                        <option value="large" <?= $labelSize === 'large' ? 'selected' : '' ?>>Large (3" x 1.5")</option>
                    </select>
                </div>

                <!-- Quantity per medicine -->
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Labels per Medicine</label>
                    <input type="number" class="form-control form-control-sm" name="qty" value="<?= $labelQty ?>" min="1" max="100">
                </div>

                <!-- Include on label -->
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Include on Label</label>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="show_barcode" value="1" id="chkBarcode" <?= $showBarcode ? 'checked' : '' ?>><label class="form-check-label small" for="chkBarcode">Barcode</label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="show_name" value="1" id="chkName" <?= $showName ? 'checked' : '' ?>><label class="form-check-label small" for="chkName">Medicine Name</label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="show_price" value="1" id="chkPrice" <?= $showPrice ? 'checked' : '' ?>><label class="form-check-label small" for="chkPrice">Price</label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="show_expiry" value="1" id="chkExpiry" <?= $showExpiry ? 'checked' : '' ?>><label class="form-check-label small" for="chkExpiry">Expiry Date</label></div>
                </div>

                <button type="submit" class="btn btn-primary btn-sm w-100 mb-2"><i class="bi bi-upc me-1"></i>Generate Labels</button>
            </form>
            <button onclick="window.print()" class="btn btn-outline-dark btn-sm w-100 no-print"><i class="bi bi-printer me-1"></i>Print Labels</button>
        </div>

        <?php if (!empty($medicines)): ?>
        <div class="card p-3">
            <h6><i class="bi bi-list-check me-2"></i>Selected (<?= count($medicines) ?>)</h6>
            <div class="list-group list-group-flush" style="max-height:200px;overflow-y:auto">
                <?php foreach ($medicines as $m): ?>
                <div class="list-group-item px-0 py-1 small">
                    <strong><?= sanitize($m['name']) ?></strong>
                    <span class="text-muted"><?= $m['barcode'] ? '['.$m['barcode'].']' : '[no barcode]' ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3 no-print">
                <h6 class="mb-0"><i class="bi bi-eye me-2"></i>Label Preview</h6>
                <?php if (!empty($medicines)): ?>
                <span class="badge bg-primary"><?= count($medicines) ?> medicine(s), <?= count($medicines) * $labelQty ?> label(s)</span>
                <?php endif; ?>
            </div>

            <?php if (empty($medicines)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-upc-scan" style="font-size:3rem"></i>
                <p class="mt-2">Select medicines and click "Generate Labels" to preview barcode labels.</p>
            </div>
            <?php else: ?>
            <div id="labelSheet" class="label-grid">
                <?php foreach ($medicines as $med):
                    $barcodeData = $med['barcode'] ?: ('MED' . str_pad($med['id'], 6, '0', STR_PAD_LEFT));
                    $qty = $perMedQty[$med['id']] ?? $labelQty;

                    if ($format === 'ean13') {
                        $numericData = preg_replace('/[^0-9]/', '', $barcodeData);
                        $numericData = str_pad(substr($numericData, 0, 12), 12, '0', STR_PAD_LEFT);
                        // Calculate check digit
                        $sum = 0;
                        for ($ci = 0; $ci < 12; $ci++) {
                            $sum += intval($numericData[$ci]) * ($ci % 2 === 0 ? 1 : 3);
                        }
                        $checkDigit = (10 - ($sum % 10)) % 10;
                        $displayCode = $numericData . $checkDigit;
                    } else {
                        $displayCode = $barcodeData;
                    }

                    $barcodeSVG = generateBarcodeSVG($barcodeData, $sizeConfig['barcodeW'], $sizeConfig['barcodeH']);

                    for ($c = 0; $c < $qty; $c++):
                ?>
                <div class="barcode-label" style="width:<?= $sizeConfig['w'] ?>;height:<?= $sizeConfig['h'] ?>;font-size:<?= $sizeConfig['fontSize'] ?>">
                    <?php if ($showName): ?>
                    <div class="label-name"><?= sanitize(mb_strimwidth($med['name'], 0, 40, '...')) ?></div>
                    <?php endif; ?>
                    <?php if ($showBarcode): ?>
                    <div class="label-barcode"><?= $barcodeSVG ?></div>
                    <div class="label-code"><?= sanitize($displayCode) ?></div>
                    <?php endif; ?>
                    <div class="label-footer">
                        <?php if ($showPrice): ?>
                        <span class="label-price"><?= formatCurrency($med['sell_price']) ?></span>
                        <?php endif; ?>
                        <?php if ($showExpiry && $med['expiry_date']): ?>
                        <span class="label-expiry">Exp: <?= formatDate($med['expiry_date'], 'M Y') ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endfor; endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.label-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    justify-content: flex-start;
}
.barcode-label {
    border: 1px solid #ccc;
    border-radius: 3px;
    padding: 4px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    overflow: hidden;
    background: white;
    page-break-inside: avoid;
}
.label-name {
    font-weight: 600;
    line-height: 1.1;
    margin-bottom: 2px;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.label-barcode {
    margin: 2px 0;
    width: 90%;
}
.label-barcode svg {
    width: 100%;
    height: auto;
}
.label-code {
    font-family: monospace;
    font-size: 0.85em;
    letter-spacing: 1px;
}
.label-footer {
    display: flex;
    justify-content: space-between;
    width: 100%;
    margin-top: 2px;
    gap: 4px;
}
.label-price {
    font-weight: 700;
}
.label-expiry {
    color: #666;
}
@media print {
    body * { visibility: hidden !important; }
    #labelSheet, #labelSheet * { visibility: visible !important; }
    #labelSheet {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }
    .barcode-label {
        border: 1px solid #999;
    }
    .no-print { display: none !important; }
    .col-lg-4 { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
}
</style>

<?php
$extraScripts = <<<'SCRIPT'
<script>
function toggleSelectionMode() {
    var mode = document.getElementById('selectionMode').value;
    document.getElementById('manualSection').style.display = mode === 'manual' ? '' : 'none';
    document.getElementById('categorySection').style.display = mode === 'category' ? '' : 'none';
}

function filterMedicineList() {
    var query = document.getElementById('medSearchInput').value.toLowerCase();
    document.querySelectorAll('.med-check-item').forEach(function(item) {
        var name = item.getAttribute('data-name');
        item.style.display = name.indexOf(query) !== -1 ? '' : 'none';
    });
}
</script>
SCRIPT;
require_once __DIR__ . '/../../includes/footer.php';
?>
