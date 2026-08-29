<?php
$menuItems = [
    ['icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'url' => BASE_URL . '/index.php', 'dir' => ''],
    ['icon' => 'bi-box-seam', 'label' => 'Inventory', 'url' => '#', 'dir' => 'inventory', 'children' => [
        ['label' => 'All Medicines', 'url' => BASE_URL . '/modules/inventory/index.php'],
        ['label' => 'Add Medicine', 'url' => BASE_URL . '/modules/inventory/add.php'],
        ['label' => 'Categories', 'url' => BASE_URL . '/modules/inventory/categories.php'],
        ['label' => 'Shelves & Cabinets', 'url' => BASE_URL . '/modules/inventory/shelves.php'],
        ['label' => 'Expiry Alerts', 'url' => BASE_URL . '/modules/inventory/alerts.php'],
        ['label' => 'Stock Movements', 'url' => BASE_URL . '/modules/inventory/movements.php'],
    ]],
    ['icon' => 'bi-cart3', 'label' => 'Point of Sale', 'url' => BASE_URL . '/modules/pos/index.php', 'dir' => 'pos'],
    ['icon' => 'bi-receipt', 'label' => 'Sales', 'url' => '#', 'dir' => 'sales', 'children' => [
        ['label' => 'Sales History', 'url' => BASE_URL . '/modules/sales/index.php'],
        ['label' => 'Returns', 'url' => BASE_URL . '/modules/sales/returns.php'],
        ['label' => 'Reports', 'url' => BASE_URL . '/modules/sales/reports.php'],
    ]],
    ['icon' => 'bi-truck', 'label' => 'Suppliers', 'url' => '#', 'dir' => 'suppliers', 'children' => [
        ['label' => 'All Suppliers', 'url' => BASE_URL . '/modules/suppliers/index.php'],
        ['label' => 'Purchase Orders', 'url' => BASE_URL . '/modules/suppliers/orders.php'],
    ]],
    ['icon' => 'bi-cash-stack', 'label' => 'Finance', 'url' => '#', 'dir' => 'finance', 'children' => [
        ['label' => 'Overview', 'url' => BASE_URL . '/modules/finance/index.php'],
        ['label' => 'Expenses', 'url' => BASE_URL . '/modules/finance/expenses.php'],
        ['label' => 'Profit & Loss', 'url' => BASE_URL . '/modules/finance/profit_loss.php'],
        ['label' => 'Taxes', 'url' => BASE_URL . '/modules/finance/taxes.php'],
    ]],
    ['icon' => 'bi-building', 'label' => 'MoPH', 'url' => '#', 'dir' => 'moph', 'children' => [
        ['label' => 'Price List', 'url' => BASE_URL . '/modules/moph/price_list.php'],
        ['label' => 'Controlled Substances', 'url' => BASE_URL . '/modules/moph/controlled.php'],
        ['label' => 'Subsidy Tracking', 'url' => BASE_URL . '/modules/moph/subsidy.php'],
    ]],
    ['icon' => 'bi-shield-plus', 'label' => 'Insurance', 'url' => '#', 'dir' => 'insurance', 'children' => [
        ['label' => 'Providers', 'url' => BASE_URL . '/modules/insurance/providers.php'],
        ['label' => 'Claims', 'url' => BASE_URL . '/modules/insurance/claims.php'],
    ]],
    ['icon' => 'bi-people', 'label' => 'Customers', 'url' => BASE_URL . '/modules/pos/customers.php', 'dir' => 'customers'],
    ['icon' => 'bi-gear', 'label' => 'Settings', 'url' => BASE_URL . '/modules/settings/index.php', 'dir' => 'settings'],
];
?>
<div class="sidebar bg-dark text-white" id="sidebar">
    <div class="sidebar-header p-3 border-bottom border-secondary">
        <h5 class="mb-0"><i class="bi bi-capsule me-2"></i><?= APP_NAME ?></h5>
        <small class="text-muted"><?= sanitize(getSetting('pharmacy_name', 'My Pharmacy')) ?></small>
    </div>
    <ul class="nav flex-column p-2">
        <?php foreach ($menuItems as $item): ?>
            <?php $isActive = ($currentDir === $item['dir']) || ($item['dir'] === '' && $currentPage === 'index' && $currentDir === basename(BASE_URL)); ?>
            <?php if (isset($item['children'])): ?>
            <li class="nav-item">
                <a class="nav-link text-white d-flex justify-content-between align-items-center <?= $isActive ? 'active' : '' ?>"
                   data-bs-toggle="collapse" href="#menu-<?= $item['dir'] ?>" role="button"
                   aria-expanded="<?= $isActive ? 'true' : 'false' ?>">
                    <span><i class="<?= $item['icon'] ?> me-2"></i><?= $item['label'] ?></span>
                    <i class="bi bi-chevron-down small"></i>
                </a>
                <div class="collapse <?= $isActive ? 'show' : '' ?>" id="menu-<?= $item['dir'] ?>">
                    <ul class="nav flex-column ms-3">
                        <?php foreach ($item['children'] as $child): ?>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1" href="<?= $child['url'] ?>">
                                <i class="bi bi-dot me-1"></i><?= $child['label'] ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </li>
            <?php else: ?>
            <li class="nav-item">
                <a class="nav-link text-white <?= $isActive ? 'active' : '' ?>" href="<?= $item['url'] ?>">
                    <i class="<?= $item['icon'] ?> me-2"></i><?= $item['label'] ?>
                </a>
            </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
</div>
