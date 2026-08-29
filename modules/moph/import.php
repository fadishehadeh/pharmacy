<?php
$pageTitle = 'Import MoPH Price List';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('admin')) { header('Location: ' . BASE_URL); exit; }
$db = getDB();

$importResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, 'r');
        if ($handle) {
            $header = fgetcsv($handle);
            if (!$header) {
                flashMessage('Empty CSV file', 'danger');
                header('Location: import.php');
                exit;
            }

            $header = array_map('strtolower', array_map('trim', $header));
            $nameCol = array_search('medicine_name', $header) !== false ? array_search('medicine_name', $header) : (array_search('name', $header) !== false ? array_search('name', $header) : 0);
            $barcodeCol = array_search('barcode', $header) !== false ? array_search('barcode', $header) : null;
            $priceUsdCol = array_search('public_price_usd', $header) !== false ? array_search('public_price_usd', $header) : (array_search('price_usd', $header) !== false ? array_search('price_usd', $header) : null);
            $priceLbpCol = array_search('public_price_lbp', $header) !== false ? array_search('public_price_lbp', $header) : (array_search('price_lbp', $header) !== false ? array_search('price_lbp', $header) : null);
            $hospitalCol = array_search('hospital_price_usd', $header) !== false ? array_search('hospital_price_usd', $header) : null;
            $agentCol = array_search('agent_name', $header) !== false ? array_search('agent_name', $header) : (array_search('agent', $header) !== false ? array_search('agent', $header) : null);
            $subsidyCol = array_search('is_subsidized', $header) !== false ? array_search('is_subsidized', $header) : (array_search('subsidized', $header) !== false ? array_search('subsidized', $header) : null);
            $catCol = array_search('subsidy_category', $header) !== false ? array_search('subsidy_category', $header) : null;

            $imported = 0;
            $updated = 0;
            $skipped = 0;

            $clearFirst = isset($_POST['clear_existing']);
            if ($clearFirst) {
                $db->exec("TRUNCATE TABLE moph_price_list");
            }

            $stmt = $db->prepare("INSERT INTO moph_price_list (medicine_name, barcode, public_price_usd, public_price_lbp, hospital_price_usd, agent_name, is_subsidized, subsidy_category, effective_date)
                VALUES (?,?,?,?,?,?,?,?,CURDATE())
                ON DUPLICATE KEY UPDATE public_price_usd = VALUES(public_price_usd), public_price_lbp = VALUES(public_price_lbp),
                hospital_price_usd = VALUES(hospital_price_usd), agent_name = VALUES(agent_name),
                is_subsidized = VALUES(is_subsidized), subsidy_category = VALUES(subsidy_category),
                effective_date = CURDATE()");

            $updateMed = isset($_POST['update_medicines']);

            while (($row = fgetcsv($handle)) !== false) {
                if (empty(trim($row[$nameCol] ?? ''))) { $skipped++; continue; }

                $name = trim($row[$nameCol]);
                $barcode = $barcodeCol !== null ? trim($row[$barcodeCol] ?? '') : null;
                $priceUsd = $priceUsdCol !== null ? floatval(str_replace(',', '', $row[$priceUsdCol] ?? '0')) : null;
                $priceLbp = $priceLbpCol !== null ? floatval(str_replace(',', '', $row[$priceLbpCol] ?? '0')) : null;
                $hospital = $hospitalCol !== null ? floatval(str_replace(',', '', $row[$hospitalCol] ?? '0')) : null;
                $agent = $agentCol !== null ? trim($row[$agentCol] ?? '') : null;
                $subsidized = $subsidyCol !== null ? (intval($row[$subsidyCol] ?? 0) ? 1 : 0) : 0;
                $subCat = $catCol !== null ? trim($row[$catCol] ?? '') : null;

                try {
                    $stmt->execute([$name, $barcode ?: null, $priceUsd, $priceLbp, $hospital, $agent, $subsidized, $subCat]);
                    if ($stmt->rowCount() > 1) { $updated++; } else { $imported++; }
                } catch (Exception $e) {
                    $skipped++;
                    continue;
                }

                if ($updateMed && $barcode) {
                    $db->prepare("UPDATE medicines SET moph_price = ?, is_subsidized = ? WHERE barcode = ?")
                        ->execute([$priceUsd, $subsidized, $barcode]);
                }
            }
            fclose($handle);

            addAuditLog('moph_import', 'moph_price_list', 0, null, ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped]);
            $importResult = ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped];
            flashMessage("Import complete: $imported new, $updated updated, $skipped skipped");
        }
    } else {
        flashMessage('Please upload a CSV file', 'danger');
    }
    if (!$importResult) { header('Location: import.php'); exit; }
}

$totalPrices = $db->query("SELECT COUNT(*) FROM moph_price_list")->fetchColumn();
$lastUpdate = $db->query("SELECT MAX(effective_date) FROM moph_price_list")->fetchColumn();
$subsidizedCount = $db->query("SELECT COUNT(*) FROM moph_price_list WHERE is_subsidized = 1")->fetchColumn();
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card"><div class="stat-label">Price List Entries</div><div class="stat-value"><?= number_format($totalPrices) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card info"><div class="stat-label">Subsidized Items</div><div class="stat-value"><?= number_format($subsidizedCount) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card success"><div class="stat-label">Last Updated</div><div class="stat-value"><?= $lastUpdate ? formatDate($lastUpdate, 'M d, Y') : 'Never' ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><a href="<?= BASE_URL ?>/modules/moph/price_list.php" class="btn btn-outline-primary w-100"><i class="bi bi-list me-1"></i>View Price List</a></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card p-3">
            <h6><i class="bi bi-upload me-2"></i>Import CSV File</h6>
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label">CSV File</label>
                    <input type="file" class="form-control" name="csv_file" accept=".csv,.txt" required>
                    <small class="text-muted">Upload MoPH price list in CSV format</small>
                </div>
                <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input" name="update_medicines" id="updateMeds" checked>
                    <label class="form-check-label" for="updateMeds">Update medicine MoPH prices (matched by barcode)</label>
                </div>
                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" name="clear_existing" id="clearExisting">
                    <label class="form-check-label text-danger" for="clearExisting">Clear existing price list before import</label>
                </div>
                <button type="submit" name="import_csv" value="1" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Import</button>
            </form>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-3">
            <h6><i class="bi bi-file-earmark-text me-2"></i>Expected CSV Format</h6>
            <p class="small text-muted mb-2">The CSV should have a header row. Recognized columns (case-insensitive):</p>
            <div class="table-responsive">
                <table class="table table-sm small mb-0">
                    <thead><tr><th>Column</th><th>Required</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>medicine_name</code> or <code>name</code></td><td>Yes</td><td>Medicine name</td></tr>
                        <tr><td><code>barcode</code></td><td>No</td><td>Product barcode</td></tr>
                        <tr><td><code>public_price_usd</code> or <code>price_usd</code></td><td>No</td><td>Public price in USD</td></tr>
                        <tr><td><code>public_price_lbp</code> or <code>price_lbp</code></td><td>No</td><td>Public price in LBP</td></tr>
                        <tr><td><code>hospital_price_usd</code></td><td>No</td><td>Hospital price in USD</td></tr>
                        <tr><td><code>agent_name</code> or <code>agent</code></td><td>No</td><td>Distributor/agent</td></tr>
                        <tr><td><code>is_subsidized</code> or <code>subsidized</code></td><td>No</td><td>1 or 0</td></tr>
                        <tr><td><code>subsidy_category</code></td><td>No</td><td>Subsidy category</td></tr>
                    </tbody>
                </table>
            </div>
            <?php if ($importResult): ?>
            <div class="alert alert-info mt-3 mb-0 small">
                <strong>Last Import Results:</strong><br>
                New: <?= $importResult['imported'] ?> | Updated: <?= $importResult['updated'] ?> | Skipped: <?= $importResult['skipped'] ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
