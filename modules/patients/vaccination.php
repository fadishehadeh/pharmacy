<?php
$pageTitle = 'Vaccination Records';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) {
    flashMessage('Access denied', 'error');
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$db = getDB();

$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');

// Auto-create vaccination_records table if not exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS vaccination_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NULL,
        customer_id INT NULL,
        patient_name VARCHAR(255) NOT NULL,
        vaccine_name VARCHAR(255) NOT NULL,
        medicine_id INT NULL,
        batch_number VARCHAR(100) NULL,
        dose_number INT DEFAULT 1,
        total_doses INT DEFAULT 1,
        vaccination_date DATE NOT NULL,
        administration_site VARCHAR(100) NULL,
        administering_pharmacist VARCHAR(255) NULL,
        next_dose_date DATE NULL,
        adverse_reactions TEXT NULL,
        consent_given TINYINT(1) DEFAULT 1,
        consent_notes TEXT NULL,
        notes TEXT NULL,
        status VARCHAR(50) DEFAULT 'completed',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_patient (customer_id),
        INDEX idx_vaccine (vaccine_name),
        INDEX idx_date (vaccination_date),
        INDEX idx_next_dose (next_dose_date),
        INDEX idx_batch (batch_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // Table likely exists
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_vaccination'])) {
        $customerId = intval($_POST['customer_id']) ?: null;
        $patientId = null;

        // Look up patient profile if customer linked
        if ($customerId) {
            $ppStmt = $db->prepare("SELECT id FROM patient_profiles WHERE customer_id = ?");
            $ppStmt->execute([$customerId]);
            $patientId = $ppStmt->fetchColumn() ?: null;
        }

        $medicineId = intval($_POST['medicine_id']) ?: null;

        $db->prepare("INSERT INTO vaccination_records
            (patient_id, customer_id, patient_name, vaccine_name, medicine_id, batch_number,
             dose_number, total_doses, vaccination_date, administration_site,
             administering_pharmacist, next_dose_date, adverse_reactions,
             consent_given, consent_notes, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
            $patientId,
            $customerId,
            $_POST['patient_name'],
            $_POST['vaccine_name'],
            $medicineId,
            $_POST['batch_number'] ?: null,
            intval($_POST['dose_number']) ?: 1,
            intval($_POST['total_doses']) ?: 1,
            $_POST['vaccination_date'],
            $_POST['administration_site'] ?: null,
            $_POST['administering_pharmacist'] ?: null,
            $_POST['next_dose_date'] ?: null,
            $_POST['adverse_reactions'] ?: null,
            isset($_POST['consent_given']) ? 1 : 0,
            $_POST['consent_notes'] ?: null,
            $_POST['notes'] ?: null,
            $_SESSION['user_id']
        ]);

        // Deduct vaccine from inventory if medicine linked
        if ($medicineId) {
            try {
                updateStock($medicineId, -1);
                addStockMovement($medicineId, 'out', 1, 'Vaccination administered: ' . $_POST['vaccine_name'], 'vaccination', $db->lastInsertId());
            } catch (Exception $e) {
                // Stock update failed silently
            }
        }

        addAuditLog('create', 'vaccination_records', $db->lastInsertId(), null, ['vaccine' => $_POST['vaccine_name'], 'patient' => $_POST['patient_name']]);
        flashMessage('Vaccination record created');
        header('Location: vaccination.php');
        exit;
    }

    if (isset($_POST['report_reaction'])) {
        $recId = intval($_POST['record_id']);
        $db->prepare("UPDATE vaccination_records SET adverse_reactions = ?, status = 'reaction_reported', updated_at = NOW() WHERE id = ?")->execute([
            $_POST['reaction_details'],
            $recId
        ]);
        addAuditLog('update', 'vaccination_records', $recId, null, ['action' => 'reaction_reported']);
        flashMessage('Adverse reaction reported', 'warning');
        header('Location: vaccination.php');
        exit;
    }
}

// Filters
$filterVaccine = $_GET['vaccine'] ?? '';
$filterPatient = intval($_GET['patient'] ?? 0);
$filterDate = $_GET['date'] ?? '';
$view = $_GET['view'] ?? 'records';

// Stats
$totalVaccinations = 0;
$thisMonth = 0;
$upcomingCount = 0;
$reactionCount = 0;

try {
    $totalVaccinations = $db->query("SELECT COUNT(*) FROM vaccination_records")->fetchColumn();
    $thisMonth = $db->query("SELECT COUNT(*) FROM vaccination_records WHERE MONTH(vaccination_date) = MONTH(CURDATE()) AND YEAR(vaccination_date) = YEAR(CURDATE())")->fetchColumn();
    $upcomingCount = $db->query("SELECT COUNT(*) FROM vaccination_records WHERE next_dose_date IS NOT NULL AND next_dose_date >= CURDATE() AND next_dose_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    $reactionCount = $db->query("SELECT COUNT(*) FROM vaccination_records WHERE adverse_reactions IS NOT NULL AND adverse_reactions != ''")->fetchColumn();
} catch (Exception $e) {}

// Vaccination records
$where = ['1=1'];
$params = [];

if ($filterVaccine) {
    $where[] = 'vr.vaccine_name LIKE ?';
    $params[] = "%{$filterVaccine}%";
}
if ($filterPatient) {
    $where[] = 'vr.customer_id = ?';
    $params[] = $filterPatient;
}
if ($filterDate) {
    $where[] = 'DATE(vr.vaccination_date) = ?';
    $params[] = $filterDate;
}

$whereClause = implode(' AND ', $where);

$records = $db->prepare("SELECT vr.*, u.full_name as created_by_name
    FROM vaccination_records vr
    LEFT JOIN users u ON vr.created_by = u.id
    WHERE {$whereClause}
    ORDER BY vr.vaccination_date DESC, vr.created_at DESC
    LIMIT 200");
$records->execute($params);
$records = $records->fetchAll();

// Upcoming vaccinations (next 30 days)
$upcoming = [];
try {
    $upcoming = $db->query("SELECT vr.*, c.phone as customer_phone
        FROM vaccination_records vr
        LEFT JOIN customers c ON vr.customer_id = c.id
        WHERE vr.next_dose_date IS NOT NULL
        AND vr.next_dose_date >= CURDATE()
        AND vr.next_dose_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY vr.next_dose_date ASC")->fetchAll();
} catch (Exception $e) {}

// Vaccinations by type
$byType = [];
try {
    $byType = $db->query("SELECT vaccine_name, COUNT(*) as cnt,
        SUM(CASE WHEN MONTH(vaccination_date) = MONTH(CURDATE()) AND YEAR(vaccination_date) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as this_month
        FROM vaccination_records
        GROUP BY vaccine_name ORDER BY cnt DESC")->fetchAll();
} catch (Exception $e) {}

// Vaccine medicines in inventory
$vaccineProducts = [];
try {
    $vaccineProducts = $db->query("SELECT m.id, m.name, m.quantity_in_stock, m.expiry_date, m.batch_number
        FROM medicines m
        WHERE m.is_active = 1 AND (
            m.name LIKE '%vaccine%' OR m.name LIKE '%vacc%'
            OR m.name LIKE '%flu shot%' OR m.name LIKE '%covid%'
            OR m.name LIKE '%hepatitis%' OR m.name LIKE '%tetanus%'
            OR m.name LIKE '%pneumo%' OR m.name LIKE '%mmr%'
            OR m.category_id IN (SELECT id FROM categories WHERE name LIKE '%vaccine%')
        )
        ORDER BY m.name")->fetchAll();
} catch (Exception $e) {}

// Load customers/patients for form
$customersList = $db->query("SELECT c.id, c.name, c.phone FROM customers c ORDER BY c.name")->fetchAll();

// Load pharmacists for form
$pharmacists = $db->query("SELECT full_name FROM users WHERE role IN ('pharmacist','admin') AND is_active = 1 ORDER BY full_name")->fetchAll();

// Common vaccine names
$commonVaccines = [
    'Influenza (Flu) Vaccine',
    'COVID-19 Vaccine (Pfizer)',
    'COVID-19 Vaccine (Moderna)',
    'COVID-19 Vaccine (AstraZeneca)',
    'Hepatitis A Vaccine',
    'Hepatitis B Vaccine',
    'Tetanus/Diphtheria (Td)',
    'Tetanus/Diphtheria/Pertussis (Tdap)',
    'Pneumococcal Vaccine (PCV13)',
    'Pneumococcal Vaccine (PPSV23)',
    'MMR (Measles, Mumps, Rubella)',
    'Varicella (Chickenpox)',
    'HPV Vaccine',
    'Meningococcal Vaccine',
    'Shingles (Zoster) Vaccine',
    'Typhoid Vaccine',
    'Rabies Vaccine',
];
?>

<!-- Stats -->
<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card info"><div class="stat-label">Total Vaccinations</div><div class="stat-value"><?= number_format($totalVaccinations) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card success"><div class="stat-label">This Month</div><div class="stat-value"><?= number_format($thisMonth) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card warning"><div class="stat-label">Upcoming (30d)</div><div class="stat-value"><?= number_format($upcomingCount) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card <?= $reactionCount > 0 ? 'danger' : '' ?>"><div class="stat-label">Reactions Reported</div><div class="stat-value"><?= number_format($reactionCount) ?></div></div></div>
</div>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-3 no-print">
    <li class="nav-item"><a class="nav-link <?= $view === 'records' ? 'active' : '' ?>" href="?view=records"><i class="bi bi-list-check me-1"></i>Records</a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'upcoming' ? 'active' : '' ?>" href="?view=upcoming"><i class="bi bi-calendar-event me-1"></i>Upcoming (<?= $upcomingCount ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'add' ? 'active' : '' ?>" href="?view=add"><i class="bi bi-plus-circle me-1"></i>New Vaccination</a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'inventory' ? 'active' : '' ?>" href="?view=inventory"><i class="bi bi-box-seam me-1"></i>Vaccine Inventory</a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'stats' ? 'active' : '' ?>" href="?view=stats"><i class="bi bi-bar-chart me-1"></i>Statistics</a></li>
</ul>

<?php if ($view === 'add'): ?>
<!-- Add Vaccination Form -->
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-4">
            <h6 class="mb-3"><i class="bi bi-plus-circle me-2"></i>New Vaccination Record</h6>
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Patient (Customer) <span class="text-danger">*</span></label>
                        <select class="form-select" name="customer_id" id="vaccinationCustomer" onchange="fillPatientName(this)">
                            <option value="">-- Select or enter manually --</option>
                            <?php foreach ($customersList as $cl): ?>
                            <option value="<?= $cl['id'] ?>" data-name="<?= sanitize($cl['name']) ?>">
                                <?= sanitize($cl['name']) ?><?= $cl['phone'] ? ' (' . sanitize($cl['phone']) . ')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Patient Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="patient_name" id="patientNameInput" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Vaccine <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="vaccine_name" list="vaccineList" required placeholder="Type or select...">
                        <datalist id="vaccineList">
                            <?php foreach ($commonVaccines as $cv): ?>
                            <option value="<?= $cv ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Linked Medicine (Inventory)</label>
                        <select class="form-select" name="medicine_id">
                            <option value="">-- None --</option>
                            <?php foreach ($vaccineProducts as $vp): ?>
                            <option value="<?= $vp['id'] ?>">
                                <?= sanitize($vp['name']) ?> (Stock: <?= $vp['quantity_in_stock'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Links to inventory; stock deducted automatically</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Batch Number</label>
                        <input type="text" class="form-control" name="batch_number" placeholder="Lot/batch #">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Dose Number</label>
                        <input type="number" class="form-control" name="dose_number" value="1" min="1" max="10">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Total Doses in Series</label>
                        <input type="number" class="form-control" name="total_doses" value="1" min="1" max="10">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="vaccination_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Administration Site</label>
                        <select class="form-select" name="administration_site">
                            <option value="">-- Select --</option>
                            <option value="Left Deltoid (Upper Arm)">Left Deltoid (Upper Arm)</option>
                            <option value="Right Deltoid (Upper Arm)">Right Deltoid (Upper Arm)</option>
                            <option value="Left Thigh">Left Thigh</option>
                            <option value="Right Thigh">Right Thigh</option>
                            <option value="Subcutaneous (Abdomen)">Subcutaneous (Abdomen)</option>
                            <option value="Intranasal">Intranasal</option>
                            <option value="Oral">Oral</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Administering Pharmacist</label>
                        <select class="form-select" name="administering_pharmacist">
                            <option value="">-- Select --</option>
                            <?php foreach ($pharmacists as $ph): ?>
                            <option value="<?= sanitize($ph['full_name']) ?>"><?= sanitize($ph['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Next Dose Date</label>
                        <input type="date" class="form-control" name="next_dose_date">
                        <small class="text-muted">Leave blank if single-dose</small>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check mt-4">
                            <input type="checkbox" class="form-check-input" name="consent_given" checked>
                            <label class="form-check-label">Patient consent obtained</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Consent Notes</label>
                        <input type="text" class="form-control" name="consent_notes" placeholder="Verbal/written consent">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Adverse Reactions (if any at time of administration)</label>
                        <textarea class="form-control" name="adverse_reactions" rows="2" placeholder="None observed, or describe..."></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Additional observations..."></textarea>
                    </div>
                </div>
                <hr>
                <button type="submit" name="add_vaccination" value="1" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Save Vaccination Record</button>
            </form>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-info-circle me-2"></i>Guidelines</h6>
            <ul class="small text-muted">
                <li>Verify patient identity before administration</li>
                <li>Check for contraindications and allergies</li>
                <li>Obtain informed consent (verbal or written)</li>
                <li>Record the batch/lot number from the vial</li>
                <li>Monitor patient for 15-30 minutes post-vaccination</li>
                <li>Advise patient on possible side effects</li>
                <li>Schedule next dose if multi-dose series</li>
                <li>Report any adverse reactions immediately</li>
            </ul>
            <hr>
            <h6 class="small"><i class="bi bi-box-seam me-2"></i>Vaccine Stock</h6>
            <?php if (!empty($vaccineProducts)): ?>
            <table class="table table-sm small mb-0">
                <thead><tr><th>Vaccine</th><th class="text-end">Stock</th><th>Expiry</th></tr></thead>
                <tbody>
                    <?php foreach ($vaccineProducts as $vp): ?>
                    <tr class="<?= $vp['quantity_in_stock'] <= 5 ? 'table-warning' : '' ?>">
                        <td><?= sanitize($vp['name']) ?></td>
                        <td class="text-end"><?= $vp['quantity_in_stock'] ?></td>
                        <td><small><?= $vp['expiry_date'] ? formatDate($vp['expiry_date'], 'M Y') : '-' ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="small text-muted">No vaccine products found in inventory. Add medicines with "vaccine" in the name or category.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php elseif ($view === 'upcoming'): ?>
<!-- Upcoming Vaccinations -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-calendar-event me-2"></i>Upcoming Vaccinations (Next 30 Days)</h6>
        <button onclick="window.print()" class="btn btn-sm btn-outline-dark no-print"><i class="bi bi-printer me-1"></i>Print</button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead>
                <tr>
                    <th>Next Dose Date</th>
                    <th>Patient</th>
                    <th>Vaccine</th>
                    <th>Dose</th>
                    <th>Phone</th>
                    <th>Days Until</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($upcoming as $u): ?>
                <?php $daysUntil = max(0, (strtotime($u['next_dose_date']) - time()) / 86400); ?>
                <tr class="<?= $daysUntil <= 3 ? 'table-danger' : ($daysUntil <= 7 ? 'table-warning' : '') ?>">
                    <td><strong><?= formatDate($u['next_dose_date'], 'M d, Y') ?></strong></td>
                    <td><?= sanitize($u['patient_name']) ?></td>
                    <td><?= sanitize($u['vaccine_name']) ?></td>
                    <td>Dose <?= $u['dose_number'] + 1 ?> of <?= $u['total_doses'] ?></td>
                    <td><small><?= sanitize($u['customer_phone'] ?? '-') ?></small></td>
                    <td>
                        <?php if ($daysUntil <= 0): ?>
                        <span class="badge bg-danger">Today</span>
                        <?php elseif ($daysUntil <= 3): ?>
                        <span class="badge bg-warning text-dark"><?= ceil($daysUntil) ?> days</span>
                        <?php else: ?>
                        <span class="badge bg-info"><?= ceil($daysUntil) ?> days</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?view=add" class="btn btn-sm btn-outline-success no-print"><i class="bi bi-plus me-1"></i>Record</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($upcoming)): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">No upcoming vaccinations in the next 30 days</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($view === 'inventory'): ?>
<!-- Vaccine Inventory -->
<div class="card">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-box-seam me-2"></i>Vaccine Products in Inventory</h6></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead>
                <tr>
                    <th>Vaccine Name</th>
                    <th class="text-end">Stock</th>
                    <th>Batch #</th>
                    <th>Expiry</th>
                    <th>Status</th>
                    <th class="no-print">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vaccineProducts as $vp): ?>
                <?php
                    $expired = $vp['expiry_date'] && strtotime($vp['expiry_date']) < time();
                    $expiringSoon = $vp['expiry_date'] && !$expired && strtotime($vp['expiry_date']) < strtotime('+90 days');
                ?>
                <tr class="<?= $expired ? 'table-danger' : ($vp['quantity_in_stock'] <= 5 ? 'table-warning' : '') ?>">
                    <td><strong><?= sanitize($vp['name']) ?></strong></td>
                    <td class="text-end fw-bold <?= $vp['quantity_in_stock'] <= 5 ? 'text-danger' : 'text-success' ?>">
                        <?= $vp['quantity_in_stock'] ?>
                    </td>
                    <td><small><?= sanitize($vp['batch_number'] ?? '-') ?></small></td>
                    <td>
                        <?php if ($expired): ?>
                        <span class="badge bg-danger">EXPIRED <?= formatDate($vp['expiry_date'], 'M d, Y') ?></span>
                        <?php elseif ($expiringSoon): ?>
                        <span class="badge bg-warning text-dark"><?= formatDate($vp['expiry_date'], 'M d, Y') ?></span>
                        <?php else: ?>
                        <small><?= $vp['expiry_date'] ? formatDate($vp['expiry_date'], 'M d, Y') : '-' ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($expired): ?>
                        <span class="badge bg-danger">Expired</span>
                        <?php elseif ($vp['quantity_in_stock'] == 0): ?>
                        <span class="badge bg-dark">Out of Stock</span>
                        <?php elseif ($vp['quantity_in_stock'] <= 5): ?>
                        <span class="badge bg-warning text-dark">Low Stock</span>
                        <?php else: ?>
                        <span class="badge bg-success">Available</span>
                        <?php endif; ?>
                    </td>
                    <td class="no-print">
                        <a href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $vp['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($vaccineProducts)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No vaccine products found in inventory</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($view === 'stats'): ?>
<!-- Statistics -->
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-bar-chart me-2"></i>Vaccinations by Type</h6>
            <canvas id="typeChart" height="200"></canvas>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-list me-2"></i>Vaccination Summary</h6></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Vaccine</th><th class="text-end">Total</th><th class="text-end">This Month</th></tr></thead>
                    <tbody>
                        <?php foreach ($byType as $bt): ?>
                        <tr>
                            <td><?= sanitize($bt['vaccine_name']) ?></td>
                            <td class="text-end fw-bold"><?= number_format($bt['cnt']) ?></td>
                            <td class="text-end"><span class="badge bg-info"><?= $bt['this_month'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($byType)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">No vaccination data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- Records View (default) -->
<div class="card mb-3 p-3 no-print">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="view" value="records">
        <div class="col-md-3">
            <label class="form-label small mb-1">Vaccine Name</label>
            <input type="text" class="form-control form-control-sm" name="vaccine" value="<?= sanitize($filterVaccine) ?>" placeholder="Search vaccine...">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Patient</label>
            <select class="form-select form-select-sm" name="patient">
                <option value="0">All Patients</option>
                <?php foreach ($customersList as $cl): ?>
                <option value="<?= $cl['id'] ?>" <?= $filterPatient === intval($cl['id']) ? 'selected' : '' ?>><?= sanitize($cl['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Date</label>
            <input type="date" class="form-control form-control-sm" name="date" value="<?= sanitize($filterDate) ?>">
        </div>
        <div class="col-md-2">
            <button class="btn btn-sm btn-primary w-100"><i class="bi bi-search me-1"></i>Filter</button>
        </div>
        <div class="col-md-2">
            <a href="?view=records" class="btn btn-sm btn-outline-secondary w-100">Clear</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Vaccination Records (<?= count($records) ?>)</h6>
        <div class="no-print">
            <a href="?view=add" class="btn btn-sm btn-primary me-1"><i class="bi bi-plus me-1"></i>New</a>
            <button onclick="window.print()" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer me-1"></i>Print</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Patient</th>
                    <th>Vaccine</th>
                    <th>Batch #</th>
                    <th>Dose</th>
                    <th>Site</th>
                    <th>Pharmacist</th>
                    <th>Next Dose</th>
                    <th>Status</th>
                    <th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $r): ?>
                <tr class="<?= $r['adverse_reactions'] ? 'table-warning' : '' ?>">
                    <td><small><?= formatDate($r['vaccination_date'], 'M d, Y') ?></small></td>
                    <td><strong><?= sanitize($r['patient_name']) ?></strong></td>
                    <td><?= sanitize($r['vaccine_name']) ?></td>
                    <td><small><?= sanitize($r['batch_number'] ?? '-') ?></small></td>
                    <td><?= $r['dose_number'] ?>/<?= $r['total_doses'] ?></td>
                    <td><small><?= sanitize($r['administration_site'] ?? '-') ?></small></td>
                    <td><small><?= sanitize($r['administering_pharmacist'] ?? '-') ?></small></td>
                    <td>
                        <?php if ($r['next_dose_date']): ?>
                        <small class="<?= strtotime($r['next_dose_date']) < time() ? 'text-danger fw-bold' : '' ?>">
                            <?= formatDate($r['next_dose_date'], 'M d, Y') ?>
                        </small>
                        <?php else: ?>
                        <small class="text-muted">-</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['status'] === 'reaction_reported'): ?>
                        <span class="badge bg-danger">Reaction</span>
                        <?php elseif (!$r['consent_given']): ?>
                        <span class="badge bg-warning text-dark">No Consent</span>
                        <?php else: ?>
                        <span class="badge bg-success">Completed</span>
                        <?php endif; ?>
                    </td>
                    <td class="no-print">
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewRecord<?= $r['id'] ?>" title="View details"><i class="bi bi-eye"></i></button>
                            <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#reportReaction<?= $r['id'] ?>" title="Report reaction"><i class="bi bi-exclamation-triangle"></i></button>
                            <button class="btn btn-outline-dark" onclick="printCertificate(<?= $r['id'] ?>)" title="Print certificate"><i class="bi bi-file-earmark-text"></i></button>
                        </div>
                    </td>
                </tr>

                <!-- View Detail Modal -->
                <div class="modal fade" id="viewRecord<?= $r['id'] ?>"><div class="modal-dialog"><div class="modal-content">
                    <div class="modal-header"><h6 class="modal-title">Vaccination Details</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <table class="table table-sm">
                            <tr><td class="text-muted">Patient</td><td><strong><?= sanitize($r['patient_name']) ?></strong></td></tr>
                            <tr><td class="text-muted">Vaccine</td><td><?= sanitize($r['vaccine_name']) ?></td></tr>
                            <tr><td class="text-muted">Batch Number</td><td><?= sanitize($r['batch_number'] ?? 'N/A') ?></td></tr>
                            <tr><td class="text-muted">Dose</td><td><?= $r['dose_number'] ?> of <?= $r['total_doses'] ?></td></tr>
                            <tr><td class="text-muted">Date</td><td><?= formatDate($r['vaccination_date'], 'F d, Y') ?></td></tr>
                            <tr><td class="text-muted">Site</td><td><?= sanitize($r['administration_site'] ?? 'N/A') ?></td></tr>
                            <tr><td class="text-muted">Pharmacist</td><td><?= sanitize($r['administering_pharmacist'] ?? 'N/A') ?></td></tr>
                            <tr><td class="text-muted">Next Dose</td><td><?= $r['next_dose_date'] ? formatDate($r['next_dose_date'], 'F d, Y') : 'N/A' ?></td></tr>
                            <tr><td class="text-muted">Consent</td><td><?= $r['consent_given'] ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>' ?> <?= sanitize($r['consent_notes'] ?? '') ?></td></tr>
                            <?php if ($r['adverse_reactions']): ?>
                            <tr><td class="text-muted">Adverse Reactions</td><td class="text-danger"><?= sanitize($r['adverse_reactions']) ?></td></tr>
                            <?php endif; ?>
                            <?php if ($r['notes']): ?>
                            <tr><td class="text-muted">Notes</td><td><?= sanitize($r['notes']) ?></td></tr>
                            <?php endif; ?>
                            <tr><td class="text-muted">Recorded By</td><td><?= sanitize($r['created_by_name'] ?? 'Unknown') ?></td></tr>
                            <tr><td class="text-muted">Record Date</td><td><?= formatDate($r['created_at'], 'M d, Y H:i') ?></td></tr>
                        </table>
                    </div>
                </div></div></div>

                <!-- Report Reaction Modal -->
                <div class="modal fade" id="reportReaction<?= $r['id'] ?>"><div class="modal-dialog"><div class="modal-content">
                    <form method="POST">
                    <input type="hidden" name="record_id" value="<?= $r['id'] ?>">
                    <div class="modal-header"><h6 class="modal-title text-danger">Report Adverse Reaction</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <p><strong>Patient:</strong> <?= sanitize($r['patient_name']) ?></p>
                        <p><strong>Vaccine:</strong> <?= sanitize($r['vaccine_name']) ?> (<?= formatDate($r['vaccination_date'], 'M d, Y') ?>)</p>
                        <div class="mb-2">
                            <label class="form-label">Reaction Details <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="reaction_details" rows="4" required placeholder="Describe the adverse reaction: symptoms, onset time, severity..."><?= sanitize($r['adverse_reactions'] ?? '') ?></textarea>
                        </div>
                        <div class="alert alert-warning small">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Serious adverse events should also be reported to the Lebanese Ministry of Public Health (MoPH) pharmacovigilance center.
                        </div>
                    </div>
                    <div class="modal-footer"><button type="submit" name="report_reaction" value="1" class="btn btn-danger">Report Reaction</button></div>
                    </form>
                </div></div></div>
                <?php endforeach; ?>
                <?php if (empty($records)): ?>
                <tr><td colspan="10" class="text-center text-muted py-3">No vaccination records found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Printable Certificate (hidden, populated via JS) -->
<div id="vaccinationCertificate" style="display:none">
    <div class="card p-4" id="certContent">
        <div class="text-center mb-4">
            <h4><?= sanitize($pharmacyName) ?></h4>
            <h5 class="text-primary">Vaccination Certificate</h5>
        </div>
        <div id="certBody"></div>
        <div class="text-center mt-4 pt-3 border-top">
            <small class="text-muted">This certificate is issued for record-keeping purposes. | <?= sanitize($pharmacyName) ?></small>
        </div>
    </div>
</div>

<?php
// Chart data for stats view
$typeLabels = array_map(function($t) { return $t['vaccine_name']; }, $byType);
$typeValues = array_map(function($t) { return intval($t['cnt']); }, $byType);
$chartColors = ['#0d6efd','#198754','#ffc107','#dc3545','#6f42c1','#0dcaf0','#fd7e14','#20c997','#d63384','#6610f2'];

$extraScripts = "<script>
function fillPatientName(sel) {
    var opt = sel.options[sel.selectedIndex];
    if (opt.dataset.name) {
        document.getElementById('patientNameInput').value = opt.dataset.name;
    }
}

function printCertificate(recordId) {
    // Find record data from the modal
    var modal = document.getElementById('viewRecord' + recordId);
    if (!modal) return;
    var rows = modal.querySelectorAll('table tr');
    var html = '<table class=\"table table-bordered\" style=\"font-size:14px\">';
    rows.forEach(function(row) {
        html += '<tr>' + row.innerHTML + '</tr>';
    });
    html += '</table>';
    html += '<br><div class=\"row\"><div class=\"col-6\"><p>______________________<br>Patient Signature</p></div>';
    html += '<div class=\"col-6 text-end\"><p>______________________<br>Pharmacist Signature & Stamp</p></div></div>';

    var w = window.open('', '_blank', 'width=700,height=900');
    w.document.write('<html><head><title>Vaccination Certificate</title>');
    w.document.write('<link href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css\" rel=\"stylesheet\">');
    w.document.write('</head><body class=\"p-4\">');
    w.document.write('<div class=\"text-center mb-4\"><h4>" . addslashes(sanitize($pharmacyName)) . "</h4><h5 class=\"text-primary\">Vaccination Certificate</h5></div>');
    w.document.write(html);
    w.document.write('<div class=\"text-center mt-4 pt-3 border-top\"><small>Issued on " . date('M d, Y') . " | " . addslashes(sanitize($pharmacyName)) . "</small></div>');
    w.document.write('</body></html>');
    w.document.close();
    setTimeout(function() { w.print(); }, 500);
}
" . ($view === 'stats' && !empty($byType) ? "
new Chart(document.getElementById('typeChart'), {
    type: 'bar',
    data: {
        labels: " . json_encode($typeLabels) . ",
        datasets: [{
            label: 'Vaccinations',
            data: " . json_encode($typeValues) . ",
            backgroundColor: " . json_encode(array_slice($chartColors, 0, count($typeLabels))) . "
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});
" : "") . "
</script>";

require_once __DIR__ . '/../../includes/footer.php';
?>
