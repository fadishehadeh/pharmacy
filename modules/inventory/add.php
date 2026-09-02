<?php
$pageTitle = 'Add Medicine';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }

$db = getDB();
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$shelves = $db->query("SELECT s.*, cab.name as cabinet_name FROM shelves s JOIN cabinets cab ON s.cabinet_id = cab.id ORDER BY cab.name, s.shelf_number")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("INSERT INTO medicines (barcode, name, name_ar, generic_name, strength, form, category_id, shelf_id, manufacturer, country_of_origin, requires_prescription, is_controlled, controlled_schedule, is_subsidized, subsidy_percentage, unit, units_per_box, cost_price, sell_price, moph_price, quantity_in_stock, min_stock_level, max_stock_level, expiry_date, batch_number, storage_conditions, image, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $categoryId = $_POST['category_id'] ?: null;
    $shelfId = $_POST['shelf_id'] ?: null;
    $expiryDate = $_POST['expiry_date'] ?: null;
    $mophPrice = $_POST['moph_price'] ?: null;

    $imageName = null;
    if (!empty($_FILES['product_image']['name'])) {
        $ext = strtolower(pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp'])) {
            $imageName = 'med_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
            $uploadDir = __DIR__ . '/../../assets/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            move_uploaded_file($_FILES['product_image']['tmp_name'], $uploadDir . $imageName);
        }
    }

    $stmt->execute([
        $_POST['barcode'] ?: null,
        $_POST['name'],
        $_POST['name_ar'] ?: null,
        $_POST['generic_name'] ?: null,
        $_POST['strength'] ?: null,
        $_POST['form'],
        $categoryId,
        $shelfId,
        $_POST['manufacturer'] ?: null,
        $_POST['country_of_origin'] ?: null,
        isset($_POST['requires_prescription']) ? 1 : 0,
        isset($_POST['is_controlled']) ? 1 : 0,
        $_POST['controlled_schedule'] ?: null,
        isset($_POST['is_subsidized']) ? 1 : 0,
        $_POST['subsidy_percentage'] ?: 0,
        $_POST['unit'] ?: 'box',
        $_POST['units_per_box'] ?: 1,
        $_POST['cost_price'] ?: 0,
        $_POST['sell_price'] ?: 0,
        $mophPrice,
        $_POST['quantity_in_stock'] ?: 0,
        $_POST['min_stock_level'] ?: 5,
        $_POST['max_stock_level'] ?: 100,
        $expiryDate,
        $_POST['batch_number'] ?: null,
        $_POST['storage_conditions'] ?: null,
        $imageName,
        $_POST['notes'] ?: null,
    ]);

    $medicineId = $db->lastInsertId();
    $qty = intval($_POST['quantity_in_stock'] ?? 0);
    if ($qty > 0) {
        addStockMovement($medicineId, 'in', $qty, 'Initial stock');
    }

    if (!empty($_POST['captured_image_data'])) {
        $imageData = $_POST['captured_image_data'];
        if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $type)) {
            $ext = strtolower($type[1]);
            $imageData = substr($imageData, strpos($imageData, ',') + 1);
            $imageData = base64_decode($imageData);
            if ($imageData !== false) {
                $filename = 'med_' . $medicineId . '_' . time() . '.' . $ext;
                $uploadDir = __DIR__ . '/../../assets/uploads/';
                file_put_contents($uploadDir . $filename, $imageData);
                $db->prepare("UPDATE medicines SET image = ? WHERE id = ?")->execute([$filename, $medicineId]);
            }
        }
    }

    addAuditLog('create', 'medicines', $medicineId);
    flashMessage('Medicine added successfully');
    if (isset($_POST['save_and_new'])) {
        header('Location: add.php');
    } else {
        header('Location: index.php');
    }
    exit;
}
?>

<div class="card p-4">
    <form method="POST" enctype="multipart/form-data">
        <div class="row g-3">
            <div class="col-12"><h6 class="text-primary border-bottom pb-2"><i class="bi bi-upc-scan me-2"></i>Barcode Scanner</h6></div>

            <div class="col-md-6">
                <div class="card bg-light p-3">
                    <div class="d-flex gap-2 mb-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btnStartScanner"><i class="bi bi-camera me-1"></i>Scan Barcode</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnStopScanner"><i class="bi bi-stop-circle me-1"></i>Stop</button>
                    </div>
                    <div id="scannerContainer" class="d-none position-relative" style="max-width:400px">
                        <video id="scannerVideo" style="width:100%;border-radius:8px"></video>
                        <div style="position:absolute;top:50%;left:10%;right:10%;height:2px;background:red;opacity:0.7"></div>
                    </div>
                    <div id="scanResult" class="mt-2"></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-light p-3">
                    <label class="form-label fw-semibold"><i class="bi bi-camera me-1"></i>Product Photo</label>
                    <div class="mb-2">
                        <input type="file" class="form-control form-control-sm" name="product_image" accept="image/*" id="fileImageInput">
                    </div>
                    <div class="d-flex gap-2 mb-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btnCapturePhoto"><i class="bi bi-camera-fill me-1"></i>Take Photo</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnStopCapture"><i class="bi bi-stop-circle me-1"></i>Stop</button>
                    </div>
                    <div id="captureContainer" class="d-none" style="max-width:300px">
                        <video id="captureVideo" style="width:100%;border-radius:8px"></video>
                        <button type="button" class="btn btn-sm btn-success mt-1" id="btnSnap"><i class="bi bi-camera me-1"></i>Capture</button>
                    </div>
                    <div id="photoPreview" class="mt-2">
                        <canvas id="captureCanvas" class="d-none"></canvas>
                        <img id="previewImg" class="d-none" style="max-width:200px;border-radius:8px;border:2px solid #dee2e6">
                    </div>
                    <input type="hidden" name="captured_image_data" id="capturedImageData">
                </div>
            </div>

            <div class="col-12"><h6 class="text-primary border-bottom pb-2"><i class="bi bi-info-circle me-2"></i>Basic Information</h6></div>

            <div class="col-md-4">
                <label class="form-label">Medicine Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="name" id="medName" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Name (Arabic)</label>
                <input type="text" class="form-control" name="name_ar" id="medNameAr" dir="rtl">
            </div>
            <div class="col-md-4">
                <label class="form-label">Generic Name</label>
                <input type="text" class="form-control" name="generic_name" id="medGenericName">
            </div>
            <div class="col-md-3">
                <label class="form-label">Barcode</label>
                <div class="input-group">
                    <input type="text" class="form-control" name="barcode" id="barcodeInput">
                    <button type="button" class="btn btn-outline-secondary" id="btnLookupBarcode" title="Lookup barcode"><i class="bi bi-search"></i></button>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Strength/Dosage</label>
                <input type="text" class="form-control" name="strength" id="medStrength" placeholder="e.g. 500mg">
            </div>
            <div class="col-md-3">
                <label class="form-label">Form</label>
                <select class="form-select" name="form" id="medForm">
                    <option value="tablet">Tablet</option>
                    <option value="capsule">Capsule</option>
                    <option value="syrup">Syrup</option>
                    <option value="injection">Injection</option>
                    <option value="cream">Cream</option>
                    <option value="ointment">Ointment</option>
                    <option value="drops">Drops</option>
                    <option value="inhaler">Inhaler</option>
                    <option value="suppository">Suppository</option>
                    <option value="powder">Powder</option>
                    <option value="gel">Gel</option>
                    <option value="spray">Spray</option>
                    <option value="patch">Patch</option>
                    <option value="solution">Solution</option>
                    <option value="suspension">Suspension</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Category</label>
                <select class="form-select" name="category_id">
                    <option value="">-- Select --</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= sanitize($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12"><h6 class="text-primary border-bottom pb-2 mt-3"><i class="bi bi-geo-alt me-2"></i>Location & Manufacturer</h6></div>

            <div class="col-md-4">
                <label class="form-label">Shelf Location</label>
                <select class="form-select" name="shelf_id">
                    <option value="">-- Select --</option>
                    <?php foreach ($shelves as $sh): ?>
                    <option value="<?= $sh['id'] ?>"><?= sanitize($sh['cabinet_name']) ?> - Shelf <?= $sh['shelf_number'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Manufacturer</label>
                <input type="text" class="form-control" name="manufacturer" id="medManufacturer">
            </div>
            <div class="col-md-4">
                <label class="form-label">Country of Origin</label>
                <input type="text" class="form-control" name="country_of_origin">
            </div>

            <div class="col-12"><h6 class="text-primary border-bottom pb-2 mt-3"><i class="bi bi-currency-dollar me-2"></i>Pricing & Stock</h6></div>

            <div class="col-md-2">
                <label class="form-label">Cost Price ($)</label>
                <input type="number" class="form-control" name="cost_price" id="medCostPrice" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">Sell Price ($)</label>
                <input type="number" class="form-control" name="sell_price" id="medSellPrice" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">MoPH Price ($)</label>
                <input type="number" class="form-control" name="moph_price" step="0.01" min="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">Current Stock</label>
                <input type="number" class="form-control" name="quantity_in_stock" min="0" value="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">Min Stock Level</label>
                <input type="number" class="form-control" name="min_stock_level" min="0" value="5">
            </div>
            <div class="col-md-2">
                <label class="form-label">Max Stock Level</label>
                <input type="number" class="form-control" name="max_stock_level" min="0" value="100">
            </div>
            <div class="col-md-2">
                <label class="form-label">Unit</label>
                <input type="text" class="form-control" name="unit" value="box">
            </div>
            <div class="col-md-2">
                <label class="form-label">Units per Box</label>
                <input type="number" class="form-control" name="units_per_box" min="1" value="1">
            </div>

            <div class="col-12"><h6 class="text-primary border-bottom pb-2 mt-3"><i class="bi bi-calendar me-2"></i>Batch & Expiry</h6></div>

            <div class="col-md-3">
                <label class="form-label">Batch Number</label>
                <input type="text" class="form-control" name="batch_number">
            </div>
            <div class="col-md-3">
                <label class="form-label">Expiry Date</label>
                <input type="date" class="form-control" name="expiry_date">
            </div>
            <div class="col-md-3">
                <label class="form-label">Storage Conditions</label>
                <input type="text" class="form-control" name="storage_conditions" placeholder="e.g. Room temp, 2-8°C">
            </div>

            <div class="col-12"><h6 class="text-primary border-bottom pb-2 mt-3"><i class="bi bi-shield me-2"></i>Regulations</h6></div>

            <div class="col-md-3">
                <div class="form-check mt-2">
                    <input type="checkbox" class="form-check-input" name="requires_prescription" id="rxCheck">
                    <label class="form-check-label" for="rxCheck">Requires Prescription</label>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-check mt-2">
                    <input type="checkbox" class="form-check-input" name="is_controlled" id="controlledCheck">
                    <label class="form-check-label" for="controlledCheck">Controlled Substance</label>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Schedule</label>
                <input type="text" class="form-control" name="controlled_schedule" placeholder="e.g. II, III, IV">
            </div>
            <div class="col-md-3">
                <div class="form-check mt-2">
                    <input type="checkbox" class="form-check-input" name="is_subsidized" id="subsidyCheck">
                    <label class="form-check-label" for="subsidyCheck">Subsidized</label>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Subsidy %</label>
                <input type="number" class="form-control" name="subsidy_percentage" step="0.01" min="0" max="100" value="0">
            </div>

            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="2"></textarea>
            </div>

            <div class="col-12 d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Medicine</button>
                <button type="submit" name="save_and_new" value="1" class="btn btn-outline-primary"><i class="bi bi-plus me-1"></i>Save & Add Another</button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </form>
</div>

<?php
$extraScripts = <<<'SCRIPT'
<script src="https://cdnjs.cloudflare.com/ajax/libs/quagga/0.12.1/quagga.min.js"></script>
<script>
var scannerStream = null;
var captureStream = null;

// Barcode Scanner
document.getElementById('btnStartScanner').addEventListener('click', function() {
    var container = document.getElementById('scannerContainer');
    var video = document.getElementById('scannerVideo');
    container.classList.remove('d-none');
    this.classList.add('d-none');
    document.getElementById('btnStopScanner').classList.remove('d-none');

    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
        .then(function(stream) {
            scannerStream = stream;
            video.srcObject = stream;
            video.play();
            startBarcodeDetection(video);
        })
        .catch(function(err) {
            document.getElementById('scanResult').innerHTML = '<div class="alert alert-warning small">Camera access denied. Enter barcode manually.</div>';
            stopScanner();
        });
});

document.getElementById('btnStopScanner').addEventListener('click', stopScanner);

function stopScanner() {
    if (scannerStream) {
        scannerStream.getTracks().forEach(function(t) { t.stop(); });
        scannerStream = null;
    }
    document.getElementById('scannerContainer').classList.add('d-none');
    document.getElementById('btnStartScanner').classList.remove('d-none');
    document.getElementById('btnStopScanner').classList.add('d-none');
    if (typeof Quagga !== 'undefined') {
        try { Quagga.stop(); } catch(e) {}
    }
}

function startBarcodeDetection(video) {
    if (typeof BarcodeDetector !== 'undefined') {
        var detector = new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'code_128', 'code_39', 'upc_a', 'upc_e'] });
        var detecting = false;
        var interval = setInterval(function() {
            if (!scannerStream || detecting) return;
            detecting = true;
            detector.detect(video).then(function(barcodes) {
                detecting = false;
                if (barcodes.length > 0) {
                    clearInterval(interval);
                    onBarcodeDetected(barcodes[0].rawValue);
                }
            }).catch(function() { detecting = false; });
        }, 300);
    } else if (typeof Quagga !== 'undefined') {
        stopScanner();
        document.getElementById('scannerContainer').classList.remove('d-none');
        Quagga.init({
            inputStream: { type: 'LiveStream', target: document.getElementById('scannerContainer'), constraints: { facingMode: 'environment' } },
            decoder: { readers: ['ean_reader', 'ean_8_reader', 'code_128_reader', 'code_39_reader', 'upc_reader'] }
        }, function(err) {
            if (!err) Quagga.start();
        });
        Quagga.onDetected(function(result) {
            onBarcodeDetected(result.codeResult.code);
            Quagga.stop();
        });
    }
}

function onBarcodeDetected(code) {
    document.getElementById('barcodeInput').value = code;
    stopScanner();
    document.getElementById('scanResult').innerHTML = '<div class="alert alert-success small py-1"><i class="bi bi-check-circle me-1"></i>Barcode detected: <strong>' + code + '</strong></div>';
    lookupBarcode(code);
}

document.getElementById('btnLookupBarcode').addEventListener('click', function() {
    var code = document.getElementById('barcodeInput').value.trim();
    if (code) lookupBarcode(code);
});

function lookupBarcode(code) {
    fetch(BASE_URL + '/api/barcode.php?action=lookup&barcode=' + encodeURIComponent(code))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.found) {
                document.getElementById('scanResult').innerHTML = '<div class="alert alert-info small py-1"><i class="bi bi-info-circle me-1"></i>Product exists: <strong>' + data.medicine.name + '</strong> (Stock: ' + data.medicine.quantity_in_stock + '). <a href="edit.php?id=' + data.medicine.id + '">Edit it</a></div>';
            } else if (data.moph_match) {
                var m = data.moph_match;
                document.getElementById('medName').value = m.medicine_name || '';
                if (m.public_price_usd) document.getElementById('medSellPrice').value = m.public_price_usd;
                document.getElementById('scanResult').innerHTML = '<div class="alert alert-success small py-1"><i class="bi bi-check-circle me-1"></i>Found in MoPH price list! Fields auto-filled.</div>';
            } else {
                document.getElementById('scanResult').innerHTML = '<div class="alert alert-warning small py-1"><i class="bi bi-question-circle me-1"></i>New product. Fill in details below.</div>';
            }
        });
}

// Photo Capture
document.getElementById('btnCapturePhoto').addEventListener('click', function() {
    var container = document.getElementById('captureContainer');
    var video = document.getElementById('captureVideo');
    container.classList.remove('d-none');
    this.classList.add('d-none');
    document.getElementById('btnStopCapture').classList.remove('d-none');

    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 640 }, height: { ideal: 480 } } })
        .then(function(stream) {
            captureStream = stream;
            video.srcObject = stream;
            video.play();
        })
        .catch(function() {
            alert('Camera access denied');
            stopCapture();
        });
});

document.getElementById('btnStopCapture').addEventListener('click', stopCapture);

function stopCapture() {
    if (captureStream) {
        captureStream.getTracks().forEach(function(t) { t.stop(); });
        captureStream = null;
    }
    document.getElementById('captureContainer').classList.add('d-none');
    document.getElementById('btnCapturePhoto').classList.remove('d-none');
    document.getElementById('btnStopCapture').classList.add('d-none');
}

document.getElementById('btnSnap').addEventListener('click', function() {
    var video = document.getElementById('captureVideo');
    var canvas = document.getElementById('captureCanvas');
    var preview = document.getElementById('previewImg');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    var dataUrl = canvas.toDataURL('image/jpeg', 0.8);
    document.getElementById('capturedImageData').value = dataUrl;
    preview.src = dataUrl;
    preview.classList.remove('d-none');
    stopCapture();
});

document.getElementById('fileImageInput').addEventListener('change', function() {
    if (this.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('previewImg').src = e.target.result;
            document.getElementById('previewImg').classList.remove('d-none');
        };
        reader.readAsDataURL(this.files[0]);
        document.getElementById('capturedImageData').value = '';
    }
});

var BASE_URL = '<?= BASE_URL ?>';
</script>
SCRIPT;

require_once __DIR__ . '/../../includes/footer.php';
?>
