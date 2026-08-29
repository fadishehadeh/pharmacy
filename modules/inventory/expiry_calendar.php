<?php
$pageTitle = 'Expiry Calendar';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

// Calendar navigation
$year = intval($_GET['year'] ?? date('Y'));
$month = intval($_GET['month'] ?? date('n'));
$view = $_GET['view'] ?? 'calendar';

// Clamp month and adjust year
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$firstDay = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = date('t', $firstDay);
$startDow = date('w', $firstDay); // 0=Sun
$monthName = date('F Y', $firstDay);

$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$today = date('Y-m-d');
$currentMonthStart = date('Y-m-01', $firstDay);
$currentMonthEnd = date('Y-m-t', $firstDay);

// Fetch all medicines expiring in this month
$monthExpiring = $db->prepare("SELECT m.id, m.name, m.expiry_date, m.quantity_in_stock, m.sell_price,
    m.batch_number, c.name as category_name
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE m.is_active = 1 AND m.quantity_in_stock > 0
    AND m.expiry_date BETWEEN ? AND ?
    ORDER BY m.expiry_date ASC, m.name ASC");
$monthExpiring->execute([$currentMonthStart, $currentMonthEnd]);
$monthExpiring = $monthExpiring->fetchAll();

// Group by day
$expiryByDay = [];
foreach ($monthExpiring as $med) {
    $day = intval(date('j', strtotime($med['expiry_date'])));
    $expiryByDay[$day][] = $med;
}

// Sidebar stats
$expiringThisMonth = count($monthExpiring);

$nextMonthStart = date('Y-m-01', mktime(0, 0, 0, $month + 1, 1, $year));
$nextMonthEnd = date('Y-m-t', mktime(0, 0, 0, $month + 1, 1, $year));
$expiringNextMonth = intval($db->prepare("SELECT COUNT(*) FROM medicines WHERE is_active = 1 AND quantity_in_stock > 0 AND expiry_date BETWEEN ? AND ?")->execute([$nextMonthStart, $nextMonthEnd]) ? $db->prepare("SELECT COUNT(*) FROM medicines WHERE is_active = 1 AND quantity_in_stock > 0 AND expiry_date BETWEEN ? AND ?") : 0);
$stmtNext = $db->prepare("SELECT COUNT(*) FROM medicines WHERE is_active = 1 AND quantity_in_stock > 0 AND expiry_date BETWEEN ? AND ?");
$stmtNext->execute([$nextMonthStart, $nextMonthEnd]);
$expiringNextMonth = intval($stmtNext->fetchColumn());

$threeMonthEnd = date('Y-m-t', mktime(0, 0, 0, $month + 3, 1, $year));
$stmt3 = $db->prepare("SELECT COUNT(*) FROM medicines WHERE is_active = 1 AND quantity_in_stock > 0 AND expiry_date BETWEEN ? AND ?");
$stmt3->execute([$currentMonthStart, $threeMonthEnd]);
$expiringThreeMonths = intval($stmt3->fetchColumn());

$expiredCount = intval($db->query("SELECT COUNT(*) FROM medicines WHERE is_active = 1 AND quantity_in_stock > 0 AND expiry_date < CURDATE()")->fetchColumn());

// For selected day detail
$selectedDay = isset($_GET['day']) ? intval($_GET['day']) : null;
$selectedMeds = [];
if ($selectedDay && isset($expiryByDay[$selectedDay])) {
    $selectedMeds = $expiryByDay[$selectedDay];
}

// For list view: fetch 3-month window
$listData = [];
if ($view === 'list') {
    $stmtList = $db->prepare("SELECT m.id, m.name, m.expiry_date, m.quantity_in_stock, m.sell_price,
        m.batch_number, c.name as category_name,
        DATEDIFF(m.expiry_date, CURDATE()) as days_left
        FROM medicines m
        LEFT JOIN categories c ON m.category_id = c.id
        WHERE m.is_active = 1 AND m.quantity_in_stock > 0
        AND m.expiry_date BETWEEN ? AND ?
        ORDER BY m.expiry_date ASC");
    $stmtList->execute([$currentMonthStart, $threeMonthEnd]);
    $listData = $stmtList->fetchAll();
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center gap-2">
        <a href="?year=<?= $prevYear ?>&month=<?= $prevMonth ?>&view=<?= sanitize($view) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
        <h5 class="mb-0"><?= $monthName ?></h5>
        <a href="?year=<?= $nextYear ?>&month=<?= $nextMonth ?>&view=<?= sanitize($view) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
        <?php if ($year != date('Y') || $month != date('n')): ?>
        <a href="?view=<?= sanitize($view) ?>" class="btn btn-sm btn-outline-primary">Today</a>
        <?php endif; ?>
    </div>
    <div class="btn-group btn-group-sm no-print">
        <a href="?year=<?= $year ?>&month=<?= $month ?>&view=calendar" class="btn btn-<?= $view === 'calendar' ? 'dark' : 'outline-dark' ?>"><i class="bi bi-calendar3 me-1"></i>Calendar</a>
        <a href="?year=<?= $year ?>&month=<?= $month ?>&view=list" class="btn btn-<?= $view === 'list' ? 'dark' : 'outline-dark' ?>"><i class="bi bi-list-ul me-1"></i>List</a>
    </div>
</div>

<div class="row g-3">
    <!-- Main Content -->
    <div class="col-lg-9">
        <?php if ($view === 'calendar'): ?>
        <!-- Calendar View -->
        <div class="card">
            <div class="table-responsive">
                <table class="table table-bordered mb-0" style="table-layout:fixed">
                    <thead>
                        <tr class="text-center bg-light">
                            <th style="width:14.28%">Sun</th>
                            <th style="width:14.28%">Mon</th>
                            <th style="width:14.28%">Tue</th>
                            <th style="width:14.28%">Wed</th>
                            <th style="width:14.28%">Thu</th>
                            <th style="width:14.28%">Fri</th>
                            <th style="width:14.28%">Sat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $day = 1;
                        $cellCount = 0;
                        $totalCells = $startDow + $daysInMonth;
                        $rows = ceil($totalCells / 7);

                        for ($row = 0; $row < $rows; $row++):
                        ?>
                        <tr>
                            <?php for ($col = 0; $col < 7; $col++):
                                $cellCount++;
                                if ($cellCount <= $startDow || $day > $daysInMonth):
                            ?>
                                <td class="bg-light" style="height:90px"></td>
                            <?php else:
                                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                                $isToday = ($dateStr === $today);
                                $isSelected = ($day === $selectedDay);
                                $count = isset($expiryByDay[$day]) ? count($expiryByDay[$day]) : 0;

                                // Color coding
                                $cellBg = '';
                                $textColor = '';
                                if ($count > 0) {
                                    if (strtotime($dateStr) < strtotime($today)) {
                                        $cellBg = 'rgba(220,38,38,0.15)'; // red - expired
                                        $textColor = '#DC2626';
                                    } elseif ($year == date('Y') && $month == date('n')) {
                                        $cellBg = 'rgba(249,115,22,0.15)'; // orange - this month
                                        $textColor = '#EA580C';
                                    } elseif ($month == date('n') + 1 || ($month == 1 && date('n') == 12)) {
                                        $cellBg = 'rgba(234,179,8,0.15)'; // yellow - next month
                                        $textColor = '#CA8A04';
                                    } else {
                                        $cellBg = 'rgba(34,197,94,0.15)'; // green - 2-3 months
                                        $textColor = '#16A34A';
                                    }
                                }
                            ?>
                                <td style="height:90px;vertical-align:top;cursor:<?= $count > 0 ? 'pointer' : 'default' ?>;background:<?= $cellBg ?>;<?= $isToday ? 'border:2px solid #2563EB;' : '' ?><?= $isSelected ? 'border:2px solid #1F2937;' : '' ?>"
                                    <?php if ($count > 0): ?>onclick="location.href='?year=<?= $year ?>&month=<?= $month ?>&day=<?= $day ?>&view=calendar'"<?php endif; ?>>
                                    <div class="d-flex justify-content-between align-items-start p-1">
                                        <span class="<?= $isToday ? 'badge bg-primary rounded-pill' : 'small' ?>"><?= $day ?></span>
                                        <?php if ($count > 0): ?>
                                        <span class="badge rounded-pill" style="background:<?= $textColor ?>;font-size:0.7rem"><?= $count ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($count > 0 && $count <= 2): ?>
                                    <div class="px-1">
                                        <?php foreach (array_slice($expiryByDay[$day], 0, 2) as $em): ?>
                                        <div class="text-truncate" style="font-size:0.65rem;color:<?= $textColor ?>" title="<?= sanitize($em['name']) ?>"><?= sanitize($em['name']) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php elseif ($count > 2): ?>
                                    <div class="px-1">
                                        <div class="text-truncate" style="font-size:0.65rem;color:<?= $textColor ?>"><?= sanitize($expiryByDay[$day][0]['name']) ?></div>
                                        <div style="font-size:0.65rem;color:<?= $textColor ?>">+<?= $count - 1 ?> more</div>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            <?php $day++; endif; ?>
                            <?php endfor; ?>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Selected Day Detail -->
        <?php if ($selectedDay && !empty($selectedMeds)): ?>
        <div class="card p-3 mt-3">
            <h6><i class="bi bi-calendar-event me-2"></i>Expiring on <?= date('F j, Y', mktime(0, 0, 0, $month, $selectedDay, $year)) ?> (<?= count($selectedMeds) ?> items)</h6>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Category</th><th>Batch</th><th>Stock</th><th class="text-end">Value</th></tr></thead>
                    <tbody>
                        <?php foreach ($selectedMeds as $sm): ?>
                        <tr>
                            <td><strong class="small"><?= sanitize($sm['name']) ?></strong></td>
                            <td><small><?= sanitize($sm['category_name'] ?? '-') ?></small></td>
                            <td><small><?= sanitize($sm['batch_number'] ?? '-') ?></small></td>
                            <td><?= $sm['quantity_in_stock'] ?></td>
                            <td class="text-end text-warning"><?= formatCurrency($sm['sell_price'] * $sm['quantity_in_stock']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- List View -->
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Expiry List - <?= $monthName ?> to <?= date('F Y', strtotime($threeMonthEnd)) ?></h6></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 data-table">
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Category</th>
                            <th>Batch</th>
                            <th>Expiry Date</th>
                            <th>Days Left</th>
                            <th>Stock</th>
                            <th class="text-end">Value</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($listData as $ld): ?>
                        <?php
                        $dl = $ld['days_left'];
                        if ($dl < 0) { $statusBg = 'danger'; $statusLabel = 'Expired'; }
                        elseif ($dl <= 30) { $statusBg = 'danger'; $statusLabel = 'Critical'; }
                        elseif ($dl <= 60) { $statusBg = 'warning'; $statusLabel = 'Urgent'; }
                        elseif ($dl <= 90) { $statusBg = 'info'; $statusLabel = 'Soon'; }
                        else { $statusBg = 'secondary'; $statusLabel = 'OK'; }
                        ?>
                        <tr class="<?= $dl < 0 ? 'table-danger' : ($dl <= 30 ? 'table-warning' : '') ?>">
                            <td><strong class="small"><?= sanitize($ld['name']) ?></strong></td>
                            <td><small><?= sanitize($ld['category_name'] ?? '-') ?></small></td>
                            <td><small><?= sanitize($ld['batch_number'] ?? '-') ?></small></td>
                            <td><small><?= formatDate($ld['expiry_date'], 'M d, Y') ?></small></td>
                            <td>
                                <?php if ($dl < 0): ?>
                                <span class="badge bg-danger"><?= abs($dl) ?>d ago</span>
                                <?php else: ?>
                                <span class="badge bg-<?= $statusBg ?>"><?= $dl ?>d</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $ld['quantity_in_stock'] ?></td>
                            <td class="text-end"><?= formatCurrency($ld['sell_price'] * $ld['quantity_in_stock']) ?></td>
                            <td><span class="badge bg-<?= $statusBg ?>"><?= $statusLabel ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($listData)): ?><tr><td colspan="8" class="text-center text-muted py-3">No expiring medicines in this period</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Legend -->
        <div class="card p-3 mt-3">
            <div class="d-flex flex-wrap gap-3">
                <div class="d-flex align-items-center gap-1"><span class="d-inline-block rounded" style="width:16px;height:16px;background:rgba(220,38,38,0.3)"></span><small>Expired</small></div>
                <div class="d-flex align-items-center gap-1"><span class="d-inline-block rounded" style="width:16px;height:16px;background:rgba(249,115,22,0.3)"></span><small>This Month</small></div>
                <div class="d-flex align-items-center gap-1"><span class="d-inline-block rounded" style="width:16px;height:16px;background:rgba(234,179,8,0.3)"></span><small>Next Month</small></div>
                <div class="d-flex align-items-center gap-1"><span class="d-inline-block rounded" style="width:16px;height:16px;background:rgba(34,197,94,0.3)"></span><small>2-3 Months</small></div>
                <div class="d-flex align-items-center gap-1"><span class="d-inline-block border border-primary rounded" style="width:16px;height:16px;border-width:2px!important"></span><small>Today</small></div>
            </div>
        </div>
    </div>

    <!-- Sidebar Summary -->
    <div class="col-lg-3">
        <div class="card p-3 mb-3 border-danger">
            <h6 class="text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Already Expired</h6>
            <div class="display-6 fw-bold text-danger"><?= $expiredCount ?></div>
            <small class="text-muted">items still in stock</small>
        </div>

        <div class="card p-3 mb-3">
            <h6><i class="bi bi-calendar-check me-2"></i>Expiry Summary</h6>
            <div class="list-group list-group-flush">
                <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                    <small>This Month</small>
                    <span class="badge bg-warning rounded-pill"><?= $expiringThisMonth ?></span>
                </div>
                <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                    <small>Next Month</small>
                    <span class="badge bg-info rounded-pill"><?= $expiringNextMonth ?></span>
                </div>
                <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                    <small>Next 3 Months</small>
                    <span class="badge bg-secondary rounded-pill"><?= $expiringThreeMonths ?></span>
                </div>
            </div>
        </div>

        <?php
        // Upcoming expiries (soonest 10)
        $upcoming = $db->query("SELECT m.name, m.expiry_date, m.quantity_in_stock,
            DATEDIFF(m.expiry_date, CURDATE()) as days_left
            FROM medicines m
            WHERE m.is_active = 1 AND m.quantity_in_stock > 0
            AND m.expiry_date >= CURDATE()
            ORDER BY m.expiry_date ASC LIMIT 10")->fetchAll();
        ?>
        <div class="card p-3 mb-3">
            <h6><i class="bi bi-clock me-2"></i>Soonest Expiring</h6>
            <div class="list-group list-group-flush" style="max-height:400px;overflow-y:auto">
                <?php foreach ($upcoming as $u): ?>
                <div class="list-group-item px-0 py-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <small class="fw-bold"><?= sanitize($u['name']) ?></small>
                        <span class="badge bg-<?= $u['days_left'] <= 30 ? 'danger' : ($u['days_left'] <= 60 ? 'warning' : 'info') ?>"><?= $u['days_left'] ?>d</span>
                    </div>
                    <small class="text-muted"><?= formatDate($u['expiry_date'], 'M d, Y') ?> | <?= $u['quantity_in_stock'] ?> units</small>
                </div>
                <?php endforeach; ?>
                <?php if (empty($upcoming)): ?>
                <div class="text-center text-muted py-3"><small>No upcoming expiries</small></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card p-3 no-print">
            <h6><i class="bi bi-link-45deg me-2"></i>Related</h6>
            <div class="list-group list-group-flush">
                <a href="<?= BASE_URL ?>/modules/inventory/near_expiry_deals.php" class="list-group-item list-group-item-action px-0 py-2 small"><i class="bi bi-tag me-2"></i>Near-Expiry Deals</a>
                <a href="<?= BASE_URL ?>/modules/reports/expiry_forecast.php" class="list-group-item list-group-item-action px-0 py-2 small"><i class="bi bi-graph-up me-2"></i>Expiry Forecast</a>
                <a href="<?= BASE_URL ?>/modules/inventory/disposal.php" class="list-group-item list-group-item-action px-0 py-2 small"><i class="bi bi-trash me-2"></i>Waste & Disposal</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
