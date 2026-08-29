<?php
$pageTitle = 'Prescriptions';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_prescription'])) {
    $rxNumber = 'RX-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    $stmt = $db->prepare("INSERT INTO prescriptions (rx_number, patient_id, customer_id, doctor_name, doctor_license, doctor_phone, diagnosis, issue_date, expiry_date, notes, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $rxNumber,
        $_POST['patient_id'] ?: null,
        $_POST['customer_id'] ?: null,
        $_POST['doctor_name'],
        $_POST['doctor_license'] ?: null,
        $_POST['doctor_phone'] ?: null,
        $_POST['diagnosis'] ?: null,
        $_POST['issue_date'],
        $_POST['expiry_date'] ?: null,
        $_POST['notes'] ?: null,
        'active',
        $_SESSION['user_id']
    ]);
    $rxId = $db->lastInsertId();

    if (!empty($_POST['med_id'])) {
        $medStmt = $db->prepare("INSERT INTO prescription_items (prescription_id, medicine_id, dosage, frequency, duration, quantity_prescribed, quantity_dispensed, instructions) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($_POST['med_id'] as $idx => $medId) {
            if (empty($medId)) continue;
            $medStmt->execute([
                $rxId, $medId,
                $_POST['med_dosage'][$idx] ?? '',
                $_POST['med_frequency'][$idx] ?? '',
                $_POST['med_duration'][$idx] ?? '',
                $_POST['med_qty'][$idx] ?? 0,
                0,
                $_POST['med_instructions'][$idx] ?? ''
            ]);
        }
    }

    addAuditLog('create', 'prescriptions', $rxId);
    flashMessage('Prescription ' . $rxNumber . ' created');
    header('Location: view.php?id=' . $rxId);
    exit;
}

$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$where = "WHERE 1=1";
$params = [];
if ($filter === 'active') { $where .= " AND p.status = 'active'"; }
elseif ($filter === 'dispensed') { $where .= " AND p.status = 'dispensed'"; }
elseif ($filter === 'expired') { $where .= " AND p.status = 'expired'"; }
if ($search) { $where .= " AND (p.rx_number LIKE ? OR p.doctor_name LIKE ? OR c.name LIKE ?)"; $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]); }

$prescriptions = $db->prepare("SELECT p.*, c.name as customer_name,
    (SELECT COUNT(*) FROM prescription_items pi WHERE pi.prescription_id = p.id) as item_count,
    (SELECT SUM(pi.quantity_dispensed) FROM prescription_items pi WHERE pi.prescription_id = p.id) as total_dispensed
    FROM prescriptions p
    LEFT JOIN customers c ON p.customer_id = c.id
    $where
    ORDER BY p.created_at DESC LIMIT 100");
$prescriptions->execute($params);
$prescriptions = $prescriptions->fetchAll();

$customers = $db->query("SELECT id, name FROM customers ORDER BY name")->fetchAll();
$patients = [];
try { $patients = $db->query("SELECT pp.id, c.name FROM patient_profiles pp JOIN customers c ON pp.customer_id = c.id ORDER BY c.name")->fetchAll(); } catch (Exception $e) {}
$medicines = $db->query("SELECT id, name, strength, form FROM medicines WHERE is_active = 1 ORDER BY name")->fetchAll();

$stats = [
    'total' => $db->query("SELECT COUNT(*) FROM prescriptions")->fetchColumn(),
    'active' => $db->query("SELECT COUNT(*) FROM prescriptions WHERE status = 'active'")->fetchColumn(),
    'dispensed' => $db->query("SELECT COUNT(*) FROM prescriptions WHERE status = 'dispensed'")->fetchColumn(),
    'today' => $db->query("SELECT COUNT(*) FROM prescriptions WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
];
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card"><div class="stat-label">Total Prescriptions</div><div class="stat-value"><?= $stats['total'] ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card success"><div class="stat-label">Active</div><div class="stat-value"><?= $stats['active'] ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card info"><div class="stat-label">Dispensed</div><div class="stat-value"><?= $stats['dispensed'] ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card warning"><div class="stat-label">Today</div><div class="stat-value"><?= $stats['today'] ?></div></div></div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <div class="btn-group btn-group-sm">
                <a href="?filter=all" class="btn btn-<?= $filter === 'all' ? 'primary' : 'outline-primary' ?>">All</a>
                <a href="?filter=active" class="btn btn-<?= $filter === 'active' ? 'primary' : 'outline-primary' ?>">Active</a>
                <a href="?filter=dispensed" class="btn btn-<?= $filter === 'dispensed' ? 'primary' : 'outline-primary' ?>">Dispensed</a>
                <a href="?filter=expired" class="btn btn-<?= $filter === 'expired' ? 'primary' : 'outline-primary' ?>">Expired</a>
            </div>
        </div>
        <div class="d-flex gap-2">
            <form class="d-flex gap-2" method="GET">
                <input type="hidden" name="filter" value="<?= sanitize($filter) ?>">
                <input type="text" class="form-control form-control-sm" name="search" value="<?= sanitize($search) ?>" placeholder="Search Rx#, doctor, patient...">
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
            </form>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addRxModal"><i class="bi bi-plus me-1"></i>New Rx</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead><tr><th>Rx #</th><th>Patient</th><th>Doctor</th><th>Date</th><th>Items</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($prescriptions as $rx): ?>
                <tr>
                    <td><strong><?= sanitize($rx['rx_number']) ?></strong></td>
                    <td><?= sanitize($rx['customer_name'] ?? 'Walk-in') ?></td>
                    <td><?= sanitize($rx['doctor_name']) ?></td>
                    <td><?= formatDate($rx['issue_date'], 'M d, Y') ?></td>
                    <td><span class="badge bg-secondary"><?= $rx['item_count'] ?> items</span></td>
                    <td>
                        <?php $sColors = ['active'=>'success','dispensed'=>'info','partial'=>'warning','expired'=>'danger','cancelled'=>'secondary']; ?>
                        <span class="badge bg-<?= $sColors[$rx['status']] ?? 'secondary' ?>"><?= ucfirst($rx['status']) ?></span>
                    </td>
                    <td>
                        <a href="view.php?id=<?= $rx['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($prescriptions)): ?><tr><td colspan="7" class="text-center text-muted py-3">No prescriptions found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addRxModal"><div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST" id="rxForm">
    <div class="modal-header"><h6 class="modal-title"><i class="bi bi-file-medical me-2"></i>New Prescription</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Patient/Customer</label>
                <select class="form-select" name="customer_id">
                    <option value="">Walk-in</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Patient Profile</label>
                <select class="form-select" name="patient_id">
                    <option value="">-- Optional --</option>
                    <?php foreach ($patients as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Doctor Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="doctor_name" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Doctor License #</label>
                <input type="text" class="form-control" name="doctor_license">
            </div>
            <div class="col-md-4">
                <label class="form-label">Doctor Phone</label>
                <input type="text" class="form-control" name="doctor_phone">
            </div>
            <div class="col-md-4">
                <label class="form-label">Issue Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="issue_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Expiry Date</label>
                <input type="date" class="form-control" name="expiry_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Diagnosis</label>
                <input type="text" class="form-control" name="diagnosis">
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="2"></textarea>
            </div>
        </div>
        <hr>
        <h6>Prescribed Medicines</h6>
        <div id="rxMedItems">
            <div class="row g-2 mb-2 rx-med-row">
                <div class="col-md-3"><select class="form-select form-select-sm" name="med_id[]">
                    <option value="">Select Medicine</option>
                    <?php foreach ($medicines as $m): ?>
                    <option value="<?= $m['id'] ?>"><?= sanitize($m['name']) ?> <?= $m['strength'] ? '('.$m['strength'].')' : '' ?></option>
                    <?php endforeach; ?>
                </select></div>
                <div class="col-md-2"><input type="text" class="form-control form-control-sm" name="med_dosage[]" placeholder="Dosage"></div>
                <div class="col-md-2"><input type="text" class="form-control form-control-sm" name="med_frequency[]" placeholder="Frequency"></div>
                <div class="col-md-2"><input type="text" class="form-control form-control-sm" name="med_duration[]" placeholder="Duration"></div>
                <div class="col-md-1"><input type="number" class="form-control form-control-sm" name="med_qty[]" placeholder="Qty" min="0"></div>
                <div class="col-md-2"><input type="text" class="form-control form-control-sm" name="med_instructions[]" placeholder="Instructions"></div>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="addRxMed"><i class="bi bi-plus me-1"></i>Add Medicine</button>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_prescription" value="1" class="btn btn-primary"><i class="bi bi-check me-1"></i>Create Prescription</button>
    </div>
    </form>
</div></div></div>

<?php
$extraScripts = <<<'SCRIPT'
<script>
document.getElementById('addRxMed').addEventListener('click', function() {
    var row = document.querySelector('.rx-med-row').cloneNode(true);
    row.querySelectorAll('input, select').forEach(function(el) { el.value = ''; });
    document.getElementById('rxMedItems').appendChild(row);
});
</script>
SCRIPT;
require_once __DIR__ . '/../../includes/footer.php';
?>
