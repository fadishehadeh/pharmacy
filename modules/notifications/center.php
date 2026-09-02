<?php
$pageTitle = 'Notification Center';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));
$expiryDays = intval(getSetting('expiry_alert_days', getSetting('expiry_warning_days', 90)));

// Handle dismiss action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['dismiss_type'])) {
        $type = $_POST['dismiss_type'];
        $dismissedKey = 'notifications_dismissed_' . $type;
        $_SESSION[$dismissedKey] = date('Y-m-d H:i:s');
        flashMessage('Notifications dismissed');
        header('Location: center.php');
        exit;
    }
    if (isset($_POST['dismiss_all'])) {
        $types = ['expired', 'expiring', 'out_of_stock', 'low_stock', 'pending_claims', 'due_reminders', 'pending_deliveries', 'expiring_quotes', 'pending_returns', 'overdue_credits'];
        foreach ($types as $type) {
            $_SESSION['notifications_dismissed_' . $type] = date('Y-m-d H:i:s');
        }
        flashMessage('All notifications dismissed');
        header('Location: center.php');
        exit;
    }
    if (isset($_POST['clear_dismissed'])) {
        foreach ($_SESSION as $key => $val) {
            if (strpos($key, 'notifications_dismissed_') === 0) {
                unset($_SESSION[$key]);
            }
        }
        flashMessage('Dismissed notifications restored');
        header('Location: center.php');
        exit;
    }
}

$isDismissed = function($type) {
    $key = 'notifications_dismissed_' . $type;
    return isset($_SESSION[$key]);
};

// 1. Expired medicines still in stock
$expired = $db->query("SELECT m.id, m.name, m.expiry_date, m.quantity_in_stock, m.cost_price,
    c.name as category_name
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE m.expiry_date IS NOT NULL AND m.expiry_date < CURDATE() AND m.is_active = 1 AND m.quantity_in_stock > 0
    ORDER BY m.expiry_date ASC")->fetchAll();

// 2. Expiring medicines (within X days)
$expiringSoon = $db->prepare("SELECT m.id, m.name, m.expiry_date, m.quantity_in_stock, m.cost_price,
    c.name as category_name, DATEDIFF(m.expiry_date, CURDATE()) as days_left
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE m.expiry_date IS NOT NULL AND m.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) AND m.is_active = 1
    ORDER BY m.expiry_date ASC");
$expiringSoon->execute([$expiryDays]);
$expiringSoon = $expiringSoon->fetchAll();

// 3. Out of stock
$outOfStock = $db->query("SELECT m.id, m.name, m.min_stock_level, c.name as category_name
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE m.quantity_in_stock = 0 AND m.is_active = 1
    ORDER BY m.name")->fetchAll();

// 4. Low stock (below min_stock_level but not zero)
$lowStock = $db->query("SELECT m.id, m.name, m.quantity_in_stock, m.min_stock_level, c.name as category_name
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE m.quantity_in_stock > 0 AND m.quantity_in_stock <= m.min_stock_level AND m.is_active = 1
    ORDER BY m.quantity_in_stock ASC")->fetchAll();

// 5. Pending insurance claims
$pendingClaims = [];
try {
    $pendingClaims = $db->query("SELECT ic.id, ic.claim_number, ic.total_amount, ic.status, ic.claim_date,
        ip.name as provider_name, c.name as customer_name
        FROM insurance_claims ic
        JOIN insurance_providers ip ON ic.insurance_provider_id = ip.id
        LEFT JOIN customers c ON ic.customer_id = c.id
        WHERE ic.status IN ('pending','submitted')
        ORDER BY ic.claim_date ASC")->fetchAll();
} catch (Exception $e) {}

// 6. Due refill reminders
$dueReminders = [];
try {
    $dueReminders = $db->query("SELECT mr.id, mr.reminder_date, mr.message, mr.phone, mr.status,
        c.name as customer_name, m.name as medicine_name
        FROM medicine_reminders mr
        LEFT JOIN customers c ON mr.customer_id = c.id
        LEFT JOIN medicines m ON mr.medicine_id = m.id
        WHERE mr.status = 'pending' AND mr.reminder_date <= CURDATE()
        ORDER BY mr.reminder_date ASC")->fetchAll();
} catch (Exception $e) {}

// 7. Pending deliveries
$pendingDeliveries = [];
try {
    $pendingDeliveries = $db->query("SELECT d.id, d.customer_name, d.phone, d.delivery_date, d.time_slot, d.status,
        s.invoice_number
        FROM deliveries d
        LEFT JOIN sales s ON d.sale_id = s.id
        WHERE d.status IN ('pending','in_transit')
        ORDER BY d.delivery_date ASC")->fetchAll();
} catch (Exception $e) {}

// 8. Quotations expiring within 3 days
$expiringQuotes = [];
try {
    $expiringQuotes = $db->query("SELECT q.id, q.quote_number, q.total, q.valid_until, q.created_at,
        c.name as customer_name
        FROM quotations q
        LEFT JOIN customers c ON q.customer_id = c.id
        WHERE q.status = 'active' AND q.valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
        ORDER BY q.valid_until ASC")->fetchAll();
} catch (Exception $e) {}

// 9. Pending supplier returns
$pendingReturns = [];
try {
    $pendingReturns = $db->query("SELECT sr.id, sr.quantity, sr.total_value, sr.reason, sr.status, sr.created_at,
        s.name as supplier_name, m.name as medicine_name
        FROM supplier_returns sr
        JOIN suppliers s ON sr.supplier_id = s.id
        JOIN medicines m ON sr.medicine_id = m.id
        WHERE sr.status = 'pending'
        ORDER BY sr.created_at ASC")->fetchAll();
} catch (Exception $e) {}

// 10. Customer credits overdue >30 days
$overdueCredits = [];
try {
    $overdueCredits = $db->query("SELECT
        p.id as customer_id,
        COALESCE(CONCAT(p.first_name, ' ', p.last_name), c.name) as customer_name,
        COALESCE(p.phone, c.phone) as phone,
        SUM(CASE WHEN cc.type = 'credit' THEN cc.amount_usd ELSE -cc.amount_usd END) as balance,
        MIN(CASE WHEN cc.type = 'credit' THEN cc.created_at END) as oldest_credit,
        DATEDIFF(CURDATE(), MIN(CASE WHEN cc.type = 'credit' THEN cc.created_at END)) as days_overdue
        FROM customer_credits cc
        LEFT JOIN patients p ON cc.customer_id = p.id
        LEFT JOIN customers c ON cc.customer_id = c.id
        GROUP BY cc.customer_id
        HAVING balance > 0 AND days_overdue > 30
        ORDER BY balance DESC")->fetchAll();
} catch (Exception $e) {}

// Build notifications array with priorities
$notifications = [
    ['type' => 'expired', 'label' => 'Expired Medicines in Stock', 'icon' => 'bi-exclamation-octagon', 'priority' => 'critical', 'color' => 'danger', 'data' => $expired],
    ['type' => 'out_of_stock', 'label' => 'Out of Stock Items', 'icon' => 'bi-x-circle', 'priority' => 'critical', 'color' => 'danger', 'data' => $outOfStock],
    ['type' => 'expiring', 'label' => "Expiring Within {$expiryDays} Days", 'icon' => 'bi-clock-history', 'priority' => 'warning', 'color' => 'warning', 'data' => $expiringSoon],
    ['type' => 'low_stock', 'label' => 'Low Stock Alerts', 'icon' => 'bi-arrow-down-circle', 'priority' => 'warning', 'color' => 'warning', 'data' => $lowStock],
    ['type' => 'pending_claims', 'label' => 'Pending Insurance Claims', 'icon' => 'bi-shield-plus', 'priority' => 'info', 'color' => 'info', 'data' => $pendingClaims],
    ['type' => 'due_reminders', 'label' => 'Due Refill Reminders', 'icon' => 'bi-bell', 'priority' => 'info', 'color' => 'info', 'data' => $dueReminders],
    ['type' => 'pending_deliveries', 'label' => 'Pending Deliveries', 'icon' => 'bi-truck', 'priority' => 'info', 'color' => 'info', 'data' => $pendingDeliveries],
    ['type' => 'expiring_quotes', 'label' => 'Quotations Expiring Soon', 'icon' => 'bi-file-earmark-text', 'priority' => 'warning', 'color' => 'warning', 'data' => $expiringQuotes],
    ['type' => 'pending_returns', 'label' => 'Pending Supplier Returns', 'icon' => 'bi-arrow-return-left', 'priority' => 'info', 'color' => 'info', 'data' => $pendingReturns],
    ['type' => 'overdue_credits', 'label' => 'Overdue Customer Credits (>30 days)', 'icon' => 'bi-credit-card-2-front', 'priority' => 'warning', 'color' => 'warning', 'data' => $overdueCredits],
];

$criticalCount = 0; $warningCount = 0; $infoCount = 0;
foreach ($notifications as $n) {
    $count = count($n['data']);
    if ($n['priority'] === 'critical') $criticalCount += $count;
    elseif ($n['priority'] === 'warning') $warningCount += $count;
    else $infoCount += $count;
}
$totalAlerts = $criticalCount + $warningCount + $infoCount;
?>

<!-- Summary Bar -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card danger">
            <div class="stat-label">Critical</div>
            <div class="stat-value"><?= $criticalCount ?></div>
            <small class="text-muted">Expired & Out of Stock</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="stat-label">Warnings</div>
            <div class="stat-value"><?= $warningCount ?></div>
            <small class="text-muted">Low stock, Expiring, Overdue</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card info">
            <div class="stat-label">Info</div>
            <div class="stat-value"><?= $infoCount ?></div>
            <small class="text-muted">Reminders & Pending</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Total Alerts</div>
            <div class="stat-value"><?= $totalAlerts ?></div>
            <div class="d-flex gap-1 mt-1 no-print">
                <form method="POST" class="d-inline">
                    <button name="dismiss_all" value="1" class="btn btn-sm btn-outline-secondary" onclick="return confirm('Dismiss all notifications?')">Dismiss All</button>
                </form>
                <form method="POST" class="d-inline">
                    <button name="clear_dismissed" value="1" class="btn btn-sm btn-outline-primary">Restore</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($totalAlerts === 0): ?>
<div class="card p-5 text-center">
    <i class="bi bi-check-circle text-success" style="font-size:3rem"></i>
    <h5 class="mt-3">All Clear!</h5>
    <p class="text-muted">No alerts or notifications at this time.</p>
</div>
<?php else: ?>

<div class="accordion" id="notificationAccordion">
<?php foreach ($notifications as $idx => $n):
    $count = count($n['data']);
    if ($count === 0) continue;
    $dismissed = $isDismissed($n['type']);
    $collapseId = 'collapse_' . $n['type'];
    $priorityBadge = $n['priority'] === 'critical' ? 'danger' : ($n['priority'] === 'warning' ? 'warning' : 'info');
?>
<div class="accordion-item mb-2 border-<?= $n['color'] ?> <?= $dismissed ? 'opacity-50' : '' ?>">
    <h2 class="accordion-header">
        <button class="accordion-button <?= $dismissed ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>">
            <i class="bi <?= $n['icon'] ?> me-2 text-<?= $n['color'] ?>"></i>
            <strong><?= sanitize($n['label']) ?></strong>
            <span class="badge bg-<?= $priorityBadge ?> ms-2"><?= $count ?></span>
            <span class="badge bg-<?= $n['priority'] === 'critical' ? 'danger' : ($n['priority'] === 'warning' ? 'warning' : 'secondary') ?> ms-1 text-uppercase" style="font-size:0.65em"><?= $n['priority'] ?></span>
        </button>
    </h2>
    <div id="<?= $collapseId ?>" class="accordion-collapse collapse <?= (!$dismissed && $n['priority'] === 'critical') ? 'show' : '' ?>" data-bs-parent="#notificationAccordion">
        <div class="accordion-body p-0">
            <?php if ($n['type'] === 'expired'): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Category</th><th>Expired On</th><th>Stock</th><th>Value at Risk</th><th class="no-print">Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($n['data'] as $m): ?>
                        <tr>
                            <td><strong><?= sanitize($m['name']) ?></strong></td>
                            <td><small><?= sanitize($m['category_name'] ?? '-') ?></small></td>
                            <td class="text-danger"><?= formatDate($m['expiry_date'], 'M d, Y') ?></td>
                            <td><?= $m['quantity_in_stock'] ?></td>
                            <td class="text-danger fw-bold"><?= formatCurrency($m['quantity_in_stock'] * $m['cost_price']) ?></td>
                            <td class="no-print"><a href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-danger">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php elseif ($n['type'] === 'out_of_stock'): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Category</th><th>Min Level</th><th class="no-print">Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($n['data'] as $m): ?>
                        <tr>
                            <td><strong><?= sanitize($m['name']) ?></strong></td>
                            <td><small><?= sanitize($m['category_name'] ?? '-') ?></small></td>
                            <td><?= $m['min_stock_level'] ?></td>
                            <td class="no-print"><a href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">Restock</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php elseif ($n['type'] === 'expiring'): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Category</th><th>Expiry Date</th><th>Days Left</th><th>Stock</th><th class="no-print">Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($n['data'] as $m): ?>
                        <tr>
                            <td><strong><?= sanitize($m['name']) ?></strong></td>
                            <td><small><?= sanitize($m['category_name'] ?? '-') ?></small></td>
                            <td><?= formatDate($m['expiry_date'], 'M d, Y') ?></td>
                            <td><span class="badge bg-<?= $m['days_left'] <= 30 ? 'danger' : 'warning' ?>"><?= $m['days_left'] ?> days</span></td>
                            <td><?= $m['quantity_in_stock'] ?></td>
                            <td class="no-print"><a href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-warning">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php elseif ($n['type'] === 'low_stock'): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Category</th><th>Stock</th><th>Min Level</th><th class="no-print">Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($n['data'] as $m): ?>
                        <tr>
                            <td><strong><?= sanitize($m['name']) ?></strong></td>
                            <td><small><?= sanitize($m['category_name'] ?? '-') ?></small></td>
                            <td><span class="badge bg-warning"><?= $m['quantity_in_stock'] ?></span></td>
                            <td><?= $m['min_stock_level'] ?></td>
                            <td class="no-print"><a href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">Restock</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php elseif ($n['type'] === 'pending_claims'): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Claim #</th><th>Provider</th><th>Patient</th><th>Amount</th><th>Date</th><th>Status</th><th class="no-print">Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($n['data'] as $cl): ?>
                        <tr>
                            <td><strong><?= sanitize($cl['claim_number']) ?></strong></td>
                            <td><?= sanitize($cl['provider_name']) ?></td>
                            <td><?= sanitize($cl['customer_name'] ?? '-') ?></td>
                            <td><?= formatCurrency($cl['total_amount']) ?></td>
                            <td><?= formatDate($cl['claim_date'], 'M d, Y') ?></td>
                            <td><span class="badge bg-warning"><?= ucfirst(sanitize($cl['status'])) ?></span></td>
                            <td class="no-print"><a href="<?= BASE_URL ?>/modules/insurance/claims.php" class="btn btn-sm btn-outline-info">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php elseif ($n['type'] === 'due_reminders'): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Customer</th><th>Medicine</th><th>Due Date</th><th>Phone</th><th>Message</th><th class="no-print">Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($n['data'] as $r): ?>
                        <tr>
                            <td><strong><?= sanitize($r['customer_name'] ?? '-') ?></strong></td>
                            <td><?= sanitize($r['medicine_name'] ?? '-') ?></td>
                            <td><?= formatDate($r['reminder_date'], 'M d, Y') ?></td>
                            <td><?= sanitize($r['phone'] ?? '-') ?></td>
                            <td><small><?= sanitize($r['message'] ?? '-') ?></small></td>
                            <td class="no-print"><a href="<?= BASE_URL ?>/modules/patients/reminders.php" class="btn btn-sm btn-outline-info">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php elseif ($n['type'] === 'pending_deliveries'): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Customer</th><th>Invoice</th><th>Delivery Date</th><th>Time Slot</th><th>Status</th><th class="no-print">Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($n['data'] as $d): ?>
                        <tr>
                            <td><strong><?= sanitize($d['customer_name']) ?></strong></td>
                            <td><?= sanitize($d['invoice_number'] ?? '-') ?></td>
                            <td><?= formatDate($d['delivery_date'], 'M d, Y') ?></td>
                            <td><?= sanitize($d['time_slot'] ?? '-') ?></td>
                            <td><span class="badge bg-<?= $d['status'] === 'in_transit' ? 'info' : 'warning' ?>"><?= ucfirst(sanitize($d['status'])) ?></span></td>
                            <td class="no-print"><a href="<?= BASE_URL ?>/modules/sales/deliveries.php" class="btn btn-sm btn-outline-info">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php elseif ($n['type'] === 'expiring_quotes'): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Quote #</th><th>Customer</th><th>Total</th><th>Valid Until</th><th class="no-print">Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($n['data'] as $q): ?>
                        <tr>
                            <td><strong><?= sanitize($q['quote_number']) ?></strong></td>
                            <td><?= sanitize($q['customer_name'] ?? 'Walk-in') ?></td>
                            <td><?= formatCurrency($q['total']) ?></td>
                            <td><span class="badge bg-warning"><?= formatDate($q['valid_until'], 'M d, Y') ?></span></td>
                            <td class="no-print"><a href="<?= BASE_URL ?>/modules/sales/quotations.php" class="btn btn-sm btn-outline-warning">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php elseif ($n['type'] === 'pending_returns'): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Supplier</th><th>Qty</th><th>Value</th><th>Reason</th><th>Date</th><th class="no-print">Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($n['data'] as $sr): ?>
                        <tr>
                            <td><strong><?= sanitize($sr['medicine_name']) ?></strong></td>
                            <td><?= sanitize($sr['supplier_name']) ?></td>
                            <td><?= $sr['quantity'] ?></td>
                            <td><?= formatCurrency($sr['total_value']) ?></td>
                            <td><small><?= sanitize($sr['reason']) ?></small></td>
                            <td><?= formatDate($sr['created_at'], 'M d, Y') ?></td>
                            <td class="no-print"><a href="<?= BASE_URL ?>/modules/suppliers/returns.php" class="btn btn-sm btn-outline-info">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php elseif ($n['type'] === 'overdue_credits'): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Customer</th><th>Phone</th><th class="text-end">Balance (USD)</th><th class="text-end">Balance (LBP)</th><th>Days Overdue</th><th class="no-print">Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($n['data'] as $oc): ?>
                        <tr>
                            <td><strong><?= sanitize($oc['customer_name'] ?? '-') ?></strong></td>
                            <td><?= sanitize($oc['phone'] ?? '-') ?></td>
                            <td class="text-end text-danger fw-bold"><?= formatCurrency($oc['balance']) ?></td>
                            <td class="text-end"><small class="text-muted"><?= formatCurrency($oc['balance'] * $exchangeRate, 'LBP') ?></small></td>
                            <td><span class="badge bg-<?= $oc['days_overdue'] > 90 ? 'danger' : 'warning' ?>"><?= $oc['days_overdue'] ?> days</span></td>
                            <td class="no-print"><a href="<?= BASE_URL ?>/modules/finance/credits.php?customer=<?= $oc['customer_id'] ?>" class="btn btn-sm btn-outline-warning">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="p-2 bg-light border-top d-flex justify-content-end no-print">
                <form method="POST">
                    <input type="hidden" name="dismiss_type" value="<?= sanitize($n['type']) ?>">
                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-check2 me-1"></i>Dismiss</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
