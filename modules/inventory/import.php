<?php
$pageTitle = 'Import Medicines';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { header('Location: ' . BASE_URL); exit; }
$db = getDB();

$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$categoryMap = [];
foreach ($categories as $cat) {
    $categoryMap[strtolower(trim($cat['name']))] = $cat['id'];
}

$importResults = null;
$previewData = null;
$previewHeaders = null;

// Download sample CSV template
if (isset($_GET['template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="medicine_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'barcode', 'generic_name', 'category', 'cost_price', 'sell_price', 'quantity', 'expiry_date', 'manufacturer', 'form', 'strength']);
    fputcsv($out, ['Paracetamol 500mg', '6281000000001', 'Paracetamol', 'Pain Relief', '1.50', '3.00', '100', '2026-12-31', 'Pharma Co', 'tablet', '500mg']);
    fputcsv($out, ['Amoxicillin 250mg', '6281000000002', 'Amoxicillin', 'Antibiotics', '2.00', '5.50', '50', '2025-08-15', 'MedLab', 'capsule', '250mg']);
    fclose($out);
    exit;
}

// Preview CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview'])) {
    if (!empty($_FILES['csv_file']['tmp_name'])) {
        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $header = fgetcsv($file);
        if ($header) {
            // Normalize headers
            $header = array_map(function($h) {
                return strtolower(trim(str_replace([' ', '-'], '_', $h)));
            }, $header);
            $previewHeaders = $header;
            $previewData = [];
            $rowCount = 0;
            while (($row = fgetcsv($file)) !== false && $rowCount < 10) {
                if (count($row) >= count($header)) {
                    $previewData[] = array_combine($header, array_slice($row, 0, count($header)));
                } else {
                    $padded = array_pad($row, count($header), '');
                    $previewData[] = array_combine($header, $padded);
                }
                $rowCount++;
            }
            // Count total rows
            $totalRows = $rowCount;
            while (fgetcsv($file) !== false) {
                $totalRows++;
            }
        }
        fclose($file);

        // Save file temporarily for import
        $tmpPath = sys_get_temp_dir() . '/pharmacy_import_' . session_id() . '.csv';
        move_uploaded_file($_FILES['csv_file']['tmp_name'], $tmpPath);
        $_SESSION['import_tmp_file'] = $tmpPath;
        $_SESSION['import_total_rows'] = $totalRows ?? 0;
    } else {
        flashMessage('Please select a CSV file', 'error');
    }
}

// Process import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    $tmpPath = $_SESSION['import_tmp_file'] ?? '';
    $importMode = $_POST['import_mode'] ?? 'skip';

    if (!$tmpPath || !file_exists($tmpPath)) {
        flashMessage('Import file not found. Please upload again.', 'error');
    } else {
        $file = fopen($tmpPath, 'r');
        $header = fgetcsv($file);
        $header = array_map(function($h) {
            return strtolower(trim(str_replace([' ', '-'], '_', $h)));
        }, $header);

        // Map column indices
        $knownCols = ['name', 'barcode', 'generic_name', 'category', 'cost_price', 'sell_price', 'quantity', 'expiry_date', 'manufacturer', 'form', 'strength'];
        $colIndex = [];
        foreach ($knownCols as $col) {
            $idx = array_search($col, $header);
            $colIndex[$col] = $idx !== false ? $idx : -1;
        }

        $imported = 0;
        $skipped = 0;
        $updated = 0;
        $errors = [];
        $rowNum = 1;

        while (($row = fgetcsv($file)) !== false) {
            $rowNum++;
            $data = [];
            foreach ($knownCols as $col) {
                $data[$col] = ($colIndex[$col] >= 0 && isset($row[$colIndex[$col]])) ? trim($row[$colIndex[$col]]) : '';
            }

            // Validate required fields
            if (empty($data['name'])) {
                $errors[] = "Row $rowNum: Missing medicine name";
                continue;
            }
            if (empty($data['cost_price']) && empty($data['sell_price'])) {
                $errors[] = "Row $rowNum: At least one price (cost or sell) is required for '{$data['name']}'";
                continue;
            }

            // Match category
            $categoryId = null;
            if (!empty($data['category'])) {
                $catKey = strtolower(trim($data['category']));
                if (isset($categoryMap[$catKey])) {
                    $categoryId = $categoryMap[$catKey];
                }
            }

            // Check for existing by barcode
            $existing = null;
            if (!empty($data['barcode'])) {
                $stmt = $db->prepare("SELECT id FROM medicines WHERE barcode = ?");
                $stmt->execute([$data['barcode']]);
                $existing = $stmt->fetch();
            }

            if ($existing) {
                if ($importMode === 'skip') {
                    $skipped++;
                    continue;
                } elseif ($importMode === 'update') {
                    $db->prepare("UPDATE medicines SET name=?, generic_name=?, category_id=COALESCE(?,category_id), cost_price=?, sell_price=?, manufacturer=?, form=COALESCE(NULLIF(?,'''),form), strength=COALESCE(NULLIF(?,'''),strength), expiry_date=COALESCE(NULLIF(?,'''),expiry_date) WHERE id=?")->execute([
                        $data['name'],
                        $data['generic_name'] ?: null,
                        $categoryId,
                        floatval($data['cost_price']) ?: 0,
                        floatval($data['sell_price']) ?: 0,
                        $data['manufacturer'] ?: null,
                        $data['form'],
                        $data['strength'],
                        $data['expiry_date'],
                        $existing['id']
                    ]);

                    $qty = intval($data['quantity']);
                    if ($qty > 0) {
                        $db->prepare("UPDATE medicines SET quantity_in_stock = quantity_in_stock + ? WHERE id = ?")->execute([$qty, $existing['id']]);
                        addStockMovement($existing['id'], 'in', $qty, 'CSV import - stock update');
                    }

                    $updated++;
                    continue;
                } elseif ($importMode === 'new_only') {
                    $skipped++;
                    continue;
                }
            }

            // Insert new medicine
            try {
                $expiryDate = !empty($data['expiry_date']) ? date('Y-m-d', strtotime($data['expiry_date'])) : null;
                if ($expiryDate === '1970-01-01') $expiryDate = null;

                $db->prepare("INSERT INTO medicines (name, barcode, generic_name, category_id, cost_price, sell_price, quantity_in_stock, expiry_date, manufacturer, form, strength) VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute([
                    $data['name'],
                    $data['barcode'] ?: null,
                    $data['generic_name'] ?: null,
                    $categoryId,
                    floatval($data['cost_price']) ?: 0,
                    floatval($data['sell_price']) ?: 0,
                    intval($data['quantity']) ?: 0,
                    $expiryDate,
                    $data['manufacturer'] ?: null,
                    $data['form'] ?: 'tablet',
                    $data['strength'] ?: null
                ]);

                $medicineId = $db->lastInsertId();
                $qty = intval($data['quantity']);
                if ($qty > 0) {
                    addStockMovement($medicineId, 'in', $qty, 'CSV import - initial stock');
                }

                $imported++;
            } catch (Exception $e) {
                $errors[] = "Row $rowNum: Database error for '{$data['name']}' - " . $e->getMessage();
            }
        }

        fclose($file);
        @unlink($tmpPath);
        unset($_SESSION['import_tmp_file'], $_SESSION['import_total_rows']);

        addAuditLog('import', 'medicines', 0, null, ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped, 'errors' => count($errors)]);

        $importResults = [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }
}
?>

<div class="row g-3">
    <div class="col-lg-8">
        <!-- Import Results -->
        <?php if ($importResults): ?>
        <div class="card p-4 mb-3">
            <h6 class="mb-3"><i class="bi bi-clipboard-check me-2"></i>Import Results</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="card bg-success bg-opacity-10 p-3 text-center">
                        <div class="fs-3 fw-bold text-success"><?= $importResults['imported'] ?></div>
                        <small class="text-muted">Imported</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info bg-opacity-10 p-3 text-center">
                        <div class="fs-3 fw-bold text-info"><?= $importResults['updated'] ?></div>
                        <small class="text-muted">Updated</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning bg-opacity-10 p-3 text-center">
                        <div class="fs-3 fw-bold text-warning"><?= $importResults['skipped'] ?></div>
                        <small class="text-muted">Skipped</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-danger bg-opacity-10 p-3 text-center">
                        <div class="fs-3 fw-bold text-danger"><?= count($importResults['errors']) ?></div>
                        <small class="text-muted">Errors</small>
                    </div>
                </div>
            </div>

            <?php if (!empty($importResults['errors'])): ?>
            <div class="alert alert-danger">
                <h6 class="alert-heading"><i class="bi bi-exclamation-triangle me-1"></i>Errors</h6>
                <ul class="mb-0 small">
                    <?php foreach ($importResults['errors'] as $err): ?>
                    <li><?= sanitize($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="text-center">
                <a href="import.php" class="btn btn-primary"><i class="bi bi-arrow-clockwise me-1"></i>Import Another File</a>
                <a href="index.php" class="btn btn-outline-secondary ms-2"><i class="bi bi-box-seam me-1"></i>View Inventory</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Preview Table -->
        <?php if ($previewData && !$importResults): ?>
        <div class="card p-4 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0"><i class="bi bi-eye me-2"></i>Preview (first <?= count($previewData) ?> of <?= $_SESSION['import_total_rows'] ?? '?' ?> rows)</h6>
                <span class="badge bg-primary"><?= count($previewHeaders) ?> columns detected</span>
            </div>

            <div class="alert alert-info small py-2">
                <i class="bi bi-info-circle me-1"></i>Detected columns: <strong><?= sanitize(implode(', ', $previewHeaders)) ?></strong>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <?php foreach ($previewHeaders as $h): ?>
                            <th class="small"><?= sanitize(ucwords(str_replace('_', ' ', $h))) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewData as $i => $row): ?>
                        <tr>
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <?php foreach ($previewHeaders as $h): ?>
                            <td class="small"><?= sanitize($row[$h] ?? '') ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="POST" class="mt-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Duplicate Handling (by barcode)</label>
                        <select class="form-select" name="import_mode">
                            <option value="skip">Skip duplicates</option>
                            <option value="update">Update existing</option>
                            <option value="new_only">Create new only</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" name="import" value="1" class="btn btn-success">
                            <i class="bi bi-cloud-upload me-1"></i>Import <?= $_SESSION['import_total_rows'] ?? '' ?> Rows
                        </button>
                    </div>
                    <div class="col-md-4 text-end">
                        <a href="import.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Upload Form -->
        <?php if (!$previewData && !$importResults): ?>
        <div class="card p-4">
            <h6 class="mb-3"><i class="bi bi-cloud-arrow-up me-2"></i>Upload CSV File</h6>

            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label">Select CSV File <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" name="csv_file" accept=".csv" required>
                    <div class="form-text">Accepted format: .csv (comma-separated values)</div>
                </div>

                <div class="alert alert-light border small">
                    <h6 class="small fw-bold mb-2">Expected Columns</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <ul class="mb-0 ps-3">
                                <li><strong>name</strong> <span class="text-danger">*</span> - Medicine name</li>
                                <li><strong>barcode</strong> - Barcode/EAN</li>
                                <li><strong>generic_name</strong> - Generic/active ingredient</li>
                                <li><strong>category</strong> - Category name (matched to existing)</li>
                                <li><strong>cost_price</strong> <span class="text-danger">*</span> - Cost price (USD)</li>
                                <li><strong>sell_price</strong> <span class="text-danger">*</span> - Selling price (USD)</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="mb-0 ps-3">
                                <li><strong>quantity</strong> - Initial stock quantity</li>
                                <li><strong>expiry_date</strong> - Expiry date (YYYY-MM-DD)</li>
                                <li><strong>manufacturer</strong> - Manufacturer name</li>
                                <li><strong>form</strong> - tablet, capsule, syrup, etc.</li>
                                <li><strong>strength</strong> - e.g. 500mg, 250ml</li>
                            </ul>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2">* At least the name and one price are required per row</small>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" name="preview" value="1" class="btn btn-primary">
                        <i class="bi bi-eye me-1"></i>Preview & Validate
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-download me-2"></i>Sample Template</h6>
            <p class="small text-muted">Download a pre-formatted CSV template with sample data to see the expected format.</p>
            <a href="import.php?template=1" class="btn btn-outline-primary btn-sm w-100 no-print">
                <i class="bi bi-file-earmark-arrow-down me-1"></i>Download Sample CSV
            </a>
        </div>

        <div class="card p-3 mb-3">
            <h6><i class="bi bi-lightbulb me-2"></i>Import Tips</h6>
            <ul class="small text-muted mb-0 ps-3">
                <li class="mb-1">First row must be column headers</li>
                <li class="mb-1">Column names are auto-detected (case-insensitive)</li>
                <li class="mb-1">Category names are matched to existing categories</li>
                <li class="mb-1">Dates should be in YYYY-MM-DD format</li>
                <li class="mb-1">Prices should be in USD (numeric, no symbols)</li>
                <li class="mb-1">Duplicates are detected by barcode</li>
                <li class="mb-1">The preview shows the first 10 rows</li>
            </ul>
        </div>

        <div class="card p-3">
            <h6><i class="bi bi-tags me-2"></i>Available Categories</h6>
            <div class="d-flex flex-wrap gap-1">
                <?php foreach ($categories as $cat): ?>
                <span class="badge bg-light text-dark border"><?= sanitize($cat['name']) ?></span>
                <?php endforeach; ?>
                <?php if (empty($categories)): ?>
                <small class="text-muted">No categories found. Add categories in settings first.</small>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
