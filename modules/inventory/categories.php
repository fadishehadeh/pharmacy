<?php
$pageTitle = 'Categories';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add'])) {
        $db->prepare("INSERT INTO categories (name, name_ar, description, color) VALUES (?,?,?,?)")->execute([
            $_POST['name'], $_POST['name_ar'] ?: null, $_POST['description'] ?: null, $_POST['color'] ?: '#3B82F6'
        ]);
        flashMessage('Category added');
    } elseif (isset($_POST['edit'])) {
        $db->prepare("UPDATE categories SET name=?, name_ar=?, description=?, color=? WHERE id=?")->execute([
            $_POST['name'], $_POST['name_ar'] ?: null, $_POST['description'] ?: null, $_POST['color'] ?: '#3B82F6', $_POST['id']
        ]);
        flashMessage('Category updated');
    }
    header('Location: categories.php');
    exit;
}

if (isset($_GET['delete'])) {
    $db->prepare("DELETE FROM categories WHERE id = ?")->execute([$_GET['delete']]);
    flashMessage('Category deleted');
    header('Location: categories.php');
    exit;
}

$categories = $db->query("SELECT c.*, COUNT(m.id) as medicine_count FROM categories c LEFT JOIN medicines m ON c.id = m.category_id AND m.is_active = 1 GROUP BY c.id ORDER BY c.name")->fetchAll();
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-plus-circle me-2"></i>Add Category</h6>
            <form method="POST">
                <div class="mb-2"><input type="text" class="form-control" name="name" placeholder="Category name" required></div>
                <div class="mb-2"><input type="text" class="form-control" name="name_ar" placeholder="Arabic name" dir="rtl"></div>
                <div class="mb-2"><input type="text" class="form-control" name="description" placeholder="Description"></div>
                <div class="mb-3"><input type="color" class="form-control form-control-color" name="color" value="#3B82F6"></div>
                <button type="submit" name="add" value="1" class="btn btn-primary w-100">Add Category</button>
            </form>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Color</th><th>Name</th><th>Arabic</th><th>Medicines</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><span style="display:inline-block;width:20px;height:20px;border-radius:4px;background:<?= $cat['color'] ?>"></span></td>
                            <td><strong><?= sanitize($cat['name']) ?></strong></td>
                            <td dir="rtl"><?= sanitize($cat['name_ar'] ?? '') ?></td>
                            <td><span class="badge bg-secondary"><?= $cat['medicine_count'] ?></span></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editCat<?= $cat['id'] ?>"><i class="bi bi-pencil"></i></button>
                                <?php if ($cat['medicine_count'] == 0): ?>
                                <a href="?delete=<?= $cat['id'] ?>" class="btn btn-sm btn-outline-danger" data-confirm="Delete this category?"><i class="bi bi-trash"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <div class="modal fade" id="editCat<?= $cat['id'] ?>"><div class="modal-dialog"><div class="modal-content">
                            <form method="POST"><input type="hidden" name="id" value="<?= $cat['id'] ?>">
                            <div class="modal-header"><h6 class="modal-title">Edit Category</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body">
                                <div class="mb-2"><input type="text" class="form-control" name="name" value="<?= sanitize($cat['name']) ?>" required></div>
                                <div class="mb-2"><input type="text" class="form-control" name="name_ar" value="<?= sanitize($cat['name_ar'] ?? '') ?>" dir="rtl"></div>
                                <div class="mb-2"><input type="text" class="form-control" name="description" value="<?= sanitize($cat['description'] ?? '') ?>"></div>
                                <div><input type="color" class="form-control form-control-color" name="color" value="<?= $cat['color'] ?>"></div>
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
