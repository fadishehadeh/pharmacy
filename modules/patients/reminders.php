<?php
$pageTitle = 'Refill Reminders';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_reminder'])) {
        $db->prepare("INSERT INTO medicine_reminders (customer_id, medicine_id, reminder_date, message, phone, status) VALUES (?,?,?,?,?,?)")
            ->execute([
                intval($_POST['customer_id']), intval($_POST['medicine_id']),
                $_POST['reminder_date'], $_POST['message'] ?? null,
                $_POST['phone'] ?? null, 'pending'
            ]);
        flashMessage('Reminder created');
        header('Location: reminders.php');
        exit;
    } elseif (isset($_POST['mark_sent'])) {
        $db->prepare("UPDATE medicine_reminders SET status = 'sent' WHERE id = ?")->execute([intval($_POST['reminder_id'])]);
        flashMessage('Marked as sent');
        header('Location: reminders.php');
        exit;
    } elseif (isset($_POST['cancel_reminder'])) {
        $db->prepare("UPDATE medicine_reminders SET status = 'cancelled' WHERE id = ?")->execute([intval($_POST['reminder_id'])]);
        flashMessage('Reminder cancelled');
        header('Location: reminders.php');
        exit;
    } elseif (isset($_POST['auto_generate'])) {
        $generated = 0;
        $activeRx = $db->query("SELECT pi.*, p.customer_id, m.name as med_name, c.phone
            FROM prescription_items pi
            JOIN prescriptions p ON pi.prescription_id = p.id
            JOIN medicines m ON pi.medicine_id = m.id
            LEFT JOIN customers c ON p.customer_id = c.id
            WHERE p.status IN ('active','partial') AND pi.quantity_dispensed > 0 AND pi.duration IS NOT NULL")->fetchAll();

        foreach ($activeRx as $rx) {
            $daysMatch = [];
            if (preg_match('/(\d+)\s*(day|week|month)/i', $rx['duration'], $daysMatch)) {
                $num = intval($daysMatch[1]);
                $unit = strtolower($daysMatch[2]);
                $days = $num;
                if ($unit === 'week') $days = $num * 7;
                if ($unit === 'month') $days = $num * 30;

                $reminderDate = date('Y-m-d', strtotime("+$days days -3 days"));

                $exists = $db->prepare("SELECT id FROM medicine_reminders WHERE customer_id = ? AND medicine_id = ? AND reminder_date = ?");
                $exists->execute([$rx['customer_id'], $rx['medicine_id'], $reminderDate]);
                if (!$exists->fetch() && $rx['customer_id']) {
                    $db->prepare("INSERT INTO medicine_reminders (customer_id, medicine_id, reminder_date, message, phone, status) VALUES (?,?,?,?,?,?)")
                        ->execute([$rx['customer_id'], $rx['medicine_id'], $reminderDate,
                            "Time to refill {$rx['med_name']}. Please visit the pharmacy.",
                            $rx['phone'], 'pending']);
                    $generated++;
                }
            }
        }
        flashMessage("$generated reminders auto-generated from prescriptions");
        header('Location: reminders.php');
        exit;
    }
}

$statusFilter = $_GET['status'] ?? 'pending';
$where = "WHERE 1=1";
$params = [];
if ($statusFilter) { $where .= " AND r.status = ?"; $params[] = $statusFilter; }

$reminders = $db->prepare("SELECT r.*, c.name as customer_name, m.name as med_name
    FROM medicine_reminders r
    JOIN customers c ON r.customer_id = c.id
    JOIN medicines m ON r.medicine_id = m.id
    $where ORDER BY r.reminder_date ASC");
$reminders->execute($params);
$reminders = $reminders->fetchAll();

$todayReminders = $db->query("SELECT COUNT(*) FROM medicine_reminders WHERE status = 'pending' AND reminder_date <= CURDATE()")->fetchColumn();
$upcomingReminders = $db->query("SELECT COUNT(*) FROM medicine_reminders WHERE status = 'pending' AND reminder_date > CURDATE() AND reminder_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

$customers = $db->query("SELECT id, name, phone FROM customers ORDER BY name")->fetchAll();
$medicines = $db->query("SELECT id, name FROM medicines WHERE is_active = 1 ORDER BY name")->fetchAll();
?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card danger"><div class="stat-label">Due Today/Overdue</div><div class="stat-value"><?= $todayReminders ?></div></div></div>
    <div class="col-md-3"><div class="card stat-card warning"><div class="stat-label">Next 7 Days</div><div class="stat-value"><?= $upcomingReminders ?></div></div></div>
    <div class="col-md-3">
        <div class="card p-3">
            <form method="POST"><button type="submit" name="auto_generate" value="1" class="btn btn-outline-primary w-100"><i class="bi bi-magic me-1"></i>Auto-Generate from Rx</button></form>
        </div>
    </div>
    <div class="col-md-3"><div class="card p-3"><button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#newReminder"><i class="bi bi-plus me-1"></i>New Reminder</button></div></div>
</div>

<div class="card p-3 mb-3">
    <div class="btn-group btn-group-sm">
        <a href="?status=pending" class="btn btn-<?= $statusFilter === 'pending' ? 'primary' : 'outline-primary' ?>">Pending</a>
        <a href="?status=sent" class="btn btn-<?= $statusFilter === 'sent' ? 'success' : 'outline-success' ?>">Sent</a>
        <a href="?status=cancelled" class="btn btn-<?= $statusFilter === 'cancelled' ? 'secondary' : 'outline-secondary' ?>">Cancelled</a>
        <a href="?status=" class="btn btn-<?= !$statusFilter ? 'dark' : 'outline-dark' ?>">All</a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
            <thead><tr><th>Date</th><th>Customer</th><th>Medicine</th><th>Phone</th><th>Message</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($reminders as $r): ?>
                <?php $overdue = $r['status'] === 'pending' && $r['reminder_date'] <= date('Y-m-d'); ?>
                <tr class="<?= $overdue ? 'table-warning' : '' ?>">
                    <td>
                        <small><?= formatDate($r['reminder_date'], 'M d, Y') ?></small>
                        <?php if ($overdue): ?><br><span class="badge bg-danger">Overdue</span><?php endif; ?>
                    </td>
                    <td><strong><?= sanitize($r['customer_name']) ?></strong></td>
                    <td><?= sanitize($r['med_name']) ?></td>
                    <td><?php if ($r['phone']): ?><a href="tel:<?= sanitize($r['phone']) ?>"><?= sanitize($r['phone']) ?></a><?php else: ?>-<?php endif; ?></td>
                    <td><small><?= sanitize($r['message'] ?? '-') ?></small></td>
                    <td>
                        <?php $colors = ['pending' => 'warning', 'sent' => 'success', 'cancelled' => 'secondary']; ?>
                        <span class="badge bg-<?= $colors[$r['status']] ?? 'secondary' ?>"><?= ucfirst($r['status']) ?></span>
                    </td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="reminder_id" value="<?= $r['id'] ?>">
                            <button type="submit" name="mark_sent" value="1" class="btn btn-sm btn-outline-success" title="Mark as contacted"><i class="bi bi-check"></i></button>
                            <button type="submit" name="cancel_reminder" value="1" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="newReminder"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
    <div class="modal-header"><h6 class="modal-title">New Reminder</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">Customer</label>
            <select class="form-select" name="customer_id" required>
                <option value="">Select...</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" data-phone="<?= sanitize($c['phone'] ?? '') ?>"><?= sanitize($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Medicine</label>
            <select class="form-select" name="medicine_id" required>
                <option value="">Select...</option>
                <?php foreach ($medicines as $m): ?>
                <option value="<?= $m['id'] ?>"><?= sanitize($m['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3"><label class="form-label">Reminder Date</label><input type="date" class="form-control" name="reminder_date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required></div>
        <div class="mb-3"><label class="form-label">Phone</label><input type="tel" class="form-control" name="phone" id="reminderPhone"></div>
        <div><label class="form-label">Message</label><textarea class="form-control" name="message" rows="2" placeholder="Time to refill your medication..."></textarea></div>
    </div>
    <div class="modal-footer"><button type="submit" name="create_reminder" value="1" class="btn btn-primary">Create</button></div>
    </form>
</div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
