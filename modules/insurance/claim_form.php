<?php
$pageTitle = 'Insurance Claim Form';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

// Ensure insurance_claims table has all needed columns
$db->exec("CREATE TABLE IF NOT EXISTS insurance_claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    claim_number VARCHAR(50),
    sale_id INT,
    insurance_provider_id INT,
    customer_id INT,
    patient_name VARCHAR(200),
    patient_insurance_no VARCHAR(100),
    claim_date DATE,
    gross_amount DECIMAL(10,2) DEFAULT 0,
    subsidy_amount DECIMAL(10,2) DEFAULT 0,
    patient_copay DECIMAL(10,2) DEFAULT 0,
    total_amount DECIMAL(10,2) DEFAULT 0,
    covered_amount DECIMAL(10,2) DEFAULT 0,
    status ENUM('draft','submitted','approved','rejected','paid','pending','partial') DEFAULT 'draft',
    notes TEXT,
    rejection_reason TEXT,
    payment_date DATE,
    payment_amount DECIMAL(10,2),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$saleId   = intval($_GET['sale_id'] ?? 0);
$claimId  = intval($_GET['claim_id'] ?? 0);
$sale     = null;
$claim    = null;
$items    = [];
$customer = null;

// ── Handle POST: save claim ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_claim'])) {
    $providerId      = intval($_POST['insurance_provider_id']);
    $patientName     = trim($_POST['patient_name']);
    $patientInsNo    = trim($_POST['patient_insurance_no']);
    $claimDate       = $_POST['claim_date'];
    $grossAmount     = floatval($_POST['gross_amount']);
    $subsidyAmount   = floatval($_POST['subsidy_amount']);
    $patientCopay    = floatval($_POST['patient_copay']);
    $notes           = trim($_POST['notes'] ?? '');
    $postSaleId      = intval($_POST['sale_id'] ?? 0);
    $postClaimId     = intval($_POST['claim_id'] ?? 0);
    $custId          = intval($_POST['customer_id'] ?? 0) ?: null;

    if ($postClaimId) {
        $db->prepare("UPDATE insurance_claims SET insurance_provider_id=?, patient_name=?, patient_insurance_no=?,
            claim_date=?, gross_amount=?, subsidy_amount=?, patient_copay=?, covered_amount=?, total_amount=?,
            notes=?, customer_id=? WHERE id=?")
            ->execute([$providerId, $patientName, $patientInsNo, $claimDate,
                $grossAmount, $subsidyAmount, $patientCopay, $subsidyAmount, $grossAmount,
                $notes, $custId, $postClaimId]);
        flashMessage('Claim updated successfully');
        header('Location: claim_form.php?claim_id=' . $postClaimId);
    } else {
        $claimNumber = 'CLM-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        $db->prepare("INSERT INTO insurance_claims (claim_number, sale_id, insurance_provider_id, customer_id,
            patient_name, patient_insurance_no, claim_date, gross_amount, subsidy_amount, patient_copay,
            covered_amount, total_amount, notes, status, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?)")
            ->execute([$claimNumber, $postSaleId ?: null, $providerId, $custId,
                $patientName, $patientInsNo, $claimDate,
                $grossAmount, $subsidyAmount, $patientCopay,
                $subsidyAmount, $grossAmount, $notes, $_SESSION['user_id'] ?? null]);
        $newId = $db->lastInsertId();
        flashMessage('Claim ' . $claimNumber . ' created');
        header('Location: claim_form.php?claim_id=' . $newId);
    }
    exit;
}

// ── Load data ────────────────────────────────────────────────────────────────
if ($claimId) {
    $claim = $db->prepare("SELECT ic.*, ip.name as provider_name FROM insurance_claims ic
        LEFT JOIN insurance_providers ip ON ic.insurance_provider_id = ip.id WHERE ic.id = ?");
    $claim->execute([$claimId]);
    $claim = $claim->fetch();
    if ($claim) {
        $saleId = $claim['sale_id'] ?: 0;
        if (!$claim['customer_id']) {
            // try from sale
        } else {
            $customer = $db->prepare("SELECT * FROM customers WHERE id = ?");
            $customer->execute([$claim['customer_id']]);
            $customer = $customer->fetch();
        }
    }
}

if ($saleId) {
    $sale = $db->prepare("SELECT s.*, c.name as customer_name, c.phone as customer_phone,
        c.id as cust_id, ip.name as insurance_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN insurance_providers ip ON c.insurance_provider_id = ip.id
        WHERE s.id = ?");
    $sale->execute([$saleId]);
    $sale = $sale->fetch();

    if ($sale) {
        $items = $db->prepare("SELECT si.*, m.name as med_name, m.strength, m.form
            FROM sale_items si
            JOIN medicines m ON si.medicine_id = m.id
            WHERE si.sale_id = ?");
        $items->execute([$saleId]);
        $items = $items->fetchAll();

        if (!$customer && $sale['cust_id']) {
            $customer = $db->prepare("SELECT * FROM customers WHERE id = ?");
            $customer->execute([$sale['cust_id']]);
            $customer = $customer->fetch();
        }
    }
}

$providers = $db->query("SELECT * FROM insurance_providers WHERE is_active = 1 ORDER BY name")->fetchAll();
$pharmacyName      = getSetting('pharmacy_name') ?: 'Pharmacy';
$pharmacyAddress   = getSetting('pharmacy_address') ?: '';
$pharmacyPhone     = getSetting('pharmacy_phone') ?: '';
$pharmacyLicense   = getSetting('pharmacy_license') ?: '';
$pharmacistName    = getSetting('pharmacist_name') ?: '';
$pharmacistLicense = getSetting('pharmacist_license') ?: '';

// Pre-fill amounts from sale items
$grossAmt   = 0;
$subsidyAmt = 0;
foreach ($items as $it) {
    $grossAmt   += $it['total_price'];
    $subsidyAmt += ($it['subsidy_amount'] ?? 0);
}
$copayAmt = $grossAmt - $subsidyAmt;

if ($claim) {
    $grossAmt   = $claim['gross_amount'];
    $subsidyAmt = $claim['subsidy_amount'];
    $copayAmt   = $claim['patient_copay'];
}

// Determine selected provider
$selectedProvider = null;
if ($claim && $claim['insurance_provider_id']) {
    foreach ($providers as $p) {
        if ($p['id'] == $claim['insurance_provider_id']) { $selectedProvider = $p; break; }
    }
} elseif ($sale && $sale['customer_name']) {
    // try customer's insurer
    if ($customer) {
        $cp = $db->prepare("SELECT ip.* FROM insurance_providers ip JOIN customers c ON c.insurance_provider_id = ip.id WHERE c.id = ?");
        $cp->execute([$customer['id']]);
        $selectedProvider = $cp->fetch() ?: null;
    }
}
?>

<style>
@media print {
    .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    body { font-size: 12px; }
    .claim-header { border-bottom: 2px solid #000 !important; }
    .signature-block { border: 1px solid #000 !important; }
}
.claim-wrapper { max-width: 860px; margin: 0 auto; }
.claim-header { border-bottom: 2px solid var(--bs-primary); padding-bottom: 12px; margin-bottom: 16px; }
.section-title { background: #f0f4ff; border-left: 3px solid var(--bs-primary); padding: 4px 10px; font-size: .8rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 10px; }
.signature-block { border: 1px dashed #aaa; border-radius: 6px; height: 80px; display: flex; align-items: flex-end; padding: 6px 10px; }
.nssf-band { background: #003189; color: #fff; padding: 6px 14px; border-radius: 4px 4px 0 0; font-size: .75rem; letter-spacing: .08em; }
</style>

<!-- Toolbar -->
<div class="no-print mb-3 d-flex gap-2 align-items-center flex-wrap">
    <a href="claims.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Claims</a>
    <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print Claim</button>
    <?php if ($claim): ?>
    <span class="badge bg-<?= ['draft'=>'secondary','submitted'=>'info','approved'=>'primary','rejected'=>'danger','paid'=>'success'][$claim['status']] ?? 'secondary' ?> ms-2">
        <?= ucfirst($claim['status']) ?>
    </span>
    <?php endif; ?>
    <div class="ms-auto no-print">
        <label class="form-label mb-0 me-2 small fw-semibold">Insurer Header:</label>
        <select class="form-select form-select-sm d-inline-block w-auto" id="insurerSelector">
            <option value="">Generic</option>
            <option value="nssf">NSSF – Caisse Nationale de Sécurité Sociale</option>
            <option value="generation">Génération</option>
            <option value="allianz">Allianz</option>
            <option value="other">Other</option>
        </select>
    </div>
</div>

<div class="claim-wrapper">

<!-- ── Insurer-specific header band ─────────────────────────────────────────── -->
<div id="insurerBand" class="d-none mb-0"></div>

<!-- ── Pharmacy Header ──────────────────────────────────────────────────────── -->
<div class="card p-4">
    <div class="claim-header row align-items-start">
        <div class="col-8">
            <h4 class="fw-bold mb-1"><?= sanitize($pharmacyName) ?></h4>
            <?php if ($pharmacyAddress): ?><div class="small text-muted"><i class="bi bi-geo-alt me-1"></i><?= sanitize($pharmacyAddress) ?></div><?php endif; ?>
            <?php if ($pharmacyPhone): ?><div class="small text-muted"><i class="bi bi-telephone me-1"></i><?= sanitize($pharmacyPhone) ?></div><?php endif; ?>
            <?php if ($pharmacyLicense): ?><div class="small text-muted">License: <?= sanitize($pharmacyLicense) ?></div><?php endif; ?>
        </div>
        <div class="col-4 text-end">
            <div class="fw-bold" style="font-size:1.1rem">INSURANCE CLAIM FORM</div>
            <?php if ($claim): ?>
            <div class="small text-muted mt-1">Ref: <strong><?= sanitize($claim['claim_number']) ?></strong></div>
            <?php endif; ?>
            <?php if ($sale): ?>
            <div class="small text-muted">Invoice: <strong><?= sanitize($sale['invoice_number']) ?></strong></div>
            <?php endif; ?>
            <div class="small text-muted">Date: <strong><?= date('d M Y', strtotime($claim['claim_date'] ?? 'now')) ?></strong></div>
        </div>
    </div>

    <!-- ── Patient Information ──────────────────────────────────────────────── -->
    <div class="section-title"><i class="bi bi-person me-1"></i>Patient Information</div>
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="small text-muted">Patient Name</div>
            <div class="fw-semibold"><?= sanitize($claim['patient_name'] ?? $sale['customer_name'] ?? '—') ?></div>
        </div>
        <div class="col-md-4">
            <div class="small text-muted">Insurance Number</div>
            <div class="fw-semibold"><?= sanitize($claim['patient_insurance_no'] ?? '—') ?></div>
        </div>
        <div class="col-md-4">
            <div class="small text-muted">Insurance Provider</div>
            <div class="fw-semibold"><?= sanitize($selectedProvider['name'] ?? $claim['provider_name'] ?? '—') ?></div>
        </div>
        <?php if ($customer && !empty($customer['phone'])): ?>
        <div class="col-md-4">
            <div class="small text-muted">Phone</div>
            <div class="fw-semibold"><?= sanitize($customer['phone']) ?></div>
        </div>
        <?php endif; ?>
        <div class="col-md-4">
            <div class="small text-muted">Claim Date</div>
            <div class="fw-semibold"><?= date('d M Y', strtotime($claim['claim_date'] ?? date('Y-m-d'))) ?></div>
        </div>
        <div class="col-md-4">
            <div class="small text-muted">Sale Date</div>
            <div class="fw-semibold"><?= $sale ? formatDate($sale['sale_date'], 'M d, Y') : '—' ?></div>
        </div>
    </div>

    <!-- ── Itemized Medicine List ────────────────────────────────────────────── -->
    <div class="section-title"><i class="bi bi-capsule me-1"></i>Prescribed Medicines</div>
    <div class="table-responsive mb-3">
        <table class="table table-sm table-bordered mb-0" style="font-size:.85rem">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Medicine</th>
                    <th>Strength / Form</th>
                    <th class="text-center">Qty</th>
                    <th class="text-end">Unit Price</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Subsidy</th>
                    <th class="text-end">Patient Portion</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($items)): ?>
                    <?php foreach ($items as $i => $it): ?>
                    <?php
                        $itSubsidy  = $it['subsidy_amount'] ?? 0;
                        $itPatient  = $it['total_price'] - $itSubsidy;
                    ?>
                    <tr>
                        <td><?= $i+1 ?></td>
                        <td><?= sanitize($it['med_name']) ?></td>
                        <td class="text-muted small"><?= sanitize(trim(($it['strength'] ?? '') . ' ' . ($it['form'] ?? ''))) ?></td>
                        <td class="text-center"><?= $it['quantity'] ?></td>
                        <td class="text-end"><?= formatCurrency($it['unit_price']) ?></td>
                        <td class="text-end"><?= formatCurrency($it['total_price']) ?></td>
                        <td class="text-end text-success"><?= $itSubsidy > 0 ? formatCurrency($itSubsidy) : '—' ?></td>
                        <td class="text-end"><?= formatCurrency($itPatient) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">No items — attach sale to populate</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Totals ────────────────────────────────────────────────────────────── -->
    <div class="section-title"><i class="bi bi-calculator me-1"></i>Claim Summary</div>
    <div class="row justify-content-end mb-4">
        <div class="col-md-5">
            <table class="table table-sm mb-0" style="font-size:.9rem">
                <tr><td class="text-muted">Gross Amount</td><td class="text-end fw-semibold"><?= formatCurrency($grossAmt) ?></td></tr>
                <tr class="table-success"><td class="text-muted">Subsidy Amount (Insurer)</td><td class="text-end fw-semibold text-success"><?= formatCurrency($subsidyAmt) ?></td></tr>
                <tr class="table-warning"><td class="text-muted">Patient Co-Pay</td><td class="text-end fw-semibold"><?= formatCurrency($copayAmt) ?></td></tr>
                <tr class="table-primary fw-bold"><td>Amount Claimed from Insurer</td><td class="text-end"><?= formatCurrency($subsidyAmt) ?></td></tr>
            </table>
        </div>
    </div>

    <!-- ── Pharmacist Information ────────────────────────────────────────────── -->
    <div class="section-title"><i class="bi bi-person-badge me-1"></i>Dispensing Pharmacist</div>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="small text-muted">Pharmacist Name</div>
            <div class="fw-semibold"><?= sanitize($pharmacistName) ?></div>
        </div>
        <div class="col-md-4">
            <div class="small text-muted">License Number</div>
            <div class="fw-semibold"><?= sanitize($pharmacistLicense) ?></div>
        </div>
    </div>

    <!-- ── Signature Blocks ──────────────────────────────────────────────────── -->
    <div class="section-title"><i class="bi bi-pen me-1"></i>Signatures</div>
    <div class="row g-3 mt-1">
        <div class="col-md-4">
            <div class="small text-muted mb-1">Pharmacist Signature</div>
            <div class="signature-block"><span class="text-muted small">Signature / Stamp</span></div>
            <div class="small text-muted mt-1">Date: _______________</div>
        </div>
        <div class="col-md-4">
            <div class="small text-muted mb-1">Patient / Beneficiary Signature</div>
            <div class="signature-block"><span class="text-muted small">Signature</span></div>
            <div class="small text-muted mt-1">Date: _______________</div>
        </div>
        <div class="col-md-4">
            <div class="small text-muted mb-1">Pharmacy Stamp</div>
            <div class="signature-block"><span class="text-muted small">Official Stamp</span></div>
        </div>
    </div>

    <?php if ($claim && $claim['notes']): ?>
    <div class="mt-3 p-2 bg-light rounded small">
        <strong>Notes:</strong> <?= sanitize($claim['notes']) ?>
    </div>
    <?php endif; ?>
</div><!-- /card -->

<!-- ── Edit / Create Form ────────────────────────────────────────────────────── -->
<div class="card mt-4 no-print">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-pencil me-2"></i><?= $claim ? 'Edit Claim' : 'Save Claim' ?></h6></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="claim_id" value="<?= $claim['id'] ?? 0 ?>">
            <input type="hidden" name="sale_id" value="<?= $saleId ?>">
            <input type="hidden" name="customer_id" value="<?= $customer['id'] ?? ($sale['cust_id'] ?? 0) ?>">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small">Insurance Provider</label>
                    <select class="form-select form-select-sm" name="insurance_provider_id" required>
                        <option value="">Select provider…</option>
                        <?php foreach ($providers as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= ($claim['insurance_provider_id'] ?? ($selectedProvider['id'] ?? 0)) == $p['id'] ? 'selected' : '' ?>>
                            <?= sanitize($p['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Patient Name</label>
                    <input type="text" class="form-control form-control-sm" name="patient_name"
                        value="<?= sanitize($claim['patient_name'] ?? $sale['customer_name'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Insurance / Member No.</label>
                    <input type="text" class="form-control form-control-sm" name="patient_insurance_no"
                        value="<?= sanitize($claim['patient_insurance_no'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Claim Date</label>
                    <input type="date" class="form-control form-control-sm" name="claim_date"
                        value="<?= $claim['claim_date'] ?? date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Gross Amount</label>
                    <input type="number" class="form-control form-control-sm" name="gross_amount" step="0.01" id="fGross"
                        value="<?= number_format($grossAmt, 2, '.', '') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Subsidy / Insurer Pays</label>
                    <input type="number" class="form-control form-control-sm" name="subsidy_amount" step="0.01" id="fSubsidy"
                        value="<?= number_format($subsidyAmt, 2, '.', '') ?>" oninput="updateCopay()">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Patient Co-Pay</label>
                    <input type="number" class="form-control form-control-sm" name="patient_copay" step="0.01" id="fCopay"
                        value="<?= number_format($copayAmt, 2, '.', '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label small">Notes</label>
                    <textarea class="form-control form-control-sm" name="notes" rows="2"><?= sanitize($claim['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" name="save_claim" value="1" class="btn btn-primary btn-sm">
                        <i class="bi bi-save me-1"></i><?= $claim ? 'Update Claim' : 'Save Claim' ?>
                    </button>
                    <?php if ($claim): ?>
                    <a href="claims.php" class="btn btn-outline-secondary btn-sm ms-2">View All Claims</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

</div><!-- /claim-wrapper -->

<?php
$extraScripts = <<<'JS'
<script>
function updateCopay() {
    var gross   = parseFloat(document.getElementById('fGross').value) || 0;
    var subsidy = parseFloat(document.getElementById('fSubsidy').value) || 0;
    document.getElementById('fCopay').value = Math.max(0, gross - subsidy).toFixed(2);
}

// Insurer selector changes header band
document.getElementById('insurerSelector').addEventListener('change', function() {
    var band = document.getElementById('insurerBand');
    var v = this.value;
    if (!v) { band.className = 'd-none mb-0'; band.innerHTML = ''; return; }
    var labels = {
        nssf:       'CAISSE NATIONALE DE SÉCURITÉ SOCIALE (NSSF) — Demande de remboursement',
        generation: 'GÉNÉRATION — Insurance Reimbursement Request',
        allianz:    'ALLIANZ INSURANCE — Medical Claim Form',
        other:      'INSURANCE PROVIDER — Medical Claim Form'
    };
    band.className = 'nssf-band mb-0 fw-semibold text-uppercase';
    band.textContent = labels[v] || '';
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
