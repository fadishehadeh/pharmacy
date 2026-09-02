<?php
require_once __DIR__ . '/config/app.php';
requireLogin();

$user = currentUser();
$role = $user['role'] ?? 'viewer';
$name = $user['full_name'] ?? $user['username'] ?? 'User';
$firstName = explode(' ', trim($name))[0];

$hour = (int)date('H');
$greeting = $hour < 12 ? tr('good_morning', 'Good morning') : ($hour < 18 ? tr('good_afternoon', 'Good afternoon') : tr('good_evening', 'Good evening'));

$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');

// Role-based launcher tiles
$tiles = [
    'admin' => [
        ['icon' => 'bi-cart3',         'color' => '#2563EB', 'bg' => '#EFF6FF', 'label' => 'New Sale',   'sub' => 'Open the cash register',      'url' => BASE_URL . '/modules/pos/index.php'],
        ['icon' => 'bi-box-seam',      'color' => '#059669', 'bg' => '#ECFDF5', 'label' => 'Inventory',  'sub' => 'Medicines & stock levels',     'url' => BASE_URL . '/modules/inventory/index.php'],
        ['icon' => 'bi-bar-chart-line','color' => '#7C3AED', 'bg' => '#F5F3FF', 'label' => 'Analytics',  'sub' => 'Sales & performance reports',  'url' => BASE_URL . '/modules/reports/dashboard.php'],
        ['icon' => 'bi-gear',          'color' => '#475569', 'bg' => '#F8FAFC', 'label' => 'Settings',   'sub' => 'Users, pharmacy & system',     'url' => BASE_URL . '/modules/settings/index.php'],
    ],
    'pharmacist' => [
        ['icon' => 'bi-cart3',         'color' => '#2563EB', 'bg' => '#EFF6FF', 'label' => 'New Sale',      'sub' => 'Start a new transaction',        'url' => BASE_URL . '/modules/pos/index.php'],
        ['icon' => 'bi-file-medical',  'color' => '#0891B2', 'bg' => '#ECFEFF', 'label' => 'Prescriptions', 'sub' => 'View & process prescriptions',   'url' => BASE_URL . '/modules/prescriptions/index.php'],
        ['icon' => 'bi-box-seam',      'color' => '#059669', 'bg' => '#ECFDF5', 'label' => 'Inventory',     'sub' => 'Stock, expiry & reorder',        'url' => BASE_URL . '/modules/inventory/index.php'],
        ['icon' => 'bi-shield-exclamation','color' => '#DC2626','bg' => '#FEF2F2','label'=>'Drug Interactions','sub'=> 'Check drug compatibility',       'url' => BASE_URL . '/modules/interactions/index.php'],
    ],
    'cashier' => [
        ['icon' => 'bi-cart3',         'color' => '#2563EB', 'bg' => '#EFF6FF', 'label' => 'New Sale',      'sub' => 'Start a new transaction',        'url' => BASE_URL . '/modules/pos/index.php'],
        ['icon' => 'bi-receipt',       'color' => '#D97706', 'bg' => '#FFFBEB', 'label' => 'Sales History', 'sub' => 'View past transactions',         'url' => BASE_URL . '/modules/sales/index.php'],
        ['icon' => 'bi-cash-stack',    'color' => '#059669', 'bg' => '#ECFDF5', 'label' => 'Cash Register', 'sub' => 'Open or close register',        'url' => BASE_URL . '/modules/finance/cash_register.php'],
        ['icon' => 'bi-person-heart',  'color' => '#7C3AED', 'bg' => '#F5F3FF', 'label' => 'Customers',    'sub' => 'Search customer profiles',       'url' => BASE_URL . '/modules/pos/customers.php'],
    ],
    'viewer' => [
        ['icon' => 'bi-box-seam',      'color' => '#059669', 'bg' => '#ECFDF5', 'label' => 'Inventory',     'sub' => 'Browse medicines & stock',       'url' => BASE_URL . '/modules/inventory/index.php'],
        ['icon' => 'bi-building',      'color' => '#2563EB', 'bg' => '#EFF6FF', 'label' => 'MoPH Prices',   'sub' => 'Official drug price list',       'url' => BASE_URL . '/modules/moph/price_list.php'],
        ['icon' => 'bi-bar-chart-line','color' => '#7C3AED', 'bg' => '#F5F3FF', 'label' => 'Reports',       'sub' => 'View sales & analytics',         'url' => BASE_URL . '/modules/reports/daily.php'],
        ['icon' => 'bi-receipt',       'color' => '#D97706', 'bg' => '#FFFBEB', 'label' => 'Sales',         'sub' => 'Browse transaction history',     'url' => BASE_URL . '/modules/sales/index.php'],
    ],
];

$myTiles = $tiles[$role] ?? $tiles['viewer'];

// Quick stats for header strip
$todaySales = 0; $alertCount = 0;
try {
    $db = getDB();
    $todaySales = $db->query("SELECT COUNT(*) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed'")->fetchColumn();
    $low   = $db->query("SELECT COUNT(*) FROM medicines WHERE quantity_in_stock <= min_stock_level AND is_active = 1")->fetchColumn();
    $expir = $db->query("SELECT COUNT(*) FROM medicines WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE() AND is_active = 1")->fetchColumn();
    $alertCount = $low + $expir;
} catch (Exception $e) {}

$roleLabels = ['admin' => 'Administrator', 'pharmacist' => 'Pharmacist', 'cashier' => 'Cashier', 'viewer' => 'Viewer'];
$roleColors = ['admin' => '#7C3AED', 'pharmacist' => '#0891B2', 'cashier' => '#059669', 'viewer' => '#64748B'];
$roleLabel  = $roleLabels[$role] ?? ucfirst($role);
$roleColor  = $roleColors[$role] ?? '#64748B';
?>
<!DOCTYPE html>
<html lang="<?= langCode() ?>" dir="<?= isRtl() ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome — <?= htmlspecialchars($pharmacyName, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <meta name="theme-color" content="#2563EB">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body {
            height: 100%; margin: 0;
            font-family: 'Inter', sans-serif;
            background: #F1F5F9;
        }

        .welcome-shell {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* TOP BAR */
        .top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 2rem;
            background: #fff;
            border-bottom: 1px solid #E2E8F0;
        }
        .top-bar .brand {
            display: flex; align-items: center; gap: 10px;
            font-weight: 700; font-size: 1.1rem; color: #0F172A;
        }
        .top-bar .brand i { color: #2563EB; font-size: 1.4rem; }
        .top-bar .pharmacy-sub { font-size: 0.78rem; color: #94A3B8; font-weight: 400; }

        .top-bar .top-actions { display: flex; align-items: center; gap: 1rem; }
        .top-bar .stat-chip {
            display: flex; align-items: center; gap: 6px;
            background: #F8FAFC; border: 1px solid #E2E8F0;
            border-radius: 20px; padding: 5px 14px;
            font-size: 0.8rem; color: #475569; font-weight: 500;
        }
        .top-bar .stat-chip .dot { width: 7px; height: 7px; border-radius: 50%; background: #22C55E; display: inline-block; }
        .btn-logout {
            font-size: 0.8rem; color: #94A3B8;
            border: 1px solid #E2E8F0; border-radius: 8px;
            padding: 5px 12px; background: #fff; text-decoration: none;
            transition: all .15s;
        }
        .btn-logout:hover { color: #DC2626; border-color: #FCA5A5; background: #FEF2F2; }

        /* MAIN */
        .welcome-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem 1.5rem;
        }

        .greeting-block {
            text-align: center;
            margin-bottom: 3rem;
        }
        .greeting-block .greeting-text {
            font-size: 2rem;
            font-weight: 700;
            color: #0F172A;
            line-height: 1.2;
        }
        .greeting-block .greeting-sub {
            font-size: 0.95rem;
            color: #94A3B8;
            margin-top: 0.4rem;
        }
        .role-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #fff;
            margin-top: 0.6rem;
        }

        /* TILES */
        .tiles-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            max-width: 860px;
            width: 100%;
        }
        @media (max-width: 900px) {
            .tiles-grid { grid-template-columns: repeat(2, 1fr); max-width: 500px; }
        }
        @media (max-width: 480px) {
            .tiles-grid { grid-template-columns: 1fr 1fr; gap: 0.75rem; }
            .greeting-block .greeting-text { font-size: 1.5rem; }
        }

        .tile {
            background: #fff;
            border-radius: 16px;
            border: 2px solid transparent;
            padding: 2rem 1.25rem 1.5rem;
            text-align: center;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            transition: transform .18s, box-shadow .18s, border-color .18s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .tile:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(0,0,0,0.10);
            border-color: var(--tile-color);
            text-decoration: none;
        }
        .tile:active { transform: translateY(-1px); }

        .tile-icon {
            width: 64px; height: 64px;
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.8rem;
            flex-shrink: 0;
        }
        .tile-label {
            font-size: 1rem;
            font-weight: 700;
            color: #0F172A;
            line-height: 1.2;
        }
        .tile-sub {
            font-size: 0.78rem;
            color: #94A3B8;
            line-height: 1.4;
        }

        /* ALERTS STRIP */
        .alerts-strip {
            margin-top: 2.5rem;
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .alert-chip {
            display: flex; align-items: center; gap: 6px;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            transition: opacity .15s;
        }
        .alert-chip:hover { opacity: 0.8; text-decoration: none; }
        .chip-warning { background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A; }
        .chip-info    { background: #EFF6FF; color: #1D4ED8; border: 1px solid #BFDBFE; }

        .enter-full-btn {
            margin-top: 2rem;
            font-size: 0.82rem;
            color: #CBD5E1;
            text-decoration: none;
            transition: color .15s;
        }
        .enter-full-btn:hover { color: #2563EB; text-decoration: none; }
    </style>
</head>
<body>
<div class="welcome-shell">

    <!-- TOP BAR -->
    <div class="top-bar">
        <div class="brand">
            <i class="bi bi-capsule"></i>
            <div>
                <div><?= htmlspecialchars($pharmacyName, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="pharmacy-sub"><?= date('l, F j, Y') ?></div>
            </div>
        </div>
        <div class="top-actions">
            <?php if ($todaySales > 0): ?>
            <div class="stat-chip">
                <span class="dot"></span>
                <?= (int)$todaySales ?> <?= $todaySales != 1 ? t('sales_today_pl') : t('sales_today') ?>
            </div>
            <?php endif; ?>
            <!-- Language toggle -->
            <a href="?setlang=<?= langCode() === 'ar' ? 'en' : 'ar' ?>" style="font-size:.8rem;font-weight:700;color:#94A3B8;text-decoration:none;border:1px solid #E2E8F0;border-radius:6px;padding:4px 10px">
                <?= langCode() === 'ar' ? 'EN' : 'ع' ?>
            </a>
            <a href="<?= BASE_URL ?>/logout.php" class="btn-logout">
                <i class="bi bi-box-arrow-right me-1"></i><?= t('sign_out') ?>
            </a>
        </div>
    </div>

    <!-- MAIN -->
    <div class="welcome-main">

        <div class="greeting-block">
            <div class="greeting-text"><?= $greeting ?>, <?= htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="greeting-sub"><?= t('what_today') ?></div>
            <div class="role-badge" style="background:<?= $roleColor ?>">
                <?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>

        <!-- TILES -->
        <div class="tiles-grid">
            <?php foreach ($myTiles as $tile): ?>
            <a href="<?= $tile['url'] ?>" class="tile" style="--tile-color:<?= $tile['color'] ?>">
                <div class="tile-icon" style="background:<?= $tile['bg'] ?>;color:<?= $tile['color'] ?>">
                    <i class="bi <?= $tile['icon'] ?>"></i>
                </div>
                <div class="tile-label"><?= htmlspecialchars($tile['label'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="tile-sub"><?= htmlspecialchars($tile['sub'], ENT_QUOTES, 'UTF-8') ?></div>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- ALERT CHIPS -->
        <?php if ($alertCount > 0): ?>
        <div class="alerts-strip">
            <a href="<?= BASE_URL ?>/modules/inventory/alerts.php" class="alert-chip chip-warning">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= (int)$alertCount ?> <?= $alertCount != 1 ? t('stock_alerts_pl') : t('stock_alerts') ?>
            </a>
        </div>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/modules/pos/index.php" class="enter-full-btn">
            <i class="bi bi-grid-3x3-gap me-1"></i><?= t('full_dashboard') ?> &rarr;
        </a>
    </div>

</div>
</body>
</html>
