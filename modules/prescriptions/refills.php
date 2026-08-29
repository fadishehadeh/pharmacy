<?php
$pageTitle = 'Prescription Refills';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) {
    flashMessage('Access denied', 'error');
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$db = getDB();

// POST handler: process refill
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_refill'])) {
    $rxId = intval($_POST['prescription_id']);

    $rx = $db->prepare("SELECT p.*, c.name as customer_name
        FROM prescriptions p
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE p.id = ?");
    $rx->execute([$rxId]);
    $rx = $rx->fetch();

    if (!$rx) {
        flashMessage('Prescription not found', 'error');
        header('Location: refills.php');
        exit;
    }

    // Check expiry
    if ($rx['expiry_date'] && strtotime($rx['expiry_date']) < time()) {
        flashMessage('This prescription has expired on ' . formatDate($rx['expiry_date'], 'M d, Y') . '. Cannot refill.', 'error');
        header('Location: refills.php');
        exit;
    }

    // Check status
    if (!in_array($rx['status'], ['active', 'partial'])) {
        flashMessage('This prescription is not eligible for refill (status: ' . $rx['status'] . ').', 'error');
        header('Location: refills.php');
        exit;
    }

    // Get items and refill quantities
    $items = $db->prepare("SELECT pi.*, m.name as med_name, m.quantity_in_stock
        FROM prescription_items pi
        LEFT JOIN medicines m ON pi.medicine_id = m.id
        WHERE pi.prescription_id = ?");
    $items->execute([$rxId]);
    $items = $items->fetchAll();

    $dispensedAny = false;
    $errors = [];

    $db->beginTransaction();
    try {
        foreach ($items as $item) {
            $refillQty = intval($_POST['refill_qty'][$item['id']] ?? 0);
            if ($refillQty <= 0) continue;

            $remaining = $item['quantity_prescribed'] - $item['quantity_dispensed'];
            if ($remaining <= 0) {
                $errors[] = sanitize($item['med_name']) . ': fully dispensed, no refill needed.';
                continue;
            }

            // Cap at remaining
            $refillQty = min($refillQty, $remaining);

            // Check stock
            if ($item['medicine_id'] && $item['quantity_in_stock'] < $refillQty) {
                $errors[] = sanitize($item['med_name']) . ': insufficient stock (' . $item['quantity_in_stock'] . ' available, ' . $refillQty . ' requested).';
                continue;
            }

            // Update dispensed quantity
            $db->prepare("UPDATE prescription_items SET quantity_dispensed = quantity_dispensed + ? WHERE id = ?")
                ->execute([$refillQty, $item['id']]);

            // Decrement stock
            if ($item['medicine_id']) {
                updateStock($item['medicine_id'], -$refillQty);
                addStockMovement($item['medicine_id'], 'out', $refillQty, 'Prescription refill ' . $rx['rx_number'], 'prescription', $rxId);
            }

            $dispensedAny = true;
        }

        // Check if fully dispensed now
        $checkItems = $db->prepare("SELECT SUM(quantity_prescribed) as total_prescribed, SUM(quantity_dispensed) as total_dispensed FROM prescription_items WHERE prescription_id = ?");
        $checkItems->execute([$rxId]);
        $totals = $checkItems->fetch();
        $newStatus = ($totals['total_dispensed'] >= $totals['total_prescribed']) ? 'dispensed' : 'partial';
        $db->prepare("UPDATE prescriptions SET status = ? WHERE id = ?")->execute([$newStatus, $rxId]);

        // Log the refill
        $refillCount = 0;
        try {
            $refillCount = $db->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'refill' AND table_name = 'prescriptions' AND record_id = ?");
            $refillCount->execute([$rxId]);
            $refillCount = intval($refillCount->fetchColumn());
        } catch (Exception $e) {}

        addAuditLog('refill', 'prescriptions', $rxId, null, [
            'refill_number' => $refillCount + 1,
            'status' => $newStatus
        ]);

        $db->commit();

        if ($dispensedAny) {
            $msg = 'Refill processed for ' . $rx['rx_number'] . '.';
            if ($newStatus === 'dispensed') $msg .= ' Prescription is now fully dispensed.';
            if (!empty($errors)) $msg .= ' Warnings: ' . implode(' ', $errors);
            flashMessage($msg);
        } else {
            flashMessage('No items were refilled. ' . implode(' ', $errors), 'warning');
        }
    } catch (Exception $e) {
        $db->rollBack();
        flashMessage('Refill failed: ' . $e->getMessage(), 'error');
    }

    header('Location: refills.php');
    exit;
}

// Filters
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? 'eligible';

$where = "WHERE p.status IN ('active', 'partial')";
$params = [];

if ($filter === 'expired') {
    $where = "WHERE p.status IN ('active', 'partial') AND p.expiry_date IS NOT NULL AND p.expiry_date < CURDATE()";
} elseif ($filter === 'all') {
    $where = "WHERE 1=1";
}

if ($search) {
    $where .= " AND (p.rx_number LIKE ? OR c.name LIKE ? OR p.doctor_name LIKE ? OR m_search.med_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$prescriptions = $db->prepare("SELECT p.*, c.name as customer_name,
    (SELECT COUNT(*) FROM prescription_items pi WHERE pi.prescription_id = p.id) as item_count,
    (SELECT SUM(pi.quantity_prescribed) FROM prescription_items pi WHERE pi.prescription_id = p.id) as total_prescribed,
    (SELECT SUM(pi.quantity_dispensed) FROM prescription_items pi WHERE pi.prescription_id = p.id) as total_dispensed,
    (SELECT MAX(sm.created_at) FROM stock_movements sm WHERE sm.reference_type = 'prescription' AND sm.reference_id = p.id) as last_dispensed,
    (SELECT COUNT(*) FROM audit_log al WHERE al.action = 'refill' AND al.table_name = 'prescriptions' AND al.record_id = p.id) as refill_count
    FROM prescriptions p
    LEFT JOIN customers c ON p.customer_id = c.id
    LEFT JOIN (SELECT pi2.prescription_id, GROUP_CONCAT(m2.name) as med_name FROM prescription_items pi2 LEFT JOIN medicines m2 ON pi2.medicine_id = m2.id GROUP BY pi2.prescription_id) m_search ON m_search.prescription_id = p.id
    $where
    ORDER BY p.created_at DESC
    LIMIT 100");
$prescriptions->execute($params);
$prescriptions = $prescriptions->fetchAll();

// Stats
$stats = [
    'eligible' => $db->query("SELECT COUNT(*) FROM prescriptions WHERE status IN ('active', 'partial')")->fetchColumn(),
    'partial' => $db->query("SELECT COUNT(*) FROM prescriptions WHERE status = 'partial'")->fetchColumn(),
    'expired_active' => $db->query("SELECT COUNT(*) FROM prescriptions WHERE status IN ('active', 'partial') AND expiry_date IS NOT NULL AND expiry_date < CURDATE()")->fetchColumn(),
    'today_refills' => $db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'refill' AND table_name = 'prescriptions' AND DATE(created_at) = CURDATE()")->fetchColumn(),
];
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card success"><div class="stat-label">Eligible for Refill</div><div class="stat-value"><?= $stats['eligible'] ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card warning"><div class="stat-label">Partially Dispensed</div><div class="stat-value"><?= $stats['partial'] ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card danger"><div class="stat-label">Expired (Active)</div><div class="stat-value"><?= $stats['expired_active'] ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card info"><div class="stat-label">Today's Refills</div><div class="stat-value"><?= $stats['today_refills'] ?></div></div></div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="btn-group btn-group-sm">
            <a href="?filter=eligible" class="btn btn-<?= $filter === 'eligible' ? 'primary' : 'outline-primary' ?>">Eligible</a>
            <a href="?filter=expired" class="btn btn-<?= $filter === 'expired' ? 'primary' : 'outline-primary' ?>">Expired</a>
            <a href="?filter=all" class="btn btn-<?= $filter === 'all' ? 'primary' : 'outline-primary' ?>">All</a>
        </div>
        <form class="d-flex gap-2" method="GET">
            <input type="hidden" name="filter" value="<?= sanitize($filter) ?>">
            <input type="text" class="form-control form-control-sm" name="search" value="<?= sanitize($search) ?>" placeholder="Search Rx#, patient, doctor, medicine...">
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead>
                <tr>
                    <th>Rx #</th>
                    <th>Patient</th>
                    <th>Doctor</th>
                    <th>Items</th>
                    <th>Dispensed</th>
                    <th>Last Dispensed</th>
                    <th>Refills</th>
                    <th>Expiry</th>
                    <th>Status</th>
                    <th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($prescriptions as $rx):
                    $remaining = ($rx['total_prescribed'] ?? 0) - ($rx['total_dispensed'] ?? 0);
                    $isExpired = $rx['expiry_date'] && strtotime($rx['expiry_date']) < time();
                    $canRefill = in_array($rx['status'], ['active', 'partial']) && !$isExpired && $remaining > 0;
                ?>
                <tr class="<?= $isExpired ? 'table-danger' : '' ?>">
                    <td><strong><?= sanitize($rx['rx_number']) ?></strong></td>
                    <td><?= sanitize($rx['customer_name'] ?? 'Walk-in') ?></td>
                    <td><?= sanitize($rx['doctor_name']) ?></td>
                    <td><span class="badge bg-secondary"><?= $rx['item_count'] ?></span></td>
                    <td>
                        <div class="progress" style="height:18px;width:80px" title="<?= $rx['total_dispensed'] ?? 0 ?> / <?= $rx['total_prescribed'] ?? 0 ?>">
                            <?php $pct = ($rx['total_prescribed'] > 0) ? round(($rx['total_dispensed'] / $rx['total_prescribed']) * 100) : 0; ?>
                            <div class="progress-bar bg-<?= $pct >= 100 ? 'success' : ($pct > 0 ? 'warning' : 'secondary') ?>" style="width:<?= $pct ?>%">
                                <small><?= $pct ?>%</small>
                            </div>
                        </div>
                    </td>
                    <td><small><?= $rx['last_dispensed'] ? formatDate($rx['last_dispensed'], 'M d, Y') : '-' ?></small></td>
                    <td><span class="badge bg-info"><?= intval($rx['refill_count']) ?></span></td>
                    <td>
                        <?php if ($rx['expiry_date']): ?>
                        <small class="<?= $isExpired ? 'text-danger fw-bold' : '' ?>"><?= formatDate($rx['expiry_date'], 'M d, Y') ?></small>
                        <?php if ($isExpired): ?><br><span class="badge bg-danger">Expired</span><?php endif; ?>
                        <?php else: ?>
                        <small class="text-muted">-</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $sColors = ['active'=>'success','dispensed'=>'info','partial'=>'warning','expired'=>'danger','cancelled'=>'secondary']; ?>
                        <span class="badge bg-<?= $sColors[$rx['status']] ?? 'secondary' ?>"><?= ucfirst($rx['status']) ?></span>
                    </td>
                    <td class="no-print">
                        <?php if ($canRefill): ?>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#refillModal" onclick="loadRefillData(<?= $rx['id'] ?>)">
                            <i class="bi bi-arrow-repeat me-1"></i>Refill
                        </button>
                        <?php elseif ($isExpired): ?>
                        <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Expired</span>
                        <?php elseif ($remaining <= 0): ?>
                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Complete</span>
                        <?php endif; ?>
                        <a href="view.php?id=<?= $rx['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="bi bi-eye"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($prescriptions)): ?>
                <tr><td colspan="10" class="text-center text-muted py-3">No prescriptions found matching your criteria.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Refill Modal -->
<div class="modal fade" id="refillModal"><div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST" id="refillForm">
    <input type="hidden" name="prescription_id" id="refillRxId">
    <div class="modal-header">
        <h6 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i>Process Refill - <span id="refillRxNumber"></span></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div id="refillLoading" class="text-center py-4">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-muted">Loading prescription details...</p>
        </div>
        <div id="refillContent" style="display:none">
            <div class="row g-2 mb-3">
                <div class="col-md-4"><strong>Patient:</strong> <span id="refillPatient"></span></div>
                <div class="col-md-4"><strong>Doctor:</strong> <span id="refillDoctor"></span></div>
                <div class="col-md-4"><strong>Expiry:</strong> <span id="refillExpiry"></span></div>
            </div>
            <div id="refillWarnings"></div>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Medicine</th><th>Prescribed</th><th>Dispensed</th><th>Remaining</th><th>Stock</th><th>Refill Qty</th></tr></thead>
                    <tbody id="refillItemsBody"></tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="process_refill" value="1" class="btn btn-primary" id="btnProcessRefill"><i class="bi bi-arrow-repeat me-1"></i>Process Refill</button>
    </div>
    </form>
</div></div></div>

<?php
// Prepare prescription data for JS
$rxDataMap = [];
foreach ($prescriptions as $rx) {
    if (!in_array($rx['status'], ['active', 'partial'])) continue;
    $items = $db->prepare("SELECT pi.*, m.name as med_name, m.strength, m.form, m.quantity_in_stock
        FROM prescription_items pi
        LEFT JOIN medicines m ON pi.medicine_id = m.id
        WHERE pi.prescription_id = ?");
    $items->execute([$rx['id']]);
    $itemList = $items->fetchAll(PDO::FETCH_ASSOC);
    $rxDataMap[$rx['id']] = [
        'rx_number' => $rx['rx_number'],
        'customer_name' => $rx['customer_name'] ?? 'Walk-in',
        'doctor_name' => $rx['doctor_name'],
        'expiry_date' => $rx['expiry_date'] ? formatDate($rx['expiry_date'], 'M d, Y') : 'No expiry',
        'is_expired' => $rx['expiry_date'] && strtotime($rx['expiry_date']) < time(),
        'items' => $itemList
    ];
}
$rxDataJson = json_encode($rxDataMap);

$extraScripts = <<<SCRIPT
<script>
var rxData = {$rxDataJson};

function loadRefillData(rxId) {
    var data = rxData[rxId];
    if (!data) return;

    document.getElementById('refillRxId').value = rxId;
    document.getElementById('refillRxNumber').textContent = data.rx_number;
    document.getElementById('refillPatient').textContent = data.customer_name;
    document.getElementById('refillDoctor').textContent = data.doctor_name;
    document.getElementById('refillExpiry').textContent = data.expiry_date;

    var warnings = '';
    if (data.is_expired) {
        warnings += '<div class="alert alert-danger py-1 small"><i class="bi bi-exclamation-triangle me-1"></i>This prescription has expired. Refill will be blocked.</div>';
    }
    document.getElementById('refillWarnings').innerHTML = warnings;

    var tbody = document.getElementById('refillItemsBody');
    tbody.innerHTML = '';

    data.items.forEach(function(item) {
        var remaining = item.quantity_prescribed - item.quantity_dispensed;
        var stock = item.quantity_in_stock !== null ? parseInt(item.quantity_in_stock) : 0;
        var lowStock = stock < remaining;
        var maxRefill = Math.min(remaining, stock);

        var tr = document.createElement('tr');
        if (remaining <= 0) tr.className = 'table-success';

        tr.innerHTML = '<td><strong>' + escapeHtml(item.med_name || 'Unknown') + '</strong>' +
            (item.strength ? '<br><small class="text-muted">' + escapeHtml(item.strength) + ' - ' + escapeHtml(item.form || '') + '</small>' : '') +
            '</td>' +
            '<td>' + item.quantity_prescribed + '</td>' +
            '<td><span class="badge bg-info">' + item.quantity_dispensed + '</span></td>' +
            '<td>' + (remaining > 0 ? '<span class="badge bg-warning">' + remaining + '</span>' : '<span class="badge bg-success">Done</span>') + '</td>' +
            '<td class="' + (lowStock && remaining > 0 ? 'text-danger fw-bold' : '') + '">' + stock + '</td>' +
            '<td>' + (remaining > 0 ? '<input type="number" class="form-control form-control-sm" name="refill_qty[' + item.id + ']" min="0" max="' + maxRefill + '" value="' + maxRefill + '" style="width:80px">' +
                (lowStock ? '<small class="text-danger">Low stock!</small>' : '') : '<span class="text-success"><i class="bi bi-check-circle"></i></span>') +
            '</td>';

        tbody.appendChild(tr);
    });

    document.getElementById('refillLoading').style.display = 'none';
    document.getElementById('refillContent').style.display = 'block';
    document.getElementById('btnProcessRefill').disabled = data.is_expired;
}

// Reset modal on close
document.getElementById('refillModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('refillLoading').style.display = 'block';
    document.getElementById('refillContent').style.display = 'none';
});

function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}
</script>
SCRIPT;

require_once __DIR__ . '/../../includes/footer.php';
?>
