<?php
$pageTitle = 'User Activity';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('admin')) { header('Location: ' . BASE_URL); exit; }
$db = getDB();

$userFilter = $_GET['user'] ?? '';
$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo = $_GET['to'] ?? date('Y-m-d');

$where = "WHERE ll.login_time BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)";
$params = [$dateFrom, $dateTo];
if ($userFilter) { $where .= " AND ll.user_id = ?"; $params[] = intval($userFilter); }

$logins = $db->prepare("SELECT ll.*, u.full_name, u.username, u.role
    FROM login_log ll
    JOIN users u ON ll.user_id = u.id
    $where ORDER BY ll.login_time DESC LIMIT 200");
$logins->execute($params);
$logins = $logins->fetchAll();

$users = $db->query("SELECT id, username, full_name FROM users ORDER BY username")->fetchAll();

$todayLogins = $db->query("SELECT COUNT(DISTINCT user_id) FROM login_log WHERE DATE(login_time) = CURDATE() AND status = 'success'")->fetchColumn();
$failedToday = $db->query("SELECT COUNT(*) FROM login_log WHERE DATE(login_time) = CURDATE() AND status = 'failed'")->fetchColumn();
$activeNow = $db->query("SELECT COUNT(*) FROM login_log WHERE status = 'success' AND logout_time IS NULL AND login_time > DATE_SUB(NOW(), INTERVAL 8 HOUR)")->fetchColumn();

$userHours = $db->prepare("SELECT u.full_name, u.username,
    COUNT(DISTINCT DATE(ll.login_time)) as days_active,
    SEC_TO_TIME(SUM(TIMESTAMPDIFF(SECOND, ll.login_time, COALESCE(ll.logout_time, NOW())))) as total_hours
    FROM login_log ll JOIN users u ON ll.user_id = u.id
    WHERE ll.status = 'success' AND ll.login_time BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY ll.user_id ORDER BY total_hours DESC");
$userHours->execute([$dateFrom, $dateTo]);
$userHours = $userHours->fetchAll();
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card"><div class="stat-label">Users Today</div><div class="stat-value"><?= $todayLogins ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card success"><div class="stat-label">Active Now</div><div class="stat-value"><?= $activeNow ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card danger"><div class="stat-label">Failed Logins Today</div><div class="stat-value"><?= $failedToday ?></div></div></div>
    <div class="col-md-3">
        <div class="card p-3">
            <a href="<?= BASE_URL ?>/modules/settings/index.php" class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-arrow-left me-1"></i>Back to Settings</a>
        </div>
    </div>
</div>

<div class="card p-3 mb-3">
    <form class="d-flex gap-2 flex-wrap" method="GET">
        <select class="form-select form-select-sm" name="user" style="width:auto">
            <option value="">All Users</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>" <?= $userFilter == $u['id'] ? 'selected' : '' ?>><?= sanitize($u['full_name'] ?? $u['username']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" class="form-control form-control-sm" name="from" value="<?= sanitize($dateFrom) ?>" style="width:auto">
        <input type="date" class="form-control form-control-sm" name="to" value="<?= sanitize($dateTo) ?>" style="width:auto">
        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
    </form>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Login History</h6></div>
            <div class="table-responsive" style="max-height:600px;overflow-y:auto">
                <table class="table table-sm table-hover mb-0">
                    <thead class="sticky-top bg-white"><tr><th>User</th><th>Role</th><th>Login</th><th>Logout</th><th>Duration</th><th>IP</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($logins as $l): ?>
                        <tr>
                            <td><strong class="small"><?= sanitize($l['full_name'] ?? $l['username']) ?></strong></td>
                            <td><span class="badge bg-<?= $l['role'] === 'admin' ? 'danger' : ($l['role'] === 'pharmacist' ? 'primary' : 'secondary') ?>"><?= ucfirst($l['role']) ?></span></td>
                            <td><small><?= formatDate($l['login_time'], 'M d, H:i:s') ?></small></td>
                            <td><small><?= $l['logout_time'] ? formatDate($l['logout_time'], 'H:i:s') : '<span class="text-success">Active</span>' ?></small></td>
                            <td>
                                <?php if ($l['status'] === 'success'):
                                    $end = $l['logout_time'] ? strtotime($l['logout_time']) : time();
                                    $dur = $end - strtotime($l['login_time']);
                                    $hours = floor($dur / 3600);
                                    $mins = floor(($dur % 3600) / 60);
                                ?>
                                <small><?= $hours ?>h <?= $mins ?>m</small>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                            <td><small><?= sanitize($l['ip_address'] ?? '-') ?></small></td>
                            <td>
                                <?php if ($l['status'] === 'success'): ?>
                                <span class="badge bg-success">OK</span>
                                <?php else: ?>
                                <span class="badge bg-danger">Failed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-person-badge me-2"></i>User Hours (<?= formatDate($dateFrom, 'M d') ?> - <?= formatDate($dateTo, 'M d') ?>)</h6>
            <div class="list-group list-group-flush">
                <?php foreach ($userHours as $uh): ?>
                <div class="list-group-item px-0 py-2 d-flex justify-content-between">
                    <div>
                        <strong class="small"><?= sanitize($uh['full_name'] ?? $uh['username']) ?></strong><br>
                        <small class="text-muted"><?= $uh['days_active'] ?> days active</small>
                    </div>
                    <span class="badge bg-primary align-self-center"><?= $uh['total_hours'] ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($userHours)): ?><p class="text-muted small py-2">No data for this period</p><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
