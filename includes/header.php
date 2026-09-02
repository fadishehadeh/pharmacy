<?php
require_once __DIR__ . '/../config/app.php';

// Auth gate: redirect unauthenticated users before any DB queries or HTML output.
requireLogin();

// CSRF: validate every state-changing POST before any module code runs.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$flash = getFlashMessage();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir = basename(dirname($_SERVER['PHP_SELF']));
?>
<!DOCTYPE html>
<html lang="<?= langCode() ?>" dir="<?= isRtl() ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'PharmaSys' ?> - Pharmacy Management</title>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <meta name="theme-color" content="#2563EB">
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/icons/icon-192.png">
    <?php if (isRtl()): ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <?php else: ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/datatables.net-bs5@2.0.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <?php if (isRtl()): ?>
    <style>
        body { font-family: 'Segoe UI', Tahoma, 'Cairo', sans-serif; }
        .sidebar { left: auto !important; right: 0; border-right: none; border-left: 1px solid var(--border); }
        #page-content-wrapper { margin-left: 0 !important; margin-right: var(--sidebar-w); }
        .sidebar-nav .nav-link .chevron { margin-left: 0; margin-right: auto; }
        .sidebar-nav .sub-item { padding-right: 2.5rem; padding-left: 1rem; }
        .dropdown-menu-end { --bs-position: start; }
    </style>
    <?php endif; ?>
    <script>
    // Auto-inject CSRF token into every fetch() call and XMLHttpRequest as a header,
    // and into every dynamically-created form as a hidden field.
    (function () {
        var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        // Patch fetch
        var _fetch = window.fetch;
        window.fetch = function (url, opts) {
            opts = opts || {};
            opts.headers = Object.assign({}, opts.headers, {'X-CSRF-Token': token});
            return _fetch(url, opts);
        };
        // Patch XMLHttpRequest
        var _open = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function () {
            _open.apply(this, arguments);
            this.setRequestHeader('X-CSRF-Token', token);
        };
        // Inject hidden field into every form on submit
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form.querySelector('input[name="_csrf"]')) {
                var h = document.createElement('input');
                h.type = 'hidden'; h.name = '_csrf'; h.value = token;
                form.appendChild(h);
            }
        }, true);
    })();
    </script>
<?php $__gaId = getSetting('ga_measurement_id',''); if ($__gaId): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($__gaId, ENT_QUOTES) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?= htmlspecialchars($__gaId, ENT_QUOTES) ?>');</script>
<?php endif; ?>
</head>
<body>
<div class="d-flex" id="wrapper">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div id="page-content-wrapper" class="w-100">
        <nav class="navbar navbar-expand-lg navbar-light border-bottom px-3">
            <button class="btn btn-sm navbar-toggle-btn me-3" id="sidebarToggle">
                <i class="bi bi-list"></i>
            </button>
            <span class="navbar-text"><?= htmlspecialchars($pageTitle ?? '', ENT_QUOTES, 'UTF-8') ?></span>
            <div class="ms-auto d-flex align-items-center gap-2">
                <!-- Language toggle -->
                <a href="?setlang=<?= langCode() === 'ar' ? 'en' : 'ar' ?>"
                   class="btn btn-sm"
                   style="border:1px solid #E2E8F0;border-radius:8px;font-size:.8rem;font-weight:600;color:#475569;background:#fff;padding:4px 10px"
                   title="Switch language">
                    <?= langCode() === 'ar' ? 'EN' : 'ع' ?>
                </a>
                <a href="<?= BASE_URL ?>/modules/pos/index.php"
                   class="btn btn-primary btn-sm d-flex align-items-center gap-1"
                   style="font-weight:600;padding:6px 14px;border-radius:8px">
                    <i class="bi bi-cart3"></i>
                    <span class="d-none d-sm-inline"><?= t('new_sale') ?></span>
                </a>
                <a href="<?= BASE_URL ?>/modules/reports/mobile_dashboard.php"
                   class="btn btn-sm d-flex align-items-center gap-1"
                   style="border:1px solid #E2E8F0;border-radius:8px;background:#fff;color:#7C3AED;font-weight:600;padding:6px 10px"
                   title="Owner Mobile Dashboard">
                    <i class="bi bi-phone"></i>
                    <span class="d-none d-lg-inline" style="font-size:.8rem">Dashboard</span>
                </a>
                <?php
                $expiring = getExpiringMedicines(30);
                $lowStock = getLowStockMedicines();
                $alertCount = count($expiring) + count($lowStock);
                ?>
                <?php if ($alertCount > 0): ?>
                <div class="dropdown">
                    <button class="btn btn-sm position-relative" style="border:1px solid #E2E8F0;border-radius:8px;color:#D97706;background:#FFFBEB" data-bs-toggle="dropdown">
                        <i class="bi bi-bell-fill"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem"><?= $alertCount ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" style="width:320px;max-height:400px;overflow-y:auto">
                        <li><div class="px-3 py-2 fw-semibold" style="font-size:.8rem;color:#64748B;border-bottom:1px solid #F1F5F9">
                            <i class="bi bi-bell me-1"></i> <?= $alertCount ?> Alert<?= $alertCount > 1 ? 's' : '' ?>
                        </div></li>
                        <?php foreach (array_slice($expiring, 0, 5) as $med): ?>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $med['id'] ?>" style="font-size:.82rem">
                            <i class="bi bi-clock-fill me-2 text-warning"></i><?= sanitize($med['name']) ?> &mdash; expires <?= formatDate($med['expiry_date'], 'M d, Y') ?>
                        </a></li>
                        <?php endforeach; ?>
                        <?php foreach (array_slice($lowStock, 0, 5) as $med): ?>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $med['id'] ?>" style="font-size:.82rem">
                            <i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i><?= sanitize($med['name']) ?> &mdash; <?= (int)$med['quantity_in_stock'] ?> left
                        </a></li>
                        <?php endforeach; ?>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li><a class="dropdown-item text-center" href="<?= BASE_URL ?>/modules/inventory/alerts.php" style="font-size:.82rem;color:#2563EB">View all alerts &rarr;</a></li>
                    </ul>
                </div>
                <?php endif; ?>
                <div class="dropdown">
                    <?php
                    $u = currentUser();
                    $initials = strtoupper(substr($u['full_name'] ?? 'U', 0, 1) . (strpos($u['full_name'] ?? '', ' ') !== false ? substr(strrchr($u['full_name'], ' '), 1, 1) : ''));
                    ?>
                    <button class="btn btn-sm d-flex align-items-center gap-2 dropdown-toggle"
                            style="border:1px solid #E2E8F0;border-radius:8px;background:#fff;color:#334155;font-weight:500;font-size:.85rem;padding:5px 10px"
                            data-bs-toggle="dropdown">
                        <div class="user-avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
                        <span class="d-none d-md-inline"><?= sanitize($u['full_name'] ?? 'User') ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><div class="px-3 py-2" style="font-size:.8rem;color:#94A3B8"><?= sanitize($u['role'] ?? '') ?></div></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/settings/index.php"><i class="bi bi-gear me-2 text-muted"></i>Settings</a></li>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </nav>
        <!-- Offline banner -->
        <div id="offlineBanner" class="d-none" style="background:#FEF3C7;border-bottom:1px solid #FDE68A;padding:8px 16px;font-size:.83rem;color:#92400E;display:flex;align-items:center;gap:8px">
            <i class="bi bi-wifi-off"></i>
            <span id="offlineBannerText"><?= t('offline_banner') ?></span>
        </div>
        <div class="container-fluid p-4">
            <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show">
                <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
