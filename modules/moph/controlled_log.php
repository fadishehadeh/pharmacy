<?php
ob_start();
$pageTitle = 'Controlled Substance Register';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

// ── Print mode ───────────────────────────────────────────────────────────────
if (isset($_GET['print'])) {
    $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
    $dateTo   = $_GET['date_to']   ?? date('Y-m-t');
    $medFilter = $_GET['medicine']  ?? '';
    $where  = ['1=1'];
    $params = [];
    if ($dateFrom) { $where[] = 'DATE(COALESCE(csl.dispensed_at, csl.created_at)) >= ?'; $params[] = $dateFrom; }
    if ($dateTo)   { $where[] = 'DATE(COALESCE(csl.dispensed_at, csl.created_at)) <= ?'; $params[] = $dateTo; }
    if ($medFilter){ $where[] = 'm.name LIKE ?'; $params[] = "%$medFilter%"; }
    $whereStr = implode(' AND ', $where);
    $rows = $db->prepare("
        SELECT csl.*, m.name AS medicine_name, m.strength, m.controlled_category,
               u.full_name AS dispensed_by_name,
               s.invoice_number
        FROM controlled_substance_log csl
        JOIN medicines m ON csl.medicine_id = m.id
        LEFT JOIN users u ON COALESCE(csl.dispensed_by, csl.created_by) = u.id
        LEFT JOIN sales s ON csl.sale_id = s.id
        WHERE $whereStr
        ORDER BY COALESCE(csl.dispensed_at, csl.created_at) ASC
    ");
    $rows->execute($params);
    $rows = $rows->fetchAll();
    $pharmacyName = getSetting('pharmacy_name', 'Pharmacy');
    $pharmacyLicense = getSetting('pharmacy_license', '');
    $pharmacistName = getSetting('pharmacist_name', '');
    $monthYear = date('F Y', strtotime($dateFrom));
    ?>
<!doctype html><html><head>
<meta charset="utf-8">
<title>Controlled Substance Register — <?= htmlspecialchars($monthYear) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',serif;font-size:11pt;background:#fff;color:#000;padding:15mm}
h1{font-size:16pt;text-align:center;font-weight:bold;margin-bottom:4px}
.sub{text-align:center;font-size:11pt;margin-bottom:2px}
.period{text-align:center;font-size:10pt;margin-bottom:8px;font-style:italic}
table{width:100%;border-collapse:collapse;margin-top:8px;font-size:9pt}
th{background:#d0d0d0;border:1px solid #555;padding:4px;text-align:center;font-weight:bold}
td{border:1px solid #888;padding:3px 4px;vertical-align:top}
.no{text-align:center;width:28px}
.date{width:58px}
.qty{text-align:center;width:36px}
.sig{width:55px}
tr:nth-child(even){background:#f8f8f8}
@media print{
  @page{size:A4 landscape;margin:10mm}
  body{padding:0}
  .no-print{display:none}
}
.legend{margin-top:12px;font-size:9pt;border:1px solid #ccc;padding:6px}
.footer-sig{display:flex;justify-content:space-between;margin-top:20px;font-size:10pt}
.sig-box{border-top:1px solid #000;width:180px;text-align:center;padding-top:4px;margin-top:30px}
</style>
</head><body>
<button class="no-print" onclick="window.print()" style="position:fixed;top:10px;right:10px;padding:6px 14px;font-size:11pt;cursor:pointer">Print</button>
<h1>CONTROLLED SUBSTANCE REGISTER</h1>
<div class="sub"><?= htmlspecialchars($pharmacyName) ?><?= $pharmacyLicense ? ' — License: '.htmlspecialchars($pharmacyLicense) : '' ?></div>
<?php if ($pharmacistName): ?><div class="sub">Responsible Pharmacist: <?= htmlspecialchars($pharmacistName) ?></div><?php endif; ?>
<div class="period">Period: <?= htmlspecialchars(date('d M Y', strtotime($dateFrom))) ?> — <?= htmlspecialchars(date('d M Y', strtotime($dateTo))) ?></div>
<div class="period">Printed: <?= date('d M Y H:i') ?></div>

<table>
<thead>
<tr>
  <th class="no">#</th>
  <th class="date">Date &amp; Time</th>
  <th>Medicine / Category</th>
  <th class="qty">Qty</th>
  <th>Patient Name</th>
  <th>Patient ID</th>
  <th>DOB</th>
  <th>Prescriber</th>
  <th>License #</th>
  <th>Rx #</th>
  <th>Invoice</th>
  <th>Dispensed By</th>
  <th>Notes</th>
  <th class="sig">Signature</th>
</tr>
</thead>
<tbody>
<?php if (empty($rows)): ?>
<tr><td colspan="14" style="text-align:center;padding:10px;color:#666">No records found for this period</td></tr>
<?php endif; ?>
<?php foreach ($rows as $i => $r):
    $qty = $r['dispensed_qty'] ?: $r['quantity'] ?: '';
    $patientName = $r['patient_name'] ?: '';
    $patientId   = $r['patient_id_number'] ?: $r['patient_id'] ?: '';
    $prescriber  = $r['prescriber_name'] ?: $r['doctor_name'] ?: '';
    $license     = $r['prescriber_license'] ?: $r['doctor_license'] ?: '';
    $rxNum       = $r['prescription_number'] ?? '';
    $dispBy      = $r['dispensed_by_name'] ?? '';
    $dispensedAt = $r['dispensed_at'] ?: $r['created_at'];
?>
<tr>
  <td class="no"><?= $i + 1 ?></td>
  <td class="date"><?= htmlspecialchars(formatDate($dispensedAt, 'd/m/Y H:i')) ?></td>
  <td><?= htmlspecialchars($r['medicine_name']) ?>
      <?php if ($r['strength']): ?><br><small><?= htmlspecialchars($r['strength']) ?></small><?php endif; ?>
      <?php if ($r['controlled_category']): ?><br><em><?= htmlspecialchars(ucfirst($r['controlled_category'])) ?></em><?php endif; ?>
  </td>
  <td class="qty"><?= htmlspecialchars($qty) ?></td>
  <td><?= htmlspecialchars($patientName) ?></td>
  <td><?= htmlspecialchars($patientId) ?></td>
  <td><?= $r['patient_dob'] ? htmlspecialchars(formatDate($r['patient_dob'], 'd/m/Y')) : '' ?></td>
  <td><?= htmlspecialchars($prescriber) ?></td>
  <td><?= htmlspecialchars($license) ?></td>
  <td><?= htmlspecialchars($rxNum) ?></td>
  <td><?= htmlspecialchars($r['invoice_number'] ?? '') ?></td>
  <td><?= htmlspecialchars($dispBy) ?></td>
  <td><?= htmlspecialchars($r['notes'] ?? '') ?></td>
  <td class="sig">&nbsp;</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<div class="footer-sig">
  <div>
    <div class="sig-box">Pharmacist Signature</div>
    <div style="margin-top:4px;font-size:9pt"><?= htmlspecialchars($pharmacistName) ?></div>
  </div>
  <div>
    <div class="sig-box">Pharmacy Stamp</div>
  </div>
  <div>
    <div class="sig-box">Inspector Signature</div>
  </div>
</div>

<div class="legend">
  <strong>Note:</strong> This register is a legal document under Lebanese Narcotic Drugs and Psychotropic Substances law.
  Any alteration must be initialled. This register must be retained for a minimum of 10 years.
  Total records this period: <strong><?= count($rows) ?></strong>
</div>
</body></html>
<?php
    exit;
}

// ── Handle manual log entry POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_manual'])) {
    try {
        $stmt = $db->prepare("
            INSERT INTO controlled_substance_log
                (medicine_id, sale_id, dispensed_qty, patient_name, patient_id_number,
                 patient_dob, prescriber_name, prescriber_license, prescription_number,
                 dispensed_by, dispensed_at, notes, transaction_type, quantity, created_by)
            VALUES (?,NULL,?,?,?,?,?,?,?,?,?,?,'dispensed',?,?)
        ");
        $dispAt = $_POST['dispensed_at'] ?: date('Y-m-d H:i:s');
        $qty    = floatval($_POST['dispensed_qty']);
        $stmt->execute([
            intval($_POST['medicine_id']),
            $qty,
            trim($_POST['patient_name']),
            trim($_POST['patient_id_number'] ?? ''),
            $_POST['patient_dob'] ?: null,
            trim($_POST['prescriber_name'] ?? ''),
            trim($_POST['prescriber_license'] ?? ''),
            trim($_POST['prescription_number'] ?? ''),
            intval($_SESSION['user_id']),
            $dispAt,
            trim($_POST['notes'] ?? ''),
            intval($qty),
            intval($_SESSION['user_id']),
        ]);
        flashMessage('Manual entry added to controlled substance register');
    } catch (Exception $e) {
        flashMessage('Error: ' . $e->getMessage(), 'danger');
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Filters & Pagination ──────────────────────────────────────────────────────
$page     = max(1, intval($_GET['page'] ?? 1));
$perPage  = 25;
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';
$medFilter= $_GET['medicine']  ?? '';

$where  = ['1=1'];
$params = [];
if ($dateFrom) { $where[] = 'DATE(COALESCE(csl.dispensed_at,csl.created_at)) >= ?'; $params[] = $dateFrom; }
if ($dateTo)   { $where[] = 'DATE(COALESCE(csl.dispensed_at,csl.created_at)) <= ?'; $params[] = $dateTo; }
if ($medFilter){ $where[] = 'm.name LIKE ?'; $params[] = "%$medFilter%"; }
$whereStr = implode(' AND ', $where);

$total = $db->prepare("
    SELECT COUNT(*) FROM controlled_substance_log csl
    JOIN medicines m ON csl.medicine_id = m.id
    WHERE $whereStr
");
$total->execute($params);
$total = (int)$total->fetchColumn();

$totalPages = max(1, ceil($total / $perPage));
$offset     = ($page - 1) * $perPage;

$rows = $db->prepare("
    SELECT csl.*, m.name AS medicine_name, m.strength, m.controlled_category,
           u.full_name AS dispensed_by_name,
           s.invoice_number
    FROM controlled_substance_log csl
    JOIN medicines m ON csl.medicine_id = m.id
    LEFT JOIN users u ON COALESCE(csl.dispensed_by, csl.created_by) = u.id
    LEFT JOIN sales s ON csl.sale_id = s.id
    WHERE $whereStr
    ORDER BY COALESCE(csl.dispensed_at, csl.created_at) DESC
    LIMIT $perPage OFFSET $offset
");
$rows->execute($params);
$rows = $rows->fetchAll();

$controlledMeds = $db->query("SELECT id, name, strength FROM medicines WHERE is_controlled=1 AND is_active=1 ORDER BY name")->fetchAll();

// Build print URL preserving current filters
$printUrl = '?' . http_build_query(array_filter([
    'print'     => '1',
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
    'medicine'  => $medFilter,
]));
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-0"><i class="bi bi-journal-text me-2 text-danger"></i>Controlled Substance Register</h5>
        <small class="text-muted"><?= number_format($total) ?> records</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $printUrl ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Print Register
        </a>
        <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalManual">
            <i class="bi bi-plus-lg me-1"></i>Add Manual Entry
        </button>
    </div>
</div>

<!-- Filters -->
<div class="card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small">Date From</label>
            <input type="date" class="form-control form-control-sm" name="date_from" value="<?= sanitize($dateFrom) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small">Date To</label>
            <input type="date" class="form-control form-control-sm" name="date_to" value="<?= sanitize($dateTo) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label small">Medicine Name</label>
            <input type="text" class="form-control form-control-sm" name="medicine" placeholder="Filter by medicine..." value="<?= sanitize($medFilter) ?>">
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="bi bi-funnel me-1"></i>Filter</button>
            <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-outline-secondary btn-sm">✕</a>
        </div>
    </form>
</div>

<!-- Log Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>#</th>
                    <th>Date &amp; Time</th>
                    <th>Medicine</th>
                    <th>Patient Name</th>
                    <th>Patient ID</th>
                    <th>Prescriber</th>
                    <th>Rx #</th>
                    <th class="text-center">Qty</th>
                    <th>Dispensed By</th>
                    <th>Invoice</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">No controlled substance records found.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $i => $r):
                    $qty        = $r['dispensed_qty'] ?: $r['quantity'] ?: '';
                    $patientId  = $r['patient_id_number'] ?: $r['patient_id'] ?: '';
                    $prescriber = $r['prescriber_name'] ?: $r['doctor_name'] ?: '';
                    $rxNum      = $r['prescription_number'] ?? '';
                    $dispensedAt= $r['dispensed_at'] ?: $r['created_at'];
                ?>
                <tr>
                    <td class="text-muted small"><?= $offset + $i + 1 ?></td>
                    <td><small><?= htmlspecialchars(formatDate($dispensedAt, 'd M Y')) ?><br>
                        <span class="text-muted"><?= htmlspecialchars(formatDate($dispensedAt, 'H:i')) ?></span></small></td>
                    <td>
                        <strong class="small"><?= sanitize($r['medicine_name']) ?></strong>
                        <?php if ($r['strength']): ?><br><small class="text-muted"><?= sanitize($r['strength']) ?></small><?php endif; ?>
                        <?php if ($r['controlled_category']): ?>
                        <br><span class="badge bg-danger bg-opacity-75 small"><?= sanitize(ucfirst($r['controlled_category'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= sanitize($r['patient_name'] ?: '—') ?></td>
                    <td><small class="font-monospace"><?= sanitize($patientId ?: '—') ?></small></td>
                    <td><small><?= sanitize($prescriber ?: '—') ?></small></td>
                    <td><small class="font-monospace"><?= sanitize($rxNum ?: '—') ?></small></td>
                    <td class="text-center fw-bold"><?= htmlspecialchars($qty) ?></td>
                    <td><small><?= sanitize($r['dispensed_by_name'] ?? '—') ?></small></td>
                    <td>
                        <?php if ($r['invoice_number']): ?>
                        <a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $r['sale_id'] ?>" target="_blank" class="small">
                            <?= sanitize($r['invoice_number']) ?>
                        </a>
                        <?php else: ?>
                        <small class="text-muted">Manual</small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="p-3 d-flex justify-content-between align-items-center border-top">
        <small class="text-muted">Page <?= $page ?> of <?= $totalPages ?></small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php if ($page > 1): ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $page-1 ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&medicine=<?= urlencode($medFilter) ?>">«</a></li>
                <?php endif; ?>
                <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
                <li class="page-item <?= $p==$page?'active':'' ?>">
                    <a class="page-link" href="?page=<?= $p ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&medicine=<?= urlencode($medFilter) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $page+1 ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&medicine=<?= urlencode($medFilter) ?>">»</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Manual Entry Modal -->
<div class="modal fade" id="modalManual" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-journal-plus me-2"></i>Add Manual Entry</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Use this for dispensings not linked to a sale (adjustments, returns, emergencies).
                    </div>
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Controlled Substance <span class="text-danger">*</span></label>
                            <select class="form-select" name="medicine_id" required>
                                <option value="">— Select medicine —</option>
                                <?php foreach ($controlledMeds as $cm): ?>
                                <option value="<?= $cm['id'] ?>"><?= sanitize($cm['name']) ?><?= $cm['strength'] ? ' ('.$cm['strength'].')' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($controlledMeds)): ?>
                            <div class="form-text text-warning">No medicines flagged as controlled. Go to Inventory → Edit medicine → check "Controlled".</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12"><hr class="my-0"><small class="text-uppercase text-muted fw-bold">Patient Details</small></div>
                        <div class="col-md-6">
                            <label class="form-label">Patient Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="patient_name" required placeholder="Full name">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Patient ID / Passport</label>
                            <input type="text" class="form-control" name="patient_id_number" placeholder="ID number">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" name="patient_dob">
                        </div>

                        <div class="col-12"><hr class="my-0"><small class="text-uppercase text-muted fw-bold">Prescription Details</small></div>
                        <div class="col-md-4">
                            <label class="form-label">Prescriber Name</label>
                            <input type="text" class="form-control" name="prescriber_name" placeholder="Dr. Name">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Prescriber License #</label>
                            <input type="text" class="form-control" name="prescriber_license" placeholder="License number">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Prescription #</label>
                            <input type="text" class="form-control" name="prescription_number" placeholder="Rx number">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Quantity Dispensed <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="dispensed_qty" min="0.01" step="0.01" required placeholder="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date &amp; Time</label>
                            <input type="datetime-local" class="form-control" name="dispensed_at" value="<?= date('Y-m-d\TH:i') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Reason for manual entry, any relevant notes..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_manual" value="1" class="btn btn-danger">
                        <i class="bi bi-journal-check me-1"></i>Save to Register
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
