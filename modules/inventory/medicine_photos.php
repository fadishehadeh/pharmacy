<?php
$pageTitle = 'Medicine Photos';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) {
    flashMessage('Access denied', 'error');
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$db = getDB();

$uploadDir = __DIR__ . '/../../assets/uploads/medicines/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// POST handler: upload photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo'])) {
    $medId = intval($_POST['medicine_id']);
    $medicine = $db->prepare("SELECT id, name, image FROM medicines WHERE id = ?");
    $medicine->execute([$medId]);
    $medicine = $medicine->fetch();

    if (!$medicine) {
        flashMessage('Medicine not found', 'error');
        header('Location: medicine_photos.php');
        exit;
    }

    if (!empty($_FILES['photo']['name'])) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowedTypes = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($ext, $allowedTypes)) {
            flashMessage('Invalid file type. Only JPG, PNG, and WebP are allowed.', 'error');
            header('Location: medicine_photos.php');
            exit;
        }

        if ($_FILES['photo']['size'] > 500 * 1024) {
            flashMessage('File too large. Maximum size is 500KB.', 'error');
            header('Location: medicine_photos.php');
            exit;
        }

        if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            flashMessage('Upload error occurred', 'error');
            header('Location: medicine_photos.php');
            exit;
        }

        // Remove old photo if exists
        if ($medicine['image'] && file_exists($uploadDir . $medicine['image'])) {
            unlink($uploadDir . $medicine['image']);
        }
        // Also check old location (assets/uploads/)
        $oldUploadDir = __DIR__ . '/../../assets/uploads/';
        if ($medicine['image'] && file_exists($oldUploadDir . $medicine['image'])) {
            unlink($oldUploadDir . $medicine['image']);
        }

        $filename = 'med_' . $medId . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $filename)) {
            $db->prepare("UPDATE medicines SET image = ? WHERE id = ?")->execute(['medicines/' . $filename, $medId]);
            addAuditLog('update_photo', 'medicines', $medId, ['old_image' => $medicine['image']], ['new_image' => 'medicines/' . $filename]);
            flashMessage('Photo uploaded for ' . $medicine['name']);
        } else {
            flashMessage('Failed to save uploaded file', 'error');
        }
    } else {
        flashMessage('No file selected', 'error');
    }

    header('Location: medicine_photos.php' . (isset($_GET['category']) ? '?category=' . intval($_GET['category']) : ''));
    exit;
}

// POST handler: remove photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_photo'])) {
    $medId = intval($_POST['medicine_id']);
    $medicine = $db->prepare("SELECT id, name, image FROM medicines WHERE id = ?");
    $medicine->execute([$medId]);
    $medicine = $medicine->fetch();

    if ($medicine && $medicine['image']) {
        $fullPath = __DIR__ . '/../../assets/uploads/' . $medicine['image'];
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
        $db->prepare("UPDATE medicines SET image = NULL WHERE id = ?")->execute([$medId]);
        addAuditLog('remove_photo', 'medicines', $medId);
        flashMessage('Photo removed for ' . $medicine['name']);
    }

    header('Location: medicine_photos.php' . (isset($_GET['category']) ? '?category=' . intval($_GET['category']) : ''));
    exit;
}

// Filters
$categoryFilter = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';
$viewMode = $_GET['view'] ?? 'gallery';

$where = "WHERE m.is_active = 1";
$params = [];
if ($categoryFilter) {
    $where .= " AND m.category_id = ?";
    $params[] = intval($categoryFilter);
}
if ($search) {
    $where .= " AND (m.name LIKE ? OR m.generic_name LIKE ? OR m.barcode LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$medicines = $db->prepare("SELECT m.*, c.name as category_name
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    $where
    ORDER BY m.name ASC");
$medicines->execute($params);
$medicines = $medicines->fetchAll();

$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

$withPhotos = 0;
$withoutPhotos = 0;
foreach ($medicines as $m) {
    if (!empty($m['image'])) $withPhotos++;
    else $withoutPhotos++;
}
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card"><div class="stat-label">Total Medicines</div><div class="stat-value"><?= count($medicines) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card success"><div class="stat-label">With Photos</div><div class="stat-value"><?= $withPhotos ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card warning"><div class="stat-label">Without Photos</div><div class="stat-value"><?= $withoutPhotos ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card info"><div class="stat-label">Coverage</div><div class="stat-value"><?= count($medicines) > 0 ? round($withPhotos / count($medicines) * 100) : 0 ?>%</div></div></div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex gap-2 align-items-center">
            <form class="d-flex gap-2" method="GET">
                <select class="form-select form-select-sm" name="category" style="width:180px">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $categoryFilter == $cat['id'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" class="form-control form-control-sm" name="search" value="<?= sanitize($search) ?>" placeholder="Search medicine..." style="width:180px">
                <input type="hidden" name="view" value="<?= sanitize($viewMode) ?>">
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
            </form>
        </div>
        <div class="btn-group btn-group-sm no-print">
            <a href="?view=gallery<?= $categoryFilter ? '&category=' . intval($categoryFilter) : '' ?><?= $search ? '&search=' . urlencode($search) : '' ?>" class="btn btn-<?= $viewMode === 'gallery' ? 'primary' : 'outline-primary' ?>"><i class="bi bi-grid me-1"></i>Gallery</a>
            <a href="?view=bulk<?= $categoryFilter ? '&category=' . intval($categoryFilter) : '' ?><?= $search ? '&search=' . urlencode($search) : '' ?>" class="btn btn-<?= $viewMode === 'bulk' ? 'primary' : 'outline-primary' ?>"><i class="bi bi-list me-1"></i>Bulk</a>
        </div>
    </div>

    <?php if ($viewMode === 'gallery'): ?>
    <!-- Gallery View -->
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($medicines as $med): ?>
            <?php
                $imagePath = '';
                $hasImage = false;
                if (!empty($med['image'])) {
                    $testPath = __DIR__ . '/../../assets/uploads/' . $med['image'];
                    if (file_exists($testPath)) {
                        $imagePath = BASE_URL . '/assets/uploads/' . $med['image'];
                        $hasImage = true;
                    }
                }
            ?>
            <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                <div class="card h-100 position-relative">
                    <?php if ($hasImage): ?>
                    <img src="<?= sanitize($imagePath) ?>" class="card-img-top" style="height:140px;object-fit:cover;cursor:pointer" data-bs-toggle="modal" data-bs-target="#viewModal" onclick="showFullImage('<?= sanitize($imagePath) ?>', '<?= sanitize(addslashes($med['name'])) ?>')">
                    <?php else: ?>
                    <div class="card-img-top bg-light d-flex align-items-center justify-content-center" style="height:140px">
                        <i class="bi bi-capsule text-muted" style="font-size:2.5rem"></i>
                    </div>
                    <?php endif; ?>
                    <div class="card-body p-2">
                        <h6 class="card-title small mb-0" title="<?= sanitize($med['name']) ?>"><?= sanitize(mb_strimwidth($med['name'], 0, 30, '...')) ?></h6>
                        <small class="text-muted"><?= sanitize($med['category_name'] ?? 'Uncategorized') ?></small>
                        <div class="small fw-semibold text-primary"><?= formatCurrency($med['sell_price']) ?></div>
                    </div>
                    <div class="card-footer p-2 no-print">
                        <button class="btn btn-sm btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#uploadModal" onclick="setUploadMed(<?= $med['id'] ?>, '<?= sanitize(addslashes($med['name'])) ?>')">
                            <i class="bi bi-camera me-1"></i><?= $hasImage ? 'Change' : 'Add Photo' ?>
                        </button>
                        <?php if ($hasImage): ?>
                        <form method="POST" class="d-inline mt-1">
                            <input type="hidden" name="medicine_id" value="<?= $med['id'] ?>">
                            <button type="submit" name="remove_photo" value="1" class="btn btn-sm btn-outline-danger w-100 mt-1" onclick="return confirm('Remove photo for <?= sanitize(addslashes($med['name'])) ?>?')">
                                <i class="bi bi-trash me-1"></i>Remove
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($medicines)): ?>
            <div class="col-12 text-center text-muted py-5">
                <i class="bi bi-image" style="font-size:3rem"></i>
                <p class="mt-2">No medicines found matching your criteria.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php else: ?>
    <!-- Bulk View -->
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead>
                <tr>
                    <th style="width:60px">Photo</th>
                    <th>Medicine</th>
                    <th>Category</th>
                    <th>Form</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($medicines as $med): ?>
                <?php
                    $imagePath = '';
                    $hasImage = false;
                    if (!empty($med['image'])) {
                        $testPath = __DIR__ . '/../../assets/uploads/' . $med['image'];
                        if (file_exists($testPath)) {
                            $imagePath = BASE_URL . '/assets/uploads/' . $med['image'];
                            $hasImage = true;
                        }
                    }
                ?>
                <tr>
                    <td>
                        <?php if ($hasImage): ?>
                        <img src="<?= sanitize($imagePath) ?>" style="width:40px;height:40px;object-fit:cover;border-radius:4px;cursor:pointer" data-bs-toggle="modal" data-bs-target="#viewModal" onclick="showFullImage('<?= sanitize($imagePath) ?>', '<?= sanitize(addslashes($med['name'])) ?>')">
                        <?php else: ?>
                        <div class="bg-light d-flex align-items-center justify-content-center" style="width:40px;height:40px;border-radius:4px"><i class="bi bi-capsule text-muted"></i></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= sanitize($med['name']) ?></strong>
                        <?php if ($med['strength']): ?><br><small class="text-muted"><?= sanitize($med['strength']) ?></small><?php endif; ?>
                    </td>
                    <td><small><?= sanitize($med['category_name'] ?? '-') ?></small></td>
                    <td><small><?= ucfirst($med['form'] ?? '-') ?></small></td>
                    <td><?= formatCurrency($med['sell_price']) ?></td>
                    <td>
                        <?php if ($med['quantity_in_stock'] <= 0): ?>
                        <span class="badge bg-danger"><?= $med['quantity_in_stock'] ?></span>
                        <?php elseif ($med['quantity_in_stock'] <= $med['min_stock_level']): ?>
                        <span class="badge bg-warning"><?= $med['quantity_in_stock'] ?></span>
                        <?php else: ?>
                        <span class="badge bg-success"><?= $med['quantity_in_stock'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="no-print">
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadModal" onclick="setUploadMed(<?= $med['id'] ?>, '<?= sanitize(addslashes($med['name'])) ?>')">
                            <i class="bi bi-camera"></i>
                        </button>
                        <?php if ($hasImage): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="medicine_id" value="<?= $med['id'] ?>">
                            <button type="submit" name="remove_photo" value="1" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove?')"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($medicines)): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">No medicines found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal"><div class="modal-dialog"><div class="modal-content">
    <form method="POST" enctype="multipart/form-data">
    <div class="modal-header"><h6 class="modal-title"><i class="bi bi-camera me-2"></i>Upload Photo - <span id="uploadMedName"></span></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" name="medicine_id" id="uploadMedId">
        <div class="mb-3">
            <label class="form-label">Select Image</label>
            <input type="file" class="form-control" name="photo" accept="image/jpeg,image/png,image/webp" required>
            <div class="form-text">JPG, PNG, or WebP. Maximum 500KB.</div>
        </div>
        <div id="uploadPreview" class="text-center" style="display:none">
            <img id="previewUploadImg" style="max-width:100%;max-height:250px;border-radius:8px">
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="upload_photo" value="1" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload</button>
    </div>
    </form>
</div></div></div>

<!-- View Full Image Modal -->
<div class="modal fade" id="viewModal"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h6 class="modal-title" id="viewModalTitle"></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body text-center p-0">
        <img id="viewFullImage" style="max-width:100%;max-height:70vh">
    </div>
</div></div></div>

<?php
$extraScripts = <<<'SCRIPT'
<script>
function setUploadMed(id, name) {
    document.getElementById('uploadMedId').value = id;
    document.getElementById('uploadMedName').textContent = name;
    document.getElementById('uploadPreview').style.display = 'none';
}

function showFullImage(src, name) {
    document.getElementById('viewFullImage').src = src;
    document.getElementById('viewModalTitle').textContent = name;
}

document.querySelector('#uploadModal input[name="photo"]').addEventListener('change', function() {
    if (this.files && this.files[0]) {
        if (this.files[0].size > 500 * 1024) {
            alert('File too large. Maximum 500KB allowed.');
            this.value = '';
            return;
        }
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('previewUploadImg').src = e.target.result;
            document.getElementById('uploadPreview').style.display = 'block';
        };
        reader.readAsDataURL(this.files[0]);
    }
});
</script>
SCRIPT;
require_once __DIR__ . '/../../includes/footer.php';
?>
