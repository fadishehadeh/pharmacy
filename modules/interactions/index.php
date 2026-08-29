<?php
$pageTitle = 'Drug Interactions';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_interaction'])) {
    $db->prepare("INSERT INTO drug_interactions (drug_a, drug_b, severity, description, recommendation) VALUES (?,?,?,?,?)")->execute([
        $_POST['drug_a'], $_POST['drug_b'], $_POST['severity'], $_POST['description'], $_POST['recommendation'] ?: null
    ]);
    flashMessage('Drug interaction added');
    header('Location: index.php');
    exit;
}

$interactions = $db->query("SELECT * FROM drug_interactions ORDER BY FIELD(severity,'contraindicated','major','moderate','minor'), drug_a")->fetchAll();
$medicines = $db->query("SELECT id, name, generic_name FROM medicines WHERE is_active = 1 ORDER BY name")->fetchAll();
?>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <h6><i class="bi bi-shield-exclamation me-2"></i>Check Drug Interactions</h6>
            <p class="small text-muted">Enter 2 or more medicines to check for interactions</p>
            <div id="drugInputs">
                <div class="row g-2 mb-2">
                    <div class="col"><input type="text" class="form-control drug-input" placeholder="Medicine 1 (e.g. Warfarin)" list="drugList"></div>
                    <div class="col"><input type="text" class="form-control drug-input" placeholder="Medicine 2 (e.g. Aspirin)" list="drugList"></div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col"><input type="text" class="form-control drug-input" placeholder="Medicine 3 (optional)" list="drugList"></div>
                    <div class="col"><input type="text" class="form-control drug-input" placeholder="Medicine 4 (optional)" list="drugList"></div>
                </div>
            </div>
            <datalist id="drugList">
                <?php foreach ($medicines as $m): ?>
                <option value="<?= sanitize($m['name']) ?>">
                <?php if ($m['generic_name']): ?><option value="<?= sanitize($m['generic_name']) ?>"><?php endif; ?>
                <?php endforeach; ?>
                <?php
                $uniqueDrugs = $db->query("SELECT DISTINCT drug_a FROM drug_interactions UNION SELECT DISTINCT drug_b FROM drug_interactions ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($uniqueDrugs as $d): ?>
                <option value="<?= sanitize($d) ?>">
                <?php endforeach; ?>
            </datalist>
            <button type="button" class="btn btn-primary" id="btnCheckInteractions"><i class="bi bi-search me-1"></i>Check Interactions</button>
            <div id="interactionResults" class="mt-3"></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6>Quick Stats</h6>
            <?php
            $counts = ['contraindicated'=>0,'major'=>0,'moderate'=>0,'minor'=>0];
            foreach ($interactions as $i) $counts[$i['severity']]++;
            ?>
            <div class="d-flex justify-content-between mb-1"><span class="text-danger">Contraindicated</span><strong><?= $counts['contraindicated'] ?></strong></div>
            <div class="d-flex justify-content-between mb-1"><span class="text-danger">Major</span><strong><?= $counts['major'] ?></strong></div>
            <div class="d-flex justify-content-between mb-1"><span class="text-warning">Moderate</span><strong><?= $counts['moderate'] ?></strong></div>
            <div class="d-flex justify-content-between"><span class="text-info">Minor</span><strong><?= $counts['minor'] ?></strong></div>
            <hr>
            <button class="btn btn-sm btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#addInteraction"><i class="bi bi-plus me-1"></i>Add Interaction</button>
        </div>
    </div>
</div>

<div class="card">
    <div class="p-3 border-bottom"><h6 class="mb-0">Interaction Database (<?= count($interactions) ?> entries)</h6></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead><tr><th>Drug A</th><th>Drug B</th><th>Severity</th><th>Description</th><th>Recommendation</th></tr></thead>
            <tbody>
                <?php foreach ($interactions as $i): ?>
                <tr>
                    <td><strong><?= sanitize($i['drug_a']) ?></strong></td>
                    <td><strong><?= sanitize($i['drug_b']) ?></strong></td>
                    <td>
                        <?php $colors = ['contraindicated'=>'danger','major'=>'danger','moderate'=>'warning','minor'=>'info']; ?>
                        <span class="badge bg-<?= $colors[$i['severity']] ?? 'secondary' ?>"><?= strtoupper($i['severity']) ?></span>
                    </td>
                    <td><small><?= sanitize($i['description']) ?></small></td>
                    <td><small class="text-primary"><?= sanitize($i['recommendation'] ?? '') ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addInteraction"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title">Add Drug Interaction</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-2"><label class="form-label">Drug A</label><input type="text" class="form-control" name="drug_a" required></div>
        <div class="mb-2"><label class="form-label">Drug B</label><input type="text" class="form-control" name="drug_b" required></div>
        <div class="mb-2"><label class="form-label">Severity</label>
            <select class="form-select" name="severity" required>
                <option value="minor">Minor</option>
                <option value="moderate">Moderate</option>
                <option value="major">Major</option>
                <option value="contraindicated">Contraindicated</option>
            </select></div>
        <div class="mb-2"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3" required></textarea></div>
        <div><label class="form-label">Recommendation</label><textarea class="form-control" name="recommendation" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="submit" name="add_interaction" value="1" class="btn btn-primary">Add</button></div>
    </form>
</div></div></div>

<?php
$extraScripts = <<<'SCRIPT'
<script>
document.getElementById('btnCheckInteractions').addEventListener('click', function() {
    var drugs = [];
    document.querySelectorAll('.drug-input').forEach(function(input) {
        var val = input.value.trim();
        if (val) drugs.push(val);
    });
    if (drugs.length < 2) {
        document.getElementById('interactionResults').innerHTML = '<div class="alert alert-info small">Enter at least 2 medicines to check.</div>';
        return;
    }

    var resultsDiv = document.getElementById('interactionResults');
    resultsDiv.innerHTML = '<div class="spinner-border spinner-border-sm text-primary"></div> Checking...';

    fetch(BASE_URL + '/api/barcode.php?action=interactions&medicines=' + encodeURIComponent(drugs.join(',')))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.interactions.length === 0) {
                resultsDiv.innerHTML = '<div class="alert alert-success small"><i class="bi bi-check-circle me-1"></i>No known interactions found between these medicines.</div>';
                return;
            }
            var html = '<div class="alert alert-danger"><h6><i class="bi bi-exclamation-triangle-fill me-2"></i>' + data.interactions.length + ' Interaction(s) Found</h6>';
            var colors = {contraindicated:'danger',major:'danger',moderate:'warning',minor:'info'};
            data.interactions.forEach(function(i) {
                html += '<div class="mb-2 p-2 bg-white rounded">';
                html += '<span class="badge bg-' + (colors[i.severity]||'secondary') + '">' + i.severity.toUpperCase() + '</span> ';
                html += '<strong>' + i.drug_a + ' + ' + i.drug_b + '</strong><br>';
                html += '<small>' + i.description + '</small>';
                if (i.recommendation) html += '<br><small class="text-primary"><i class="bi bi-lightbulb me-1"></i>' + i.recommendation + '</small>';
                html += '</div>';
            });
            html += '</div>';
            resultsDiv.innerHTML = html;
        });
});

var BASE_URL = '<?= BASE_URL ?>';
</script>
SCRIPT;
require_once __DIR__ . '/../../includes/footer.php';
?>
