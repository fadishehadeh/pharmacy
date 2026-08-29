<?php
$pageTitle = 'Medical History';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$patientId = intval($_GET['patient_id'] ?? 0);

// If no patient_id, show search/select interface
if (!$patientId):
    $search = trim($_GET['search'] ?? '');
    $patients = [];
    if ($search) {
        $stmt = $db->prepare("SELECT pp.*, c.name, c.phone, c.email FROM patient_profiles pp JOIN customers c ON pp.customer_id = c.id WHERE c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? ORDER BY c.name LIMIT 50");
        $like = "%{$search}%";
        $stmt->execute([$like, $like, $like]);
        $patients = $stmt->fetchAll();
    } else {
        $patients = $db->query("SELECT pp.*, c.name, c.phone, c.email FROM patient_profiles pp JOIN customers c ON pp.customer_id = c.id ORDER BY c.name LIMIT 50")->fetchAll();
    }
?>

<div class="card p-4">
    <h6 class="mb-3"><i class="bi bi-search me-2"></i>Select Patient</h6>
    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-6">
            <div class="input-group">
                <input type="text" class="form-control" name="search" placeholder="Search by name, phone or email..." value="<?= sanitize($search) ?>">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
            </div>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover data-table mb-0">
            <thead><tr><th>Name</th><th>Phone</th><th>DOB</th><th>Blood Type</th><th>Conditions</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($patients as $p): ?>
                <tr>
                    <td><strong><?= sanitize($p['name']) ?></strong></td>
                    <td><?= sanitize($p['phone'] ?? '-') ?></td>
                    <td><?= $p['date_of_birth'] ? formatDate($p['date_of_birth'], 'M d, Y') : '-' ?></td>
                    <td><?= sanitize($p['blood_type'] ?? '-') ?></td>
                    <td>
                        <?php if (!empty($p['chronic_conditions'])): ?>
                        <small class="text-muted"><?= sanitize(mb_strimwidth($p['chronic_conditions'], 0, 40, '...')) ?></small>
                        <?php else: ?>
                        <small class="text-muted">-</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="medical_history.php?patient_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-journal-medical me-1"></i>View History
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($patients)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No patients found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
exit;
endif;

// --- Patient selected: show full medical history ---
$patient = $db->prepare("SELECT pp.*, c.name, c.phone, c.email, c.insurance_number, c.insurance_provider_id, ip.name as insurance_name
    FROM patient_profiles pp
    JOIN customers c ON pp.customer_id = c.id
    LEFT JOIN insurance_providers ip ON c.insurance_provider_id = ip.id
    WHERE pp.id = ?");
$patient->execute([$patientId]);
$patient = $patient->fetch();

if (!$patient) {
    flashMessage('Patient not found', 'error');
    header('Location: medical_history.php');
    exit;
}

$age = $patient['date_of_birth'] ? floor((time() - strtotime($patient['date_of_birth'])) / 31557600) : null;

// Active medications
$activeMeds = $db->prepare("SELECT pm.*, m.sell_price, m.quantity_in_stock, m.generic_name FROM patient_medications pm LEFT JOIN medicines m ON pm.medicine_id = m.id WHERE pm.patient_id = ? AND pm.is_active = 1 ORDER BY pm.start_date DESC");
$activeMeds->execute([$patientId]);
$activeMeds = $activeMeds->fetchAll();

// Past medications
$pastMeds = $db->prepare("SELECT pm.*, m.generic_name FROM patient_medications pm LEFT JOIN medicines m ON pm.medicine_id = m.id WHERE pm.patient_id = ? AND pm.is_active = 0 ORDER BY pm.end_date DESC");
$pastMeds->execute([$patientId]);
$pastMeds = $pastMeds->fetchAll();

// Prescriptions
$prescriptions = $db->prepare("SELECT p.*, GROUP_CONCAT(DISTINCT COALESCE(m.name, pi.medicine_name) SEPARATOR ', ') as medicines
    FROM prescriptions p
    LEFT JOIN prescription_items pi ON p.id = pi.prescription_id
    LEFT JOIN medicines m ON pi.medicine_id = m.id
    WHERE p.customer_id = ?
    GROUP BY p.id
    ORDER BY p.issue_date DESC, p.created_at DESC");
$prescriptions->execute([$patient['customer_id']]);
$prescriptions = $prescriptions->fetchAll();

// Purchase history
$purchases = $db->prepare("SELECT s.id, s.invoice_number, s.sale_date, s.total_amount, s.payment_method,
    GROUP_CONCAT(DISTINCT CONCAT(m.name, ' x', si.quantity) SEPARATOR ', ') as items
    FROM sales s
    JOIN sale_items si ON s.id = si.sale_id
    JOIN medicines m ON si.medicine_id = m.id
    WHERE s.customer_id = ?
    GROUP BY s.id
    ORDER BY s.sale_date DESC");
$purchases->execute([$patient['customer_id']]);
$purchases = $purchases->fetchAll();

// Drug interactions for active meds
$medNames = array_column($activeMeds, 'medicine_name');
$interactionWarnings = [];
if (count($medNames) >= 2) {
    $allInteractions = $db->query("SELECT * FROM drug_interactions ORDER BY FIELD(severity,'contraindicated','major','moderate','minor')")->fetchAll();
    foreach ($allInteractions as $inter) {
        $matchA = false; $matchB = false;
        foreach ($medNames as $n) {
            if (stripos($n, $inter['drug_a']) !== false || stripos($inter['drug_a'], $n) !== false) $matchA = true;
            if (stripos($n, $inter['drug_b']) !== false || stripos($inter['drug_b'], $n) !== false) $matchB = true;
        }
        if ($matchA && $matchB) $interactionWarnings[] = $inter;
    }
}

// Build unified timeline
$timeline = [];

foreach ($activeMeds as $med) {
    $timeline[] = [
        'date' => $med['start_date'] ?? $med['created_at'] ?? '',
        'type' => 'medication_start',
        'icon' => 'bi-capsule',
        'color' => 'success',
        'title' => 'Started: ' . ($med['medicine_name'] ?? 'Unknown'),
        'detail' => ($med['dosage'] ? $med['dosage'] . ' - ' : '') . ($med['frequency'] ?? '') . ($med['prescribing_doctor'] ? ' | Dr. ' . $med['prescribing_doctor'] : ''),
    ];
}

foreach ($pastMeds as $med) {
    if ($med['start_date']) {
        $timeline[] = [
            'date' => $med['start_date'],
            'type' => 'medication_start',
            'icon' => 'bi-capsule',
            'color' => 'secondary',
            'title' => 'Started: ' . ($med['medicine_name'] ?? 'Unknown'),
            'detail' => ($med['dosage'] ? $med['dosage'] . ' - ' : '') . ($med['frequency'] ?? ''),
        ];
    }
    if ($med['end_date']) {
        $timeline[] = [
            'date' => $med['end_date'],
            'type' => 'medication_stop',
            'icon' => 'bi-stop-circle',
            'color' => 'warning',
            'title' => 'Stopped: ' . ($med['medicine_name'] ?? 'Unknown'),
            'detail' => $med['prescribing_doctor'] ? 'Dr. ' . $med['prescribing_doctor'] : '',
        ];
    }
}

foreach ($prescriptions as $rx) {
    $timeline[] = [
        'date' => $rx['issue_date'] ?? $rx['created_at'] ?? '',
        'type' => 'prescription',
        'icon' => 'bi-file-medical',
        'color' => 'primary',
        'title' => 'Prescription: ' . ($rx['rx_number'] ?? ''),
        'detail' => 'Dr. ' . ($rx['doctor_name'] ?? 'Unknown') . ($rx['medicines'] ? ' | ' . $rx['medicines'] : ''),
        'link' => BASE_URL . '/modules/prescriptions/view.php?id=' . $rx['id'],
    ];
}

foreach ($purchases as $p) {
    $timeline[] = [
        'date' => $p['sale_date'] ?? '',
        'type' => 'purchase',
        'icon' => 'bi-bag-check',
        'color' => 'info',
        'title' => 'Purchase: ' . ($p['invoice_number'] ?? ''),
        'detail' => ($p['items'] ?? '') . ' | ' . formatCurrency($p['total_amount']),
        'link' => BASE_URL . '/modules/sales/view.php?id=' . $p['id'],
    ];
}

// Sort timeline by date descending
usort($timeline, function($a, $b) {
    return strcmp($b['date'], $a['date']);
});
?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <div>
        <a href="medical_history.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Search</a>
    </div>
    <div class="d-flex gap-2">
        <a href="profile.php?id=<?= $patientId ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit Profile</a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print Record</button>
    </div>
</div>

<!-- Warnings -->
<?php if (!empty($patient['allergies'])): ?>
<div class="alert alert-danger py-2">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>Allergies:</strong> <?= sanitize($patient['allergies']) ?>
    <span class="badge bg-danger ms-2">ALERT</span>
</div>
<?php endif; ?>

<?php if (!empty($interactionWarnings)): ?>
<div class="alert alert-warning py-2">
    <h6 class="mb-2"><i class="bi bi-exclamation-triangle-fill me-2"></i>Drug Interaction Warnings <span class="badge bg-warning text-dark"><?= count($interactionWarnings) ?></span></h6>
    <?php foreach ($interactionWarnings as $w): ?>
    <div class="mb-1 p-2 bg-white rounded border">
        <?php $colors = ['contraindicated'=>'danger','major'=>'danger','moderate'=>'warning','minor'=>'info']; ?>
        <span class="badge bg-<?= $colors[$w['severity']] ?? 'secondary' ?>"><?= strtoupper(sanitize($w['severity'])) ?></span>
        <strong><?= sanitize($w['drug_a']) ?></strong> + <strong><?= sanitize($w['drug_b']) ?></strong>
        <small class="d-block text-muted"><?= sanitize($w['description']) ?></small>
        <?php if (!empty($w['recommendation'])): ?>
        <small class="text-primary"><i class="bi bi-lightbulb me-1"></i><?= sanitize($w['recommendation']) ?></small>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3">
    <!-- Sidebar: Patient Summary -->
    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            <div class="text-center mb-3">
                <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width:64px;height:64px">
                    <i class="bi bi-person-fill text-primary fs-3"></i>
                </div>
                <h5 class="mt-2 mb-0"><?= sanitize($patient['name']) ?></h5>
                <?php if ($age !== null): ?>
                <small class="text-muted"><?= $age ?> years old</small>
                <?php endif; ?>
            </div>

            <table class="table table-sm mb-0">
                <tbody>
                    <tr><td class="text-muted">Gender</td><td class="text-end"><?= ucfirst(sanitize($patient['gender'] ?? '-')) ?></td></tr>
                    <tr><td class="text-muted">Blood Type</td><td class="text-end">
                        <?php if (!empty($patient['blood_type'])): ?>
                        <span class="badge bg-danger"><?= sanitize($patient['blood_type']) ?></span>
                        <?php else: ?>-<?php endif; ?>
                    </td></tr>
                    <tr><td class="text-muted">Date of Birth</td><td class="text-end"><?= $patient['date_of_birth'] ? formatDate($patient['date_of_birth'], 'M d, Y') : '-' ?></td></tr>
                    <tr><td class="text-muted">Phone</td><td class="text-end"><?= sanitize($patient['phone'] ?? '-') ?></td></tr>
                    <tr><td class="text-muted">Email</td><td class="text-end"><?= sanitize($patient['email'] ?? '-') ?></td></tr>
                    <?php if (!empty($patient['insurance_name'])): ?>
                    <tr><td class="text-muted">Insurance</td><td class="text-end"><?= sanitize($patient['insurance_name']) ?></td></tr>
                    <?php if (!empty($patient['insurance_number'])): ?>
                    <tr><td class="text-muted">Insurance #</td><td class="text-end"><?= sanitize($patient['insurance_number']) ?></td></tr>
                    <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Allergies -->
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-shield-exclamation me-2 text-danger"></i>Allergies</h6>
            <?php if (!empty($patient['allergies'])): ?>
                <?php foreach (explode(',', $patient['allergies']) as $allergy): ?>
                <span class="badge bg-danger bg-opacity-10 text-danger me-1 mb-1"><?= sanitize(trim($allergy)) ?></span>
                <?php endforeach; ?>
            <?php else: ?>
                <small class="text-muted">No known allergies</small>
            <?php endif; ?>
        </div>

        <!-- Chronic Conditions -->
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-heart-pulse me-2 text-warning"></i>Chronic Conditions</h6>
            <?php if (!empty($patient['chronic_conditions'])): ?>
                <?php foreach (explode(',', $patient['chronic_conditions']) as $cond): ?>
                <span class="badge bg-warning bg-opacity-10 text-warning me-1 mb-1"><?= sanitize(trim($cond)) ?></span>
                <?php endforeach; ?>
            <?php else: ?>
                <small class="text-muted">None recorded</small>
            <?php endif; ?>
        </div>

        <!-- Emergency Contact -->
        <?php if (!empty($patient['emergency_contact'])): ?>
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-telephone me-2 text-info"></i>Emergency Contact</h6>
            <p class="small mb-0">
                <strong><?= sanitize($patient['emergency_contact']) ?></strong><br>
                <?= sanitize($patient['emergency_phone'] ?? '-') ?>
            </p>
        </div>
        <?php endif; ?>

        <!-- Active Medications Summary -->
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-capsule me-2 text-success"></i>Current Medications <span class="badge bg-success"><?= count($activeMeds) ?></span></h6>
            <?php if (!empty($activeMeds)): ?>
                <?php foreach ($activeMeds as $med): ?>
                <div class="border-bottom py-1">
                    <strong class="small"><?= sanitize($med['medicine_name']) ?></strong>
                    <?php if (!empty($med['generic_name'])): ?>
                    <small class="text-muted d-block">(<?= sanitize($med['generic_name']) ?>)</small>
                    <?php endif; ?>
                    <small class="text-muted"><?= sanitize($med['dosage'] ?? '') ?> <?= sanitize($med['frequency'] ?? '') ?></small>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <small class="text-muted">No active medications</small>
            <?php endif; ?>
        </div>

        <!-- Stats -->
        <div class="card p-3">
            <h6><i class="bi bi-bar-chart me-2"></i>Summary</h6>
            <div class="row g-2 text-center">
                <div class="col-6">
                    <div class="bg-light rounded p-2">
                        <div class="fs-5 fw-bold text-primary"><?= count($prescriptions) ?></div>
                        <small class="text-muted">Prescriptions</small>
                    </div>
                </div>
                <div class="col-6">
                    <div class="bg-light rounded p-2">
                        <div class="fs-5 fw-bold text-info"><?= count($purchases) ?></div>
                        <small class="text-muted">Purchases</small>
                    </div>
                </div>
                <div class="col-6">
                    <div class="bg-light rounded p-2">
                        <div class="fs-5 fw-bold text-success"><?= count($activeMeds) ?></div>
                        <small class="text-muted">Active Meds</small>
                    </div>
                </div>
                <div class="col-6">
                    <div class="bg-light rounded p-2">
                        <div class="fs-5 fw-bold text-secondary"><?= count($pastMeds) ?></div>
                        <small class="text-muted">Past Meds</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content: Timeline -->
    <div class="col-lg-8">
        <div class="card p-4">
            <h6 class="mb-3"><i class="bi bi-clock-history me-2"></i>Medical Timeline</h6>

            <?php if (empty($timeline)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-journal-x fs-1 d-block mb-2"></i>
                No medical history recorded for this patient
            </div>
            <?php else: ?>
            <div class="position-relative" style="padding-left:30px">
                <!-- Vertical line -->
                <div style="position:absolute;left:14px;top:0;bottom:0;width:2px;background:#dee2e6"></div>

                <?php
                $currentMonth = '';
                foreach ($timeline as $event):
                    $eventMonth = !empty($event['date']) ? date('F Y', strtotime($event['date'])) : 'Unknown Date';
                    if ($eventMonth !== $currentMonth):
                        $currentMonth = $eventMonth;
                ?>
                <div class="mb-2 mt-3" style="margin-left:-30px">
                    <span class="badge bg-dark"><?= sanitize($currentMonth) ?></span>
                </div>
                <?php endif; ?>

                <div class="d-flex mb-3 position-relative">
                    <!-- Timeline dot -->
                    <div class="position-absolute" style="left:-24px;top:4px">
                        <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-<?= $event['color'] ?> bg-opacity-25" style="width:20px;height:20px">
                            <i class="bi <?= $event['icon'] ?> text-<?= $event['color'] ?>" style="font-size:10px"></i>
                        </span>
                    </div>

                    <div class="card flex-grow-1 p-2 ms-2">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong class="small"><?= sanitize($event['title']) ?></strong>
                                <?php if (!empty($event['detail'])): ?>
                                <small class="text-muted d-block"><?= sanitize($event['detail']) ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
                                <small class="text-muted"><?= !empty($event['date']) ? formatDate($event['date'], 'M d, Y') : '' ?></small>
                                <?php if (!empty($event['link'])): ?>
                                <br><a href="<?= $event['link'] ?>" class="small no-print">View</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Detailed Prescription History -->
        <?php if (!empty($prescriptions)): ?>
        <div class="card p-3 mt-3">
            <h6 class="mb-3"><i class="bi bi-file-medical me-2"></i>Prescription History</h6>
            <div class="table-responsive">
                <table class="table table-hover data-table mb-0">
                    <thead><tr><th>Rx #</th><th>Date</th><th>Doctor</th><th>Diagnosis</th><th>Status</th><th>Medicines</th><th class="no-print"></th></tr></thead>
                    <tbody>
                        <?php foreach ($prescriptions as $rx): ?>
                        <tr>
                            <td><strong class="small"><?= sanitize($rx['rx_number'] ?? '') ?></strong></td>
                            <td><small><?= formatDate($rx['issue_date'], 'M d, Y') ?></small></td>
                            <td><small><?= sanitize($rx['doctor_name'] ?? '-') ?></small></td>
                            <td><small><?= sanitize($rx['diagnosis'] ?? '-') ?></small></td>
                            <td>
                                <?php $sColors = ['active'=>'success','dispensed'=>'info','partial'=>'warning','expired'=>'danger','cancelled'=>'secondary']; ?>
                                <span class="badge bg-<?= $sColors[$rx['status']] ?? 'secondary' ?>"><?= sanitize(ucfirst($rx['status'])) ?></span>
                            </td>
                            <td><small class="text-muted"><?= sanitize(mb_strimwidth($rx['medicines'] ?? '', 0, 50, '...')) ?></small></td>
                            <td class="no-print">
                                <a href="<?= BASE_URL ?>/modules/prescriptions/view.php?id=<?= $rx['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Purchase History -->
        <?php if (!empty($purchases)): ?>
        <div class="card p-3 mt-3">
            <h6 class="mb-3"><i class="bi bi-bag-check me-2"></i>Purchase History</h6>
            <div class="table-responsive">
                <table class="table table-hover data-table mb-0">
                    <thead><tr><th>Invoice</th><th>Date</th><th>Items</th><th>Total</th><th>Payment</th><th class="no-print"></th></tr></thead>
                    <tbody>
                        <?php foreach ($purchases as $p): ?>
                        <tr>
                            <td><strong class="small"><?= sanitize($p['invoice_number']) ?></strong></td>
                            <td><small><?= formatDate($p['sale_date'], 'M d, Y') ?></small></td>
                            <td><small class="text-muted"><?= sanitize(mb_strimwidth($p['items'] ?? '', 0, 60, '...')) ?></small></td>
                            <td><?= formatCurrency($p['total_amount']) ?></td>
                            <td><small><?= ucfirst(sanitize($p['payment_method'] ?? '-')) ?></small></td>
                            <td class="no-print">
                                <a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Medication Details -->
        <?php if (!empty($pastMeds)): ?>
        <div class="card p-3 mt-3">
            <h6 class="mb-3"><i class="bi bi-clock-history me-2"></i>Past Medications</h6>
            <div class="table-responsive">
                <table class="table table-sm data-table mb-0">
                    <thead><tr><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Period</th><th>Doctor</th></tr></thead>
                    <tbody>
                        <?php foreach ($pastMeds as $pm): ?>
                        <tr class="text-muted">
                            <td>
                                <small><?= sanitize($pm['medicine_name']) ?></small>
                                <?php if (!empty($pm['generic_name'])): ?>
                                <br><small class="text-muted fst-italic">(<?= sanitize($pm['generic_name']) ?>)</small>
                                <?php endif; ?>
                            </td>
                            <td><small><?= sanitize($pm['dosage'] ?? '-') ?></small></td>
                            <td><small><?= sanitize($pm['frequency'] ?? '-') ?></small></td>
                            <td><small><?= $pm['start_date'] ? formatDate($pm['start_date'], 'M Y') : '' ?> - <?= $pm['end_date'] ? formatDate($pm['end_date'], 'M Y') : 'Ongoing' ?></small></td>
                            <td><small><?= sanitize($pm['prescribing_doctor'] ?? '-') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($patient['notes'])): ?>
        <div class="card p-3 mt-3">
            <h6><i class="bi bi-sticky me-2"></i>Patient Notes</h6>
            <p class="small mb-0"><?= nl2br(sanitize($patient['notes'])) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
