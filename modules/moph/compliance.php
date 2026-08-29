<?php
$pageTitle = 'MoPH Compliance';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { header('Location: ' . BASE_URL); exit; }
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));

// Save checklist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_checklist'])) {
    $checklistData = [];
    $items = $_POST['items'] ?? [];
    $notes = $_POST['notes'] ?? [];
    foreach ($items as $key => $val) {
        $checklistData[$key] = [
            'checked' => isset($val['checked']) ? 1 : 0,
            'notes' => $notes[$key] ?? ''
        ];
    }
    $checklistData['last_audit_date'] = $_POST['last_audit_date'] ?? '';
    $checklistData['next_audit_date'] = $_POST['next_audit_date'] ?? '';
    $checklistData['saved_at'] = date('Y-m-d H:i:s');
    $checklistData['saved_by'] = $_SESSION['user_id'] ?? null;

    updateSetting('moph_compliance_checklist', json_encode($checklistData));
    addAuditLog('update', 'settings', 0, null, ['key' => 'moph_compliance_checklist']);
    flashMessage('Compliance checklist saved successfully');
    header('Location: compliance.php');
    exit;
}

// Load saved checklist
$savedChecklist = json_decode(getSetting('moph_compliance_checklist', '{}'), true);

// Auto-check: License validity
$licenseExpiry = getSetting('license_expiry_date', '');
$licenseValid = !empty($licenseExpiry) && strtotime($licenseExpiry) >= strtotime(date('Y-m-d'));

// Auto-check: Pharmacist on duty (check employee_shifts if exists)
$pharmacistOnDuty = false;
try {
    $shiftCheck = $db->query("SELECT COUNT(*) FROM employee_shifts WHERE DATE(shift_date) = CURDATE() AND status = 'active'");
    $pharmacistOnDuty = $shiftCheck->fetchColumn() > 0;
} catch (Exception $e) {
    // Table may not exist
    $pharmacistOnDuty = null;
}

// Auto-check: Controlled substance log up to date
$lastControlledEntry = null;
try {
    $lastControlledEntry = $db->query("SELECT MAX(created_at) FROM controlled_substance_log")->fetchColumn();
} catch (Exception $e) {}
$controlledUpToDate = !empty($lastControlledEntry) && strtotime($lastControlledEntry) >= strtotime('-7 days');

// Auto-check: Expired medicines segregation
$expiredCount = 0;
try {
    $expiredCount = $db->query("SELECT COUNT(*) FROM medicines WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND is_active = 1")->fetchColumn();
} catch (Exception $e) {}
$expiredSegregated = $expiredCount == 0;

// Auto-check: Price list posted (check moph_price_list)
$priceListCount = 0;
try {
    $priceListCount = $db->query("SELECT COUNT(*) FROM moph_price_list")->fetchColumn();
} catch (Exception $e) {}
$priceListPosted = $priceListCount > 0;

// Auto-check: Prescription records
$recentPrescriptions = 0;
try {
    $recentPrescriptions = $db->query("SELECT COUNT(*) FROM prescriptions WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
} catch (Exception $e) {}

// Define compliance items
$complianceItems = [
    'license_validity' => [
        'label' => 'Pharmacy License Validity',
        'description' => 'Valid pharmacy operating license from MoPH',
        'icon' => 'bi-card-checklist',
        'auto' => $licenseValid,
        'auto_detail' => $licenseValid ? 'License valid until ' . formatDate($licenseExpiry, 'M d, Y') : ($licenseExpiry ? 'License expired ' . formatDate($licenseExpiry, 'M d, Y') : 'No license date set'),
    ],
    'pharmacist_on_duty' => [
        'label' => 'Pharmacist On Duty',
        'description' => 'Licensed pharmacist present during operating hours (MoPH Law 367)',
        'icon' => 'bi-person-badge',
        'auto' => $pharmacistOnDuty,
        'auto_detail' => $pharmacistOnDuty === null ? 'Shift tracking not available' : ($pharmacistOnDuty ? 'Active shift found today' : 'No active shift today'),
    ],
    'controlled_substances' => [
        'label' => 'Controlled Substance Log',
        'description' => 'Narcotic and psychotropic substances register up to date',
        'icon' => 'bi-shield-lock',
        'auto' => $controlledUpToDate,
        'auto_detail' => $lastControlledEntry ? 'Last entry: ' . formatDate($lastControlledEntry, 'M d, Y H:i') : 'No entries found',
    ],
    'temperature_monitoring' => [
        'label' => 'Temperature Monitoring Records',
        'description' => 'Daily temperature logs for storage areas (2-8C refrigerator, 15-25C room)',
        'icon' => 'bi-thermometer-half',
        'auto' => null,
        'auto_detail' => 'Manual verification required',
    ],
    'expired_segregation' => [
        'label' => 'Expired Medicines Segregation',
        'description' => 'Expired products separated and quarantined for disposal',
        'icon' => 'bi-exclamation-triangle',
        'auto' => $expiredSegregated,
        'auto_detail' => $expiredSegregated ? 'No expired items in active stock' : $expiredCount . ' expired item(s) still in active inventory',
    ],
    'price_list' => [
        'label' => 'MoPH Price List Posted',
        'description' => 'Official MoPH price list available and visible to customers',
        'icon' => 'bi-tag',
        'auto' => $priceListPosted,
        'auto_detail' => $priceListPosted ? $priceListCount . ' items in price list' : 'No MoPH price list entries',
    ],
    'insurance_documentation' => [
        'label' => 'Insurance Documentation',
        'description' => 'Active contracts and claim documentation for NSSF, Army, ISF, etc.',
        'icon' => 'bi-file-earmark-medical',
        'auto' => null,
        'auto_detail' => 'Manual verification required',
    ],
    'prescription_records' => [
        'label' => 'Prescription Records',
        'description' => 'Proper filing and retention of prescriptions (minimum 2 years)',
        'icon' => 'bi-file-text',
        'auto' => $recentPrescriptions > 0 ? true : null,
        'auto_detail' => $recentPrescriptions > 0 ? $recentPrescriptions . ' prescriptions in last 30 days' : 'No recent prescription records',
    ],
    'storage_conditions' => [
        'label' => 'Storage Conditions Compliance',
        'description' => 'Proper shelving, ventilation, lighting, cleanliness per MoPH standards',
        'icon' => 'bi-box-seam',
        'auto' => null,
        'auto_detail' => 'Manual verification required',
    ],
];

// Calculate compliance score
$totalItems = count($complianceItems);
$compliantItems = 0;
foreach ($complianceItems as $key => $item) {
    $isChecked = false;
    if ($item['auto'] === true) {
        $isChecked = true;
    } elseif (isset($savedChecklist[$key]['checked']) && $savedChecklist[$key]['checked']) {
        $isChecked = true;
    }
    if ($isChecked) $compliantItems++;
}
$complianceScore = $totalItems > 0 ? round(($compliantItems / $totalItems) * 100) : 0;

$lastAuditDate = $savedChecklist['last_audit_date'] ?? '';
$nextAuditDate = $savedChecklist['next_audit_date'] ?? '';
$savedAt = $savedChecklist['saved_at'] ?? '';

$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');
$pharmacistName = getSetting('pharmacist_name', '');
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h6 class="mb-0">
            <i class="bi bi-clipboard-check me-2"></i>MoPH Compliance Checklist
        </h6>
        <?php if ($savedAt): ?>
        <small class="text-muted">Last saved: <?= formatDate($savedAt, 'M d, Y H:i') ?></small>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 no-print">
        <button onclick="window.print()" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer me-1"></i>Print Report</button>
    </div>
</div>

<!-- Compliance Score -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card <?= $complianceScore >= 80 ? 'success' : ($complianceScore >= 50 ? 'warning' : 'danger') ?>">
            <div class="stat-label">Compliance Score</div>
            <div class="stat-value"><?= $complianceScore ?>%</div>
            <small class="text-muted"><?= $compliantItems ?> / <?= $totalItems ?> items</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card info">
            <div class="stat-label">Last Audit</div>
            <div class="stat-value small"><?= $lastAuditDate ? formatDate($lastAuditDate, 'M d, Y') : 'Not set' ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card <?= ($nextAuditDate && strtotime($nextAuditDate) < strtotime('+7 days')) ? 'warning' : '' ?>">
            <div class="stat-label">Next Audit</div>
            <div class="stat-value small"><?= $nextAuditDate ? formatDate($nextAuditDate, 'M d, Y') : 'Not set' ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Expired in Stock</div>
            <div class="stat-value <?= $expiredCount > 0 ? 'text-danger' : 'text-success' ?>"><?= $expiredCount ?></div>
            <small class="text-muted"><?= $expiredCount > 0 ? 'Action required' : 'All clear' ?></small>
        </div>
    </div>
</div>

<!-- Progress bar -->
<div class="card p-3 mb-3">
    <div class="d-flex justify-content-between mb-1">
        <small class="fw-semibold">Overall Compliance</small>
        <small class="fw-semibold"><?= $complianceScore ?>%</small>
    </div>
    <div class="progress" style="height: 12px">
        <div class="progress-bar bg-<?= $complianceScore >= 80 ? 'success' : ($complianceScore >= 50 ? 'warning' : 'danger') ?>"
             style="width: <?= $complianceScore ?>%"></div>
    </div>
</div>

<!-- Checklist Form -->
<form method="POST">
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Compliance Requirements</h6>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($complianceItems as $key => $item):
                        $autoStatus = $item['auto'];
                        $savedItem = $savedChecklist[$key] ?? [];
                        $isChecked = ($autoStatus === true) || (!empty($savedItem['checked']));
                        $itemNotes = $savedItem['notes'] ?? '';
                    ?>
                    <div class="list-group-item">
                        <div class="d-flex align-items-start gap-3">
                            <div class="pt-1">
                                <?php if ($autoStatus === true): ?>
                                <i class="bi bi-check-circle-fill text-success fs-5"></i>
                                <input type="hidden" name="items[<?= $key ?>][checked]" value="1">
                                <?php elseif ($autoStatus === false): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="items[<?= $key ?>][checked]" value="1"
                                        id="check_<?= $key ?>" <?= $isChecked ? 'checked' : '' ?>>
                                </div>
                                <?php else: ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="items[<?= $key ?>][checked]" value="1"
                                        id="check_<?= $key ?>" <?= $isChecked ? 'checked' : '' ?>>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center">
                                    <label class="fw-semibold mb-0 <?= $isChecked ? 'text-success' : '' ?>" for="check_<?= $key ?>">
                                        <i class="bi <?= $item['icon'] ?> me-1"></i><?= sanitize($item['label']) ?>
                                    </label>
                                    <?php if ($autoStatus !== null): ?>
                                    <span class="badge bg-<?= $autoStatus ? 'success' : 'danger' ?> bg-opacity-10 text-<?= $autoStatus ? 'success' : 'danger' ?>">
                                        <i class="bi bi-<?= $autoStatus ? 'check' : 'x' ?>-circle me-1"></i><?= $autoStatus ? 'Auto-verified' : 'Auto-failed' ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary">
                                        <i class="bi bi-hand-index me-1"></i>Manual check
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted d-block"><?= sanitize($item['description']) ?></small>
                                <small class="text-<?= ($autoStatus === false) ? 'danger' : 'info' ?> d-block mt-1">
                                    <i class="bi bi-info-circle me-1"></i><?= sanitize($item['auto_detail']) ?>
                                </small>
                                <div class="mt-2">
                                    <input type="text" class="form-control form-control-sm" name="notes[<?= $key ?>]"
                                        value="<?= sanitize($itemNotes) ?>" placeholder="Notes...">
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Audit Dates -->
            <div class="card p-3 mb-3">
                <h6><i class="bi bi-calendar-event me-2"></i>Audit Schedule</h6>
                <div class="mb-2">
                    <label class="form-label small">Last Audit Date</label>
                    <input type="date" class="form-control" name="last_audit_date" value="<?= sanitize($lastAuditDate) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Next Audit Date</label>
                    <input type="date" class="form-control" name="next_audit_date" value="<?= sanitize($nextAuditDate) ?>">
                </div>
                <button type="submit" name="save_checklist" value="1" class="btn btn-primary w-100 no-print">
                    <i class="bi bi-save me-1"></i>Save Checklist
                </button>
            </div>

            <!-- Quick Links -->
            <div class="card p-3 mb-3">
                <h6><i class="bi bi-link-45deg me-2"></i>Related Modules</h6>
                <div class="list-group list-group-flush">
                    <a href="<?= BASE_URL ?>/modules/moph/controlled.php" class="list-group-item list-group-item-action py-2">
                        <i class="bi bi-shield-lock me-2"></i>Controlled Substances
                    </a>
                    <a href="<?= BASE_URL ?>/modules/moph/price_list.php" class="list-group-item list-group-item-action py-2">
                        <i class="bi bi-tag me-2"></i>MoPH Price List
                    </a>
                    <a href="<?= BASE_URL ?>/modules/inventory/alerts.php" class="list-group-item list-group-item-action py-2">
                        <i class="bi bi-exclamation-triangle me-2"></i>Inventory Alerts
                    </a>
                    <a href="<?= BASE_URL ?>/modules/inventory/disposal.php" class="list-group-item list-group-item-action py-2">
                        <i class="bi bi-trash me-2"></i>Disposal Management
                    </a>
                    <a href="<?= BASE_URL ?>/modules/settings/shifts.php" class="list-group-item list-group-item-action py-2">
                        <i class="bi bi-clock me-2"></i>Shift Management
                    </a>
                </div>
            </div>

            <!-- Printable Summary -->
            <div class="card p-3">
                <h6><i class="bi bi-building me-2"></i>Pharmacy Details</h6>
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Name</td><td><?= sanitize($pharmacyName) ?></td></tr>
                    <tr><td class="text-muted">Pharmacist</td><td><?= sanitize($pharmacistName ?: 'Not set') ?></td></tr>
                    <tr><td class="text-muted">License Expiry</td><td><?= $licenseExpiry ? formatDate($licenseExpiry, 'M d, Y') : 'Not set' ?></td></tr>
                    <tr><td class="text-muted">Report Date</td><td><?= date('M d, Y') ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
