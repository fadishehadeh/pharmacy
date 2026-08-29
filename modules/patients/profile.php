<?php
$pageTitle = 'Patient Profile';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$patientId = intval($_GET['id'] ?? 0);
if (!$patientId) { header('Location: index.php'); exit; }

$patient = $db->prepare("SELECT pp.*, c.name, c.phone, c.email, c.insurance_number, c.insurance_provider_id, ip.name as insurance_name
    FROM patient_profiles pp
    JOIN customers c ON pp.customer_id = c.id
    LEFT JOIN insurance_providers ip ON c.insurance_provider_id = ip.id
    WHERE pp.id = ?");
$patient->execute([$patientId]);
$patient = $patient->fetch();
if (!$patient) { header('Location: index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $db->prepare("UPDATE patient_profiles SET date_of_birth=?, gender=?, blood_type=?, allergies=?, chronic_conditions=?, emergency_contact=?, emergency_phone=?, notes=? WHERE id=?")->execute([
            $_POST['date_of_birth'] ?: null, $_POST['gender'] ?: null, $_POST['blood_type'] ?: null,
            $_POST['allergies'] ?: null, $_POST['chronic_conditions'] ?: null,
            $_POST['emergency_contact'] ?: null, $_POST['emergency_phone'] ?: null,
            $_POST['notes'] ?: null, $patientId
        ]);
        flashMessage('Profile updated');
    } elseif (isset($_POST['add_medication'])) {
        $db->prepare("INSERT INTO patient_medications (patient_id, medicine_id, medicine_name, dosage, frequency, start_date, end_date, prescribing_doctor, notes) VALUES (?,?,?,?,?,?,?,?,?)")->execute([
            $patientId, $_POST['medicine_id'] ?: null, $_POST['medicine_name'],
            $_POST['dosage'] ?: null, $_POST['frequency'] ?: null,
            $_POST['start_date'] ?: null, $_POST['end_date'] ?: null,
            $_POST['prescribing_doctor'] ?: null, $_POST['med_notes'] ?: null
        ]);
        flashMessage('Medication added');
    } elseif (isset($_POST['stop_medication'])) {
        $db->prepare("UPDATE patient_medications SET is_active = 0, end_date = CURDATE() WHERE id = ? AND patient_id = ?")->execute([$_POST['med_id'], $patientId]);
        flashMessage('Medication stopped');
    }
    header("Location: profile.php?id=$patientId");
    exit;
}

$activeMeds = $db->prepare("SELECT pm.*, m.sell_price, m.quantity_in_stock FROM patient_medications pm LEFT JOIN medicines m ON pm.medicine_id = m.id WHERE pm.patient_id = ? AND pm.is_active = 1 ORDER BY pm.start_date DESC");
$activeMeds->execute([$patientId]);
$activeMeds = $activeMeds->fetchAll();

$pastMeds = $db->prepare("SELECT * FROM patient_medications WHERE patient_id = ? AND is_active = 0 ORDER BY end_date DESC LIMIT 20");
$pastMeds->execute([$patientId]);
$pastMeds = $pastMeds->fetchAll();

$purchaseHistory = $db->prepare("SELECT s.invoice_number, s.sale_date, s.total_amount, si.quantity, m.name as medicine_name
    FROM sales s
    JOIN sale_items si ON s.id = si.sale_id
    JOIN medicines m ON si.medicine_id = m.id
    WHERE s.customer_id = ?
    ORDER BY s.sale_date DESC LIMIT 30");
$purchaseHistory->execute([$patient['customer_id']]);
$purchaseHistory = $purchaseHistory->fetchAll();

$medicines = $db->query("SELECT id, name, generic_name FROM medicines WHERE is_active = 1 ORDER BY name")->fetchAll();

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

$age = $patient['date_of_birth'] ? floor((time() - strtotime($patient['date_of_birth'])) / 31557600) : null;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-0"><i class="bi bi-person-circle me-2"></i><?= sanitize($patient['name']) ?></h5>
        <?php if ($age !== null): ?><small class="text-muted"><?= $age ?> years old | <?= ucfirst($patient['gender'] ?? '') ?> | Blood: <?= $patient['blood_type'] ?: 'N/A' ?></small><?php endif; ?>
    </div>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?php if (!empty($interactionWarnings)): ?>
<div class="alert alert-danger">
    <h6><i class="bi bi-exclamation-triangle-fill me-2"></i>Drug Interaction Warnings</h6>
    <?php foreach ($interactionWarnings as $w): ?>
    <div class="mb-2 p-2 bg-white rounded">
        <strong>
            <?php $colors = ['contraindicated'=>'danger','major'=>'danger','moderate'=>'warning','minor'=>'info']; ?>
            <span class="badge bg-<?= $colors[$w['severity']] ?? 'secondary' ?>"><?= strtoupper($w['severity']) ?></span>
            <?= sanitize($w['drug_a']) ?> + <?= sanitize($w['drug_b']) ?>
        </strong>
        <br><small><?= sanitize($w['description']) ?></small>
        <?php if ($w['recommendation']): ?><br><small class="text-primary"><i class="bi bi-lightbulb me-1"></i><?= sanitize($w['recommendation']) ?></small><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($patient['allergies']): ?>
<div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-2"></i><strong>Allergies:</strong> <?= sanitize($patient['allergies']) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            <h6>Patient Information</h6>
            <form method="POST">
                <div class="mb-2"><label class="form-label small">Date of Birth</label>
                    <input type="date" class="form-control form-control-sm" name="date_of_birth" value="<?= $patient['date_of_birth'] ?? '' ?>"></div>
                <div class="mb-2"><label class="form-label small">Gender</label>
                    <select class="form-select form-select-sm" name="gender">
                        <option value="">--</option>
                        <option value="male" <?= ($patient['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= ($patient['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                    </select></div>
                <div class="mb-2"><label class="form-label small">Blood Type</label>
                    <select class="form-select form-select-sm" name="blood_type">
                        <option value="">--</option>
                        <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bt): ?>
                        <option value="<?= $bt ?>" <?= ($patient['blood_type'] ?? '') === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="mb-2"><label class="form-label small text-danger">Allergies</label>
                    <textarea class="form-control form-control-sm" name="allergies" rows="2"><?= sanitize($patient['allergies'] ?? '') ?></textarea></div>
                <div class="mb-2"><label class="form-label small">Chronic Conditions</label>
                    <textarea class="form-control form-control-sm" name="chronic_conditions" rows="2"><?= sanitize($patient['chronic_conditions'] ?? '') ?></textarea></div>
                <div class="mb-2"><label class="form-label small">Emergency Contact</label>
                    <input type="text" class="form-control form-control-sm" name="emergency_contact" value="<?= sanitize($patient['emergency_contact'] ?? '') ?>"></div>
                <div class="mb-2"><label class="form-label small">Emergency Phone</label>
                    <input type="tel" class="form-control form-control-sm" name="emergency_phone" value="<?= sanitize($patient['emergency_phone'] ?? '') ?>"></div>
                <div class="mb-2"><label class="form-label small">Notes</label>
                    <textarea class="form-control form-control-sm" name="notes" rows="2"><?= sanitize($patient['notes'] ?? '') ?></textarea></div>
                <button type="submit" name="update_profile" value="1" class="btn btn-primary btn-sm w-100">Update Profile</button>
            </form>
        </div>

        <?php if ($patient['insurance_name']): ?>
        <div class="card p-3">
            <h6>Insurance</h6>
            <p class="small mb-1"><strong><?= sanitize($patient['insurance_name']) ?></strong></p>
            <?php if ($patient['insurance_number']): ?><p class="small text-muted mb-0">ID: <?= sanitize($patient['insurance_number']) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-capsule me-2"></i>Active Medications</h6>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addMedication"><i class="bi bi-plus me-1"></i>Add</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Since</th><th>Doctor</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($activeMeds as $med): ?>
                        <tr>
                            <td><strong class="small"><?= sanitize($med['medicine_name']) ?></strong></td>
                            <td><small><?= sanitize($med['dosage'] ?? '-') ?></small></td>
                            <td><small><?= sanitize($med['frequency'] ?? '-') ?></small></td>
                            <td><small><?= $med['start_date'] ? formatDate($med['start_date'], 'M d, Y') : '-' ?></small></td>
                            <td><small><?= sanitize($med['prescribing_doctor'] ?? '-') ?></small></td>
                            <td>
                                <form method="POST" class="d-inline"><input type="hidden" name="med_id" value="<?= $med['id'] ?>">
                                    <button type="submit" name="stop_medication" value="1" class="btn btn-sm btn-outline-danger" data-confirm="Stop this medication?"><i class="bi bi-stop-circle"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($activeMeds)): ?><tr><td colspan="6" class="text-center text-muted py-3">No active medications</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="p-3 border-bottom"><h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Purchase History</h6></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Date</th><th>Invoice</th><th>Medicine</th><th>Qty</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($purchaseHistory as $ph): ?>
                        <tr>
                            <td><small><?= formatDate($ph['sale_date'], 'M d, Y') ?></small></td>
                            <td><a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $ph['invoice_number'] ?>"><?= sanitize($ph['invoice_number']) ?></a></td>
                            <td><?= sanitize($ph['medicine_name']) ?></td>
                            <td><?= $ph['quantity'] ?></td>
                            <td><?= formatCurrency($ph['total_amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($purchaseHistory)): ?><tr><td colspan="5" class="text-center text-muted py-3">No purchase history</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($pastMeds)): ?>
        <div class="card">
            <div class="p-3 border-bottom"><h6 class="mb-0">Past Medications</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Medicine</th><th>Dosage</th><th>Period</th><th>Doctor</th></tr></thead>
                    <tbody>
                        <?php foreach ($pastMeds as $pm): ?>
                        <tr class="text-muted">
                            <td><small><?= sanitize($pm['medicine_name']) ?></small></td>
                            <td><small><?= sanitize($pm['dosage'] ?? '-') ?></small></td>
                            <td><small><?= $pm['start_date'] ? formatDate($pm['start_date'], 'M Y') : '' ?> - <?= $pm['end_date'] ? formatDate($pm['end_date'], 'M Y') : '' ?></small></td>
                            <td><small><?= sanitize($pm['prescribing_doctor'] ?? '-') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="addMedication"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title">Add Medication</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-2">
            <label class="form-label">Medicine</label>
            <select class="form-select" name="medicine_id" id="medSelect">
                <option value="">-- Select or type below --</option>
                <?php foreach ($medicines as $m): ?>
                <option value="<?= $m['id'] ?>" data-name="<?= sanitize($m['name']) ?>"><?= sanitize($m['name']) ?> <?= $m['generic_name'] ? '(' . sanitize($m['generic_name']) . ')' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-2"><label class="form-label">Medicine Name</label><input type="text" class="form-control" name="medicine_name" id="medNameInput" required></div>
        <div class="row mb-2">
            <div class="col"><label class="form-label">Dosage</label><input type="text" class="form-control" name="dosage" placeholder="e.g. 500mg"></div>
            <div class="col"><label class="form-label">Frequency</label><input type="text" class="form-control" name="frequency" placeholder="e.g. Twice daily"></div>
        </div>
        <div class="row mb-2">
            <div class="col"><label class="form-label">Start Date</label><input type="date" class="form-control" name="start_date" value="<?= date('Y-m-d') ?>"></div>
            <div class="col"><label class="form-label">End Date</label><input type="date" class="form-control" name="end_date"></div>
        </div>
        <div class="mb-2"><label class="form-label">Prescribing Doctor</label><input type="text" class="form-control" name="prescribing_doctor"></div>
        <div><label class="form-label">Notes</label><textarea class="form-control" name="med_notes" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="submit" name="add_medication" value="1" class="btn btn-primary">Add Medication</button></div>
    </form>
</div></div></div>

<?php
$extraScripts = <<<'SCRIPT'
<script>
document.getElementById('medSelect').addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    if (opt.dataset.name) {
        document.getElementById('medNameInput').value = opt.dataset.name;
    }
});
</script>
SCRIPT;
require_once __DIR__ . '/../../includes/footer.php';
?>
