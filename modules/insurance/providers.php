<?php
$pageTitle = 'Insurance Providers';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add'])) {
        $db->prepare("INSERT INTO insurance_providers (name, type, contact_person, phone, email, address, coverage_percentage, payment_terms, contract_start, contract_end, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute([
            $_POST['name'], $_POST['type'], $_POST['contact_person'] ?: null, $_POST['phone'] ?: null,
            $_POST['email'] ?: null, $_POST['address'] ?: null, $_POST['coverage_percentage'] ?: 0,
            $_POST['payment_terms'] ?: null, $_POST['contract_start'] ?: null, $_POST['contract_end'] ?: null,
            $_POST['notes'] ?: null
        ]);
        flashMessage('Insurance provider added');
    } elseif (isset($_POST['edit'])) {
        $db->prepare("UPDATE insurance_providers SET name=?, type=?, contact_person=?, phone=?, email=?, address=?, coverage_percentage=?, payment_terms=?, contract_start=?, contract_end=?, is_active=?, notes=? WHERE id=?")->execute([
            $_POST['name'], $_POST['type'], $_POST['contact_person'] ?: null, $_POST['phone'] ?: null,
            $_POST['email'] ?: null, $_POST['address'] ?: null, $_POST['coverage_percentage'] ?: 0,
            $_POST['payment_terms'] ?: null, $_POST['contract_start'] ?: null, $_POST['contract_end'] ?: null,
            isset($_POST['is_active']) ? 1 : 0, $_POST['notes'] ?: null, $_POST['id']
        ]);
        flashMessage('Provider updated');
    }
    header('Location: providers.php');
    exit;
}

$providers = $db->query("SELECT ip.*, (SELECT COUNT(*) FROM insurance_claims WHERE insurance_provider_id = ip.id) as claim_count, (SELECT COALESCE(SUM(covered_amount),0) FROM insurance_claims WHERE insurance_provider_id = ip.id AND status = 'paid') as total_paid FROM insurance_providers ip ORDER BY ip.name")->fetchAll();
?>

<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProvider"><i class="bi bi-plus me-1"></i>Add Provider</button>
</div>

<div class="row g-3">
    <?php foreach ($providers as $p): ?>
    <div class="col-md-6 col-xl-4">
        <div class="card p-3">
            <div class="d-flex justify-content-between">
                <div>
                    <h6 class="mb-1"><?= sanitize($p['name']) ?></h6>
                    <span class="badge bg-primary"><?= strtoupper($p['type']) ?></span>
                    <span class="badge bg-<?= $p['is_active'] ? 'success' : 'secondary' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span>
                </div>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editProv<?= $p['id'] ?>"><i class="bi bi-pencil"></i></button>
            </div>
            <hr class="my-2">
            <div class="row small">
                <div class="col-6"><span class="text-muted">Coverage:</span> <?= $p['coverage_percentage'] ?>%</div>
                <div class="col-6"><span class="text-muted">Claims:</span> <?= $p['claim_count'] ?></div>
                <div class="col-6"><span class="text-muted">Total Paid:</span> <?= formatCurrency($p['total_paid']) ?></div>
                <?php if ($p['phone']): ?><div class="col-6"><span class="text-muted">Tel:</span> <?= sanitize($p['phone']) ?></div><?php endif; ?>
            </div>
            <?php if ($p['contract_end']): ?>
            <small class="text-muted mt-1">Contract: <?= formatDate($p['contract_start'], 'M Y') ?> - <?= formatDate($p['contract_end'], 'M Y') ?></small>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade" id="editProv<?= $p['id'] ?>"><div class="modal-dialog"><div class="modal-content">
        <form method="POST"><input type="hidden" name="id" value="<?= $p['id'] ?>">
        <div class="modal-header"><h6 class="modal-title">Edit Provider</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-2"><input type="text" class="form-control" name="name" value="<?= sanitize($p['name']) ?>" required></div>
            <div class="mb-2"><select class="form-select" name="type">
                <?php foreach (['NSSF','army','ISF','public_sector','private','cooperative','other'] as $t): ?>
                <option value="<?= $t ?>" <?= $p['type'] === $t ? 'selected' : '' ?>><?= strtoupper($t) ?></option>
                <?php endforeach; ?></select></div>
            <div class="mb-2"><input type="text" class="form-control" name="contact_person" value="<?= sanitize($p['contact_person'] ?? '') ?>" placeholder="Contact"></div>
            <div class="mb-2"><input type="tel" class="form-control" name="phone" value="<?= sanitize($p['phone'] ?? '') ?>" placeholder="Phone"></div>
            <div class="mb-2"><input type="email" class="form-control" name="email" value="<?= sanitize($p['email'] ?? '') ?>" placeholder="Email"></div>
            <div class="mb-2"><textarea class="form-control" name="address" rows="2"><?= sanitize($p['address'] ?? '') ?></textarea></div>
            <div class="mb-2"><input type="number" class="form-control" name="coverage_percentage" value="<?= $p['coverage_percentage'] ?>" step="0.01" placeholder="Coverage %"></div>
            <div class="mb-2"><input type="text" class="form-control" name="payment_terms" value="<?= sanitize($p['payment_terms'] ?? '') ?>" placeholder="Payment terms"></div>
            <div class="row mb-2"><div class="col"><input type="date" class="form-control" name="contract_start" value="<?= $p['contract_start'] ?? '' ?>"></div><div class="col"><input type="date" class="form-control" name="contract_end" value="<?= $p['contract_end'] ?? '' ?>"></div></div>
            <div class="mb-2"><textarea class="form-control" name="notes" rows="2"><?= sanitize($p['notes'] ?? '') ?></textarea></div>
            <div class="form-check"><input type="checkbox" class="form-check-input" name="is_active" <?= $p['is_active'] ? 'checked' : '' ?>><label class="form-check-label">Active</label></div>
        </div>
        <div class="modal-footer"><button type="submit" name="edit" value="1" class="btn btn-primary">Save</button></div>
        </form>
    </div></div></div>
    <?php endforeach; ?>
</div>

<div class="modal fade" id="addProvider"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title">Add Insurance Provider</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-2"><input type="text" class="form-control" name="name" placeholder="Provider name" required></div>
        <div class="mb-2"><select class="form-select" name="type" required>
            <option value="">Type</option>
            <option value="NSSF">NSSF</option><option value="army">Army</option><option value="ISF">ISF</option>
            <option value="public_sector">Public Sector</option><option value="private">Private</option>
            <option value="cooperative">Cooperative</option><option value="other">Other</option>
        </select></div>
        <div class="mb-2"><input type="text" class="form-control" name="contact_person" placeholder="Contact person"></div>
        <div class="mb-2"><input type="tel" class="form-control" name="phone" placeholder="Phone"></div>
        <div class="mb-2"><input type="email" class="form-control" name="email" placeholder="Email"></div>
        <div class="mb-2"><textarea class="form-control" name="address" placeholder="Address" rows="2"></textarea></div>
        <div class="mb-2"><input type="number" class="form-control" name="coverage_percentage" step="0.01" placeholder="Coverage %"></div>
        <div class="mb-2"><input type="text" class="form-control" name="payment_terms" placeholder="Payment terms"></div>
        <div class="row mb-2"><div class="col"><input type="date" class="form-control" name="contract_start" placeholder="Start"></div><div class="col"><input type="date" class="form-control" name="contract_end" placeholder="End"></div></div>
        <div><textarea class="form-control" name="notes" placeholder="Notes" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="submit" name="add" value="1" class="btn btn-primary">Add</button></div>
    </form>
</div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
