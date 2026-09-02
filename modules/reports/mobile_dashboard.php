<?php
ob_start();
require_once __DIR__ . '/../../config/app.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }

$db = getDB();
$user = currentUser();
$exchangeRate = floatval(getSetting('exchange_rate', '89500'));
$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');

// ── KPIs ──────────────────────────────────────────────────────────────────────
$todayRevUSD    = 0;
$todaySalesCount = 0;
$topMedName     = '—';
$topMedQty      = 0;
$cashInRegister = 0;
$lowStockCount  = 0;
$nearExpiryCount = 0;
$top5Today      = [];
$last7days      = [];

try {
    // Today's revenue (all in USD equiv)
    $rUSD = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sale_date)=CURDATE() AND status='completed' AND currency='USD'")->fetchColumn();
    $rLBP = (float)$db->query("SELECT COALESCE(SUM(total_amount/NULLIF(exchange_rate,0)),0) FROM sales WHERE DATE(sale_date)=CURDATE() AND status='completed' AND currency='LBP'")->fetchColumn();
    $todayRevUSD = $rUSD + $rLBP;

    $todaySalesCount = (int)$db->query("SELECT COUNT(*) FROM sales WHERE DATE(sale_date)=CURDATE() AND status='completed'")->fetchColumn();

    // Top medicine today
    $topRow = $db->query("SELECT m.name, SUM(si.quantity) as qty FROM sale_items si JOIN medicines m ON si.medicine_id=m.id JOIN sales s ON si.sale_id=s.id WHERE DATE(s.sale_date)=CURDATE() AND s.status='completed' GROUP BY m.id,m.name ORDER BY qty DESC LIMIT 1")->fetch();
    if ($topRow) { $topMedName = $topRow['name']; $topMedQty = (int)$topRow['qty']; }

    // Cash in register (cash sales today USD equiv)
    $cashUSD = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sale_date)=CURDATE() AND status='completed' AND payment_method='cash' AND currency='USD'")->fetchColumn();
    $cashLBP = (float)$db->query("SELECT COALESCE(SUM(total_amount/NULLIF(exchange_rate,0)),0) FROM sales WHERE DATE(sale_date)=CURDATE() AND status='completed' AND payment_method='cash' AND currency='LBP'")->fetchColumn();
    $cashInRegister = $cashUSD + $cashLBP;

    // Low stock & near-expiry
    $lowStockCount   = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE quantity_in_stock>0 AND quantity_in_stock<=min_stock_level AND is_active=1")->fetchColumn();
    $nearExpiryCount = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE expiry_date IS NOT NULL AND expiry_date<=DATE_ADD(CURDATE(),INTERVAL 90 DAY) AND expiry_date>=CURDATE() AND is_active=1")->fetchColumn();

    // Top 5 medicines today
    $top5Today = $db->query("SELECT m.name, SUM(si.quantity) as qty FROM sale_items si JOIN medicines m ON si.medicine_id=m.id JOIN sales s ON si.sale_id=s.id WHERE DATE(s.sale_date)=CURDATE() AND s.status='completed' GROUP BY m.id,m.name ORDER BY qty DESC LIMIT 5")->fetchAll();

    // Last 7 days revenue
    $last7days = $db->query("SELECT DATE(sale_date) as day, COALESCE(SUM(CASE WHEN currency='USD' THEN total_amount ELSE total_amount/NULLIF(exchange_rate,0) END),0) as rev FROM sales WHERE sale_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) AND status='completed' GROUP BY DATE(sale_date) ORDER BY day ASC")->fetchAll();
} catch (Exception $e) {}

// Fill missing days in last 7
$dayMap = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $dayMap[$d] = 0;
}
foreach ($last7days as $row) { $dayMap[$row['day']] = (float)$row['rev']; }
$sparkValues = array_values($dayMap);
$sparkLabels = array_keys($dayMap);
$sparkMax    = max($sparkValues) ?: 1;

$maxTop5 = $top5Today ? max(array_column($top5Today, 'qty')) : 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title><?= htmlspecialchars($pharmacyName) ?> – Owner Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root {
    --bg: #F0F4FF;
    --card: #ffffff;
    --text: #1E293B;
    --muted: #64748B;
    --border: #E2E8F0;
    --blue: #2563EB;
    --green: #16A34A;
    --amber: #D97706;
    --red: #DC2626;
    --purple: #7C3AED;
    --radius: 14px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; min-height: 100dvh; }

.top-bar {
    background: linear-gradient(135deg, #1E40AF, #2563EB);
    color: #fff;
    padding: 18px 16px 14px;
    position: sticky;
    top: 0;
    z-index: 10;
}
.top-bar h1 { font-size: 1.1rem; font-weight: 700; }
.top-bar .date { font-size: .78rem; opacity: .8; margin-top: 2px; }
.top-bar .user { font-size: .75rem; opacity: .7; }

.top-actions { display: flex; gap: 8px; margin-top: 12px; }
.top-actions a, .top-actions button {
    flex: 1;
    padding: 8px 4px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,.3);
    background: rgba(255,255,255,.12);
    color: #fff;
    font-size: .78rem;
    font-weight: 600;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    transition: background .15s;
}
.top-actions a:hover, .top-actions button:hover { background: rgba(255,255,255,.22); }

.content { padding: 14px 12px 80px; max-width: 480px; margin: 0 auto; }

.section-label {
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--muted);
    margin: 18px 0 8px;
}

/* KPI Grid */
.kpi-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.kpi-card {
    background: var(--card);
    border-radius: var(--radius);
    padding: 14px 14px 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,.07);
    position: relative;
    overflow: hidden;
}
.kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
}
.kpi-card.blue::before   { background: var(--blue); }
.kpi-card.green::before  { background: var(--green); }
.kpi-card.amber::before  { background: var(--amber); }
.kpi-card.purple::before { background: var(--purple); }

.kpi-icon {
    width: 34px; height: 34px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
    margin-bottom: 8px;
}
.kpi-icon.blue   { background: #EFF6FF; color: var(--blue); }
.kpi-icon.green  { background: #F0FDF4; color: var(--green); }
.kpi-icon.amber  { background: #FFFBEB; color: var(--amber); }
.kpi-icon.purple { background: #F5F3FF; color: var(--purple); }

.kpi-val  { font-size: 1.45rem; font-weight: 800; line-height: 1; }
.kpi-label{ font-size: .72rem; color: var(--muted); margin-top: 4px; }
.kpi-sub  { font-size: .68rem; color: var(--muted); margin-top: 2px; }

/* Sparkline */
.spark-card {
    background: var(--card);
    border-radius: var(--radius);
    padding: 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,.07);
}
.spark-card h3 { font-size: .82rem; font-weight: 700; margin-bottom: 10px; color: var(--text); }
.spark-svg { width: 100%; overflow: visible; }
.spark-bar { fill: var(--blue); opacity: .8; rx: 3; }
.spark-bar.today { fill: var(--blue); opacity: 1; }
.spark-day-label { font-size: 9px; fill: var(--muted); text-anchor: middle; }
.spark-val-label { font-size: 8px; fill: var(--blue); text-anchor: middle; font-weight: 700; }

/* Alerts */
.alert-chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 4px; }
.chip {
    padding: 5px 12px;
    border-radius: 999px;
    font-size: .75rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 5px;
}
.chip.red    { background: #FEE2E2; color: #B91C1C; }
.chip.orange { background: #FEF3C7; color: #92400E; }
.chip.gray   { background: #F1F5F9; color: #475569; }

/* Top medicines list */
.med-list { display: flex; flex-direction: column; gap: 8px; }
.med-item {
    background: var(--card);
    border-radius: 10px;
    padding: 10px 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
}
.med-item .med-name { font-size: .82rem; font-weight: 600; }
.med-item .med-qty  { font-size: .72rem; color: var(--muted); }
.med-progress {
    height: 5px;
    border-radius: 999px;
    background: #E2E8F0;
    margin-top: 6px;
    overflow: hidden;
}
.med-progress-fill {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, var(--blue), #60A5FA);
}

/* Bottom nav placeholder */
.bottom-pad { height: 20px; }

@media (min-width: 480px) {
    .kpi-val { font-size: 1.6rem; }
}
</style>
</head>
<body>

<div class="top-bar">
    <h1><i class="bi bi-hospital" style="margin-right:6px"></i><?= htmlspecialchars($pharmacyName) ?></h1>
    <div class="date"><?= date('l, F j, Y') ?></div>
    <div class="user">Logged in as <?= htmlspecialchars($user['full_name'] ?? 'User') ?></div>
    <div class="top-actions">
        <button onclick="location.reload()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        <a href="<?= BASE_URL ?>/modules/reports/dashboard.php"><i class="bi bi-grid"></i> Full App</a>
    </div>
</div>

<div class="content">

    <!-- Alerts -->
    <div class="section-label">Alerts</div>
    <div class="alert-chips">
        <?php if ($lowStockCount > 0): ?>
        <a href="<?= BASE_URL ?>/modules/inventory/alerts.php" class="chip red" style="text-decoration:none">
            <i class="bi bi-exclamation-triangle-fill"></i> <?= $lowStockCount ?> Low Stock
        </a>
        <?php else: ?>
        <span class="chip gray"><i class="bi bi-check-circle"></i> No low stock</span>
        <?php endif; ?>

        <?php if ($nearExpiryCount > 0): ?>
        <a href="<?= BASE_URL ?>/modules/inventory/alerts.php" class="chip orange" style="text-decoration:none">
            <i class="bi bi-clock-fill"></i> <?= $nearExpiryCount ?> Near Expiry
        </a>
        <?php else: ?>
        <span class="chip gray"><i class="bi bi-check-circle"></i> No near-expiry</span>
        <?php endif; ?>
    </div>

    <!-- KPI Cards -->
    <div class="section-label">Today's Performance</div>
    <div class="kpi-grid">
        <div class="kpi-card blue">
            <div class="kpi-icon blue"><i class="bi bi-currency-dollar"></i></div>
            <div class="kpi-val">$<?= number_format($todayRevUSD, 0) ?></div>
            <div class="kpi-label">Revenue Today</div>
            <div class="kpi-sub">USD equivalent</div>
        </div>
        <div class="kpi-card green">
            <div class="kpi-icon green"><i class="bi bi-receipt"></i></div>
            <div class="kpi-val"><?= $todaySalesCount ?></div>
            <div class="kpi-label">Sales Count</div>
            <div class="kpi-sub">transactions</div>
        </div>
        <div class="kpi-card amber">
            <div class="kpi-icon amber"><i class="bi bi-capsule"></i></div>
            <div class="kpi-val" style="font-size:1rem;line-height:1.3"><?= htmlspecialchars(mb_substr($topMedName,0,18) . (mb_strlen($topMedName)>18?'…':'')) ?></div>
            <div class="kpi-label">Top Medicine</div>
            <div class="kpi-sub"><?= $topMedQty ?> units sold</div>
        </div>
        <div class="kpi-card purple">
            <div class="kpi-icon purple"><i class="bi bi-safe2"></i></div>
            <div class="kpi-val">$<?= number_format($cashInRegister, 0) ?></div>
            <div class="kpi-label">Cash in Register</div>
            <div class="kpi-sub">USD equivalent</div>
        </div>
    </div>

    <!-- Sparkline: last 7 days -->
    <div class="section-label">Last 7 Days Revenue</div>
    <div class="spark-card">
        <h3><i class="bi bi-bar-chart-fill" style="color:var(--blue);margin-right:5px"></i>Daily Revenue Trend</h3>
        <?php
        $svgW = 320;
        $svgH = 80;
        $n    = count($sparkValues);
        $padL = 4; $padR = 4; $padT = 16; $padB = 22;
        $barW = ($svgW - $padL - $padR) / $n;
        $gap  = 4;
        $innerW = $barW - $gap;
        $innerH = $svgH - $padT - $padB;
        ?>
        <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" class="spark-svg" style="display:block;width:100%;height:auto">
        <?php foreach ($sparkValues as $i => $val):
            $barH = max(4, ($val / $sparkMax) * $innerH);
            $x    = $padL + $i * $barW + $gap/2;
            $y    = $padT + $innerH - $barH;
            $isToday = ($i === $n - 1);
            $label = date('D', strtotime($sparkLabels[$i]));
        ?>
            <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $innerW ?>" height="<?= $barH ?>"
                  rx="3" fill="<?= $isToday ? '#2563EB' : '#93C5FD' ?>" opacity="<?= $isToday ? '1' : '.75' ?>"/>
            <?php if ($val > 0): ?>
            <text x="<?= $x + $innerW/2 ?>" y="<?= $y - 3 ?>" text-anchor="middle"
                  font-size="7" fill="#1D4ED8" font-weight="<?= $isToday ? '700' : '400' ?>">
                $<?= number_format($val,0) ?>
            </text>
            <?php endif; ?>
            <text x="<?= $x + $innerW/2 ?>" y="<?= $svgH - 4 ?>" text-anchor="middle"
                  font-size="8" fill="<?= $isToday ? '#1D4ED8' : '#94A3B8' ?>"
                  font-weight="<?= $isToday ? '700' : '400' ?>">
                <?= $label ?>
            </text>
        <?php endforeach; ?>
        </svg>
    </div>

    <!-- Top 5 medicines today -->
    <?php if (!empty($top5Today)): ?>
    <div class="section-label">Top Medicines Sold Today</div>
    <div class="med-list">
        <?php foreach ($top5Today as $i => $med): ?>
        <div class="med-item">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <div>
                    <div class="med-name"><?= htmlspecialchars($med['name']) ?></div>
                    <div class="med-qty"><?= (int)$med['qty'] ?> units sold</div>
                </div>
                <div style="font-size:1.1rem;font-weight:800;color:var(--blue)">#<?= $i+1 ?></div>
            </div>
            <div class="med-progress">
                <div class="med-progress-fill" style="width:<?= round(($med['qty']/$maxTop5)*100) ?>%"></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="section-label">Top Medicines</div>
    <div style="background:var(--card);border-radius:var(--radius);padding:20px;text-align:center;color:var(--muted);font-size:.82rem">
        No sales recorded today yet.
    </div>
    <?php endif; ?>

    <div class="bottom-pad"></div>
</div>

</body>
</html>
<?php ob_end_flush(); ?>
