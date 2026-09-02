<?php
$pageTitle = 'Suppliers';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add'])) {
        $db->prepare("INSERT INTO suppliers (name, contact_person, phone, email, address, payment_terms, notes) VALUES (?,?,?,?,?,?,?)")->execute([
            $_POST['name'], $_POST['contact_person'] ?: null, $_POST['phone'] ?: null, $_POST['email'] ?: null,
            $_POST['address'] ?: null, $_POST['payment_terms'] ?: null, $_POST['notes'] ?: null
        ]);
        flashMessage('Supplier added');
    } elseif (isset($_POST['edit'])) {
        $db->prepare("UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=?, payment_terms=?, notes=?, is_active=? WHERE id=?")->execute([
            $_POST['name'], $_POST['contact_person'] ?: null, $_POST['phone'] ?: null, $_POST['email'] ?: null,
            $_POST['address'] ?: null, $_POST['payment_terms'] ?: null, $_POST['notes'] ?: null,
            isset($_POST['is_active']) ? 1 : 0, $_POST['id']
        ]);
        flashMessage('Supplier updated');
    }
    header('Location: index.php');
    exit;
}

$suppliers = $db->query("SELECT s.*, (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = s.id) as order_count, (SELECT COALESCE(SUM(total),0) FROM purchase_orders WHERE supplier_id = s.id AND status != 'cancelled') as total_purchases FROM suppliers s ORDER BY s.name")->fetchAll();
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-plus-circle me-2"></i>Add Supplier</h6>
            <form method="POST">
                <div class="mb-2"><input type="text" class="form-control" name="name" placeholder="Supplier name" required></div>
                <div class="mb-2"><input type="text" class="form-control" name="contact_person" placeholder="Contact person"></div>
                <div class="mb-2"><input type="tel" class="form-control" name="phone" placeholder="Phone"></div>
                <div class="mb-2"><input type="email" class="form-control" name="email" placeholder="Email"></div>
                <div class="mb-2"><textarea class="form-control" name="address" placeholder="Address" rows="2"></textarea></div>
                <div class="mb-2"><input type="text" class="form-control" name="payment_terms" placeholder="Payment terms"></div>
                <div class="mb-3"><input type="text" class="form-control" name="notes" placeholder="Notes"></div>
                <button type="submit" name="add" value="1" class="btn btn-primary w-100">Add Supplier</button>
            </form>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table data-table table-hover mb-0">
                    <thead><tr><th>Name</th><th>Contact</th><th>Phone</th><th>Orders</th><th>Total</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($suppliers as $s): ?>
                        <tr>
                            <td><strong><?= sanitize($s['name']) ?></strong></td>
                            <td><?= sanitize($s['contact_person'] ?? '-') ?></td>
                            <td><?= sanitize($s['phone'] ?? '-') ?></td>
                            <td><?= $s['order_count'] ?></td>
                            <td><?= formatCurrency($s['total_purchases']) ?></td>
                            <td><span class="badge bg-<?= $s['is_active'] ? 'success' : 'secondary' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                            <td><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editSup<?= $s['id'] ?>"><i class="bi bi-pencil"></i></button></td>
                        </tr>
                        <div class="modal fade" id="editSup<?= $s['id'] ?>"><div class="modal-dialog"><div class="modal-content">
                            <form method="POST"><input type="hidden" name="id" value="<?= $s['id'] ?>">
                            <div class="modal-header"><h6 class="modal-title">Edit Supplier</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body">
                                <div class="mb-2"><input type="text" class="form-control" name="name" value="<?= sanitize($s['name']) ?>" required></div>
                                <div class="mb-2"><input type="text" class="form-control" name="contact_person" value="<?= sanitize($s['contact_person'] ?? '') ?>"></div>
                                <div class="mb-2"><input type="tel" class="form-control" name="phone" value="<?= sanitize($s['phone'] ?? '') ?>"></div>
                                <div class="mb-2"><input type="email" class="form-control" name="email" value="<?= sanitize($s['email'] ?? '') ?>"></div>
                                <div class="mb-2"><textarea class="form-control" name="address" rows="2"><?= sanitize($s['address'] ?? '') ?></textarea></div>
                                <div class="mb-2"><input type="text" class="form-control" name="payment_terms" value="<?= sanitize($s['payment_terms'] ?? '') ?>"></div>
                                <div class="mb-2"><input type="text" class="form-control" name="notes" value="<?= sanitize($s['notes'] ?? '') ?>"></div>
                                <div class="form-check"><input type="checkbox" class="form-check-input" name="is_active" <?= $s['is_active'] ? 'checked' : '' ?>><label class="form-check-label">Active</label></div>
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
