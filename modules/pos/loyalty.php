<?php
$pageTitle = 'Customer Loyalty';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_settings'])) {
        updateSetting('loyalty_enabled', isset($_POST['loyalty_enabled']) ? '1' : '0');
        updateSetting('loyalty_points_per_dollar', $_POST['points_per_dollar'] ?? '1');
        updateSetting('loyalty_redemption_rate', $_POST['redemption_rate'] ?? '100');
        updateSetting('loyalty_min_redeem', $_POST['min_redeem'] ?? '500');
        flashMessage('Loyalty settings updated');
    }

    if (isset($_POST['adjust_points'])) {
        $customerId = intval($_POST['customer_id']);
        $points = intval($_POST['points']);
        $type = $points > 0 ? 'earned' : 'adjusted';
        $db->prepare("INSERT INTO loyalty_points (customer_id, points, type, description, created_by) VALUES (?,?,?,?,?)")->execute([
            $customerId, $points, $type, $_POST['reason'] ?? 'Manual adjustment', $_SESSION['user_id']
        ]);
        flashMessage("Points adjusted: " . ($points > 0 ? '+' : '') . $points);
    }

    header('Location: loyalty.php');
    exit;
}

$loyaltyEnabled = getSetting('loyalty_enabled', '0') === '1';
$pointsPerDollar = floatval(getSetting('loyalty_points_per_dollar', '1'));
$redemptionRate = intval(getSetting('loyalty_redemption_rate', '100'));
$minRedeem = intval(getSetting('loyalty_min_redeem', '500'));

$customers = $db->query("SELECT c.*,
    COALESCE((SELECT SUM(lp.points) FROM loyalty_points lp WHERE lp.customer_id = c.id), 0) as total_points,
    (SELECT COUNT(*) FROM sales s WHERE s.customer_id = c.id AND s.status = 'completed') as total_purchases,
    (SELECT COALESCE(SUM(s.total_amount), 0) FROM sales s WHERE s.customer_id = c.id AND s.status = 'completed') as total_spent,
    (SELECT MAX(s.sale_date) FROM sales s WHERE s.customer_id = c.id) as last_visit
    FROM customers c
    ORDER BY total_points DESC")->fetchAll();

$totalPointsIssued = 0;
try { $totalPointsIssued = $db->query("SELECT COALESCE(SUM(points), 0) FROM loyalty_points WHERE points > 0")->fetchColumn(); } catch (Exception $e) {}
$totalPointsRedeemed = 0;
try { $totalPointsRedeemed = abs($db->query("SELECT COALESCE(SUM(points), 0) FROM loyalty_points WHERE type = 'redeemed'")->fetchColumn()); } catch (Exception $e) {}
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card"><div class="stat-label">Total Customers</div><div class="stat-value"><?= count($customers) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card success"><div class="stat-label">Points Issued</div><div class="stat-value"><?= number_format($totalPointsIssued) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card warning"><div class="stat-label">Points Redeemed</div><div class="stat-value"><?= number_format($totalPointsRedeemed) ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card <?= $loyaltyEnabled ? 'info' : 'danger' ?>"><div class="stat-label">Loyalty Program</div><div class="stat-value"><?= $loyaltyEnabled ? 'Active' : 'Disabled' ?></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-people me-2"></i>Customer Loyalty Rankings</h6></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 data-table">
                    <thead><tr><th>#</th><th>Customer</th><th>Points</th><th>Purchases</th><th>Total Spent</th><th>Last Visit</th><th>Tier</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($customers as $i => $c): ?>
                        <?php
                        $tier = 'Bronze';
                        $tierColor = 'secondary';
                        if ($c['total_spent'] >= 5000) { $tier = 'Platinum'; $tierColor = 'dark'; }
                        elseif ($c['total_spent'] >= 2000) { $tier = 'Gold'; $tierColor = 'warning'; }
                        elseif ($c['total_spent'] >= 500) { $tier = 'Silver'; $tierColor = 'info'; }
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <strong><?= sanitize($c['name']) ?></strong>
                                <?php if ($c['phone']): ?><br><small class="text-muted"><?= sanitize($c['phone']) ?></small><?php endif; ?>
                            </td>
                            <td><strong class="text-primary"><?= number_format($c['total_points']) ?></strong></td>
                            <td><?= $c['total_purchases'] ?></td>
                            <td><?= formatCurrency($c['total_spent']) ?></td>
                            <td><small><?= $c['last_visit'] ? formatDate($c['last_visit'], 'M d, Y') : '-' ?></small></td>
                            <td><span class="badge bg-<?= $tierColor ?>"><?= $tier ?></span></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#adjustPoints" onclick="document.getElementById('adj_customer_id').value=<?= $c['id'] ?>;document.getElementById('adj_customer_name').textContent='<?= sanitize($c['name']) ?>'"><i class="bi bi-plus-slash-minus"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-gear me-2"></i>Loyalty Settings</h6>
            <form method="POST">
                <div class="form-check form-switch mb-3">
                    <input type="checkbox" class="form-check-input" name="loyalty_enabled" <?= $loyaltyEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label">Enable Loyalty Program</label>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Points per $1 spent</label>
                    <input type="number" class="form-control form-control-sm" name="points_per_dollar" value="<?= $pointsPerDollar ?>" step="0.1" min="0">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Points for $1 discount</label>
                    <input type="number" class="form-control form-control-sm" name="redemption_rate" value="<?= $redemptionRate ?>" min="1">
                    <small class="text-muted"><?= $redemptionRate ?> points = $1.00 discount</small>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Min points to redeem</label>
                    <input type="number" class="form-control form-control-sm" name="min_redeem" value="<?= $minRedeem ?>" min="0">
                </div>
                <button type="submit" name="update_settings" value="1" class="btn btn-sm btn-primary w-100">Save Settings</button>
            </form>
        </div>

        <div class="card p-3">
            <h6><i class="bi bi-star me-2"></i>Tier Levels</h6>
            <div class="mb-2"><span class="badge bg-secondary">Bronze</span> <small>$0 - $499</small></div>
            <div class="mb-2"><span class="badge bg-info">Silver</span> <small>$500 - $1,999</small></div>
            <div class="mb-2"><span class="badge bg-warning">Gold</span> <small>$2,000 - $4,999</small></div>
            <div><span class="badge bg-dark">Platinum</span> <small>$5,000+</small></div>
        </div>
    </div>
</div>

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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
