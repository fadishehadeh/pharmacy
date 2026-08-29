<?php
$pageTitle = 'Medicine Alternatives';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_alternative'])) {
        $medA = intval($_POST['medicine_a']);
        $medB = intval($_POST['medicine_b']);
        $type = $_POST['type'];
        if ($medA && $medB && $medA !== $medB) {
            $exists = $db->prepare("SELECT id FROM medicine_alternatives WHERE (medicine_a_id = ? AND medicine_b_id = ?) OR (medicine_a_id = ? AND medicine_b_id = ?)");
            $exists->execute([$medA, $medB, $medB, $medA]);
            if (!$exists->fetch()) {
                $db->prepare("INSERT INTO medicine_alternatives (medicine_a_id, medicine_b_id, type, notes) VALUES (?,?,?,?)")
                    ->execute([$medA, $medB, $type, $_POST['notes'] ?? null]);
                flashMessage('Alternative link added');
            } else {
                flashMessage('This alternative link already exists', 'warning');
            }
        }
    } elseif (isset($_POST['delete_alt'])) {
        $db->prepare("DELETE FROM medicine_alternatives WHERE id = ?")->execute([intval($_POST['alt_id'])]);
        flashMessage('Alternative link removed');
    }
    header('Location: alternatives.php');
    exit;
}

$search = $_GET['search'] ?? '';
$where = "";
$params = [];
if ($search) {
    $where = "WHERE ma.name LIKE ? OR mb.name LIKE ? OR ma.generic_name LIKE ? OR mb.generic_name LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
}

$alternatives = $db->prepare("SELECT alt.*, ma.name as name_a, ma.generic_name as generic_a, ma.sell_price as price_a, ma.quantity_in_stock as stock_a,
    mb.name as name_b, mb.generic_name as generic_b, mb.sell_price as price_b, mb.quantity_in_stock as stock_b
    FROM medicine_alternatives alt
    JOIN medicines ma ON alt.medicine_a_id = ma.id
    JOIN medicines mb ON alt.medicine_b_id = mb.id
    $where ORDER BY ma.name");
$alternatives->execute($params);
$alternatives = $alternatives->fetchAll();

$medicines = $db->query("SELECT id, name, generic_name, sell_price, manufacturer FROM medicines WHERE is_active = 1 ORDER BY name")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>Medicine Alternatives & Substitutes</h6>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAlt"><i class="bi bi-plus me-1"></i>Link Alternative</button>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <form class="mb-3" method="GET">
                <div class="input-group">
                    <input type="text" class="form-control" name="search" value="<?= sanitize($search) ?>" placeholder="Search medicines...">
                    <button class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
                </div>
            </form>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Medicine A</th><th></th><th>Medicine B</th><th>Type</th><th>Price Diff</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($alternatives as $a): ?>
                        <tr>
                            <td>
                                <strong class="small"><?= sanitize($a['name_a']) ?></strong><br>
                                <small class="text-muted"><?= sanitize($a['generic_a'] ?? '') ?></small><br>
                                <small><?= formatCurrency($a['price_a']) ?> | Stock: <?= $a['stock_a'] ?></small>
                            </td>
                            <td class="text-center"><i class="bi bi-arrow-left-right text-primary"></i></td>
                            <td>
                                <strong class="small"><?= sanitize($a['name_b']) ?></strong><br>
                                <small class="text-muted"><?= sanitize($a['generic_b'] ?? '') ?></small><br>
                                <small><?= formatCurrency($a['price_b']) ?> | Stock: <?= $a['stock_b'] ?></small>
                            </td>
                            <td><span class="badge bg-<?= $a['type'] === 'generic' ? 'success' : ($a['type'] === 'therapeutic' ? 'info' : 'warning') ?>"><?= ucfirst($a['type']) ?></span></td>
                            <td>
                                <?php $diff = $a['price_b'] - $a['price_a']; ?>
                                <span class="text-<?= $diff > 0 ? 'danger' : ($diff < 0 ? 'success' : 'muted') ?>"><?= $diff > 0 ? '+' : '' ?><?= formatCurrency($diff) ?></span>
                            </td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="alt_id" value="<?= $a['id'] ?>">
                                    <button type="submit" name="delete_alt" value="1" class="btn btn-sm btn-outline-danger" data-confirm="Remove this link?"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($alternatives)): ?><tr><td colspan="6" class="text-center text-muted py-3">No alternatives linked yet</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-info-circle me-2"></i>About Alternatives</h6>
            <div class="small text-muted">
                <p><strong>Generic:</strong> Same active ingredient, different brand. Usually cheaper.</p>
                <p><strong>Therapeutic:</strong> Different molecule, same therapeutic effect.</p>
                <p><strong>Biosimilar:</strong> Similar biological product approved based on reference product.</p>
                <p class="mb-0">Link alternatives so staff can quickly suggest substitutes when a medicine is out of stock or the customer prefers a different price point.</p>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addAlt"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title">Link Alternative</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">Medicine A</label>
            <select class="form-select" name="medicine_a" required>
                <option value="">Select medicine...</option>
                <?php foreach ($medicines as $m): ?>
                <option value="<?= $m['id'] ?>"><?= sanitize($m['name']) ?> <?= $m['generic_name'] ? '(' . sanitize($m['generic_name']) . ')' : '' ?> - <?= sanitize($m['manufacturer'] ?? '') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Medicine B (Alternative)</label>
            <select class="form-select" name="medicine_b" required>
                <option value="">Select medicine...</option>
                <?php foreach ($medicines as $m): ?>
                <option value="<?= $m['id'] ?>"><?= sanitize($m['name']) ?> <?= $m['generic_name'] ? '(' . sanitize($m['generic_name']) . ')' : '' ?> - <?= sanitize($m['manufacturer'] ?? '') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Type</label>
            <select class="form-select" name="type" required>
                <option value="generic">Generic (same ingredient)</option>
                <option value="therapeutic">Therapeutic (same effect)</option>
                <option value="biosimilar">Biosimilar</option>
            </select>
        </div>
        <div class="mb-3"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="submit" name="add_alternative" value="1" class="btn btn-primary">Link</button></div>
    </form>
</div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
