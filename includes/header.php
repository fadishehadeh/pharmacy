<?php
require_once __DIR__ . '/../config/app.php';
$flash = getFlashMessage();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir = basename(dirname($_SERVER['PHP_SELF']));
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'PharmaSys' ?> - Pharmacy Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/datatables.net-bs5@2.0.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="d-flex" id="wrapper">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div id="page-content-wrapper" class="w-100">
        <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
            <button class="btn btn-sm btn-outline-secondary me-3" id="sidebarToggle">
                <i class="bi bi-list"></i>
            </button>
            <span class="navbar-text fw-semibold"><?= $pageTitle ?? '' ?></span>
            <div class="ms-auto d-flex align-items-center gap-3">
                <?php
                $expiring = getExpiringMedicines(30);
                $lowStock = getLowStockMedicines();
                $alertCount = count($expiring) + count($lowStock);
                ?>
                <?php if ($alertCount > 0): ?>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-warning position-relative" data-bs-toggle="dropdown">
                        <i class="bi bi-bell"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $alertCount ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" style="width:320px;max-height:400px;overflow-y:auto">
                        <li><h6 class="dropdown-header">Alerts</h6></li>
                        <?php foreach (array_slice($expiring, 0, 5) as $med): ?>
                        <li><a class="dropdown-item small" href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $med['id'] ?>">
                            <i class="bi bi-clock text-warning"></i> <?= sanitize($med['name']) ?> expires <?= formatDate($med['expiry_date'], 'M d, Y') ?>
                        </a></li>
                        <?php endforeach; ?>
                        <?php foreach (array_slice($lowStock, 0, 5) as $med): ?>
                        <li><a class="dropdown-item small" href="<?= BASE_URL ?>/modules/inventory/edit.php?id=<?= $med['id'] ?>">
                            <i class="bi bi-exclamation-triangle text-danger"></i> <?= sanitize($med['name']) ?> - Only <?= $med['quantity_in_stock'] ?> left
                        </a></li>
                        <?php endforeach; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-center small" href="<?= BASE_URL ?>/modules/inventory/alerts.php">View All Alerts</a></li>
                    </ul>
                </div>
                <?php endif; ?>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?= sanitize(currentUser()['full_name'] ?? 'User') ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/settings/index.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </nav>
        <div class="container-fluid p-4">
            <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> alert-dismissible fade show">
                <?= $flash['message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
