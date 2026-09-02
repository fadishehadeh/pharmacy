<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
header('Location: ' . BASE_URL . '/modules/pos/index.php');
exit;

$db = getDB();
$user = currentUser();
$exchangeRate = floatval(getSetting('exchange_rate', '89500'));
$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');

// --- KPI Queries (all wrapped in try/catch) ---

// Today's Revenue
$todaySales = 0;
$todayTransactions = 0;
$todayProfit = 0;
try {
    $todaySales = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed'")->fetchColumn();
    $todayTransactions = $db->query("SELECT COUNT(*) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed'")->fetchColumn();
    $todayProfit = $db->query("SELECT COALESCE(SUM(si.total_price - (si.cost_price * si.quantity)), 0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE DATE(s.sale_date) = CURDATE() AND s.status = 'completed'")->fetchColumn();
} catch (Exception $e) {}

// Low Stock & Expiry
$lowStockCount = 0;
$outOfStock = 0;
$expiringCount = 0;
$expiredCount = 0;
try {
    $lowStockCount = $db->query("SELECT COUNT(*) FROM medicines WHERE quantity_in_stock > 0 AND quantity_in_stock <= min_stock_level AND is_active = 1")->fetchColumn();
    $outOfStock = $db->query("SELECT COUNT(*) FROM medicines WHERE quantity_in_stock = 0 AND is_active = 1")->fetchColumn();
    $expiringCount = $db->query("SELECT COUNT(*) FROM medicines WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND expiry_date >= CURDATE() AND is_active = 1")->fetchColumn();
    $expiredCount = $db->query("SELECT COUNT(*) FROM medicines WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND is_active = 1")->fetchColumn();
} catch (Exception $e) {}

// Pending Orders
$pendingOrders = 0;
try {
    $pendingOrders = $db->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('draft','ordered','partial')")->fetchColumn();
} catch (Exception $e) {}

// Active Customers Today
$activeCustomersToday = 0;
try {
    $activeCustomersToday = $db->query("SELECT COUNT(DISTINCT customer_id) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed' AND customer_id IS NOT NULL")->fetchColumn();
} catch (Exception $e) {}

// Cash Register
$openRegister = null;
try {
    $openRegister = $db->query("SELECT cr.*, u.full_name as opener FROM cash_register cr LEFT JOIN users u ON cr.opened_by = u.id WHERE cr.status = 'open' ORDER BY cr.opened_at DESC LIMIT 1")->fetch();
} catch (Exception $e) {}

// --- Charts Data ---

// Sales last 7 days
$last7days = [];
try {
    $last7days = $db->query("SELECT DATE(sale_date) as day, COALESCE(SUM(total_amount),0) as total, COUNT(*) as cnt FROM sales WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status = 'completed' GROUP BY DATE(sale_date) ORDER BY day")->fetchAll();
} catch (Exception $e) {}

// Revenue by category (today)
$revenueByCategory = [];
try {
    $revenueByCategory = $db->query("SELECT COALESCE(c.name, 'Uncategorized') as category, SUM(si.total_price) as revenue FROM sale_items si JOIN sales s ON si.sale_id = s.id LEFT JOIN medicines m ON si.medicine_id = m.id LEFT JOIN categories c ON m.category_id = c.id WHERE DATE(s.sale_date) = CURDATE() AND s.status = 'completed' GROUP BY c.id ORDER BY revenue DESC LIMIT 8")->fetchAll();
} catch (Exception $e) {}

// --- Recent Sales ---
$recentSales = [];
try {
    $recentSales = $db->query("SELECT s.id, s.invoice_number, s.total_amount, s.currency, s.payment_method, s.sale_date, s.status, c.name as customer_name FROM sales s LEFT JOIN customers c ON s.customer_id = c.id ORDER BY s.sale_date DESC LIMIT 10")->fetchAll();
} catch (Exception $e) {}

// --- Alerts Data ---
$expiredMeds = [];
try {
    $expiredMeds = $db->query("SELECT id, name, expiry_date, quantity_in_stock FROM medicines WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND is_active = 1 ORDER BY expiry_date ASC LIMIT 5")->fetchAll();
} catch (Exception $e) {}

$outOfStockMeds = [];
try {
    $outOfStockMeds = $db->query("SELECT id, name, min_stock_level FROM medicines WHERE quantity_in_stock = 0 AND is_active = 1 ORDER BY name ASC LIMIT 5")->fetchAll();
} catch (Exception $e) {}

$pendingDeliveries = [];
try {
    $pendingDeliveries = $db->query("SELECT id, delivery_date, status FROM deliveries WHERE status IN ('pending','confirmed','in_transit') ORDER BY delivery_date ASC LIMIT 5")->fetchAll();
} catch (Exception $e) {}

$dueReminders = [];
try {
    $dueReminders = $db->query("SELECT mr.id, mr.reminder_date, m.name as medicine_name FROM medicine_reminders mr LEFT JOIN medicines m ON mr.medicine_id = m.id WHERE mr.status = 'pending' AND mr.reminder_date <= CURDATE() ORDER BY mr.reminder_date ASC LIMIT 5")->fetchAll();
} catch (Exception $e) {}

// --- Stats Grid ---
$totalMedicines = 0;
$totalCategories = 0;
$totalCustomers = 0;
$totalSuppliers = 0;
try { $totalMedicines = $db->query("SELECT COUNT(*) FROM medicines WHERE is_active = 1")->fetchColumn(); } catch (Exception $e) {}
try { $totalCategories = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn(); } catch (Exception $e) {}
try { $totalCustomers = $db->query("SELECT COUNT(*) FROM customers")->fetchColumn(); } catch (Exception $e) {}
try { $totalSuppliers = $db->query("SELECT COUNT(*) FROM suppliers WHERE is_active = 1")->fetchColumn(); } catch (Exception $e) {}

// Total alert count for alerts panel header
$totalAlerts = $expiredCount + $outOfStock + count($pendingDeliveries) + count($dueReminders);
?>

<style>
.dash-welcome {
    background: linear-gradient(135deg, #1E40AF 0%, #3B82F6 100%);
    color: #fff;
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
}
.dash-welcome .welcome-title { font-size: 1.25rem; font-weight: 700; }
.dash-welcome .welcome-meta { font-size: 0.85rem; opacity: 0.85; }
.dash-welcome .register-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,0.15);
    border-radius: 20px;
    padding: 4px 14px;
    font-size: 0.8rem;
}
.dash-welcome .register-badge .dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    display: inline-block;
}
.dash-welcome .register-badge .dot.open { background: #34D399; box-shadow: 0 0 6px #34D399; }
.dash-welcome .register-badge .dot.closed { background: #F87171; }

.kpi-card {
    border-radius: 12px;
    padding: 1.25rem;
    position: relative;
    overflow: hidden;
}
.kpi-card .kpi-icon {
    position: absolute;
    right: 12px;
    top: 12px;
    font-size: 2.5rem;
    opacity: 0.08;
}
.kpi-card .kpi-label {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748B;
    font-weight: 600;
    margin-bottom: 4px;
}
.kpi-card .kpi-value {
    font-size: 1.6rem;
    font-weight: 700;
    color: #0F172A;
    line-height: 1.2;
}
.kpi-card .kpi-sub {
    font-size: 0.78rem;
    color: #94A3B8;
    margin-top: 4px;
}
.kpi-card .kpi-link {
    font-size: 0.75rem;
    margin-top: 6px;
}
.kpi-card.border-accent-green { border-left: 4px solid #059669; }
.kpi-card.border-accent-blue { border-left: 4px solid #2563EB; }
.kpi-card.border-accent-amber { border-left: 4px solid #D97706; }
.kpi-card.border-accent-red { border-left: 4px solid #DC2626; }
.kpi-card.border-accent-purple { border-left: 4px solid #7C3AED; }
.kpi-card.border-accent-cyan { border-left: 4px solid #0891B2; }

.quick-action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 14px 10px;
    border-radius: 10px;
    border: 1px solid #E2E8F0;
    background: #fff;
    color: #334155;
    text-decoration: none;
    font-size: 0.8rem;
    font-weight: 600;
    transition: all 0.15s;
    text-align: center;
}
.quick-action-btn:hover {
    border-color: #2563EB;
    color: #2563EB;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(37,99,235,0.12);
}
.quick-action-btn i { font-size: 1.5rem; }

.activity-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid #F1F5F9;
    font-size: 0.85rem;
}
.activity-item:last-child { border-bottom: none; }
.activity-item .activity-icon {
    width: 36px; height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 0.9rem;
}
.activity-item .activity-amount { font-weight: 700; white-space: nowrap; }

.alert-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid #F1F5F9;
    font-size: 0.82rem;
}
.alert-item:last-child { border-bottom: none; }
.alert-item .alert-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    margin-top: 5px;
    flex-shrink: 0;
}

.stats-grid-card {
    text-align: center;
    padding: 1rem;
    border-radius: 10px;
}
.stats-grid-card .stats-num { font-size: 1.5rem; font-weight: 700; color: #0F172A; }
.stats-grid-card .stats-label { font-size: 0.78rem; color: #64748B; text-transform: uppercase; letter-spacing: 0.5px; }

.section-header {
    font-size: 0.95rem;
    font-weight: 700;
    color: #1E293B;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-header .badge { font-size: 0.7rem; }
</style>

<!-- 1. Welcome Bar -->
<div class="dash-welcome mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <div class="welcome-title">Welcome back, <?= sanitize($user['full_name'] ?? 'User') ?></div>
            <div class="welcome-meta"><?= sanitize($pharmacyName) ?> &mdash; <?= date('l, F j, Y') ?> &bull; <span id="live-clock"><?= date('h:i A') ?></span></div>
        </div>
        <div class="d-flex align-items-center gap-3">
            <?php if ($openRegister): ?>
                <div class="register-badge">
                    <span class="dot open"></span>
                    Register Open &mdash; <?= sanitize($openRegister['opener'] ?? 'Unknown') ?>
                </div>
            <?php else: ?>
                <div class="register-badge">
                    <span class="dot closed"></span>
                    Register Closed
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 2. KPI Row -->
<div class="row g-3 mb-3">
    <!-- Today's Revenue -->
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="card kpi-card border-accent-green">
            <i class="bi bi-cash-stack kpi-icon"></i>
            <div class="kpi-label">Today's Revenue</div>
            <div class="kpi-value" data-count="<?= $todaySales ?>" data-prefix="$" data-decimals="2">$0.00</div>
            <div class="kpi-sub"><span data-count="<?= round($todaySales * $exchangeRate) ?>" data-suffix=" L.L.">0 L.L.</span></div>
            <div class="kpi-sub">Profit: $<span data-count="<?= $todayProfit ?>" data-decimals="2">0.00</span></div>
        </div>
    </div>
    <!-- Today's Transactions -->
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="card kpi-card border-accent-blue">
            <i class="bi bi-receipt kpi-icon"></i>
            <div class="kpi-label">Transactions</div>
            <div class="kpi-value" data-count="<?= $todayTransactions ?>"><?= number_format($todayTransactions) ?></div>
            <div class="kpi-sub">Today's completed sales</div>
        </div>
    </div>
    <!-- Low Stock Alerts -->
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="card kpi-card border-accent-amber">
            <i class="bi bi-exclamation-triangle kpi-icon"></i>
            <div class="kpi-label">Low Stock</div>
            <div class="kpi-value" data-count="<?= $lowStockCount + $outOfStock ?>"><?= number_format($lowStockCount + $outOfStock) ?></div>
            <div class="kpi-sub"><?= $outOfStock ?> out of stock</div>
            <div class="kpi-link"><a href="<?= BASE_URL ?>/modules/inventory/alerts.php">View alerts &rarr;</a></div>
        </div>
    </div>
    <!-- Expiring Soon -->
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="card kpi-card border-accent-red">
            <i class="bi bi-clock-history kpi-icon"></i>
            <div class="kpi-label">Expiring Soon</div>
            <div class="kpi-value" data-count="<?= $expiringCount ?>"><?= number_format($expiringCount) ?></div>
            <div class="kpi-sub"><?= $expiredCount ?> already expired</div>
            <div class="kpi-link"><a href="<?= BASE_URL ?>/modules/inventory/alerts.php" class="text-danger">View expiry alerts &rarr;</a></div>
        </div>
    </div>
    <!-- Pending Orders -->
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="card kpi-card border-accent-purple">
            <i class="bi bi-box-seam kpi-icon"></i>
            <div class="kpi-label">Pending Orders</div>
            <div class="kpi-value" data-count="<?= $pendingOrders ?>"><?= number_format($pendingOrders) ?></div>
            <div class="kpi-sub">Purchase orders open</div>
            <div class="kpi-link"><a href="<?= BASE_URL ?>/modules/suppliers/index.php">View orders &rarr;</a></div>
        </div>
    </div>
    <!-- Active Customers -->
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="card kpi-card border-accent-cyan">
            <i class="bi bi-people kpi-icon"></i>
            <div class="kpi-label">Customers Today</div>
            <div class="kpi-value" data-count="<?= $activeCustomersToday ?>"><?= number_format($activeCustomersToday) ?></div>
            <div class="kpi-sub">Unique customers served</div>
        </div>
    </div>
</div>

<!-- 3. Charts Row -->
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <div class="section-header"><i class="bi bi-graph-up"></i> Sales Trend &mdash; Last 7 Days</div>
            <canvas id="salesTrendChart" height="160"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3 h-100">
            <div class="section-header"><i class="bi bi-pie-chart"></i> Revenue by Category (Today)</div>
            <?php if (empty($revenueByCategory)): ?>
                <div class="d-flex align-items-center justify-content-center flex-grow-1">
                    <p class="text-muted mb-0">No sales recorded today</p>
                </div>
            <?php else: ?>
                <canvas id="categoryChart" height="200"></canvas>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 4. Quick Actions Panel -->
<div class="card p-3 mb-3">
    <div class="section-header"><i class="bi bi-lightning"></i> Quick Actions</div>
    <div class="row g-2">
        <div class="col-6 col-sm-4 col-md-2">
            <a href="<?= BASE_URL ?>/modules/pos/index.php" class="quick-action-btn w-100">
                <i class="bi bi-cart3 text-primary"></i>
                New Sale
            </a>
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <a href="<?= BASE_URL ?>/modules/inventory/add.php" class="quick-action-btn w-100">
                <i class="bi bi-capsule text-success"></i>
                Add Medicine
            </a>
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <a href="<?= BASE_URL ?>/modules/prescriptions/index.php" class="quick-action-btn w-100">
                <i class="bi bi-file-medical text-info"></i>
                Prescriptions
            </a>
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <a href="<?= BASE_URL ?>/modules/finance/cash_register.php" class="quick-action-btn w-100">
                <i class="bi bi-cash-coin text-warning"></i>
                Cash Register
            </a>
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <a href="<?= BASE_URL ?>/modules/suppliers/index.php" class="quick-action-btn w-100">
                <i class="bi bi-truck text-purple" style="color:#7C3AED"></i>
                Purchase Order
            </a>
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <a href="<?= BASE_URL ?>/modules/sales/quotations.php" class="quick-action-btn w-100">
                <i class="bi bi-file-text text-secondary"></i>
                Quotation
            </a>
        </div>
    </div>
</div>

<!-- 5. Activity Feed + 6. Alerts Panel -->
<div class="row g-3 mb-3">
    <!-- Activity Feed -->
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="section-header mb-0"><i class="bi bi-activity"></i> Recent Sales</div>
                <a href="<?= BASE_URL ?>/modules/sales/index.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <?php if (empty($recentSales)): ?>
                <p class="text-muted text-center py-4">No sales recorded yet</p>
            <?php else: ?>
                <div style="max-height:420px;overflow-y:auto">
                <?php foreach ($recentSales as $sale): ?>
                    <?php
                    $paymentIcons = [
                        'cash' => ['bi-cash', 'bg-success bg-opacity-10 text-success'],
                        'card' => ['bi-credit-card', 'bg-primary bg-opacity-10 text-primary'],
                        'credit' => ['bi-clock', 'bg-warning bg-opacity-10 text-warning'],
                        'insurance' => ['bi-shield-check', 'bg-info bg-opacity-10 text-info'],
                    ];
                    $pm = $sale['payment_method'] ?? 'cash';
                    $icon = $paymentIcons[$pm] ?? $paymentIcons['cash'];
                    ?>
                    <div class="activity-item">
                        <div class="activity-icon <?= $icon[1] ?>">
                            <i class="bi <?= $icon[0] ?>"></i>
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="text-truncate">
                                    <a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= (int)$sale['id'] ?>" class="fw-semibold text-decoration-none"><?= sanitize($sale['invoice_number']) ?></a>
                                    <span class="text-muted ms-1">&mdash; <?= sanitize($sale['customer_name'] ?? 'Walk-in') ?></span>
                                </div>
                                <div class="activity-amount"><?= formatCurrency($sale['total_amount'], $sale['currency'] ?? 'USD') ?></div>
                            </div>
                            <div class="d-flex gap-2 mt-1">
                                <span class="badge bg-secondary bg-opacity-10 text-secondary"><?= ucfirst(sanitize($pm)) ?></span>
                                <span class="badge bg-<?= $sale['status'] === 'completed' ? 'success' : 'warning' ?> bg-opacity-10 text-<?= $sale['status'] === 'completed' ? 'success' : 'warning' ?>"><?= ucfirst(sanitize($sale['status'])) ?></span>
                                <small class="text-muted"><?= formatDate($sale['sale_date'], 'M d, h:i A') ?></small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alerts Panel -->
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="section-header mb-0">
                    <i class="bi bi-bell"></i> Alerts
                    <?php if ($totalAlerts > 0): ?>
                        <span class="badge bg-danger rounded-pill"><?= $totalAlerts ?></span>
                    <?php endif; ?>
                </div>
                <a href="<?= BASE_URL ?>/modules/notifications/index.php" class="btn btn-sm btn-outline-secondary">View All</a>
            </div>

            <div style="max-height:420px;overflow-y:auto">
                <?php if ($totalAlerts === 0 && $expiringCount === 0): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-check-circle" style="font-size:2rem;opacity:0.4"></i>
                        <p class="mt-2 mb-0">No critical alerts</p>
                    </div>
                <?php endif; ?>

                <!-- Expired Medicines -->
                <?php if (!empty($expiredMeds)): ?>
                    <div class="mb-3">
                        <div class="small fw-bold text-danger mb-1"><i class="bi bi-x-octagon me-1"></i>Expired Medicines</div>
                        <?php foreach ($expiredMeds as $med): ?>
                            <div class="alert-item">
                                <span class="alert-dot bg-danger"></span>
                                <div class="flex-grow-1">
                                    <a href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= (int)$med['id'] ?>" class="text-decoration-none fw-semibold"><?= sanitize($med['name']) ?></a>
                                    <div class="text-muted" style="font-size:0.75rem">Expired <?= formatDate($med['expiry_date'], 'M d, Y') ?> &bull; Qty: <?= (int)$med['quantity_in_stock'] ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($expiredCount > 5): ?>
                            <a href="<?= BASE_URL ?>/modules/inventory/alerts.php" class="small">View all <?= $expiredCount ?> expired &rarr;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Out of Stock -->
                <?php if (!empty($outOfStockMeds)): ?>
                    <div class="mb-3">
                        <div class="small fw-bold text-warning mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Out of Stock</div>
                        <?php foreach ($outOfStockMeds as $med): ?>
                            <div class="alert-item">
                                <span class="alert-dot bg-warning"></span>
                                <div class="flex-grow-1">
                                    <a href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= (int)$med['id'] ?>" class="text-decoration-none fw-semibold"><?= sanitize($med['name']) ?></a>
                                    <div class="text-muted" style="font-size:0.75rem">Min level: <?= (int)$med['min_stock_level'] ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($outOfStock > 5): ?>
                            <a href="<?= BASE_URL ?>/modules/inventory/alerts.php" class="small">View all <?= $outOfStock ?> out of stock &rarr;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Pending Deliveries -->
                <?php if (!empty($pendingDeliveries)): ?>
                    <div class="mb-3">
                        <div class="small fw-bold text-info mb-1"><i class="bi bi-truck me-1"></i>Pending Deliveries</div>
                        <?php foreach ($pendingDeliveries as $del): ?>
                            <div class="alert-item">
                                <span class="alert-dot bg-info"></span>
                                <div class="flex-grow-1">
                                    Delivery #<?= (int)$del['id'] ?> &mdash; <?= ucfirst(sanitize($del['status'])) ?>
                                    <div class="text-muted" style="font-size:0.75rem">Due: <?= formatDate($del['delivery_date'], 'M d, Y') ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <a href="<?= BASE_URL ?>/modules/sales/deliveries.php" class="small">View all deliveries &rarr;</a>
                    </div>
                <?php endif; ?>

                <!-- Due Reminders -->
                <?php if (!empty($dueReminders)): ?>
                    <div class="mb-3">
                        <div class="small fw-bold text-purple mb-1" style="color:#7C3AED"><i class="bi bi-alarm me-1"></i>Due Reminders</div>
                        <?php foreach ($dueReminders as $rem): ?>
                            <div class="alert-item">
                                <span class="alert-dot" style="background:#7C3AED"></span>
                                <div class="flex-grow-1">
                                    <?= sanitize($rem['medicine_name'] ?? 'Unknown') ?>
                                    <div class="text-muted" style="font-size:0.75rem">Due: <?= formatDate($rem['reminder_date'], 'M d, Y') ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <a href="<?= BASE_URL ?>/modules/notifications/index.php" class="small">View all reminders &rarr;</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 7. Stats Grid -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card stats-grid-card">
            <div class="stats-num"><i class="bi bi-capsule text-primary me-1" style="font-size:1.1rem"></i><?= number_format($totalMedicines) ?></div>
            <div class="stats-label">Total Medicines</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stats-grid-card">
            <div class="stats-num"><i class="bi bi-tags text-success me-1" style="font-size:1.1rem"></i><?= number_format($totalCategories) ?></div>
            <div class="stats-label">Categories</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stats-grid-card">
            <div class="stats-num"><i class="bi bi-people text-info me-1" style="font-size:1.1rem"></i><?= number_format($totalCustomers) ?></div>
            <div class="stats-label">Customers</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stats-grid-card">
            <div class="stats-num"><i class="bi bi-building text-warning me-1" style="font-size:1.1rem"></i><?= number_format($totalSuppliers) ?></div>
            <div class="stats-label">Suppliers</div>
        </div>
    </div>
</div>

<?php
// --- Prepare chart data ---
$chartLabels = [];
$chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('D d', strtotime($date));
    $found = false;
    foreach ($last7days as $row) {
        if ($row['day'] === $date) {
            $chartData[] = round((float)$row['total'], 2);
            $found = true;
            break;
        }
    }
    if (!$found) $chartData[] = 0;
}

$catLabels = [];
$catData = [];
$catColors = ['#2563EB','#059669','#D97706','#DC2626','#7C3AED','#0891B2','#DB2777','#84CC16'];
foreach ($revenueByCategory as $i => $rc) {
    $catLabels[] = $rc['category'];
    $catData[] = round((float)$rc['revenue'], 2);
}

$extraScripts = "<script>
// Animated counters for KPI values
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-count]').forEach(function (el) {
        var target = parseFloat(el.dataset.count);
        var prefix = el.dataset.prefix || '';
        var suffix = el.dataset.suffix || '';
        var decimals = el.dataset.decimals ? parseInt(el.dataset.decimals) : 0;
        var duration = 700;
        var start = performance.now();
        function step(now) {
            var progress = Math.min((now - start) / duration, 1);
            var ease = 1 - Math.pow(1 - progress, 3);
            var val = target * ease;
            el.textContent = prefix + (decimals > 0 ? val.toFixed(decimals) : Math.round(val).toLocaleString()) + suffix;
            if (progress < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    });
});

// Live clock
setInterval(function(){
    var now = new Date();
    var h = now.getHours(), m = now.getMinutes();
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    var el = document.getElementById('live-clock');
    if(el) el.textContent = (h<10?'0'+h:h) + ':' + (m<10?'0'+m:m) + ' ' + ampm;
}, 30000);

// Sales Trend Chart
var stCtx = document.getElementById('salesTrendChart');
if (stCtx) {
    new Chart(stCtx, {
        type: 'line',
        data: {
            labels: " . json_encode($chartLabels) . ",
            datasets: [{
                label: 'Revenue (USD)',
                data: " . json_encode($chartData) . ",
                borderColor: '#2563EB',
                backgroundColor: 'rgba(37,99,235,0.08)',
                fill: true,
                tension: 0.35,
                borderWidth: 2.5,
                pointBackgroundColor: '#2563EB',
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) { return '$' + ctx.parsed.y.toLocaleString(undefined,{minimumFractionDigits:2}); }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(v) { return '$' + v.toLocaleString(); },
                        font: { size: 11 }
                    },
                    grid: { color: 'rgba(0,0,0,0.04)' }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11 } }
                }
            }
        }
    });
}
" . (!empty($revenueByCategory) ? "
// Category Doughnut Chart
var ccCtx = document.getElementById('categoryChart');
if (ccCtx) {
    new Chart(ccCtx, {
        type: 'doughnut',
        data: {
            labels: " . json_encode($catLabels) . ",
            datasets: [{
                data: " . json_encode($catData) . ",
                backgroundColor: " . json_encode(array_slice($catColors, 0, count($catLabels))) . ",
                borderWidth: 0,
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '60%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 10, font: { size: 11 }, padding: 12 }
                },
                tooltip: {
                    callbacks: {
                        label: function(ctx) { return ctx.label + ': $' + ctx.parsed.toLocaleString(undefined,{minimumFractionDigits:2}); }
                    }
                }
            }
        }
    });
}
" : "") . "
</script>";

require_once __DIR__ . '/includes/footer.php';
?>
