<?php
$pageTitle = 'Settings';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('admin')) { header('Location: ' . BASE_URL); exit; }
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_general'])) {
        $keys = ['pharmacy_name','pharmacy_name_ar','pharmacy_address','pharmacy_phone','pharmacy_email',
                 'pharmacy_license','pharmacist_name','pharmacist_license','exchange_rate','vat_rate',
                 'receipt_header','receipt_footer','low_stock_threshold','expiry_alert_days'];
        foreach ($keys as $key) {
            if (isset($_POST[$key])) updateSetting($key, $_POST[$key]);
        }
        flashMessage('Settings saved');
    } elseif (isset($_POST['add_user'])) {
        $existing = $db->prepare("SELECT id FROM users WHERE username = ?");
        $existing->execute([$_POST['username']]);
        if ($existing->fetch()) {
            flashMessage('Username already exists', 'danger');
        } else {
            $db->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?,?,?,?,?)")->execute([
                $_POST['username'], password_hash($_POST['password'], PASSWORD_BCRYPT),
                $_POST['full_name'], $_POST['email'] ?: null, $_POST['role']
            ]);
            flashMessage('User created');
        }
    } elseif (isset($_POST['edit_user'])) {
        $sql = "UPDATE users SET full_name = ?, email = ?, role = ?, is_active = ?";
        $params = [$_POST['full_name'], $_POST['email'] ?: null, $_POST['role'], isset($_POST['is_active']) ? 1 : 0];
        if (!empty($_POST['password'])) {
            $sql .= ", password = ?";
            $params[] = password_hash($_POST['password'], PASSWORD_BCRYPT);
        }
        $sql .= " WHERE id = ?";
        $params[] = $_POST['user_id'];
        $db->prepare($sql)->execute($params);
        flashMessage('User updated');
    } elseif (isset($_POST['delete_user'])) {
        if ($_POST['user_id'] != $_SESSION['user_id']) {
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$_POST['user_id']]);
            flashMessage('User deleted');
        } else {
            flashMessage('Cannot delete your own account', 'danger');
        }
    }
    header('Location: index.php');
    exit;
}

$users = $db->query("SELECT * FROM users ORDER BY username")->fetchAll();
$auditLogs = $db->query("SELECT al.*, u.username FROM audit_log al LEFT JOIN users u ON al.user_id = u.id ORDER BY al.created_at DESC LIMIT 50")->fetchAll();
?>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#general">General</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#users">Users</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#receipt">Receipt</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#audit">Audit Log</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/modules/settings/activity.php">User Activity</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/modules/settings/backup.php">Database Backup</a></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="general">
        <form method="POST">
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card p-3 mb-3">
                    <h6>Pharmacy Information</h6>
                    <div class="mb-2"><label class="form-label">Pharmacy Name</label>
                        <input type="text" class="form-control" name="pharmacy_name" value="<?= sanitize(getSetting('pharmacy_name', 'My Pharmacy')) ?>"></div>
                    <div class="mb-2"><label class="form-label">Arabic Name</label>
                        <input type="text" class="form-control" name="pharmacy_name_ar" value="<?= sanitize(getSetting('pharmacy_name_ar')) ?>" dir="rtl"></div>
                    <div class="mb-2"><label class="form-label">Address</label>
                        <textarea class="form-control" name="pharmacy_address" rows="2"><?= sanitize(getSetting('pharmacy_address')) ?></textarea></div>
                    <div class="row mb-2">
                        <div class="col"><label class="form-label">Phone</label>
                            <input type="tel" class="form-control" name="pharmacy_phone" value="<?= sanitize(getSetting('pharmacy_phone')) ?>"></div>
                        <div class="col"><label class="form-label">Email</label>
                            <input type="email" class="form-control" name="pharmacy_email" value="<?= sanitize(getSetting('pharmacy_email')) ?>"></div>
                    </div>
                    <div class="mb-2"><label class="form-label">MoPH License Number</label>
                        <input type="text" class="form-control" name="pharmacy_license" value="<?= sanitize(getSetting('pharmacy_license')) ?>"></div>
                    <div class="row">
                        <div class="col"><label class="form-label">Pharmacist Name</label>
                            <input type="text" class="form-control" name="pharmacist_name" value="<?= sanitize(getSetting('pharmacist_name')) ?>"></div>
                        <div class="col"><label class="form-label">Pharmacist License #</label>
                            <input type="text" class="form-control" name="pharmacist_license" value="<?= sanitize(getSetting('pharmacist_license')) ?>"></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card p-3 mb-3">
                    <h6>Financial Settings</h6>
                    <div class="mb-2"><label class="form-label">USD/LBP Exchange Rate</label>
                        <div class="input-group">
                            <span class="input-group-text">1 USD =</span>
                            <input type="number" class="form-control" name="exchange_rate" value="<?= getSetting('exchange_rate', '89500') ?>" step="100">
                            <span class="input-group-text">LBP</span>
                        </div>
                    </div>
                    <div class="mb-2"><label class="form-label">VAT Rate (%)</label>
                        <input type="number" class="form-control" name="vat_rate" value="<?= getSetting('vat_rate', '11') ?>" step="0.1"></div>
                </div>
                <div class="card p-3">
                    <h6>Alert Thresholds</h6>
                    <div class="mb-2"><label class="form-label">Low Stock Threshold (default)</label>
                        <input type="number" class="form-control" name="low_stock_threshold" value="<?= getSetting('low_stock_threshold', '10') ?>"></div>
                    <div><label class="form-label">Expiry Alert Days</label>
                        <input type="number" class="form-control" name="expiry_alert_days" value="<?= getSetting('expiry_alert_days', '90') ?>"></div>
                </div>
            </div>
        </div>
        <div class="mt-3"><button type="submit" name="save_general" value="1" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Settings</button></div>
        </form>
    </div>

    <div class="tab-pane fade" id="users">
        <div class="d-flex justify-content-end mb-3">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUser"><i class="bi bi-plus me-1"></i>Add User</button>
        </div>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Username</th><th>Full Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><strong><?= sanitize($u['username']) ?></strong></td>
                            <td><?= sanitize($u['full_name'] ?? '') ?></td>
                            <td><?= sanitize($u['email'] ?? '-') ?></td>
                            <td><span class="badge bg-<?= $u['role'] === 'admin' ? 'danger' : ($u['role'] === 'pharmacist' ? 'primary' : 'secondary') ?>"><?= ucfirst($u['role']) ?></span></td>
                            <td><span class="badge bg-<?= $u['is_active'] ? 'success' : 'secondary' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                            <td><?= $u['last_login'] ? formatDate($u['last_login'], 'M d, H:i') : 'Never' ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUser<?= $u['id'] ?>"><i class="bi bi-pencil"></i></button>
                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                <form method="POST" class="d-inline"><input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" name="delete_user" value="1" class="btn btn-sm btn-outline-danger" data-confirm="Delete this user?"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <div class="modal fade" id="editUser<?= $u['id'] ?>"><div class="modal-dialog"><div class="modal-content">
                            <form method="POST"><input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <div class="modal-header"><h6 class="modal-title">Edit User</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body">
                                <div class="mb-2"><label class="form-label">Full Name</label><input type="text" class="form-control" name="full_name" value="<?= sanitize($u['full_name'] ?? '') ?>"></div>
                                <div class="mb-2"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?= sanitize($u['email'] ?? '') ?>"></div>
                                <div class="mb-2"><label class="form-label">New Password (leave blank to keep)</label><input type="password" class="form-control" name="password"></div>
                                <div class="mb-2"><label class="form-label">Role</label>
                                    <select class="form-select" name="role">
                                        <?php foreach (['admin','pharmacist','cashier','viewer'] as $r): ?>
                                        <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-check"><input type="checkbox" class="form-check-input" name="is_active" <?= $u['is_active'] ? 'checked' : '' ?>><label class="form-check-label">Active</label></div>
                            </div>
                            <div class="modal-footer"><button type="submit" name="edit_user" value="1" class="btn btn-primary">Save</button></div>
                            </form>
                        </div></div></div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="receipt">
        <form method="POST">
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card p-3">
                    <h6>Receipt Customization</h6>
                    <div class="mb-2"><label class="form-label">Receipt Header</label>
                        <textarea class="form-control" name="receipt_header" rows="3" placeholder="Appears at top of receipt"><?= sanitize(getSetting('receipt_header')) ?></textarea></div>
                    <div><label class="form-label">Receipt Footer</label>
                        <textarea class="form-control" name="receipt_footer" rows="3" placeholder="Appears at bottom of receipt"><?= sanitize(getSetting('receipt_footer', 'Thank you for your purchase!')) ?></textarea></div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card p-3">
                    <h6>Preview</h6>
                    <div style="max-width:300px;margin:auto;font-family:monospace;font-size:12px;border:1px dashed #ccc;padding:15px">
                        <div class="text-center">
                            <strong><?= sanitize(getSetting('pharmacy_name', 'My Pharmacy')) ?></strong><br>
                            <small><?= sanitize(getSetting('pharmacy_address', 'Address')) ?></small><br>
                            <small>Tel: <?= sanitize(getSetting('pharmacy_phone', '00-000000')) ?></small><br>
                            <small>License: <?= sanitize(getSetting('pharmacy_license', 'XXXXX')) ?></small>
                        </div>
                        <hr style="border-style:dashed">
                        <small><?= nl2br(sanitize(getSetting('receipt_header'))) ?></small>
                        <div class="my-2">
                            <small>1x Sample Medicine ........... $5.00</small><br>
                            <small>2x Another Item .............. $3.00</small>
                        </div>
                        <hr style="border-style:dashed">
                        <small><strong>Total: $8.00</strong></small><br>
                        <small>LBP: <?= number_format(8 * intval(getSetting('exchange_rate', 89500)), 0, '.', ',') ?> L.L.</small>
                        <hr style="border-style:dashed">
                        <div class="text-center"><small><?= nl2br(sanitize(getSetting('receipt_footer', 'Thank you for your purchase!'))) ?></small></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-3"><button type="submit" name="save_general" value="1" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Receipt Settings</button></div>
        </form>
    </div>

    <div class="tab-pane fade" id="audit">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Table</th><th>Record</th><th>IP</th></tr></thead>
                    <tbody>
                        <?php foreach ($auditLogs as $log): ?>
                        <tr>
                            <td><small><?= formatDate($log['created_at'], 'M d, H:i') ?></small></td>
                            <td><?= sanitize($log['username'] ?? 'System') ?></td>
                            <td><span class="badge bg-secondary"><?= sanitize($log['action']) ?></span></td>
                            <td><?= sanitize($log['table_name']) ?></td>
                            <td>#<?= $log['record_id'] ?></td>
                            <td><small><?= sanitize($log['ip_address']) ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($auditLogs)): ?><tr><td colspan="6" class="text-center text-muted py-3">No audit logs yet</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addUser"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title">Add User</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-2"><input type="text" class="form-control" name="username" placeholder="Username" required></div>
        <div class="mb-2"><input type="password" class="form-control" name="password" placeholder="Password" required></div>
        <div class="mb-2"><input type="text" class="form-control" name="full_name" placeholder="Full Name"></div>
        <div class="mb-2"><input type="email" class="form-control" name="email" placeholder="Email"></div>
        <div><select class="form-select" name="role" required>
            <option value="">Select role</option>
            <option value="admin">Admin</option>
            <option value="pharmacist">Pharmacist</option>
            <option value="cashier">Cashier</option>
            <option value="viewer">Viewer</option>
        </select></div>
    </div>
    <div class="modal-footer"><button type="submit" name="add_user" value="1" class="btn btn-primary">Add User</button></div>
    </form>
</div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
