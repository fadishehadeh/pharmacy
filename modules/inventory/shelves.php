<?php
$pageTitle = 'Shelves & Cabinets';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_cabinet'])) {
        $db->prepare("INSERT INTO cabinets (name, location, notes) VALUES (?,?,?)")->execute([
            $_POST['name'], $_POST['location'] ?: null, $_POST['notes'] ?: null
        ]);
        flashMessage('Cabinet added');
    } elseif (isset($_POST['add_shelf'])) {
        $db->prepare("INSERT INTO shelves (cabinet_id, shelf_number, label) VALUES (?,?,?)")->execute([
            $_POST['cabinet_id'], $_POST['shelf_number'], $_POST['label'] ?: null
        ]);
        flashMessage('Shelf added');
    }
    header('Location: shelves.php');
    exit;
}

if (isset($_GET['delete_cabinet'])) {
    $db->prepare("DELETE FROM cabinets WHERE id = ?")->execute([$_GET['delete_cabinet']]);
    flashMessage('Cabinet deleted');
    header('Location: shelves.php');
    exit;
}

if (isset($_GET['delete_shelf'])) {
    $db->prepare("DELETE FROM shelves WHERE id = ?")->execute([$_GET['delete_shelf']]);
    flashMessage('Shelf deleted');
    header('Location: shelves.php');
    exit;
}

$cabinets = $db->query("SELECT * FROM cabinets ORDER BY name")->fetchAll();
$shelves = $db->query("SELECT s.*, cab.name as cabinet_name, COUNT(m.id) as medicine_count FROM shelves s JOIN cabinets cab ON s.cabinet_id = cab.id LEFT JOIN medicines m ON s.id = m.shelf_id AND m.is_active = 1 GROUP BY s.id ORDER BY cab.name, s.shelf_number")->fetchAll();
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-plus-circle me-2"></i>Add Cabinet</h6>
            <form method="POST">
                <div class="mb-2"><input type="text" class="form-control" name="name" placeholder="Cabinet name (e.g. Cabinet A)" required></div>
                <div class="mb-2"><input type="text" class="form-control" name="location" placeholder="Location"></div>
                <div class="mb-3"><input type="text" class="form-control" name="notes" placeholder="Notes"></div>
                <button type="submit" name="add_cabinet" value="1" class="btn btn-primary w-100">Add Cabinet</button>
            </form>
        </div>
        <div class="card p-3">
            <h6><i class="bi bi-plus-circle me-2"></i>Add Shelf</h6>
            <form method="POST">
                <div class="mb-2">
                    <select class="form-select" name="cabinet_id" required>
                        <option value="">Select Cabinet</option>
                        <?php foreach ($cabinets as $cab): ?>
                        <option value="<?= $cab['id'] ?>"><?= sanitize($cab['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2"><input type="number" class="form-control" name="shelf_number" placeholder="Shelf number" min="1" required></div>
                <div class="mb-3"><input type="text" class="form-control" name="label" placeholder="Label (optional)"></div>
                <button type="submit" name="add_shelf" value="1" class="btn btn-primary w-100">Add Shelf</button>
            </form>
        </div>
    </div>
    <div class="col-lg-8">
        <?php foreach ($cabinets as $cab): ?>
        <div class="card p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><i class="bi bi-grid-3x3-gap me-2"></i><?= sanitize($cab['name']) ?></h6>
                <div>
                    <?php if ($cab['location']): ?><small class="text-muted me-2"><i class="bi bi-geo-alt"></i> <?= sanitize($cab['location']) ?></small><?php endif; ?>
                    <a href="?delete_cabinet=<?= $cab['id'] ?>" class="btn btn-sm btn-outline-danger" data-confirm="Delete this cabinet and all its shelves?"><i class="bi bi-trash"></i></a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Shelf #</th><th>Label</th><th>Medicines</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($shelves as $sh): ?>
                        <?php if ($sh['cabinet_id'] == $cab['id']): ?>
                        <tr>
                            <td>Shelf <?= $sh['shelf_number'] ?></td>
                            <td><?= sanitize($sh['label'] ?? '-') ?></td>
                            <td><a href="index.php?shelf=<?= $sh['id'] ?>"><?= $sh['medicine_count'] ?> items</a></td>
                            <td><a href="?delete_shelf=<?= $sh['id'] ?>" class="btn btn-sm btn-outline-danger" data-confirm="Delete this shelf?"><i class="bi bi-trash"></i></a></td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($cabinets)): ?>
        <div class="card p-5 text-center text-muted">
            <i class="bi bi-grid-3x3-gap display-4 mb-3"></i>
            <p>No cabinets yet. Add your first cabinet to start organizing shelves.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
