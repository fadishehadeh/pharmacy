<?php
$pageTitle = 'Expenses';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add'])) {
        $db->prepare("INSERT INTO expenses (category, description, amount, currency, expense_date, payment_method, receipt_number, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)")->execute([
            $_POST['category'], $_POST['description'], $_POST['amount'], $_POST['currency'] ?? 'USD',
            $_POST['expense_date'], $_POST['payment_method'] ?? 'cash', $_POST['receipt_number'] ?: null,
            $_POST['notes'] ?: null, $_SESSION['user_id'] ?? null
        ]);
        flashMessage('Expense added');
    }
    header('Location: expenses.php');
    exit;
}

if (isset($_GET['delete'])) {
    $db->prepare("DELETE FROM expenses WHERE id = ?")->execute([$_GET['delete']]);
    flashMessage('Expense deleted');
    header('Location: expenses.php');
    exit;
}

$month = $_GET['month'] ?? date('Y-m');
$expenses = $db->prepare("SELECT * FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m') = ? ORDER BY expense_date DESC");
$expenses->execute([$month]);
$expenses = $expenses->fetchAll();

$totalExpenses = array_sum(array_column($expenses, 'amount'));
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card p-3">
            <h6><i class="bi bi-plus-circle me-2"></i>Add Expense</h6>
            <form method="POST">
                <div class="mb-2">
                    <select class="form-select" name="category" required>
                        <option value="">Category</option>
                        <option value="rent">Rent</option>
                        <option value="utilities">Utilities</option>
                        <option value="salaries">Salaries</option>
                        <option value="supplies">Supplies</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="marketing">Marketing</option>
                        <option value="taxes">Taxes</option>
                        <option value="license">License/Permits</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="mb-2"><input type="text" class="form-control" name="description" placeholder="Description" required></div>
                <div class="mb-2"><input type="number" class="form-control" name="amount" placeholder="Amount" step="0.01" min="0" required></div>
                <div class="mb-2">
                    <select class="form-select" name="currency">
                        <option value="USD">USD</option>
                        <option value="LBP">LBP</option>
                    </select>
                </div>
                <div class="mb-2"><input type="date" class="form-control" name="expense_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="mb-2">
                    <select class="form-select" name="payment_method">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="check">Check</option>
                    </select>
                </div>
                <div class="mb-2"><input type="text" class="form-control" name="receipt_number" placeholder="Receipt #"></div>
                <div class="mb-3"><input type="text" class="form-control" name="notes" placeholder="Notes"></div>
                <button type="submit" name="add" value="1" class="btn btn-primary w-100">Add Expense</button>
            </form>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center">
                <form method="GET" class="d-flex gap-2">
                    <input type="month" class="form-control" name="month" value="<?= $month ?>">
                    <button type="submit" class="btn btn-outline-primary">View</button>
                </form>
                <div class="text-end">
                    <span class="text-muted">Total:</span>
                    <strong class="fs-5 text-danger"><?= formatCurrency($totalExpenses) ?></strong>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Payment</th><th class="text-end">Amount</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($expenses as $exp): ?>
                        <tr>
                            <td><?= formatDate($exp['expense_date'], 'M d') ?></td>
                            <td><span class="badge bg-secondary"><?= ucfirst($exp['category']) ?></span></td>
                            <td><?= sanitize($exp['description']) ?></td>
                            <td><?= ucfirst($exp['payment_method']) ?></td>
                            <td class="text-end fw-semibold"><?= formatCurrency($exp['amount'], $exp['currency']) ?></td>
                            <td><a href="?month=<?= $month ?>&delete=<?= $exp['id'] ?>" class="btn btn-sm btn-outline-danger" data-confirm="Delete?"><i class="bi bi-trash"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($expenses)): ?><tr><td colspan="6" class="text-center text-muted py-3">No expenses this month</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
