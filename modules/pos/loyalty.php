<?php
$pageTitle = 'Customer Loyalty';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

$exchangeRate = floatval(getSetting('exchange_rate', '89500'));

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_settings'])) {
        updateSetting('loyalty_enabled', isset($_POST['loyalty_enabled']) ? '1' : '0');
        updateSetting('loyalty_points_per_dollar', $_POST['points_per_dollar'] ?? '1');
        updateSetting('loyalty_redemption_rate', $_POST['redemption_rate'] ?? '100');
        updateSetting('loyalty_min_redeem', $_POST['min_redeem'] ?? '500');
        updateSetting('loyalty_birthday_bonus', $_POST['birthday_bonus'] ?? '50');
        updateSetting('loyalty_tier_bronze', $_POST['tier_bronze'] ?? '0');
        updateSetting('loyalty_tier_silver', $_POST['tier_silver'] ?? '500');
        updateSetting('loyalty_tier_gold', $_POST['tier_gold'] ?? '2000');
        updateSetting('loyalty_tier_platinum', $_POST['tier_platinum'] ?? '5000');
        addAuditLog('update', 'settings', 0, null, ['action' => 'loyalty_settings_updated']);
        flashMessage('Loyalty settings updated');
    }

    if (isset($_POST['adjust_points'])) {
        $customerId = intval($_POST['customer_id']);
        $points = intval($_POST['points']);
        $type = $points > 0 ? 'earned' : 'adjusted';
        $db->prepare("INSERT INTO loyalty_points (customer_id, points, type, description, created_by) VALUES (?,?,?,?,?)")->execute([
            $customerId, $points, $type, sanitize($_POST['reason'] ?? 'Manual adjustment'), $_SESSION['user_id']
        ]);
        addAuditLog('adjust', 'loyalty_points', $customerId, null, ['points' => $points, 'reason' => $_POST['reason'] ?? '']);
        flashMessage("Points adjusted: " . ($points > 0 ? '+' : '') . $points);
    }

    if (isset($_POST['redeem_points'])) {
        $customerId = intval($_POST['redeem_customer_id']);
        $pointsToRedeem = intval($_POST['redeem_points_amount']);
        $redemptionRate = intval(getSetting('loyalty_redemption_rate', '100'));
        $minRedeem = intval(getSetting('loyalty_min_redeem', '500'));

        // Get customer total points
        $totalPts = $db->prepare("SELECT COALESCE(SUM(points), 0) FROM loyalty_points WHERE customer_id = ?");
        $totalPts->execute([$customerId]);
        $available = intval($totalPts->fetchColumn());

        if ($pointsToRedeem > $available) {
            flashMessage('Insufficient points balance', 'error');
        } elseif ($pointsToRedeem < $minRedeem) {
            flashMessage("Minimum redemption is {$minRedeem} points", 'error');
        } else {
            $discount = $pointsToRedeem / $redemptionRate;
            $db->prepare("INSERT INTO loyalty_points (customer_id, points, type, description, created_by) VALUES (?,?,?,?,?)")->execute([
                $customerId, -$pointsToRedeem, 'redeemed',
                "Redeemed {$pointsToRedeem} pts for " . formatCurrency($discount) . " discount",
                $_SESSION['user_id']
            ]);
            addAuditLog('redeem', 'loyalty_points', $customerId, null, ['points' => $pointsToRedeem, 'discount' => $discount]);
            flashMessage("Redeemed {$pointsToRedeem} points = " . formatCurrency($discount) . " discount");
        }
    }

    if (isset($_POST['save_earn_rules'])) {
        updateSetting('loyalty_bonus_rx', $_POST['bonus_rx'] ?? '0');
        updateSetting('loyalty_bonus_otc', $_POST['bonus_otc'] ?? '0');
        updateSetting('loyalty_bonus_cosmetics', $_POST['bonus_cosmetics'] ?? '0');
        flashMessage('Earn rules updated');
    }

    header('Location: loyalty.php' . (isset($_GET['tab']) ? '?tab=' . $_GET['tab'] : ''));
    exit;
}

// Load settings
$loyaltyEnabled = getSetting('loyalty_enabled', '0') === '1';
$pointsPerDollar = floatval(getSetting('loyalty_points_per_dollar', '1'));
$redemptionRate = intval(getSetting('loyalty_redemption_rate', '100'));
$minRedeem = intval(getSetting('loyalty_min_redeem', '500'));
$birthdayBonus = intval(getSetting('loyalty_birthday_bonus', '50'));

// Tier thresholds (by points)
$tierBronze = intval(getSetting('loyalty_tier_bronze', '0'));
$tierSilver = intval(getSetting('loyalty_tier_silver', '500'));
$tierGold = intval(getSetting('loyalty_tier_gold', '2000'));
$tierPlatinum = intval(getSetting('loyalty_tier_platinum', '5000'));

// Earn rule bonuses
$bonusRx = intval(getSetting('loyalty_bonus_rx', '0'));
$bonusOtc = intval(getSetting('loyalty_bonus_otc', '0'));
$bonusCosmetics = intval(getSetting('loyalty_bonus_cosmetics', '0'));

// Active tab
$activeTab = $_GET['tab'] ?? 'dashboard';

// Customer data with loyalty info
$customers = $db->query("SELECT c.*,
    COALESCE((SELECT SUM(lp.points) FROM loyalty_points lp WHERE lp.customer_id = c.id), 0) as total_points,
    COALESCE((SELECT SUM(lp.points) FROM loyalty_points lp WHERE lp.customer_id = c.id AND lp.points > 0), 0) as points_earned,
    COALESCE(ABS((SELECT SUM(lp.points) FROM loyalty_points lp WHERE lp.customer_id = c.id AND lp.type = 'redeemed')), 0) as points_redeemed,
    (SELECT COUNT(*) FROM sales s WHERE s.customer_id = c.id AND s.status = 'completed') as total_purchases,
    (SELECT COALESCE(SUM(s.total_amount), 0) FROM sales s WHERE s.customer_id = c.id AND s.status = 'completed') as total_spent,
    (SELECT MAX(s.sale_date) FROM sales s WHERE s.customer_id = c.id) as last_visit
    FROM customers c
    ORDER BY total_points DESC")->fetchAll();

// Helper to determine tier
function getLoyaltyTier($points, $silver, $gold, $platinum) {
    if ($points >= $platinum) return ['name' => 'Platinum', 'color' => 'dark', 'icon' => 'gem', 'discount' => 10];
    if ($points >= $gold) return ['name' => 'Gold', 'color' => 'warning', 'icon' => 'star-fill', 'discount' => 7];
    if ($points >= $silver) return ['name' => 'Silver', 'color' => 'info', 'icon' => 'star-half', 'discount' => 5];
    return ['name' => 'Bronze', 'color' => 'secondary', 'icon' => 'star', 'discount' => 0];
}

// Stats
$totalPointsIssued = 0;
try { $totalPointsIssued = $db->query("SELECT COALESCE(SUM(points), 0) FROM loyalty_points WHERE points > 0")->fetchColumn(); } catch (Exception $e) {}
$totalPointsRedeemed = 0;
try { $totalPointsRedeemed = abs($db->query("SELECT COALESCE(SUM(points), 0) FROM loyalty_points WHERE type = 'redeemed'")->fetchColumn()); } catch (Exception $e) {}
$outstandingPoints = $totalPointsIssued - $totalPointsRedeemed;
$outstandingLiability = $outstandingPoints / max(1, $redemptionRate);

// Tier distribution
$tierCounts = ['Bronze' => 0, 'Silver' => 0, 'Gold' => 0, 'Platinum' => 0];
foreach ($customers as $c) {
    $t = getLoyaltyTier($c['total_points'], $tierSilver, $tierGold, $tierPlatinum);
    $tierCounts[$t['name']]++;
}

// Monthly points activity (last 12 months)
$monthlyActivity = [];
try {
    $maStmt = $db->query("SELECT DATE_FORMAT(created_at, '%Y-%m') as month_key,
        MIN(created_at) as month_date,
        COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) as earned,
        COALESCE(ABS(SUM(CASE WHEN type = 'redeemed' THEN points ELSE 0 END)), 0) as redeemed
        FROM loyalty_points
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY month_key ORDER BY month_key");
    $monthlyActivity = $maStmt->fetchAll();
} catch (Exception $e) {}

$maLabels = array_map(function($m) { return formatDate($m['month_date'], 'M Y'); }, $monthlyActivity);
$maEarned = array_map(function($m) { return intval($m['earned']); }, $monthlyActivity);
$maRedeemed = array_map(function($m) { return intval($m['redeemed']); }, $monthlyActivity);

// Customer search for individual lookup
$searchCustomer = $_GET['customer_search'] ?? '';
$viewCustomerId = intval($_GET['view_customer'] ?? 0);
$customerHistory = [];
$viewCustomer = null;

if ($viewCustomerId) {
    $vcStmt = $db->prepare("SELECT c.*,
        COALESCE((SELECT SUM(lp.points) FROM loyalty_points lp WHERE lp.customer_id = c.id), 0) as total_points,
        (SELECT COUNT(*) FROM sales s WHERE s.customer_id = c.id AND s.status = 'completed') as total_purchases,
        (SELECT COALESCE(SUM(s.total_amount), 0) FROM sales s WHERE s.customer_id = c.id AND s.status = 'completed') as total_spent
        FROM customers c WHERE c.id = ?");
    $vcStmt->execute([$viewCustomerId]);
    $viewCustomer = $vcStmt->fetch();

    if ($viewCustomer) {
        $histStmt = $db->prepare("SELECT lp.*, u.full_name as created_by_name
            FROM loyalty_points lp
            LEFT JOIN users u ON lp.created_by = u.id
            WHERE lp.customer_id = ?
            ORDER BY lp.created_at DESC LIMIT 50");
        $histStmt->execute([$viewCustomerId]);
        $customerHistory = $histStmt->fetchAll();
    }
}
?>

<!-- Stats Cards -->
<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card"><div class="stat-label">Total Members</div><div class="stat-value"><?= count($customers) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card success"><div class="stat-label">Points Issued</div><div class="stat-value"><?= number_format($totalPointsIssued) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card warning"><div class="stat-label">Points Redeemed</div><div class="stat-value"><?= number_format($totalPointsRedeemed) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card <?= $loyaltyEnabled ? 'info' : 'danger' ?>"><div class="stat-label">Program Status</div><div class="stat-value"><?= $loyaltyEnabled ? 'Active' : 'Disabled' ?></div><small class="text-muted">Liability: <?= formatCurrency($outstandingLiability) ?></small></div></div>
</div>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-3 no-print">
    <li class="nav-item"><a class="nav-link <?= $activeTab === 'dashboard' ? 'active' : '' ?>" href="?tab=dashboard"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab === 'members' ? 'active' : '' ?>" href="?tab=members"><i class="bi bi-people me-1"></i>Members</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab === 'redeem' ? 'active' : '' ?>" href="?tab=redeem"><i class="bi bi-gift me-1"></i>Redeem</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab === 'history' ? 'active' : '' ?>" href="?tab=history"><i class="bi bi-clock-history me-1"></i>Customer Lookup</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab === 'settings' ? 'active' : '' ?>" href="?tab=settings"><i class="bi bi-gear me-1"></i>Settings</a></li>
</ul>

<?php if ($activeTab === 'dashboard'): ?>
<!-- Dashboard Tab -->
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-graph-up me-2"></i>Monthly Points Activity (12 Months)</h6>
            <canvas id="activityChart" height="100"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-pie-chart me-2"></i>Tier Distribution</h6>
            <canvas id="tierChart" height="200"></canvas>
        </div>
    </div>
</div>

<!-- Tier Benefits -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card p-3 text-center border-secondary">
            <i class="bi bi-star fs-2 text-secondary"></i>
            <h6 class="mt-2">Bronze</h6>
            <p class="small text-muted mb-1"><?= number_format($tierBronze) ?> - <?= number_format($tierSilver - 1) ?> pts</p>
            <span class="badge bg-secondary"><?= $tierCounts['Bronze'] ?> members</span>
            <hr>
            <small>Base earn rate<br><?= $pointsPerDollar ?> pt/$1 spent</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3 text-center border-info">
            <i class="bi bi-star-half fs-2 text-info"></i>
            <h6 class="mt-2">Silver</h6>
            <p class="small text-muted mb-1"><?= number_format($tierSilver) ?> - <?= number_format($tierGold - 1) ?> pts</p>
            <span class="badge bg-info"><?= $tierCounts['Silver'] ?> members</span>
            <hr>
            <small>5% bonus discount<br>Birthday bonus: <?= $birthdayBonus ?> pts</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3 text-center border-warning">
            <i class="bi bi-star-fill fs-2 text-warning"></i>
            <h6 class="mt-2">Gold</h6>
            <p class="small text-muted mb-1"><?= number_format($tierGold) ?> - <?= number_format($tierPlatinum - 1) ?> pts</p>
            <span class="badge bg-warning text-dark"><?= $tierCounts['Gold'] ?> members</span>
            <hr>
            <small>7% bonus discount<br>2x birthday bonus<br>Priority service</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3 text-center border-dark">
            <i class="bi bi-gem fs-2 text-dark"></i>
            <h6 class="mt-2">Platinum</h6>
            <p class="small text-muted mb-1"><?= number_format($tierPlatinum) ?>+ pts</p>
            <span class="badge bg-dark"><?= $tierCounts['Platinum'] ?> members</span>
            <hr>
            <small>10% bonus discount<br>3x birthday bonus<br>Free delivery<br>VIP service</small>
        </div>
    </div>
</div>

<!-- Top Earners -->
<div class="card">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-trophy me-2"></i>Top 10 Loyalty Members</h6></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>#</th><th>Customer</th><th>Points Balance</th><th>Earned</th><th>Redeemed</th><th>Total Spent</th><th>Tier</th><th>Last Visit</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($customers, 0, 10) as $i => $c): ?>
                <?php $tier = getLoyaltyTier($c['total_points'], $tierSilver, $tierGold, $tierPlatinum); ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td>
                        <strong><?= sanitize($c['name']) ?></strong>
                        <?php if ($c['phone']): ?><br><small class="text-muted"><?= sanitize($c['phone']) ?></small><?php endif; ?>
                    </td>
                    <td><strong class="text-primary"><?= number_format($c['total_points']) ?></strong></td>
                    <td class="text-success"><?= number_format($c['points_earned']) ?></td>
                    <td class="text-danger"><?= number_format($c['points_redeemed']) ?></td>
                    <td><?= formatCurrency($c['total_spent']) ?></td>
                    <td><span class="badge bg-<?= $tier['color'] ?>"><i class="bi bi-<?= $tier['icon'] ?> me-1"></i><?= $tier['name'] ?></span></td>
                    <td><small><?= $c['last_visit'] ? formatDate($c['last_visit'], 'M d, Y') : '-' ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($activeTab === 'members'): ?>
<!-- Members Tab -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-people me-2"></i>All Loyalty Members</h6>
        <button onclick="window.print()" class="btn btn-sm btn-outline-dark no-print"><i class="bi bi-printer me-1"></i>Print</button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Phone</th>
                    <th class="text-end">Points Balance</th>
                    <th class="text-end">Total Earned</th>
                    <th class="text-end">Total Redeemed</th>
                    <th class="text-end">Purchases</th>
                    <th class="text-end">Total Spent</th>
                    <th>Tier</th>
                    <th>Last Visit</th>
                    <th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $i => $c): ?>
                <?php $tier = getLoyaltyTier($c['total_points'], $tierSilver, $tierGold, $tierPlatinum); ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= sanitize($c['name']) ?></strong></td>
                    <td><small><?= sanitize($c['phone'] ?? '-') ?></small></td>
                    <td class="text-end"><strong class="text-primary"><?= number_format($c['total_points']) ?></strong></td>
                    <td class="text-end text-success"><?= number_format($c['points_earned']) ?></td>
                    <td class="text-end text-danger"><?= number_format($c['points_redeemed']) ?></td>
                    <td class="text-end"><?= $c['total_purchases'] ?></td>
                    <td class="text-end"><?= formatCurrency($c['total_spent']) ?></td>
                    <td><span class="badge bg-<?= $tier['color'] ?>"><i class="bi bi-<?= $tier['icon'] ?> me-1"></i><?= $tier['name'] ?></span></td>
                    <td><small><?= $c['last_visit'] ? formatDate($c['last_visit'], 'M d, Y') : '-' ?></small></td>
                    <td class="no-print">
                        <div class="btn-group btn-group-sm">
                            <a href="?tab=history&view_customer=<?= $c['id'] ?>" class="btn btn-outline-info" title="View history"><i class="bi bi-clock-history"></i></a>
                            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#adjustPoints"
                                onclick="document.getElementById('adj_customer_id').value=<?= $c['id'] ?>;document.getElementById('adj_customer_name').textContent='<?= sanitize($c['name']) ?>'">
                                <i class="bi bi-plus-slash-minus"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($customers)): ?>
                <tr><td colspan="11" class="text-center text-muted py-3">No customers found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($activeTab === 'redeem'): ?>
<!-- Redeem Tab -->
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card p-4">
            <h6 class="mb-3"><i class="bi bi-gift me-2"></i>Redeem Points</h6>
            <form method="POST" id="redeemForm">
                <div class="mb-3">
                    <label class="form-label">Select Customer</label>
                    <select class="form-select" name="redeem_customer_id" id="redeemCustomerSelect" required onchange="updateRedeemInfo(this)">
                        <option value="">-- Select Customer --</option>
                        <?php foreach ($customers as $c): ?>
                        <?php if ($c['total_points'] > 0): ?>
                        <option value="<?= $c['id'] ?>" data-points="<?= $c['total_points'] ?>" data-name="<?= sanitize($c['name']) ?>">
                            <?= sanitize($c['name']) ?> (<?= number_format($c['total_points']) ?> pts)
                        </option>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="redeemInfo" style="display:none">
                    <div class="alert alert-info mb-3">
                        <div class="d-flex justify-content-between">
                            <span>Available Points:</span>
                            <strong id="availablePoints">0</strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Max Discount:</span>
                            <strong id="maxDiscount">$0.00</strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Minimum to Redeem:</span>
                            <strong><?= number_format($minRedeem) ?> pts</strong>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Points to Redeem</label>
                        <input type="number" class="form-control" name="redeem_points_amount" id="redeemPointsInput" min="<?= $minRedeem ?>" required oninput="calcDiscount()">
                        <div class="form-text">Redemption rate: <?= $redemptionRate ?> points = $1.00</div>
                    </div>
                    <div class="alert alert-success mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fs-5">Discount Amount:</span>
                            <strong class="fs-4" id="discountAmount">$0.00</strong>
                        </div>
                        <div class="text-end"><small id="discountLBP"></small></div>
                    </div>
                    <button type="submit" name="redeem_points" value="1" class="btn btn-success w-100"><i class="bi bi-check-circle me-1"></i>Confirm Redemption</button>
                </div>
            </form>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-4">
            <h6 class="mb-3"><i class="bi bi-info-circle me-2"></i>Redemption Guide</h6>
            <table class="table table-sm">
                <thead><tr><th>Points</th><th>Discount (USD)</th><th>Discount (LBP)</th></tr></thead>
                <tbody>
                    <?php foreach ([100, 250, 500, 1000, 2000, 5000] as $pts): ?>
                    <tr <?= $pts < $minRedeem ? 'class="text-muted"' : '' ?>>
                        <td><?= number_format($pts) ?> pts <?= $pts < $minRedeem ? '(below min)' : '' ?></td>
                        <td><?= formatCurrency($pts / $redemptionRate) ?></td>
                        <td><?= formatCurrency($pts / $redemptionRate * $exchangeRate, 'LBP') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <hr>
            <h6 class="mb-2">Tier Benefits on Redemption</h6>
            <div class="small">
                <p class="mb-1"><span class="badge bg-secondary">Bronze</span> Standard rate: <?= $redemptionRate ?> pts = $1</p>
                <p class="mb-1"><span class="badge bg-info">Silver</span> 5% bonus on redemption value</p>
                <p class="mb-1"><span class="badge bg-warning text-dark">Gold</span> 7% bonus on redemption value</p>
                <p class="mb-1"><span class="badge bg-dark">Platinum</span> 10% bonus on redemption value</p>
            </div>
        </div>
    </div>
</div>

<?php elseif ($activeTab === 'history'): ?>
<!-- Customer Lookup Tab -->
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-search me-2"></i>Find Customer</h6>
            <form method="GET">
                <input type="hidden" name="tab" value="history">
                <div class="mb-2">
                    <select class="form-select" name="view_customer">
                        <option value="">-- Select Customer --</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $viewCustomerId === intval($c['id']) ? 'selected' : '' ?>>
                            <?= sanitize($c['name']) ?> (<?= number_format($c['total_points']) ?> pts)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>View Profile</button>
            </form>
        </div>

        <?php if ($viewCustomer): ?>
        <?php $tier = getLoyaltyTier($viewCustomer['total_points'], $tierSilver, $tierGold, $tierPlatinum); ?>
        <div class="card p-3 mt-3">
            <div class="text-center mb-3">
                <i class="bi bi-person-circle fs-1 text-<?= $tier['color'] ?>"></i>
                <h5 class="mt-2 mb-0"><?= sanitize($viewCustomer['name']) ?></h5>
                <span class="badge bg-<?= $tier['color'] ?> mt-1"><i class="bi bi-<?= $tier['icon'] ?> me-1"></i><?= $tier['name'] ?> Member</span>
            </div>
            <table class="table table-sm mb-0">
                <tr><td class="text-muted">Phone</td><td class="text-end"><?= sanitize($viewCustomer['phone'] ?? '-') ?></td></tr>
                <tr><td class="text-muted">Points Balance</td><td class="text-end fw-bold text-primary"><?= number_format($viewCustomer['total_points']) ?></td></tr>
                <tr><td class="text-muted">Total Purchases</td><td class="text-end"><?= $viewCustomer['total_purchases'] ?></td></tr>
                <tr><td class="text-muted">Total Spent</td><td class="text-end"><?= formatCurrency($viewCustomer['total_spent']) ?></td></tr>
                <tr><td class="text-muted">Redeemable Value</td><td class="text-end text-success"><?= formatCurrency($viewCustomer['total_points'] / max(1, $redemptionRate)) ?></td></tr>
            </table>
            <hr>
            <?php
            $nextTier = null;
            $ptsNeeded = 0;
            if ($viewCustomer['total_points'] < $tierSilver) {
                $nextTier = 'Silver'; $ptsNeeded = $tierSilver - $viewCustomer['total_points'];
            } elseif ($viewCustomer['total_points'] < $tierGold) {
                $nextTier = 'Gold'; $ptsNeeded = $tierGold - $viewCustomer['total_points'];
            } elseif ($viewCustomer['total_points'] < $tierPlatinum) {
                $nextTier = 'Platinum'; $ptsNeeded = $tierPlatinum - $viewCustomer['total_points'];
            }
            if ($nextTier):
            ?>
            <small class="text-muted"><i class="bi bi-arrow-up-circle me-1"></i>Needs <strong><?= number_format($ptsNeeded) ?></strong> more points for <?= $nextTier ?></small>
            <?php else: ?>
            <small class="text-success"><i class="bi bi-check-circle me-1"></i>Highest tier achieved!</small>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <?php if ($viewCustomer && !empty($customerHistory)): ?>
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Points Transaction History - <?= sanitize($viewCustomer['name']) ?></h6></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 data-table">
                    <thead><tr><th>Date</th><th>Type</th><th>Points</th><th>Description</th><th>By</th></tr></thead>
                    <tbody>
                        <?php foreach ($customerHistory as $h): ?>
                        <tr>
                            <td><small><?= formatDate($h['created_at'], 'M d, Y H:i') ?></small></td>
                            <td>
                                <?php
                                $typeBadge = 'secondary';
                                if ($h['type'] === 'earned') $typeBadge = 'success';
                                elseif ($h['type'] === 'redeemed') $typeBadge = 'danger';
                                elseif ($h['type'] === 'adjusted') $typeBadge = 'warning';
                                elseif ($h['type'] === 'bonus') $typeBadge = 'info';
                                ?>
                                <span class="badge bg-<?= $typeBadge ?>"><?= ucfirst(sanitize($h['type'])) ?></span>
                            </td>
                            <td class="fw-bold <?= $h['points'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $h['points'] >= 0 ? '+' : '' ?><?= number_format($h['points']) ?>
                            </td>
                            <td><small><?= sanitize($h['description'] ?? '') ?></small></td>
                            <td><small class="text-muted"><?= sanitize($h['created_by_name'] ?? 'System') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php elseif ($viewCustomer): ?>
        <div class="card p-5 text-center text-muted">
            <i class="bi bi-clock-history fs-1 mb-2"></i>
            <p>No points transactions found for this customer</p>
        </div>
        <?php else: ?>
        <div class="card p-5 text-center text-muted">
            <i class="bi bi-person-circle fs-1 mb-2"></i>
            <p>Select a customer to view their loyalty history</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($activeTab === 'settings'): ?>
<!-- Settings Tab -->
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-gear me-2"></i>General Settings</h6>
            <form method="POST" action="?tab=settings">
                <div class="form-check form-switch mb-3">
                    <input type="checkbox" class="form-check-input" name="loyalty_enabled" <?= $loyaltyEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label">Enable Loyalty Program</label>
                </div>
                <div class="row g-2">
                    <div class="col-md-6 mb-2">
                        <label class="form-label small">Points per $1 spent</label>
                        <input type="number" class="form-control form-control-sm" name="points_per_dollar" value="<?= $pointsPerDollar ?>" step="0.1" min="0">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label small">Points for $1 discount</label>
                        <input type="number" class="form-control form-control-sm" name="redemption_rate" value="<?= $redemptionRate ?>" min="1">
                        <small class="text-muted"><?= $redemptionRate ?> pts = $1.00</small>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label small">Min points to redeem</label>
                        <input type="number" class="form-control form-control-sm" name="min_redeem" value="<?= $minRedeem ?>" min="0">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label small">Birthday bonus points</label>
                        <input type="number" class="form-control form-control-sm" name="birthday_bonus" value="<?= $birthdayBonus ?>" min="0">
                    </div>
                </div>
                <hr>
                <h6 class="small text-muted mb-2">Tier Thresholds (Points)</h6>
                <div class="row g-2">
                    <div class="col-6 mb-2">
                        <label class="form-label small">Bronze starts at</label>
                        <input type="number" class="form-control form-control-sm" name="tier_bronze" value="<?= $tierBronze ?>" min="0">
                    </div>
                    <div class="col-6 mb-2">
                        <label class="form-label small">Silver starts at</label>
                        <input type="number" class="form-control form-control-sm" name="tier_silver" value="<?= $tierSilver ?>" min="0">
                    </div>
                    <div class="col-6 mb-2">
                        <label class="form-label small">Gold starts at</label>
                        <input type="number" class="form-control form-control-sm" name="tier_gold" value="<?= $tierGold ?>" min="0">
                    </div>
                    <div class="col-6 mb-2">
                        <label class="form-label small">Platinum starts at</label>
                        <input type="number" class="form-control form-control-sm" name="tier_platinum" value="<?= $tierPlatinum ?>" min="0">
                    </div>
                </div>
                <button type="submit" name="update_settings" value="1" class="btn btn-sm btn-primary w-100 mt-2">Save Settings</button>
            </form>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-plus-circle me-2"></i>Category Bonus Points</h6>
            <form method="POST" action="?tab=settings">
                <p class="small text-muted">Extra bonus points per purchase in each category (added on top of base earn rate):</p>
                <div class="mb-2">
                    <label class="form-label small">Prescription (Rx) bonus pts</label>
                    <input type="number" class="form-control form-control-sm" name="bonus_rx" value="<?= $bonusRx ?>" min="0">
                </div>
                <div class="mb-2">
                    <label class="form-label small">OTC bonus pts</label>
                    <input type="number" class="form-control form-control-sm" name="bonus_otc" value="<?= $bonusOtc ?>" min="0">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Cosmetics/Beauty bonus pts</label>
                    <input type="number" class="form-control form-control-sm" name="bonus_cosmetics" value="<?= $bonusCosmetics ?>" min="0">
                </div>
                <button type="submit" name="save_earn_rules" value="1" class="btn btn-sm btn-primary w-100">Save Earn Rules</button>
            </form>
        </div>

        <div class="card p-3">
            <h6><i class="bi bi-plus-slash-minus me-2"></i>Manual Points Adjustment</h6>
            <form method="POST" action="?tab=settings">
                <div class="mb-2">
                    <label class="form-label small">Customer</label>
                    <select class="form-select form-select-sm" name="customer_id" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?> (<?= number_format($c['total_points']) ?> pts)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Points (+/-)</label>
                    <input type="number" class="form-control form-control-sm" name="points" required>
                    <small class="text-muted">Negative number to deduct</small>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Reason</label>
                    <input type="text" class="form-control form-control-sm" name="reason" placeholder="Reason for adjustment">
                </div>
                <button type="submit" name="adjust_points" value="1" class="btn btn-sm btn-warning w-100">Adjust Points</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Adjust Points Modal (for Members tab) -->
<div class="modal fade" id="adjustPoints"><div class="modal-dialog modal-sm"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title">Adjust Points</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <p>Customer: <strong id="adj_customer_name"></strong></p>
        <input type="hidden" name="customer_id" id="adj_customer_id">
        <div class="mb-2"><label class="form-label">Points (+/-)</label><input type="number" class="form-control" name="points" required></div>
        <div><label class="form-label">Reason</label><input type="text" class="form-control" name="reason"></div>
    </div>
    <div class="modal-footer"><button type="submit" name="adjust_points" value="1" class="btn btn-primary">Adjust</button></div>
    </form>
</div></div></div>

<?php
$extraScripts = "<script>
// Redeem calculation
var redemptionRate = {$redemptionRate};
var exchangeRate = {$exchangeRate};

function updateRedeemInfo(sel) {
    var opt = sel.options[sel.selectedIndex];
    var info = document.getElementById('redeemInfo');
    if (!opt.value) { info.style.display = 'none'; return; }
    var pts = parseInt(opt.dataset.points || 0);
    document.getElementById('availablePoints').textContent = pts.toLocaleString();
    document.getElementById('maxDiscount').textContent = '\$' + (pts / redemptionRate).toFixed(2);
    document.getElementById('redeemPointsInput').max = pts;
    info.style.display = '';
}

function calcDiscount() {
    var pts = parseInt(document.getElementById('redeemPointsInput').value) || 0;
    var discount = pts / redemptionRate;
    document.getElementById('discountAmount').textContent = '\$' + discount.toFixed(2);
    document.getElementById('discountLBP').textContent = (discount * exchangeRate).toLocaleString() + ' L.L.';
}
" . ($activeTab === 'dashboard' ? "
// Monthly Activity Chart
new Chart(document.getElementById('activityChart'), {
    type: 'bar',
    data: {
        labels: " . json_encode($maLabels) . ",
        datasets: [
            { label: 'Earned', data: " . json_encode($maEarned) . ", backgroundColor: 'rgba(25,135,84,0.7)' },
            { label: 'Redeemed', data: " . json_encode($maRedeemed) . ", backgroundColor: 'rgba(220,53,69,0.7)' }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true } }
    }
});

// Tier Distribution
new Chart(document.getElementById('tierChart'), {
    type: 'doughnut',
    data: {
        labels: ['Bronze', 'Silver', 'Gold', 'Platinum'],
        datasets: [{
            data: [" . $tierCounts['Bronze'] . ", " . $tierCounts['Silver'] . ", " . $tierCounts['Gold'] . ", " . $tierCounts['Platinum'] . "],
            backgroundColor: ['#6c757d', '#0dcaf0', '#ffc107', '#212529']
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } }
    }
});
" : "") . "
</script>";

require_once __DIR__ . '/../../includes/footer.php';
?>
