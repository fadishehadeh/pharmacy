<?php
$pageTitle = 'Receipt Templates';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('admin')) { header('Location: ' . BASE_URL); exit; }
$db = getDB();

$exchangeRate = getSetting('exchange_rate', '89500');
$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');

// Default template config
$defaultTemplate = [
    'format' => 'standard',
    'paper_width' => '80mm',
    'font_size' => 'medium',
    'show_logo' => true,
    'show_pharmacy_info' => true,
    'show_pharmacy_phone' => true,
    'show_pharmacy_address' => true,
    'show_pharmacy_license' => false,
    'show_patient_info' => true,
    'show_doctor_info' => true,
    'show_item_details' => true,
    'show_item_generic' => false,
    'show_item_barcode' => false,
    'show_subtotals' => true,
    'show_discount' => true,
    'show_tax' => true,
    'show_lbp_equivalent' => true,
    'show_payment_method' => true,
    'show_receipt_barcode' => false,
    'show_footer' => true,
    'footer_text' => getSetting('receipt_footer', 'Thank you for choosing our pharmacy!'),
    'show_date_time' => true,
    'show_cashier' => true,
    'show_rx_number' => true,
];

// Load saved template
$savedJson = getSetting('receipt_template', '');
$template = $savedJson ? array_merge($defaultTemplate, json_decode($savedJson, true) ?: []) : $defaultTemplate;

// Presets
$presets = [
    'lebanese_standard' => [
        'name' => 'Lebanese Standard',
        'format' => 'standard',
        'paper_width' => '80mm',
        'font_size' => 'medium',
        'show_logo' => true,
        'show_pharmacy_info' => true,
        'show_pharmacy_phone' => true,
        'show_pharmacy_address' => true,
        'show_pharmacy_license' => true,
        'show_patient_info' => true,
        'show_doctor_info' => true,
        'show_item_details' => true,
        'show_item_generic' => true,
        'show_item_barcode' => false,
        'show_subtotals' => true,
        'show_discount' => true,
        'show_tax' => true,
        'show_lbp_equivalent' => true,
        'show_payment_method' => true,
        'show_receipt_barcode' => false,
        'show_footer' => true,
        'show_date_time' => true,
        'show_cashier' => true,
        'show_rx_number' => true,
    ],
    'thermal_compact' => [
        'name' => 'Thermal Compact',
        'format' => 'thermal',
        'paper_width' => '58mm',
        'font_size' => 'small',
        'show_logo' => false,
        'show_pharmacy_info' => true,
        'show_pharmacy_phone' => true,
        'show_pharmacy_address' => false,
        'show_pharmacy_license' => false,
        'show_patient_info' => false,
        'show_doctor_info' => false,
        'show_item_details' => true,
        'show_item_generic' => false,
        'show_item_barcode' => false,
        'show_subtotals' => false,
        'show_discount' => true,
        'show_tax' => false,
        'show_lbp_equivalent' => true,
        'show_payment_method' => true,
        'show_receipt_barcode' => false,
        'show_footer' => true,
        'show_date_time' => true,
        'show_cashier' => false,
        'show_rx_number' => false,
    ],
    'insurance_claim' => [
        'name' => 'Insurance Claim',
        'format' => 'detailed',
        'paper_width' => 'A4',
        'font_size' => 'medium',
        'show_logo' => true,
        'show_pharmacy_info' => true,
        'show_pharmacy_phone' => true,
        'show_pharmacy_address' => true,
        'show_pharmacy_license' => true,
        'show_patient_info' => true,
        'show_doctor_info' => true,
        'show_item_details' => true,
        'show_item_generic' => true,
        'show_item_barcode' => true,
        'show_subtotals' => true,
        'show_discount' => true,
        'show_tax' => true,
        'show_lbp_equivalent' => true,
        'show_payment_method' => true,
        'show_receipt_barcode' => true,
        'show_footer' => true,
        'show_date_time' => true,
        'show_cashier' => true,
        'show_rx_number' => true,
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_template'])) {
        $config = [
            'format' => $_POST['format'] ?? 'standard',
            'paper_width' => $_POST['paper_width'] ?? '80mm',
            'font_size' => $_POST['font_size'] ?? 'medium',
            'show_logo' => isset($_POST['show_logo']),
            'show_pharmacy_info' => isset($_POST['show_pharmacy_info']),
            'show_pharmacy_phone' => isset($_POST['show_pharmacy_phone']),
            'show_pharmacy_address' => isset($_POST['show_pharmacy_address']),
            'show_pharmacy_license' => isset($_POST['show_pharmacy_license']),
            'show_patient_info' => isset($_POST['show_patient_info']),
            'show_doctor_info' => isset($_POST['show_doctor_info']),
            'show_item_details' => isset($_POST['show_item_details']),
            'show_item_generic' => isset($_POST['show_item_generic']),
            'show_item_barcode' => isset($_POST['show_item_barcode']),
            'show_subtotals' => isset($_POST['show_subtotals']),
            'show_discount' => isset($_POST['show_discount']),
            'show_tax' => isset($_POST['show_tax']),
            'show_lbp_equivalent' => isset($_POST['show_lbp_equivalent']),
            'show_payment_method' => isset($_POST['show_payment_method']),
            'show_receipt_barcode' => isset($_POST['show_receipt_barcode']),
            'show_footer' => isset($_POST['show_footer']),
            'footer_text' => $_POST['footer_text'] ?? '',
            'show_date_time' => isset($_POST['show_date_time']),
            'show_cashier' => isset($_POST['show_cashier']),
            'show_rx_number' => isset($_POST['show_rx_number']),
        ];
        updateSetting('receipt_template', json_encode($config));
        addAuditLog('update', 'settings', 0, null, ['receipt_template' => $config['format']]);
        flashMessage('Receipt template saved successfully');
        header('Location: receipt_templates.php');
        exit;
    }

    if (isset($_POST['load_preset'])) {
        $presetKey = $_POST['preset_key'] ?? '';
        if (isset($presets[$presetKey])) {
            $template = array_merge($defaultTemplate, $presets[$presetKey]);
        }
    }
}
?>

<form method="POST" id="templateForm">
<div class="row g-3">
    <!-- Configuration Panel -->
    <div class="col-lg-5">
        <div class="card p-3 mb-3">
            <h6 class="mb-3"><i class="bi bi-palette me-2"></i>Template Presets</h6>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($presets as $key => $preset): ?>
                <button type="submit" name="load_preset" value="1" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('preset_key_input').value='<?= $key ?>'">
                    <i class="bi bi-file-earmark me-1"></i><?= sanitize($preset['name']) ?>
                </button>
                <?php endforeach; ?>
                <input type="hidden" name="preset_key" id="preset_key_input" value="">
            </div>
        </div>

        <div class="card p-3 mb-3">
            <h6 class="mb-3"><i class="bi bi-sliders me-2"></i>Format Settings</h6>
            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Receipt Format</label>
                    <select class="form-select form-select-sm" name="format" id="receiptFormat" onchange="updatePreview()">
                        <option value="standard" <?= $template['format'] === 'standard' ? 'selected' : '' ?>>Standard</option>
                        <option value="compact" <?= $template['format'] === 'compact' ? 'selected' : '' ?>>Compact</option>
                        <option value="detailed" <?= $template['format'] === 'detailed' ? 'selected' : '' ?>>Detailed</option>
                        <option value="thermal" <?= $template['format'] === 'thermal' ? 'selected' : '' ?>>Thermal (80mm)</option>
                        <option value="a4" <?= $template['format'] === 'a4' ? 'selected' : '' ?>>A4</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Paper Width</label>
                    <select class="form-select form-select-sm" name="paper_width" id="paperWidth" onchange="updatePreview()">
                        <option value="58mm" <?= $template['paper_width'] === '58mm' ? 'selected' : '' ?>>58mm</option>
                        <option value="80mm" <?= $template['paper_width'] === '80mm' ? 'selected' : '' ?>>80mm</option>
                        <option value="A4" <?= $template['paper_width'] === 'A4' ? 'selected' : '' ?>>A4</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Font Size</label>
                    <select class="form-select form-select-sm" name="font_size" id="fontSize" onchange="updatePreview()">
                        <option value="small" <?= $template['font_size'] === 'small' ? 'selected' : '' ?>>Small</option>
                        <option value="medium" <?= $template['font_size'] === 'medium' ? 'selected' : '' ?>>Medium</option>
                        <option value="large" <?= $template['font_size'] === 'large' ? 'selected' : '' ?>>Large</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card p-3 mb-3">
            <h6 class="mb-3"><i class="bi bi-toggles me-2"></i>Field Visibility</h6>

            <p class="small fw-semibold text-primary mb-2">Header Section</p>
            <div class="row g-1 mb-3">
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_logo" id="showLogo" <?= $template['show_logo'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showLogo">Logo Area</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_pharmacy_info" id="showPharmacyInfo" <?= $template['show_pharmacy_info'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showPharmacyInfo">Pharmacy Info</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_pharmacy_phone" id="showPharmacyPhone" <?= $template['show_pharmacy_phone'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showPharmacyPhone">Phone</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_pharmacy_address" id="showPharmacyAddress" <?= $template['show_pharmacy_address'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showPharmacyAddress">Address</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_pharmacy_license" id="showPharmacyLicense" <?= $template['show_pharmacy_license'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showPharmacyLicense">License #</label>
                    </div>
                </div>
            </div>

            <p class="small fw-semibold text-primary mb-2">Customer / Doctor</p>
            <div class="row g-1 mb-3">
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_patient_info" id="showPatientInfo" <?= $template['show_patient_info'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showPatientInfo">Patient Info</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_doctor_info" id="showDoctorInfo" <?= $template['show_doctor_info'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showDoctorInfo">Doctor Info</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_rx_number" id="showRxNumber" <?= $template['show_rx_number'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showRxNumber">Rx Number</label>
                    </div>
                </div>
            </div>

            <p class="small fw-semibold text-primary mb-2">Items & Totals</p>
            <div class="row g-1 mb-3">
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_item_details" id="showItemDetails" <?= $template['show_item_details'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showItemDetails">Item Details</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_item_generic" id="showItemGeneric" <?= $template['show_item_generic'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showItemGeneric">Generic Name</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_item_barcode" id="showItemBarcode" <?= $template['show_item_barcode'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showItemBarcode">Item Barcode</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_subtotals" id="showSubtotals" <?= $template['show_subtotals'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showSubtotals">Subtotals</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_discount" id="showDiscount" <?= $template['show_discount'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showDiscount">Discount Line</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_tax" id="showTax" <?= $template['show_tax'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showTax">Tax/VAT</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_lbp_equivalent" id="showLbp" <?= $template['show_lbp_equivalent'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showLbp">LBP Equivalent</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_payment_method" id="showPaymentMethod" <?= $template['show_payment_method'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showPaymentMethod">Payment Method</label>
                    </div>
                </div>
            </div>

            <p class="small fw-semibold text-primary mb-2">Footer Section</p>
            <div class="row g-1 mb-3">
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_receipt_barcode" id="showReceiptBarcode" <?= $template['show_receipt_barcode'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showReceiptBarcode">Receipt Barcode</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_footer" id="showFooter" <?= $template['show_footer'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showFooter">Footer Text</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_date_time" id="showDateTime" <?= $template['show_date_time'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showDateTime">Date & Time</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_cashier" id="showCashier" <?= $template['show_cashier'] ? 'checked' : '' ?> onchange="updatePreview()">
                        <label class="form-check-label small" for="showCashier">Cashier Name</label>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold">Footer Text</label>
                <input type="text" class="form-control form-control-sm" name="footer_text" id="footerText" value="<?= sanitize($template['footer_text']) ?>" onchange="updatePreview()">
            </div>
        </div>

        <div class="d-flex gap-2 no-print">
            <button type="submit" name="save_template" value="1" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Save Template
            </button>
            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>

    <!-- Receipt Preview -->
    <div class="col-lg-7">
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0"><i class="bi bi-eye me-2"></i>Live Preview</h6>
                <span class="badge bg-secondary" id="previewFormatBadge"><?= sanitize(ucfirst($template['format'])) ?></span>
            </div>

            <div class="d-flex justify-content-center">
                <div id="receiptPreview" class="border rounded bg-white p-3" style="width:320px; font-family:'Courier New',monospace; font-size:12px;">
                    <!-- Preview content rendered by JS -->
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<?php
$templateJson = json_encode($template);
$extraScripts = <<<SCRIPT
<script>
var currentTemplate = $templateJson;
var pharmacyName = '{$pharmacyName}';
var exchangeRate = $exchangeRate;

function getVal(id) {
    var el = document.getElementById(id);
    if (!el) return false;
    if (el.type === 'checkbox') return el.checked;
    return el.value;
}

function updatePreview() {
    var config = {
        format: getVal('receiptFormat'),
        paper_width: getVal('paperWidth'),
        font_size: getVal('fontSize'),
        show_logo: getVal('showLogo'),
        show_pharmacy_info: getVal('showPharmacyInfo'),
        show_pharmacy_phone: getVal('showPharmacyPhone'),
        show_pharmacy_address: getVal('showPharmacyAddress'),
        show_pharmacy_license: getVal('showPharmacyLicense'),
        show_patient_info: getVal('showPatientInfo'),
        show_doctor_info: getVal('showDoctorInfo'),
        show_rx_number: getVal('showRxNumber'),
        show_item_details: getVal('showItemDetails'),
        show_item_generic: getVal('showItemGeneric'),
        show_item_barcode: getVal('showItemBarcode'),
        show_subtotals: getVal('showSubtotals'),
        show_discount: getVal('showDiscount'),
        show_tax: getVal('showTax'),
        show_lbp_equivalent: getVal('showLbp'),
        show_payment_method: getVal('showPaymentMethod'),
        show_receipt_barcode: getVal('showReceiptBarcode'),
        show_footer: getVal('showFooter'),
        show_date_time: getVal('showDateTime'),
        show_cashier: getVal('showCashier'),
        footer_text: document.getElementById('footerText').value
    };

    var preview = document.getElementById('receiptPreview');
    var badge = document.getElementById('previewFormatBadge');
    badge.textContent = config.format.charAt(0).toUpperCase() + config.format.slice(1);

    // Adjust preview width based on paper
    var widths = {'58mm': '240px', '80mm': '320px', 'A4': '500px'};
    preview.style.width = widths[config.paper_width] || '320px';

    // Font size
    var sizes = {'small': '10px', 'medium': '12px', 'large': '14px'};
    preview.style.fontSize = sizes[config.font_size] || '12px';

    var html = '';
    var divider = '<div style="border-top:1px dashed #999;margin:6px 0"></div>';

    // Header
    if (config.show_logo) {
        html += '<div style="text-align:center;margin-bottom:4px"><div style="width:50px;height:50px;border:1px dashed #ccc;margin:0 auto;display:flex;align-items:center;justify-content:center;color:#999;font-size:10px">LOGO</div></div>';
    }
    if (config.show_pharmacy_info) {
        html += '<div style="text-align:center"><strong style="font-size:1.2em">' + pharmacyName + '</strong></div>';
        if (config.show_pharmacy_address) html += '<div style="text-align:center;font-size:0.9em">Hamra St., Beirut, Lebanon</div>';
        if (config.show_pharmacy_phone) html += '<div style="text-align:center;font-size:0.9em">Tel: +961 1 234 567</div>';
        if (config.show_pharmacy_license) html += '<div style="text-align:center;font-size:0.85em;color:#666">License: PH-2024-001</div>';
    }

    html += divider;

    // Invoice info
    html += '<table style="width:100%"><tr><td>Invoice:</td><td style="text-align:right">INV-20240815-0042</td></tr>';
    if (config.show_date_time) html += '<tr><td>Date:</td><td style="text-align:right">2024-08-15 14:30</td></tr>';
    if (config.show_cashier) html += '<tr><td>Cashier:</td><td style="text-align:right">Ahmad</td></tr>';
    html += '</table>';

    // Patient / Doctor
    if (config.show_patient_info || config.show_doctor_info) {
        html += divider;
        if (config.show_patient_info) html += '<div><strong>Patient:</strong> Nour Haddad</div>';
        if (config.show_doctor_info) html += '<div><strong>Doctor:</strong> Dr. Khaled Mansour</div>';
        if (config.show_rx_number) html += '<div><strong>Rx #:</strong> RX-2024-0891</div>';
    }

    html += divider;

    // Items header
    html += '<table style="width:100%"><tr><td><strong>Item</strong></td><td style="text-align:center"><strong>Qty</strong></td><td style="text-align:right"><strong>Price</strong></td></tr></table>';
    html += divider;

    // Sample items
    var items = [
        {name: 'Augmentin 1g', generic: 'Amoxicillin/Clavulanic', barcode: '6281000100001', qty: 1, price: 12.50},
        {name: 'Panadol Extra', generic: 'Paracetamol/Caffeine', barcode: '6281000200002', qty: 2, price: 3.00},
        {name: 'Omeprazole 20mg', generic: 'Omeprazole', barcode: '6281000300003', qty: 1, price: 8.75}
    ];

    items.forEach(function(item) {
        if (config.show_item_details) {
            html += '<div><strong>' + item.name + '</strong></div>';
            if (config.show_item_generic) html += '<div style="font-size:0.85em;color:#666">' + item.generic + '</div>';
            if (config.show_item_barcode) html += '<div style="font-size:0.8em;color:#999">' + item.barcode + '</div>';
            html += '<table style="width:100%"><tr><td></td><td style="text-align:center">' + item.qty + ' x $' + item.price.toFixed(2) + '</td><td style="text-align:right">$' + (item.qty * item.price).toFixed(2) + '</td></tr></table>';
        } else {
            html += '<table style="width:100%"><tr><td>' + item.name + '</td><td style="text-align:right">$' + (item.qty * item.price).toFixed(2) + '</td></tr></table>';
        }
    });

    html += divider;

    // Totals
    var subtotal = 27.25;
    var discount = 2.00;
    var tax = 2.53;
    var total = subtotal - discount + tax;

    if (config.show_subtotals) html += '<table style="width:100%"><tr><td>Subtotal:</td><td style="text-align:right">$' + subtotal.toFixed(2) + '</td></tr></table>';
    if (config.show_discount) html += '<table style="width:100%"><tr><td>Discount:</td><td style="text-align:right">-$' + discount.toFixed(2) + '</td></tr></table>';
    if (config.show_tax) html += '<table style="width:100%"><tr><td>VAT (11%):</td><td style="text-align:right">$' + tax.toFixed(2) + '</td></tr></table>';
    html += '<table style="width:100%"><tr><td><strong style="font-size:1.1em">TOTAL:</strong></td><td style="text-align:right"><strong style="font-size:1.1em">$' + total.toFixed(2) + '</strong></td></tr></table>';
    if (config.show_lbp_equivalent) {
        var lbpTotal = Math.round(total * exchangeRate);
        html += '<table style="width:100%"><tr><td>In LBP:</td><td style="text-align:right">' + lbpTotal.toLocaleString() + ' L.L.</td></tr></table>';
    }
    if (config.show_payment_method) html += '<table style="width:100%"><tr><td>Payment:</td><td style="text-align:right">Cash</td></tr></table>';

    html += divider;

    // Footer
    if (config.show_receipt_barcode) {
        html += '<div style="text-align:center;margin:6px 0"><div style="height:30px;background:repeating-linear-gradient(90deg,#000 0px,#000 2px,#fff 2px,#fff 4px,#000 4px,#000 5px,#fff 5px,#fff 8px);margin:0 auto;width:60%"></div><div style="font-size:0.8em">INV-20240815-0042</div></div>';
    }
    if (config.show_footer) {
        html += '<div style="text-align:center;margin-top:4px">' + (config.footer_text || 'Thank you!') + '</div>';
    }
    if (config.show_date_time) {
        html += '<div style="text-align:center;font-size:0.8em;color:#999">Printed: ' + new Date().toLocaleString() + '</div>';
    }

    preview.innerHTML = html;
}

// Initialize preview
updatePreview();
</script>
SCRIPT;

require_once __DIR__ . '/../../includes/footer.php';
?>
