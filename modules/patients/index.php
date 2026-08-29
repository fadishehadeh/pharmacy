<?php
$pageTitle = 'Patient Profiles';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_patient'])) {
        $db->beginTransaction();
        $customerId = $_POST['customer_id'];
        if (empty($customerId)) {
            $db->prepare("INSERT INTO customers (name, phone, email) VALUES (?,?,?)")->execute([
                $_POST['patient_name'], $_POST['phone'] ?: null, $_POST['email'] ?: null
            ]);
            $customerId = $db->lastInsertId();
        }
        $db->prepare("INSERT INTO patient_profiles (customer_id, date_of_birth, gender, blood_type, allergies, chronic_conditions, emergency_contact, emergency_phone, notes) VALUES (?,?,?,?,?,?,?,?,?)")->execute([
            $customerId,
            $_POST['date_of_birth'] ?: null,
            $_POST['gender'] ?: null,
            $_POST['blood_type'] ?: null,
            $_POST['allergies'] ?: null,
            $_POST['chronic_conditions'] ?: null,
            $_POST['emergency_contact'] ?: null,
            $_POST['emergency_phone'] ?: null,
            $_POST['notes'] ?: null
        ]);
        $db->commit();
        flashMessage('Patient profile created');
    } elseif (isset($_POST['add_medication'])) {
        $db->prepare("INSERT INTO patient_medications (patient_id, medicine_id, medicine_name, dosage, frequency, start_date, end_date, prescribing_doctor, notes) VALUES (?,?,?,?,?,?,?,?,?)")->execute([
            $_POST['patient_id'],
            $_POST['medicine_id'] ?: null,
            $_POST['medicine_name'],
            $_POST['dosage'] ?: null,
            $_POST['frequency'] ?: null,
            $_POST['start_date'] ?: null,
            $_POST['end_date'] ?: null,
            $_POST['prescribing_doctor'] ?: null,
            $_POST['med_notes'] ?: null
        ]);
        flashMessage('Medication added');
        header('Location: profile.php?id=' . $_POST['patient_id']);
        exit;
    }
    header('Location: index.php');
    exit;
}

$search = $_GET['search'] ?? '';
$where = ['1=1'];
$params = [];
if ($search) {
    $where[] = '(c.name LIKE ? OR c.phone LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$patients = $db->prepare("SELECT pp.*, c.name, c.phone, c.email, c.insurance_number,
    (SELECT COUNT(*) FROM patient_medications pm WHERE pm.patient_id = pp.id AND pm.is_active = 1) as active_meds
    FROM patient_profiles pp
    JOIN customers c ON pp.customer_id = c.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY c.name");
$patients->execute($params);
$patients = $patients->fetchAll();

$customers = $db->query("SELECT c.* FROM customers c LEFT JOIN patient_profiles pp ON c.id = pp.customer_id WHERE pp.id IS NULL ORDER BY c.name")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <form method="GET" class="d-flex gap-2" style="max-width:400px">
        <input type="text" class="form-control" name="search" placeholder="Search patients..." value="<?= sanitize($search) ?>">
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
    </form>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPatient"><i class="bi bi-plus me-1"></i>New Patient</button>
</div>

<div class="row g-3">
    <?php foreach ($patients as $p): ?>
    <div class="col-md-6 col-xl-4">
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h6 class="mb-1"><i class="bi bi-person-circle me-1"></i><?= sanitize($p['name']) ?></h6>
                    <?php if ($p['phone']): ?><small class="text-muted"><i class="bi bi-telephone me-1"></i><?= sanitize($p['phone']) ?></small><br><?php endif; ?>
                    <?php if ($p['date_of_birth']): ?>
                    <small class="text-muted"><i class="bi bi-calendar me-1"></i><?= formatDate($p['date_of_birth'], 'M d, Y') ?>
                    (<?= floor((time() - strtotime($p['date_of_birth'])) / 31557600) ?> yrs)</small><br>
                    <?php endif; ?>
                </div>
                <a href="profile.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1"></i>View</a>
            </div>
            <hr class="my-2">
            <div class="row small">
                <div class="col-6">
                    <span class="text-muted">Gender:</span> <?= $p['gender'] ? ucfirst($p['gender']) : '-' ?>
                </div>
                <div class="col-6">
                    <span class="text-muted">Blood:</span> <?= $p['blood_type'] ?: '-' ?>
                </div>
                <div class="col-6">
                    <span class="text-muted">Active Meds:</span>
                    <span class="badge bg-primary"><?= $p['active_meds'] ?></span>
                </div>
            </div>
            <?php if ($p['allergies']): ?>
            <div class="mt-2"><small class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Allergies: <?= sanitize($p['allergies']) ?></small></div>
            <?php endif; ?>
            <?php if ($p['chronic_conditions']): ?>
            <div><small class="text-warning"><i class="bi bi-heart-pulse me-1"></i><?= sanitize($p['chronic_conditions']) ?></small></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($patients)): ?>
    <div class="col-12"><div class="card p-5 text-center text-muted">No patient profiles found</div></div>
    <?php endif; ?>
</div>

<div class="modal fade" id="addPatient"><div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title">New Patient Profile</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="row g-2">
            <div class="col-md-6">
                <label class="form-label">Link to Existing Customer</label>
                <select class="form-select" name="customer_id" id="patientCustomerSelect">
                    <option value="">-- New Customer --</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?> <?= $c['phone'] ? "({$c['phone']})" : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6" id="newPatientName">
                <label class="form-label">Patient Name</label>
                <input type="text" class="form-control" name="patient_name">
            </div>
            <div class="col-md-4"><label class="form-label">Phone</label><input type="tel" class="form-control" name="phone"></div>
            <div class="col-md-4"><label class="form-label">Email</label><input type="email" class="form-control" name="email"></div>
            <div class="col-md-4"><label class="form-label">Date of Birth</label><input type="date" class="form-control" name="date_of_birth"></div>
            <div class="col-md-4">
                <label class="form-label">Gender</label>
                <select class="form-select" name="gender"><option value="">--</option><option value="male">Male</option><option value="female">Female</option></select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Blood Type</label>
                <select class="form-select" name="blood_type"><option value="">--</option>
                    <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bt): ?><option value="<?= $bt ?>"><?= $bt ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-12"><label class="form-label text-danger">Allergies</label><textarea class="form-control" name="allergies" rows="2" placeholder="e.g. Penicillin, Sulfa drugs"></textarea></div>
            <div class="col-12"><label class="form-label">Chronic Conditions</label><textarea class="form-control" name="chronic_conditions" rows="2" placeholder="e.g. Diabetes Type 2, Hypertension"></textarea></div>
            <div class="col-md-6"><label class="form-label">Emergency Contact</label><input type="text" class="form-control" name="emergency_contact"></div>
            <div class="col-md-6"><label class="form-label">Emergency Phone</label><input type="tel" class="form-control" name="emergency_phone"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
        </div>
    </div>
    <div class="modal-footer"><button type="submit" name="add_patient" value="1" class="btn btn-primary">Create Profile</button></div>
    </form>
</div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
