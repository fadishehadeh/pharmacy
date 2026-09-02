<?php
$pageTitle = 'Expiry Deal Automation';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { header('Location: ' . BASE_URL); exit; }
$db = getDB();

$pharmacyName  = getSetting('pharmacy_name')  ?: 'Pharmacy';
$pharmacyPhone = getSetting('pharmacy_phone') ?: '';

// ── POST: apply discount to a single medicine ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_discount'])) {
    $medId      = intval($_POST['medicine_id']);
    $newPrice   = floatval($_POST['new_price']);
    if ($medId && $newPrice > 0) {
        $db->prepare("UPDATE medicines SET sell_price = ? WHERE id = ?")->execute([$newPrice, $medId]);
        addAuditLog('price_update', 'medicines', $medId, null, ['new_price' => $newPrice, 'reason' => 'expiry_automation']);
        flashMessage('Price updated');
    }
    header('Location: expiry_automation.php?days=' . intval($_POST['days']));
    exit;
}

// ── Configurable window ──────────────────────────────────────────────────────
$days = max(1, intval($_GET['days'] ?? 90));

// Discount tiers
function suggestDiscount(int $daysLeft): int {
    if ($daysLeft < 30)  return 50;
    if ($daysLeft < 60)  return 25;
    return 10; // 60-90
}

// ── Fetch near-expiry medicines ──────────────────────────────────────────────
$stmt = $db->prepare("SELECT m.*,
    DATEDIFF(m.expiry_date, CURDATE()) as days_left,
    (m.sell_price * m.quantity_in_stock) as stock_value
    FROM medicines m
    WHERE m.is_active = 1
    AND m.quantity_in_stock > 0
    AND m.expiry_date IS NOT NULL
    AND m.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
    ORDER BY m.expiry_date ASC");
$stmt->execute([$days]);
$meds = $stmt->fetchAll();

// ── Savings calculator ───────────────────────────────────────────────────────
$totalStockValue     = 0;
$totalRecoveryAtDisc = 0;
$totalLossIfExpired  = 0;
foreach ($meds as $m) {
    $disc            = suggestDiscount((int)$m['days_left']);
    $discPrice       = round($m['sell_price'] * (1 - $disc / 100), 2);
    $totalStockValue     += $m['stock_value'];
    $totalRecoveryAtDisc += $discPrice * $m['quantity_in_stock'];
    $totalLossIfExpired  += $m['cost_price'] * $m['quantity_in_stock'];
}
$potentialSaved = $totalRecoveryAtDisc - 0; // vs expiring worth $0

// ── Build bulk WhatsApp message ──────────────────────────────────────────────
$waLines = [];
foreach ($meds as $m) {
    $disc     = suggestDiscount((int)$m['days_left']);
    $newPrice = round($m['sell_price'] * (1 - $disc / 100), 2);
    $waLines[] = "💊 " . $m['name'] . ($m['strength'] ? ' ' . $m['strength'] : '') . " — {$disc}% OFF → $" . number_format($newPrice, 2);
}
$bulkWaMsg  = "🏥 {$pharmacyName} Special Deals!\n\n";
$bulkWaMsg .= implode("\n", $waLines);
$bulkWaMsg .= "\n\n⏰ Limited time — near-expiry deals!\n";
if ($pharmacyPhone) $bulkWaMsg .= "📞 " . $pharmacyPhone;
$bulkWaEncoded = rawurlencode($bulkWaMsg);
?>

<!-- Controls -->
<div class="row g-3 mb-3 align-items-center">
    <div class="col-md-4">
        <form method="GET" class="d-flex gap-2 align-items-center">
            <label class="form-label mb-0 text-nowrap fw-semibold">Show expiring within</label>
            <select class="form-select form-select-sm" name="days" onchange="this.form.submit()">
                <?php foreach ([30, 60, 90, 120, 180] as $d): ?>
                <option value="<?= $d ?>" <?= $d == $days ? 'selected' : '' ?>><?= $d ?> days</option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="col-md-8 d-flex gap-2 justify-content-md-end flex-wrap">
        <?php if (!empty($meds)): ?>
        <a href="https://wa.me/?text=<?= $bulkWaEncoded ?>" target="_blank" class="btn btn-success btn-sm">
            <i class="bi bi-whatsapp me-1"></i>Bulk WhatsApp (<?= count($meds) ?> items)
        </a>
        <?php endif; ?>
        <a href="near_expiry_deals.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-tag me-1"></i>Promotions
        </a>
    </div>
</div>

<!-- Stats row -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card danger">
            <div class="stat-label">Near-Expiry Items</div>
            <div class="stat-value"><?= count($meds) ?></div>
            <small class="text-muted">within <?= $days ?> days</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="stat-label">Stock Value at Risk</div>
            <div class="stat-value"><?= formatCurrency($totalStockValue) ?></div>
            <small class="text-muted">at current sell prices</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card success">
            <div class="stat-label">Recovery if Discounted</div>
            <div class="stat-value"><?= formatCurrency($totalRecoveryAtDisc) ?></div>
            <small class="text-muted">using suggested discounts</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Cost if All Expire</div>
            <div class="stat-value text-danger"><?= formatCurrency($totalLossIfExpired) ?></div>
            <small class="text-muted">purchase cost lost</small>
        </div>
    </div>
</div>

<!-- Savings calculator callout -->
<?php if (!empty($meds)): ?>
<div class="alert alert-info d-flex align-items-start gap-3 mb-3">
    <i class="bi bi-calculator fs-4 mt-1"></i>
    <div>
        <strong>Savings Calculator:</strong>
        If all <?= count($meds) ?> near-expiry items are sold at suggested discounts you recover
        <strong><?= formatCurrency($totalRecoveryAtDisc) ?></strong>,
        saving <strong><?= formatCurrency($totalLossIfExpired) ?></strong> in lost purchase costs vs letting them expire.
        Discount tiers: <span class="badge bg-info">60–90 days → 10% off</span>
        <span class="badge bg-warning text-dark">30–60 days → 25% off</span>
        <span class="badge bg-danger">under 30 days → 50% off</span>
    </div>
</div>
<?php endif; ?>

<!-- Main table -->
<div class="card">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Near-Expiry Medicines</h6></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Medicine</th>
                    <th class="text-center">Stock</th>
                    <th class="text-center">Days Left</th>
                    <th class="text-end">Current Price</th>
                    <th class="text-center">Suggested Disc.</th>
                    <th class="text-end">New Price</th>
                    <th class="text-end">Stock Value</th>
                    <th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($meds)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">
                    <i class="bi bi-check-circle text-success fs-3 d-block mb-2"></i>
                    No medicines expiring within <?= $days ?> days. Great inventory management!
                </td></tr>
                <?php else: ?>
                <?php foreach ($meds as $m): ?>
                <?php
                    $dl          = (int)$m['days_left'];
                    $disc        = suggestDiscount($dl);
                    $newPrice    = round($m['sell_price'] * (1 - $disc / 100), 2);
                    $urgencyBg   = $dl < 30 ? 'danger' : ($dl < 60 ? 'warning' : 'info');
                    $medLabel    = $m['name'] . ($m['strength'] ? ' ' . $m['strength'] : '');
                    $waMsg       = "🏥 {$pharmacyName} Special Offer!\n💊 {$medLabel}\n⏰ Limited time deal\n💰 {$disc}% OFF — Now \$" . number_format($newPrice, 2) . "\n📞 Call us: {$pharmacyPhone}";
                    $waEncoded   = rawurlencode($waMsg);
                ?>
                <tr>
                    <td>
                        <strong><?= sanitize($m['name']) ?></strong>
                        <?php if ($m['strength']): ?><small class="text-muted ms-1"><?= sanitize($m['strength']) ?></small><?php endif; ?>
                        <?php if ($m['form']): ?><br><small class="text-muted"><?= sanitize($m['form']) ?></small><?php endif; ?>
                        <?php if ($m['expiry_date']): ?>
                        <br><small class="text-muted">Exp: <?= formatDate($m['expiry_date'], 'M d, Y') ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center fw-semibold"><?= $m['quantity_in_stock'] ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= $urgencyBg ?>"><?= $dl ?>d</span>
                    </td>
                    <td class="text-end"><?= formatCurrency($m['sell_price']) ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= $urgencyBg ?> fs-6"><?= $disc ?>%</span>
                    </td>
                    <td class="text-end fw-semibold text-success"><?= formatCurrency($newPrice) ?></td>
                    <td class="text-end text-warning"><?= formatCurrency($m['stock_value']) ?></td>
                    <td class="no-print">
                        <div class="d-flex gap-1 flex-wrap">
                            <!-- Apply Discount -->
                            <form method="POST" class="d-inline" onsubmit="return confirm('Update sell price for <?= addslashes(sanitize($m['name'])) ?> to <?= formatCurrency($newPrice) ?>?')">
                                <input type="hidden" name="apply_discount" value="1">
                                <input type="hidden" name="medicine_id" value="<?= $m['id'] ?>">
                                <input type="hidden" name="new_price" value="<?= $newPrice ?>">
                                <input type="hidden" name="days" value="<?= $days ?>">
                                <button type="submit" class="btn btn-sm btn-warning text-dark" title="Apply <?= $disc ?>% discount">
                                    <i class="bi bi-percent me-1"></i><?= $disc ?>% OFF
                                </button>
                            </form>
                            <!-- WhatsApp single -->
                            <a href="https://wa.me/?text=<?= $waEncoded ?>" target="_blank"
                                class="btn btn-sm btn-success" title="WhatsApp this deal">
                                <i class="bi bi-whatsapp"></i>
                            </a>
                            <!-- Custom discount modal trigger -->
                            <button class="btn btn-sm btn-outline-primary" title="Custom price"
                                onclick="openCustom(<?= $m['id'] ?>, '<?= addslashes(sanitize($medLabel)) ?>', <?= $m['sell_price'] ?>, <?= $days ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Custom Discount Modal -->
<div class="modal fade" id="customDiscModal"><div class="modal-dialog"><div class="modal-content">
    <form method="POST" id="customDiscForm">
        <input type="hidden" name="apply_discount" value="1">
        <input type="hidden" name="days" value="<?= $days ?>">
        <input type="hidden" name="medicine_id" id="cdMedId">
        <div class="modal-header">
            <h6 class="modal-title">Custom Discount — <span id="cdMedName"></span></h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Current Price</label>
                <input type="text" class="form-control" id="cdCurrentPrice" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label">Discount %</label>
                <div class="input-group">
                    <input type="number" class="form-control" id="cdDiscPct" min="1" max="90" value="10" oninput="calcCustomPrice()">
                    <span class="input-group-text">%</span>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">New Price</label>
                <input type="number" class="form-control" name="new_price" id="cdNewPrice" step="0.01" min="0.01" required>
            </div>
        </div>
        <div class="modal-footer">
            <button type="submit" class="btn btn-warning">Apply Discount</button>
        </div>
    </form>
</div></div></div>

<?php
$extraScripts = <<<'JS'
<script>
var cdOrigPrice = 0;
function openCustom(medId, medName, origPrice, days) {
    cdOrigPrice = parseFloat(origPrice);
    document.getElementById('cdMedId').value       = medId;
    document.getElementById('cdMedName').textContent = medName;
    document.getElementById('cdCurrentPrice').value = '$' + cdOrigPrice.toFixed(2);
    document.getElementById('cdDiscPct').value      = 10;
    document.getElementById('customDiscForm').querySelector('[name=days]').value = days;
    calcCustomPrice();
    new bootstrap.Modal(document.getElementById('customDiscModal')).show();
}
function calcCustomPrice() {
    var pct = parseFloat(document.getElementById('cdDiscPct').value) || 0;
    var np  = Math.max(0, cdOrigPrice * (1 - pct / 100));
    document.getElementById('cdNewPrice').value = np.toFixed(2);
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
