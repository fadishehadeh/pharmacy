<?php
$pageTitle = 'Cash Register';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['open_register'])) {
        $db->prepare("INSERT INTO cash_register (opening_amount, opening_lbp, opened_by, opened_at, status, notes) VALUES (?,?,?,NOW(),'open',?)")
            ->execute([floatval($_POST['opening_usd']), floatval($_POST['opening_lbp']), $_SESSION['user_id'], $_POST['notes'] ?? null]);
        flashMessage('Cash register opened');
        header('Location: cash_register.php');
        exit;
    } elseif (isset($_POST['close_register'])) {
        $regId = intval($_POST['register_id']);

        $denomUsd = ['100' => 0, '50' => 0, '20' => 0, '10' => 0, '5' => 0, '1' => 0];
        $denomLbp = ['100000' => 0, '50000' => 0, '20000' => 0, '10000' => 0, '5000' => 0, '1000' => 0];

        foreach ($denomUsd as $d => &$v) { $v = intval($_POST["usd_$d"] ?? 0); }
        foreach ($denomLbp as $d => &$v) { $v = intval($_POST["lbp_$d"] ?? 0); }

        $closingUsd = 0;
        foreach ($denomUsd as $d => $c) { $closingUsd += $d * $c; }
        $closingLbp = 0;
        foreach ($denomLbp as $d => $c) { $closingLbp += $d * $c; }

        $reg = $db->prepare("SELECT * FROM cash_register WHERE id = ? AND status = 'open'");
        $reg->execute([$regId]);
        $reg = $reg->fetch();
        if (!$reg) { flashMessage('Register not found or already closed', 'danger'); header('Location: cash_register.php'); exit; }

        $cashSalesUsd = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE payment_method = 'cash' AND currency = 'USD' AND created_at >= ? AND created_at <= NOW()");
        $cashSalesUsd->execute([$reg['opened_at']]);
        $expectedUsd = $reg['opening_amount'] + $cashSalesUsd->fetchColumn();

        $cashSalesLbp = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE payment_method = 'cash' AND currency = 'LBP' AND created_at >= ? AND created_at <= NOW()");
        $cashSalesLbp->execute([$reg['opened_at']]);
        $expectedLbp = $reg['opening_lbp'] + $cashSalesLbp->fetchColumn();

        $diffUsd = $closingUsd - $expectedUsd;
        $diffLbp = $closingLbp - $expectedLbp;

        $db->prepare("UPDATE cash_register SET closing_amount = ?, closing_lbp = ?, expected_amount = ?, expected_lbp = ?,
            difference_amount = ?, difference_lbp = ?, denominations_usd = ?, denominations_lbp = ?,
            closed_by = ?, closed_at = NOW(), status = 'closed', notes = CONCAT(COALESCE(notes,''), ?) WHERE id = ?")
            ->execute([$closingUsd, $closingLbp, $expectedUsd, $expectedLbp, $diffUsd, $diffLbp,
                json_encode($denomUsd), json_encode($denomLbp),
                $_SESSION['user_id'], "\n" . ($_POST['closing_notes'] ?? ''), $regId]);

        addAuditLog('close_register', 'cash_register', $regId, null, ['diff_usd' => $diffUsd, 'diff_lbp' => $diffLbp]);
        flashMessage("Register closed. USD difference: " . formatCurrency($diffUsd) . " | LBP difference: " . formatCurrency($diffLbp, 'LBP'));
        header('Location: cash_register.php');
        exit;
    }
}

$openRegister = $db->query("SELECT cr.*, u.full_name as opener FROM cash_register cr LEFT JOIN users u ON cr.opened_by = u.id WHERE cr.status = 'open' ORDER BY cr.opened_at DESC LIMIT 1")->fetch();

$recentRegisters = $db->query("SELECT cr.*, uo.full_name as opener, uc.full_name as closer
    FROM cash_register cr
    LEFT JOIN users uo ON cr.opened_by = uo.id
    LEFT JOIN users uc ON cr.closed_by = uc.id
    ORDER BY cr.opened_at DESC LIMIT 30")->fetchAll();

$todaySales = [];
if ($openRegister) {
    $todaySales = $db->prepare("SELECT payment_method, currency, COUNT(*) as tx_count, SUM(total_amount) as total
        FROM sales WHERE created_at >= ? GROUP BY payment_method, currency");
    $todaySales->execute([$openRegister['opened_at']]);
    $todaySales = $todaySales->fetchAll();
}
?>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <?php if ($openRegister): ?>
        <div class="card p-3 border-success mb-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 text-success"><i class="bi bi-unlock me-2"></i>Register Open</h6>
                <small class="text-muted">Opened: <?= formatDate($openRegister['opened_at'], 'M d, H:i') ?> by <?= sanitize($openRegister['opener'] ?? '-') ?></small>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-4"><div class="card stat-card"><div class="stat-label">Opening USD</div><div class="stat-value"><?= formatCurrency($openRegister['opening_amount']) ?></div></div></div>
                <div class="col-md-4"><div class="card stat-card info"><div class="stat-label">Opening LBP</div><div class="stat-value"><?= formatCurrency($openRegister['opening_lbp'], 'LBP') ?></div></div></div>
                <div class="col-md-4">
                    <div class="card p-2">
                        <small class="fw-bold">Session Sales</small>
                        <?php foreach ($todaySales as $ts): ?>
                        <small><?= ucfirst($ts['payment_method']) ?> (<?= $ts['currency'] ?>): <?= formatCurrency($ts['total'], $ts['currency']) ?> (<?= $ts['tx_count'] ?> tx)</small><br>
                        <?php endforeach; ?>
                        <?php if (empty($todaySales)): ?><small class="text-muted">No sales yet</small><?php endif; ?>
                    </div>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="register_id" value="<?= $openRegister['id'] ?>">
                <h6><i class="bi bi-cash-coin me-2"></i>Count Cash to Close</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <h6 class="small text-primary">USD Bills</h6>
                        <?php foreach ([100, 50, 20, 10, 5, 1] as $d): ?>
                        <div class="input-group input-group-sm mb-1">
                            <span class="input-group-text" style="width:70px">$<?= $d ?></span>
                            <input type="number" class="form-control denom-usd" name="usd_<?= $d ?>" value="0" min="0" data-value="<?= $d ?>">
                            <span class="input-group-text denom-total" style="width:80px">$0</span>
                        </div>
                        <?php endforeach; ?>
                        <div class="fw-bold mt-1">Total USD: <span id="totalUsd">$0.00</span></div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="small text-info">LBP Bills</h6>
                        <?php foreach ([100000, 50000, 20000, 10000, 5000, 1000] as $d): ?>
                        <div class="input-group input-group-sm mb-1">
                            <span class="input-group-text" style="width:70px"><?= number_format($d) ?></span>
                            <input type="number" class="form-control denom-lbp" name="lbp_<?= $d ?>" value="0" min="0" data-value="<?= $d ?>">
                            <span class="input-group-text denom-total-lbp" style="width:100px">0 L.L.</span>
                        </div>
                        <?php endforeach; ?>
                        <div class="fw-bold mt-1">Total LBP: <span id="totalLbp">0 L.L.</span></div>
                    </div>
                </div>
                <div class="mt-3"><label class="form-label">Closing Notes</label><textarea class="form-control" name="closing_notes" rows="2"></textarea></div>
                <div class="mt-3"><button type="submit" name="close_register" value="1" class="btn btn-danger" onclick="return confirm('Close register and record cash count?')"><i class="bi bi-lock me-1"></i>Close Register</button></div>
            </form>
        </div>
        <?php else: ?>
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-lock me-2"></i>Register Closed</h6>
            <form method="POST" class="row g-3 align-items-end">
                <div class="col-md-4"><label class="form-label">Opening Cash USD</label><input type="number" class="form-control" name="opening_usd" value="0" step="0.01" min="0" required></div>
                <div class="col-md-4"><label class="form-label">Opening Cash LBP</label><input type="number" class="form-control" name="opening_lbp" value="0" step="1000" min="0"></div>
                <div class="col-md-4"><label class="form-label">Notes</label><input type="text" class="form-control" name="notes" placeholder="Optional"></div>
                <div class="col-12"><button type="submit" name="open_register" value="1" class="btn btn-success"><i class="bi bi-unlock me-1"></i>Open Register</button></div>
            </form>
        </div>
        <?php endif; ?>

        <div class="card p-3">
            <h6><i class="bi bi-clock-history me-2"></i>Register History</h6>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Opened</th><th>Closed</th><th>Opening</th><th>Closing</th><th>Expected</th><th>Difference</th><th>Staff</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentRegisters as $r): ?>
                        <tr>
                            <td><small><?= formatDate($r['opened_at'], 'M d, H:i') ?></small></td>
                            <td><small><?= $r['closed_at'] ? formatDate($r['closed_at'], 'M d, H:i') : '<span class="badge bg-success">Open</span>' ?></small></td>
                            <td><small><?= formatCurrency($r['opening_amount']) ?></small></td>
                            <td><small><?= $r['closing_amount'] !== null ? formatCurrency($r['closing_amount']) : '-' ?></small></td>
                            <td><small><?= $r['expected_amount'] !== null ? formatCurrency($r['expected_amount']) : '-' ?></small></td>
                            <td>
                                <?php if ($r['difference_amount'] !== null): ?>
                                <small class="fw-bold text-<?= $r['difference_amount'] == 0 ? 'success' : ($r['difference_amount'] > 0 ? 'info' : 'danger') ?>">
                                    <?= $r['difference_amount'] >= 0 ? '+' : '' ?><?= formatCurrency($r['difference_amount']) ?>
                                </small>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                            <td><small><?= sanitize($r['opener'] ?? '-') ?><?= $r['closer'] ? ' / ' . sanitize($r['closer']) : '' ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-info-circle me-2"></i>Cash Register Guide</h6>
            <div class="small text-muted">
                <p><strong>Opening:</strong> Count all cash in the drawer before starting. Enter the exact amounts.</p>
                <p><strong>During shift:</strong> All cash sales are tracked automatically.</p>
                <p><strong>Closing:</strong> Count each denomination. The system calculates the expected amount from opening balance + cash sales.</p>
                <p><strong>Differences:</strong></p>
                <ul>
                    <li class="text-success">Positive = overage (more cash than expected)</li>
                    <li class="text-danger">Negative = shortage (less cash than expected)</li>
                    <li class="text-muted">Zero = perfect count</li>
                </ul>
                <p class="mb-0">Large discrepancies should be investigated and documented.</p>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<'SCRIPT'
<script>
function updateTotals() {
    var totalUsd = 0;
    document.querySelectorAll('.denom-usd').forEach(function(input) {
        var val = parseInt(input.dataset.value) * (parseInt(input.value) || 0);
        totalUsd += val;
        input.closest('.input-group').querySelector('.denom-total').textContent = '$' + val.toLocaleString();
    });
    document.getElementById('totalUsd').textContent = '$' + totalUsd.toLocaleString(undefined, {minimumFractionDigits: 2});

    var totalLbp = 0;
    document.querySelectorAll('.denom-lbp').forEach(function(input) {
        var val = parseInt(input.dataset.value) * (parseInt(input.value) || 0);
        totalLbp += val;
        input.closest('.input-group').querySelector('.denom-total-lbp').textContent = val.toLocaleString() + ' L.L.';
    });
    document.getElementById('totalLbp').textContent = totalLbp.toLocaleString() + ' L.L.';
}
document.querySelectorAll('.denom-usd, .denom-lbp').forEach(function(i) { i.addEventListener('input', updateTotals); });
</script>
SCRIPT;
require_once __DIR__ . '/../../includes/footer.php';
?>
