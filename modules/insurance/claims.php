<?php
$pageTitle = 'Insurance Claims';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_claim'])) {
        $claimNumber = generateClaimNumber();
        $db->prepare("INSERT INTO insurance_claims (claim_number, insurance_provider_id, customer_id, sale_id, claim_date, total_amount, covered_amount, patient_copay, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([
            $claimNumber, $_POST['insurance_provider_id'], $_POST['customer_id'] ?: null,
            $_POST['sale_id'] ?: null, $_POST['claim_date'], $_POST['total_amount'],
            $_POST['covered_amount'], $_POST['patient_copay'] ?: 0,
            $_POST['notes'] ?: null, $_SESSION['user_id'] ?? null
        ]);
        flashMessage("Claim $claimNumber created");
    } elseif (isset($_POST['update_status'])) {
        $fields = ['status' => $_POST['status']];
        $sql = "UPDATE insurance_claims SET status = ?";
        $params = [$_POST['status']];
        if ($_POST['status'] === 'paid') {
            $sql .= ", payment_date = CURDATE(), payment_amount = ?";
            $params[] = $_POST['payment_amount'] ?? 0;
        }
        if ($_POST['status'] === 'rejected') {
            $sql .= ", rejection_reason = ?";
            $params[] = $_POST['rejection_reason'] ?? '';
        }
        $sql .= " WHERE id = ?";
        $params[] = $_POST['claim_id'];
        $db->prepare($sql)->execute($params);
        flashMessage('Claim status updated');
    }
    header('Location: claims.php');
    exit;
}

$statusFilter = $_GET['status'] ?? '';
$where = ['1=1'];
$params = [];
if ($statusFilter) { $where[] = 'ic.status = ?'; $params[] = $statusFilter; }

$claims = $db->prepare("SELECT ic.*, ip.name as provider_name, c.name as customer_name, s.invoice_number FROM insurance_claims ic JOIN insurance_providers ip ON ic.insurance_provider_id = ip.id LEFT JOIN customers c ON ic.customer_id = c.id LEFT JOIN sales s ON ic.sale_id = s.id WHERE " . implode(' AND ', $where) . " ORDER BY ic.created_at DESC");
$claims->execute($params);
$claims = $claims->fetchAll();

$providers = $db->query("SELECT * FROM insurance_providers WHERE is_active = 1 ORDER BY name")->fetchAll();
$customers = $db->query("SELECT * FROM customers ORDER BY name")->fetchAll();

$stats = $db->query("SELECT status, COUNT(*) as count, COALESCE(SUM(total_amount),0) as total FROM insurance_claims GROUP BY status")->fetchAll();
$pendingTotal = 0; $paidTotal = 0;
foreach ($stats as $s) {
    if (in_array($s['status'], ['pending','submitted'])) $pendingTotal += $s['total'];
    if ($s['status'] === 'paid') $paidTotal += $s['total'];
}
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card warning"><div class="stat-label">Pending</div><div class="stat-value"><?= formatCurrency($pendingTotal) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card success"><div class="stat-label">Paid</div><div class="stat-value"><?= formatCurrency($paidTotal) ?></div></div></div>
    <div class="col-md-6 d-flex align-items-center justify-content-end">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newClaim"><i class="bi bi-plus me-1"></i>New Claim</button>
    </div>
</div>

<div class="mb-3">
    <div class="btn-group">
        <a href="?status=" class="btn btn-sm btn-<?= !$statusFilter ? 'primary' : 'outline-primary' ?>">All</a>
        <a href="?status=pending" class="btn btn-sm btn-<?= $statusFilter === 'pending' ? 'primary' : 'outline-primary' ?>">Pending</a>
        <a href="?status=submitted" class="btn btn-sm btn-<?= $statusFilter === 'submitted' ? 'primary' : 'outline-primary' ?>">Submitted</a>
        <a href="?status=approved" class="btn btn-sm btn-<?= $statusFilter === 'approved' ? 'primary' : 'outline-primary' ?>">Approved</a>
        <a href="?status=paid" class="btn btn-sm btn-<?= $statusFilter === 'paid' ? 'primary' : 'outline-primary' ?>">Paid</a>
        <a href="?status=rejected" class="btn btn-sm btn-<?= $statusFilter === 'rejected' ? 'primary' : 'outline-primary' ?>">Rejected</a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Claim #</th><th>Provider</th><th>Customer</th><th>Invoice</th><th>Date</th><th>Amount</th><th>Covered</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($claims as $cl): ?>
                <tr>
                    <td><strong><?= sanitize($cl['claim_number']) ?></strong></td>
                    <td><?= sanitize($cl['provider_name']) ?></td>
                    <td><?= sanitize($cl['customer_name'] ?? '-') ?></td>
                    <td><?= $cl['invoice_number'] ? "<a href='".BASE_URL."/modules/sales/view.php?id={$cl['sale_id']}'>{$cl['invoice_number']}</a>" : '-' ?></td>
                    <td><?= formatDate($cl['claim_date'], 'M d, Y') ?></td>
                    <td><?= formatCurrency($cl['total_amount']) ?></td>
                    <td><?= formatCurrency($cl['covered_amount']) ?></td>
                    <td>
                        <?php $colors = ['pending'=>'warning','submitted'=>'info','approved'=>'primary','rejected'=>'danger','paid'=>'success','partial'=>'secondary']; ?>
                        <span class="badge bg-<?= $colors[$cl['status']] ?? 'secondary' ?>"><?= ucfirst($cl['status']) ?></span>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#updateClaim<?= $cl['id'] ?>"><i class="bi bi-pencil"></i></button>
                    </td>
                </tr>
                <div class="modal fade" id="updateClaim<?= $cl['id'] ?>"><div class="modal-dialog"><div class="modal-content">
                    <form method="POST"><input type="hidden" name="claim_id" value="<?= $cl['id'] ?>">
                    <div class="modal-header"><h6 class="modal-title">Update Claim <?= sanitize($cl['claim_number']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-2"><label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <?php foreach (['pending','submitted','approved','rejected','paid','partial'] as $s): ?>
                                <option value="<?= $s ?>" <?= $cl['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2"><label class="form-label">Payment Amount (if paid)</label><input type="number" class="form-control" name="payment_amount" step="0.01" value="<?= $cl['covered_amount'] ?>"></div>
                        <div><label class="form-label">Rejection Reason (if rejected)</label><textarea class="form-control" name="rejection_reason" rows="2"><?= sanitize($cl['rejection_reason'] ?? '') ?></textarea></div>
                    </div>
                    <div class="modal-footer"><button type="submit" name="update_status" value="1" class="btn btn-primary">Update</button></div>
                    </form>
                </div></div></div>
                <?php endforeach; ?>
                <?php if (empty($claims)): ?><tr><td colspan="9" class="text-center text-muted py-3">No claims found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="newClaim"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title">New Insurance Claim</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-2"><select class="form-select" name="insurance_provider_id" required><option value="">Provider</option>
            <?php foreach ($providers as $p): ?><option value="<?= $p['id'] ?>" data-coverage="<?= $p['coverage_percentage'] ?>"><?= sanitize($p['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="mb-2"><select class="form-select" name="customer_id"><option value="">Customer (optional)</option>
            <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="mb-2"><input type="number" class="form-control" name="sale_id" placeholder="Sale ID (optional)"></div>
        <div class="mb-2"><input type="date" class="form-control" name="claim_date" value="<?= date('Y-m-d') ?>" required></div>
        <div class="mb-2"><input type="number" class="form-control" name="total_amount" step="0.01" placeholder="Total amount" required></div>
        <div class="mb-2"><input type="number" class="form-control" name="covered_amount" step="0.01" placeholder="Covered amount" required></div>
        <div class="mb-2"><input type="number" class="form-control" name="patient_copay" step="0.01" placeholder="Patient copay"></div>
        <div><textarea class="form-control" name="notes" placeholder="Notes" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="submit" name="create_claim" value="1" class="btn btn-primary">Create Claim</button></div>
    </form>
</div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
