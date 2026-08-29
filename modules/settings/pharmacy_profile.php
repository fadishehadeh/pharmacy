<?php
$pageTitle = 'Pharmacy Profile';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$exchangeRate = getSetting('exchange_rate', '89500');
$vatRate = getSetting('vat_rate', '11');

// Pharmacy info from settings
$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');
$pharmacyNameAr = getSetting('pharmacy_name_ar', '');
$pharmacyAddress = getSetting('pharmacy_address', '');
$pharmacyPhone = getSetting('pharmacy_phone', '');
$pharmacyEmail = getSetting('pharmacy_email', '');
$pharmacyLicense = getSetting('pharmacy_license', '');
$pharmacistName = getSetting('pharmacist_name', '');
$pharmacistLicense = getSetting('pharmacist_license', '');
$operatingHours = getSetting('operating_hours', '');
$operatingHoursFri = getSetting('operating_hours_friday', '');
$operatingHoursSat = getSetting('operating_hours_saturday', '');

// System stats
$totalMedicines = intval($db->query("SELECT COUNT(*) FROM medicines WHERE is_active = 1")->fetchColumn());
$totalCategories = intval($db->query("SELECT COUNT(*) FROM categories")->fetchColumn());
$totalSuppliers = intval($db->query("SELECT COUNT(*) FROM suppliers WHERE is_active = 1")->fetchColumn());
$totalCustomers = intval($db->query("SELECT COUNT(*) FROM customers")->fetchColumn());
$totalSales = intval($db->query("SELECT COUNT(*) FROM sales WHERE status = 'completed'")->fetchColumn());
$totalUsers = intval($db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn());

// Database size estimate
$dbSize = $db->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
    FROM information_schema.tables WHERE table_schema = DATABASE()")->fetch();
$dbSizeMB = $dbSize['size_mb'] ?? '0';

// Inventory value
$inventoryValue = floatval($db->query("SELECT COALESCE(SUM(cost_price * quantity_in_stock), 0) FROM medicines WHERE is_active = 1")->fetchColumn());
$inventoryRetailValue = floatval($db->query("SELECT COALESCE(SUM(sell_price * quantity_in_stock), 0) FROM medicines WHERE is_active = 1")->fetchColumn());

// Total revenue
$totalRevenue = floatval($db->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE status = 'completed'")->fetchColumn());

// QR code text placeholder
$qrText = "$pharmacyName\n$pharmacyAddress\nTel: $pharmacyPhone\nEmail: $pharmacyEmail\nLicense: $pharmacyLicense";
?>

<div class="row g-3">
    <!-- Main Profile Card -->
    <div class="col-lg-8">
        <div class="card p-4 mb-3" id="profileCard">
            <div class="text-center mb-4">
                <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 rounded-circle mb-3" style="width:80px;height:80px">
                    <i class="bi bi-hospital text-primary" style="font-size:2.5rem"></i>
                </div>
                <h4 class="mb-1"><?= sanitize($pharmacyName) ?></h4>
                <?php if ($pharmacyNameAr): ?>
                <h5 class="text-muted mb-2" dir="rtl"><?= sanitize($pharmacyNameAr) ?></h5>
                <?php endif; ?>
                <p class="text-muted mb-0">
                    <?php if ($pharmacyAddress): ?><i class="bi bi-geo-alt me-1"></i><?= sanitize($pharmacyAddress) ?><br><?php endif; ?>
                    <?php if ($pharmacyPhone): ?><i class="bi bi-telephone me-1"></i><?= sanitize($pharmacyPhone) ?><?php endif; ?>
                    <?php if ($pharmacyEmail): ?> | <i class="bi bi-envelope me-1"></i><?= sanitize($pharmacyEmail) ?><?php endif; ?>
                </p>
            </div>

            <div class="row g-3">
                <!-- Licensing -->
                <div class="col-md-6">
                    <div class="card bg-light p-3 h-100">
                        <h6 class="text-primary"><i class="bi bi-shield-check me-2"></i>Licensing</h6>
                        <table class="table table-sm table-borderless mb-0">
                            <tr><td class="text-muted small" style="width:45%">MoPH License</td><td class="fw-semibold small"><?= sanitize($pharmacyLicense ?: 'Not set') ?></td></tr>
                            <tr><td class="text-muted small">Pharmacist</td><td class="fw-semibold small"><?= sanitize($pharmacistName ?: 'Not set') ?></td></tr>
                            <tr><td class="text-muted small">Pharmacist License</td><td class="fw-semibold small"><?= sanitize($pharmacistLicense ?: 'Not set') ?></td></tr>
                        </table>
                    </div>
                </div>

                <!-- Financial -->
                <div class="col-md-6">
                    <div class="card bg-light p-3 h-100">
                        <h6 class="text-primary"><i class="bi bi-currency-exchange me-2"></i>Financial</h6>
                        <table class="table table-sm table-borderless mb-0">
                            <tr><td class="text-muted small" style="width:45%">Exchange Rate</td><td class="fw-semibold small">1 USD = <?= number_format(floatval($exchangeRate), 0, '.', ',') ?> LBP</td></tr>
                            <tr><td class="text-muted small">VAT Rate</td><td class="fw-semibold small"><?= sanitize($vatRate) ?>%</td></tr>
                            <tr><td class="text-muted small">Inventory (Cost)</td><td class="fw-semibold small"><?= formatCurrency($inventoryValue) ?></td></tr>
                            <tr><td class="text-muted small">Inventory (Retail)</td><td class="fw-semibold small"><?= formatCurrency($inventoryRetailValue) ?></td></tr>
                        </table>
                    </div>
                </div>

                <!-- Operating Hours -->
                <div class="col-md-6">
                    <div class="card bg-light p-3 h-100">
                        <h6 class="text-primary"><i class="bi bi-clock me-2"></i>Operating Hours</h6>
                        <table class="table table-sm table-borderless mb-0">
                            <tr><td class="text-muted small" style="width:45%">Mon - Thu</td><td class="fw-semibold small"><?= sanitize($operatingHours ?: '8:00 AM - 8:00 PM') ?></td></tr>
                            <tr><td class="text-muted small">Friday</td><td class="fw-semibold small"><?= sanitize($operatingHoursFri ?: $operatingHours ?: '8:00 AM - 8:00 PM') ?></td></tr>
                            <tr><td class="text-muted small">Saturday</td><td class="fw-semibold small"><?= sanitize($operatingHoursSat ?: $operatingHours ?: '8:00 AM - 8:00 PM') ?></td></tr>
                        </table>
                    </div>
                </div>

                <!-- Contact -->
                <div class="col-md-6">
                    <div class="card bg-light p-3 h-100">
                        <h6 class="text-primary"><i class="bi bi-building me-2"></i>Contact</h6>
                        <table class="table table-sm table-borderless mb-0">
                            <tr><td class="text-muted small" style="width:45%">Phone</td><td class="fw-semibold small"><?= sanitize($pharmacyPhone ?: 'Not set') ?></td></tr>
                            <tr><td class="text-muted small">Email</td><td class="fw-semibold small"><?= sanitize($pharmacyEmail ?: 'Not set') ?></td></tr>
                            <tr><td class="text-muted small">Address</td><td class="fw-semibold small"><?= sanitize($pharmacyAddress ?: 'Not set') ?></td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Stats -->
        <div class="card p-3">
            <h6><i class="bi bi-bar-chart me-2"></i>System Statistics</h6>
            <div class="row g-3 mt-1">
                <div class="col-4 col-md-2 text-center">
                    <div class="fw-bold text-primary" style="font-size:1.5rem"><?= number_format($totalMedicines) ?></div>
                    <small class="text-muted">Medicines</small>
                </div>
                <div class="col-4 col-md-2 text-center">
                    <div class="fw-bold text-primary" style="font-size:1.5rem"><?= number_format($totalCategories) ?></div>
                    <small class="text-muted">Categories</small>
                </div>
                <div class="col-4 col-md-2 text-center">
                    <div class="fw-bold text-primary" style="font-size:1.5rem"><?= number_format($totalSuppliers) ?></div>
                    <small class="text-muted">Suppliers</small>
                </div>
                <div class="col-4 col-md-2 text-center">
                    <div class="fw-bold text-primary" style="font-size:1.5rem"><?= number_format($totalCustomers) ?></div>
                    <small class="text-muted">Customers</small>
                </div>
                <div class="col-4 col-md-2 text-center">
                    <div class="fw-bold text-primary" style="font-size:1.5rem"><?= number_format($totalSales) ?></div>
                    <small class="text-muted">Sales</small>
                </div>
                <div class="col-4 col-md-2 text-center">
                    <div class="fw-bold text-primary" style="font-size:1.5rem"><?= number_format($totalUsers) ?></div>
                    <small class="text-muted">Users</small>
                </div>
            </div>
            <hr>
            <div class="row g-3">
                <div class="col-md-4">
                    <small class="text-muted">Total Revenue</small>
                    <div class="fw-bold"><?= formatCurrency($totalRevenue) ?></div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">Database Size</small>
                    <div class="fw-bold"><?= $dbSizeMB ?> MB</div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">System Version</small>
                    <div class="fw-bold">PharmaSys v1.0 <span class="badge bg-success">Active</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">
        <!-- Print Card (MoPH Requirement) -->
        <div class="card p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><i class="bi bi-printer me-2"></i>Print Info Card</h6>
                <button onclick="printProfileCard()" class="btn btn-sm btn-outline-primary no-print"><i class="bi bi-printer me-1"></i>Print</button>
            </div>
            <small class="text-muted">Print pharmacy information card for display as required by MoPH regulations.</small>
        </div>

        <!-- Print-Friendly Card -->
        <div class="card p-4 mb-3 border-primary" id="printCard">
            <div class="text-center">
                <h5 class="mb-1"><?= sanitize($pharmacyName) ?></h5>
                <?php if ($pharmacyNameAr): ?>
                <h6 class="text-muted mb-2" dir="rtl"><?= sanitize($pharmacyNameAr) ?></h6>
                <?php endif; ?>
                <hr>
                <p class="small mb-1"><strong>Address:</strong> <?= sanitize($pharmacyAddress ?: '-') ?></p>
                <p class="small mb-1"><strong>Phone:</strong> <?= sanitize($pharmacyPhone ?: '-') ?></p>
                <p class="small mb-1"><strong>Email:</strong> <?= sanitize($pharmacyEmail ?: '-') ?></p>
                <hr>
                <p class="small mb-1"><strong>MoPH License:</strong> <?= sanitize($pharmacyLicense ?: '-') ?></p>
                <p class="small mb-1"><strong>Responsible Pharmacist:</strong> <?= sanitize($pharmacistName ?: '-') ?></p>
                <p class="small mb-1"><strong>Pharmacist License:</strong> <?= sanitize($pharmacistLicense ?: '-') ?></p>
                <hr>
                <p class="small mb-0"><strong>Hours:</strong> <?= sanitize($operatingHours ?: '8:00 AM - 8:00 PM') ?></p>
            </div>
        </div>

        <!-- QR Code Placeholder -->
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-qr-code me-2"></i>QR Code</h6>
            <div class="text-center p-3 bg-light rounded">
                <div style="width:150px;height:150px;margin:auto;border:2px solid #333;display:flex;align-items:center;justify-content:center;background:#fff">
                    <div style="font-size:10px;font-family:monospace;text-align:center;padding:5px;word-break:break-all">
                        QR CODE<br>
                        <?= sanitize($pharmacyName) ?><br>
                        <?= sanitize($pharmacyPhone) ?><br>
                        <?= sanitize($pharmacyEmail) ?>
                    </div>
                </div>
                <small class="text-muted d-block mt-2">Scan for pharmacy contact info</small>
                <small class="text-muted">Generate a real QR code at qr-code-generator.com</small>
            </div>
            <div class="mt-2">
                <label class="form-label small">QR Content:</label>
                <textarea class="form-control form-control-sm" rows="3" readonly><?= sanitize($qrText) ?></textarea>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="card p-3 no-print">
            <h6><i class="bi bi-link-45deg me-2"></i>Quick Links</h6>
            <div class="list-group list-group-flush">
                <a href="<?= BASE_URL ?>/modules/settings/index.php" class="list-group-item list-group-item-action px-0 py-2 small"><i class="bi bi-gear me-2"></i>Edit Settings</a>
                <a href="<?= BASE_URL ?>/modules/settings/backup.php" class="list-group-item list-group-item-action px-0 py-2 small"><i class="bi bi-download me-2"></i>Database Backup</a>
                <a href="<?= BASE_URL ?>/modules/settings/activity.php" class="list-group-item list-group-item-action px-0 py-2 small"><i class="bi bi-clock-history me-2"></i>Activity Log</a>
                <a href="<?= BASE_URL ?>/modules/moph/compliance.php" class="list-group-item list-group-item-action px-0 py-2 small"><i class="bi bi-shield-check me-2"></i>MoPH Compliance</a>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = "<script>
function printProfileCard() {
    var card = document.getElementById('printCard');
    var win = window.open('', '_blank');
    win.document.write('<html><head><title>" . addslashes($pharmacyName) . " - Info Card</title>');
    win.document.write('<style>body{font-family:Arial,sans-serif;padding:20px;max-width:400px;margin:auto}h5,h6{margin:5px 0}hr{border:1px solid #ccc}p{margin:3px 0}.text-center{text-align:center}.small{font-size:13px}</style>');
    win.document.write('</head><body>');
    win.document.write(card.innerHTML);
    win.document.write('</body></html>');
    win.document.close();
    win.print();
}
</script>";

require_once __DIR__ . '/../../includes/footer.php';
?>
