<?php
$pageTitle = 'Shift Management';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('admin')) { header('Location: ' . BASE_URL); exit; }
$db = getDB();

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_shift'])) {
        $employeeId = intval($_POST['employee_id']);
        $shiftType = $_POST['shift_type'];
        $shiftDate = $_POST['shift_date'];
        $startTime = $_POST['start_time'];
        $endTime = $_POST['end_time'];
        $notes = $_POST['notes'] ?? '';

        // Set times based on shift type
        if ($shiftType === 'morning') { $startTime = '08:00'; $endTime = '16:00'; }
        elseif ($shiftType === 'afternoon') { $startTime = '16:00'; $endTime = '00:00'; }
        elseif ($shiftType === 'night') { $startTime = '00:00'; $endTime = '08:00'; }

        $db->prepare("INSERT INTO employee_shifts (employee_id, shift_type, shift_date, start_time, end_time, notes, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute([$employeeId, $shiftType, $shiftDate, $startTime, $endTime, $notes ?: null, $_SESSION['user_id'] ?? null]);
        $shiftId = $db->lastInsertId();

        addAuditLog('create', 'employee_shifts', $shiftId, null, ['employee_id' => $employeeId, 'type' => $shiftType, 'date' => $shiftDate]);
        flashMessage('Shift assigned successfully');
    }

    if (isset($_POST['update_shift'])) {
        $shiftId = intval($_POST['shift_id']);
        $shiftType = $_POST['shift_type'];
        $startTime = $_POST['start_time'];
        $endTime = $_POST['end_time'];
        $notes = $_POST['notes'] ?? '';

        if ($shiftType === 'morning') { $startTime = '08:00'; $endTime = '16:00'; }
        elseif ($shiftType === 'afternoon') { $startTime = '16:00'; $endTime = '00:00'; }
        elseif ($shiftType === 'night') { $startTime = '00:00'; $endTime = '08:00'; }

        $db->prepare("UPDATE employee_shifts SET shift_type = ?, start_time = ?, end_time = ?, notes = ? WHERE id = ?")
            ->execute([$shiftType, $startTime, $endTime, $notes ?: null, $shiftId]);

        addAuditLog('update', 'employee_shifts', $shiftId);
        flashMessage('Shift updated');
    }

    if (isset($_POST['delete_shift'])) {
        $shiftId = intval($_POST['shift_id']);
        $db->prepare("DELETE FROM employee_shifts WHERE id = ?")->execute([$shiftId]);
        addAuditLog('delete', 'employee_shifts', $shiftId);
        flashMessage('Shift deleted');
    }

    header('Location: shifts.php' . (isset($_GET['week']) ? '?week=' . $_GET['week'] : ''));
    exit;
}

// Week navigation
$weekOffset = intval($_GET['week'] ?? 0);
$weekStart = date('Y-m-d', strtotime("monday this week " . ($weekOffset >= 0 ? "+$weekOffset" : "$weekOffset") . " weeks"));
$weekEnd = date('Y-m-d', strtotime("$weekStart +6 days"));

$prevWeek = $weekOffset - 1;
$nextWeek = $weekOffset + 1;

// Days of the week
$days = [];
for ($i = 0; $i < 7; $i++) {
    $days[] = date('Y-m-d', strtotime("$weekStart +$i days"));
}

// Employees
$employees = $db->query("SELECT id, username, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();

// Shifts for the current week
$shifts = $db->prepare("SELECT es.*, u.full_name as employee_name, u.role as employee_role
    FROM employee_shifts es
    JOIN users u ON es.employee_id = u.id
    WHERE es.shift_date BETWEEN ? AND ?
    ORDER BY es.start_time");
$shifts->execute([$weekStart, $weekEnd]);
$shifts = $shifts->fetchAll();

// Organize shifts by employee and date
$shiftGrid = [];
foreach ($shifts as $s) {
    $shiftGrid[$s['employee_id']][$s['shift_date']] = $s;
}

// Stats
$today = date('Y-m-d');
$currentHour = date('H:i');
$onDutyToday = $db->prepare("SELECT COUNT(DISTINCT employee_id) FROM employee_shifts WHERE shift_date = ?");
$onDutyToday->execute([$today]);
$onDutyToday = $onDutyToday->fetchColumn();

$upcomingShifts = $db->prepare("SELECT COUNT(*) FROM employee_shifts WHERE shift_date > ? AND shift_date <= DATE_ADD(?, INTERVAL 7 DAY)");
$upcomingShifts->execute([$today, $today]);
$upcomingShifts = $upcomingShifts->fetchColumn();

$weekTotalHours = $db->prepare("SELECT COALESCE(SUM(
    CASE
        WHEN end_time > start_time THEN TIMESTAMPDIFF(HOUR, CONCAT(shift_date, ' ', start_time), CONCAT(shift_date, ' ', end_time))
        ELSE TIMESTAMPDIFF(HOUR, CONCAT(shift_date, ' ', start_time), CONCAT(DATE_ADD(shift_date, INTERVAL 1 DAY), ' ', end_time))
    END
), 0) FROM employee_shifts WHERE shift_date BETWEEN ? AND ?");
$weekTotalHours->execute([$weekStart, $weekEnd]);
$weekTotalHours = $weekTotalHours->fetchColumn();

// Employees with overtime (>40 hours this week)
$overtimeEmployees = $db->prepare("SELECT u.full_name, SUM(
    CASE
        WHEN es.end_time > es.start_time THEN TIMESTAMPDIFF(HOUR, CONCAT(es.shift_date, ' ', es.start_time), CONCAT(es.shift_date, ' ', es.end_time))
        ELSE TIMESTAMPDIFF(HOUR, CONCAT(es.shift_date, ' ', es.start_time), CONCAT(DATE_ADD(es.shift_date, INTERVAL 1 DAY), ' ', es.end_time))
    END
) as total_hours
    FROM employee_shifts es
    JOIN users u ON es.employee_id = u.id
    WHERE es.shift_date BETWEEN ? AND ?
    GROUP BY es.employee_id
    HAVING total_hours > 40");
$overtimeEmployees->execute([$weekStart, $weekEnd]);
$overtimeEmployees = $overtimeEmployees->fetchAll();

$shiftColors = ['morning' => 'primary', 'afternoon' => 'warning', 'night' => 'dark', 'custom' => 'info'];
$shiftIcons = ['morning' => 'sun', 'afternoon' => 'sunset', 'night' => 'moon', 'custom' => 'clock'];
?>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card success">
            <div class="stat-label">On Duty Today</div>
            <div class="stat-value"><?= $onDutyToday ?></div>
            <small class="text-muted"><?= date('l, M d') ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card info">
            <div class="stat-label">Upcoming Shifts</div>
            <div class="stat-value"><?= $upcomingShifts ?></div>
            <small class="text-muted">Next 7 days</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Week Total Hours</div>
            <div class="stat-value"><?= $weekTotalHours ?>h</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card <?= count($overtimeEmployees) > 0 ? 'danger' : 'success' ?>">
            <div class="stat-label">Overtime (>40h/wk)</div>
            <div class="stat-value"><?= count($overtimeEmployees) ?></div>
            <?php if (!empty($overtimeEmployees)): ?>
            <small class="text-muted"><?= sanitize($overtimeEmployees[0]['full_name']) ?><?= count($overtimeEmployees) > 1 ? ' +' . (count($overtimeEmployees)-1) . ' more' : '' ?></small>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0"><i class="bi bi-calendar-week me-2"></i>Weekly Schedule</h6>
        <div class="d-flex align-items-center gap-2 no-print">
            <a href="?week=<?= $prevWeek ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
            <span class="fw-semibold"><?= formatDate($weekStart, 'M d') ?> - <?= formatDate($weekEnd, 'M d, Y') ?></span>
            <a href="?week=<?= $nextWeek ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
            <?php if ($weekOffset !== 0): ?>
            <a href="?week=0" class="btn btn-sm btn-outline-primary">This Week</a>
            <?php endif; ?>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addShift"><i class="bi bi-plus me-1"></i>Assign Shift</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-hover mb-0">
            <thead>
                <tr>
                    <th style="min-width:150px">Employee</th>
                    <?php foreach ($days as $day): ?>
                    <th class="text-center <?= $day === $today ? 'table-primary' : '' ?>" style="min-width:120px">
                        <div><?= date('D', strtotime($day)) ?></div>
                        <small class="text-muted"><?= date('M d', strtotime($day)) ?></small>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $emp): ?>
                <tr>
                    <td>
                        <strong class="small"><?= sanitize($emp['full_name']) ?></strong><br>
                        <span class="badge bg-<?= $emp['role'] === 'admin' ? 'danger' : ($emp['role'] === 'pharmacist' ? 'primary' : 'secondary') ?> badge-sm"><?= ucfirst($emp['role']) ?></span>
                    </td>
                    <?php foreach ($days as $day): ?>
                    <td class="text-center <?= $day === $today ? 'table-primary' : '' ?>" style="vertical-align:middle">
                        <?php if (isset($shiftGrid[$emp['id']][$day])): ?>
                        <?php $shift = $shiftGrid[$emp['id']][$day]; ?>
                        <div class="badge bg-<?= $shiftColors[$shift['shift_type']] ?? 'secondary' ?> w-100 py-2" style="cursor:pointer" data-bs-toggle="modal" data-bs-target="#editShift<?= $shift['id'] ?>">
                            <i class="bi bi-<?= $shiftIcons[$shift['shift_type']] ?? 'clock' ?> me-1"></i>
                            <?= ucfirst($shift['shift_type']) ?><br>
                            <small><?= substr($shift['start_time'], 0, 5) ?>-<?= substr($shift['end_time'], 0, 5) ?></small>
                        </div>
                        <!-- Edit/Delete modal for this shift -->
                        <div class="modal fade" id="editShift<?= $shift['id'] ?>"><div class="modal-dialog"><div class="modal-content">
                            <div class="modal-header"><h6 class="modal-title">Edit Shift - <?= sanitize($emp['full_name']) ?> (<?= formatDate($day, 'M d') ?>)</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body">
                                <form method="POST" id="editForm<?= $shift['id'] ?>">
                                    <input type="hidden" name="shift_id" value="<?= $shift['id'] ?>">
                                    <div class="mb-3">
                                        <label class="form-label">Shift Type</label>
                                        <select class="form-select shift-type-select" name="shift_type">
                                            <option value="morning" <?= $shift['shift_type'] === 'morning' ? 'selected' : '' ?>>Morning (08:00-16:00)</option>
                                            <option value="afternoon" <?= $shift['shift_type'] === 'afternoon' ? 'selected' : '' ?>>Afternoon (16:00-00:00)</option>
                                            <option value="night" <?= $shift['shift_type'] === 'night' ? 'selected' : '' ?>>Night (00:00-08:00)</option>
                                            <option value="custom" <?= $shift['shift_type'] === 'custom' ? 'selected' : '' ?>>Custom</option>
                                        </select>
                                    </div>
                                    <div class="row g-2 mb-3 custom-times" style="<?= $shift['shift_type'] !== 'custom' ? 'display:none' : '' ?>">
                                        <div class="col-6"><label class="form-label">Start</label><input type="time" class="form-control" name="start_time" value="<?= substr($shift['start_time'], 0, 5) ?>"></div>
                                        <div class="col-6"><label class="form-label">End</label><input type="time" class="form-control" name="end_time" value="<?= substr($shift['end_time'], 0, 5) ?>"></div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Notes</label>
                                        <input type="text" class="form-control" name="notes" value="<?= sanitize($shift['notes'] ?? '') ?>">
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer justify-content-between">
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this shift?')">
                                    <input type="hidden" name="shift_id" value="<?= $shift['id'] ?>">
                                    <button type="submit" name="delete_shift" value="1" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete</button>
                                </form>
                                <button type="submit" form="editForm<?= $shift['id'] ?>" name="update_shift" value="1" class="btn btn-primary btn-sm">Save Changes</button>
                            </div>
                        </div></div></div>
                        <?php else: ?>
                        <small class="text-muted">-</small>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($employees)): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">No employees found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3 mt-3">
    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-info-circle me-2"></i>Shift Legend</h6>
            <div class="d-flex flex-column gap-2">
                <div><span class="badge bg-primary me-2 py-2 px-3"><i class="bi bi-sun me-1"></i>Morning</span> 08:00 - 16:00</div>
                <div><span class="badge bg-warning me-2 py-2 px-3"><i class="bi bi-sunset me-1"></i>Afternoon</span> 16:00 - 00:00</div>
                <div><span class="badge bg-dark me-2 py-2 px-3"><i class="bi bi-moon me-1"></i>Night</span> 00:00 - 08:00</div>
                <div><span class="badge bg-info me-2 py-2 px-3"><i class="bi bi-clock me-1"></i>Custom</span> User-defined hours</div>
            </div>
        </div>
    </div>
    <?php if (!empty($overtimeEmployees)): ?>
    <div class="col-lg-4">
        <div class="card p-3 border-danger">
            <h6 class="text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Overtime Alerts</h6>
            <div class="list-group list-group-flush">
                <?php foreach ($overtimeEmployees as $ot): ?>
                <div class="list-group-item px-0 py-2 d-flex justify-content-between">
                    <span class="small"><?= sanitize($ot['full_name']) ?></span>
                    <span class="badge bg-danger"><?= $ot['total_hours'] ?>h</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Add Shift Modal -->
<div class="modal fade" id="addShift"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>Assign Shift</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">Employee</label>
            <select class="form-select" name="employee_id" required>
                <option value="">Select employee...</option>
                <?php foreach ($employees as $emp): ?>
                <option value="<?= $emp['id'] ?>"><?= sanitize($emp['full_name']) ?> (<?= ucfirst($emp['role']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Date</label>
            <input type="date" class="form-control" name="shift_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Shift Type</label>
            <select class="form-select shift-type-select" name="shift_type" required>
                <option value="morning">Morning (08:00-16:00)</option>
                <option value="afternoon">Afternoon (16:00-00:00)</option>
                <option value="night">Night (00:00-08:00)</option>
                <option value="custom">Custom</option>
            </select>
        </div>
        <div class="row g-2 mb-3 custom-times" style="display:none">
            <div class="col-6"><label class="form-label">Start Time</label><input type="time" class="form-control" name="start_time" value="08:00"></div>
            <div class="col-6"><label class="form-label">End Time</label><input type="time" class="form-control" name="end_time" value="16:00"></div>
        </div>
        <div class="mb-3">
            <label class="form-label">Notes</label>
            <input type="text" class="form-control" name="notes" placeholder="Optional notes">
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="create_shift" value="1" class="btn btn-primary"><i class="bi bi-check me-1"></i>Assign Shift</button>
    </div>
    </form>
</div></div></div>

<?php
$extraScripts = <<<'SCRIPT'
<script>
$('.shift-type-select').on('change', function() {
    var customTimes = $(this).closest('form').find('.custom-times');
    if ($(this).val() === 'custom') {
        customTimes.show();
    } else {
        customTimes.hide();
    }
});
</script>
SCRIPT;

require_once __DIR__ . '/../../includes/footer.php';
?>
