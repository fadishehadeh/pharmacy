<?php
$pageTitle = 'Customers';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add'])) {
        $db->prepare("INSERT INTO customers (name, phone, email, address, insurance_provider_id, insurance_number, notes) VALUES (?,?,?,?,?,?,?)")->execute([
            $_POST['name'], $_POST['phone'] ?: null, $_POST['email'] ?: null, $_POST['address'] ?: null,
            $_POST['insurance_provider_id'] ?: null, $_POST['insurance_number'] ?: null, $_POST['notes'] ?: null
        ]);
        flashMessage('Customer added');
    } elseif (isset($_POST['edit'])) {
        $db->prepare("UPDATE customers SET name=?, phone=?, email=?, address=?, insurance_provider_id=?, insurance_number=?, notes=? WHERE id=?")->execute([
            $_POST['name'], $_POST['phone'] ?: null, $_POST['email'] ?: null, $_POST['address'] ?: null,
            $_POST['insurance_provider_id'] ?: null, $_POST['insurance_number'] ?: null, $_POST['notes'] ?: null,
            $_POST['id']
        ]);
        flashMessage('Customer updated');
    }
    header('Location: customers.php');
    exit;
}

if (isset($_GET['delete'])) {
    $db->prepare("DELETE FROM customers WHERE id = ?")->execute([$_GET['delete']]);
    flashMessage('Customer deleted');
    header('Location: customers.php');
    exit;
}

$customers = $db->query("SELECT c.*, ip.name as insurance_name FROM customers c LEFT JOIN insurance_providers ip ON c.insurance_provider_id = ip.id ORDER BY c.name")->fetchAll();
$insuranceProviders = $db->query("SELECT * FROM insurance_providers WHERE is_active = 1 ORDER BY name")->fetchAll();
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-person-plus me-2"></i>Add Customer</h6>
            <form method="POST">
                <div class="mb-2"><input type="text" class="form-control" name="name" placeholder="Full name" required></div>
                <div class="mb-2"><input type="tel" class="form-control" name="phone" placeholder="Phone"></div>
                <div class="mb-2"><input type="email" class="form-control" name="email" placeholder="Email"></div>
                <div class="mb-2"><textarea class="form-control" name="address" placeholder="Address" rows="2"></textarea></div>
                <div class="mb-2">
                    <select class="form-select" name="insurance_provider_id">
                        <option value="">No Insurance</option>
                        <?php foreach ($insuranceProviders as $ip): ?>
                        <option value="<?= $ip['id'] ?>"><?= sanitize($ip['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2"><input type="text" class="form-control" name="insurance_number" placeholder="Insurance #"></div>
                <div class="mb-3"><input type="text" class="form-control" name="notes" placeholder="Notes"></div>
                <button type="submit" name="add" value="1" class="btn btn-primary w-100">Add Customer</button>
            </form>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table data-table table-hover mb-0">
                    <thead><tr><th>Name</th><th>Phone</th><th>Insurance</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($customers as $c): ?>
                        <tr>
                            <td><strong><?= sanitize($c['name']) ?></strong></td>
                            <td><?= sanitize($c['phone'] ?? '-') ?></td>
                            <td><?= sanitize($c['insurance_name'] ?? 'None') ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editCust<?= $c['id'] ?>"><i class="bi bi-pencil"></i></button>
                                <a href="?delete=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" data-confirm="Delete?"><i class="bi bi-trash"></i></a>
                            </td>
                        </tr>
                        <div class="modal fade" id="editCust<?= $c['id'] ?>"><div class="modal-dialog"><div class="modal-content">
                            <form method="POST"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <div class="modal-header"><h6 class="modal-title">Edit Customer</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body">
                                <div class="mb-2"><input type="text" class="form-control" name="name" value="<?= sanitize($c['name']) ?>" required></div>
                                <div class="mb-2"><input type="tel" class="form-control" name="phone" value="<?= sanitize($c['phone'] ?? '') ?>"></div>
                                <div class="mb-2"><input type="email" class="form-control" name="email" value="<?= sanitize($c['email'] ?? '') ?>"></div>
                                <div class="mb-2"><textarea class="form-control" name="address" rows="2"><?= sanitize($c['address'] ?? '') ?></textarea></div>
                                <div class="mb-2"><select class="form-select" name="insurance_provider_id"><option value="">None</option>
                                    <?php foreach ($insuranceProviders as $ip): ?><option value="<?= $ip['id'] ?>" <?= $c['insurance_provider_id'] == $ip['id'] ? 'selected' : '' ?>><?= sanitize($ip['name']) ?></option><?php endforeach; ?>
                                </select></div>
                                <div class="mb-2"><input type="text" class="form-control" name="insurance_number" value="<?= sanitize($c['insurance_number'] ?? '') ?>"></div>
                                <div><input type="text" class="form-control" name="notes" value="<?= sanitize($c['notes'] ?? '') ?>"></div>
                            </div>
                            <div class="modal-footer"><button type="submit" name="edit" value="1" class="btn btn-primary">Save</button></div>
                            </form>
                        </div></div></div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
