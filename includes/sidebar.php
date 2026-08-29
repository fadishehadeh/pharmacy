<?php
$menuItems = [
    ['icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'url' => BASE_URL . '/index.php', 'dir' => ''],
    ['icon' => 'bi-box-seam', 'label' => 'Inventory', 'url' => '#', 'dir' => 'inventory', 'children' => [
        ['label' => 'All Medicines', 'url' => BASE_URL . '/modules/inventory/index.php'],
        ['label' => 'Add Medicine', 'url' => BASE_URL . '/modules/inventory/add.php'],
        ['label' => 'Batch Tracking', 'url' => BASE_URL . '/modules/inventory/batches.php'],
        ['label' => 'Categories', 'url' => BASE_URL . '/modules/inventory/categories.php'],
        ['label' => 'Shelves & Cabinets', 'url' => BASE_URL . '/modules/inventory/shelves.php'],
        ['label' => 'Alternatives', 'url' => BASE_URL . '/modules/inventory/alternatives.php'],
        ['label' => 'Expiry Alerts', 'url' => BASE_URL . '/modules/inventory/alerts.php'],
        ['label' => 'Stock Movements', 'url' => BASE_URL . '/modules/inventory/movements.php'],
        ['label' => 'Stock Count', 'url' => BASE_URL . '/modules/inventory/stock_count.php'],
        ['label' => 'Stock Transfers', 'url' => BASE_URL . '/modules/inventory/transfer.php'],
        ['label' => 'Waste & Disposal', 'url' => BASE_URL . '/modules/inventory/disposal.php'],
        ['label' => 'Print Labels', 'url' => BASE_URL . '/modules/inventory/labels.php'],
        ['label' => 'Smart Reorder', 'url' => BASE_URL . '/modules/inventory/reorder.php'],
        ['label' => 'Export Inventory', 'url' => BASE_URL . '/modules/inventory/export.php'],
        ['label' => 'Price History', 'url' => BASE_URL . '/modules/inventory/price_history.php'],
        ['label' => 'Reorder Levels', 'url' => BASE_URL . '/modules/inventory/reorder_levels.php'],
        ['label' => 'Import Medicines', 'url' => BASE_URL . '/modules/inventory/import.php'],
        ['label' => 'Barcode Generator', 'url' => BASE_URL . '/modules/inventory/barcode_generator.php'],
        ['label' => 'Stocktake', 'url' => BASE_URL . '/modules/inventory/stocktake.php'],
        ['label' => 'Near-Expiry Deals', 'url' => BASE_URL . '/modules/inventory/near_expiry_deals.php'],
        ['label' => 'Expiry Calendar', 'url' => BASE_URL . '/modules/inventory/expiry_calendar.php'],
    ]],
    ['icon' => 'bi-cart3', 'label' => 'Point of Sale', 'url' => '#', 'dir' => 'pos', 'children' => [
        ['label' => 'New Sale', 'url' => BASE_URL . '/modules/pos/index.php'],
        ['label' => 'Receipt Templates', 'url' => BASE_URL . '/modules/pos/receipt_templates.php'],
        ['label' => 'Process Return', 'url' => BASE_URL . '/modules/pos/returns.php'],
    ]],
    ['icon' => 'bi-file-medical', 'label' => 'Prescriptions', 'url' => '#', 'dir' => 'prescriptions', 'children' => [
        ['label' => 'All Prescriptions', 'url' => BASE_URL . '/modules/prescriptions/index.php'],
    ]],
    ['icon' => 'bi-receipt', 'label' => 'Sales', 'url' => '#', 'dir' => 'sales', 'children' => [
        ['label' => 'Sales History', 'url' => BASE_URL . '/modules/sales/index.php'],
        ['label' => 'Returns', 'url' => BASE_URL . '/modules/sales/returns.php'],
        ['label' => 'Quotations', 'url' => BASE_URL . '/modules/sales/quotations.php'],
        ['label' => 'Deliveries', 'url' => BASE_URL . '/modules/sales/deliveries.php'],
        ['label' => 'Reports', 'url' => BASE_URL . '/modules/sales/reports.php'],
    ]],
    ['icon' => 'bi-truck', 'label' => 'Suppliers', 'url' => '#', 'dir' => 'suppliers', 'children' => [
        ['label' => 'All Suppliers', 'url' => BASE_URL . '/modules/suppliers/index.php'],
        ['label' => 'Purchase Orders', 'url' => BASE_URL . '/modules/suppliers/orders.php'],
        ['label' => 'Performance', 'url' => BASE_URL . '/modules/suppliers/performance.php'],
        ['label' => 'Returns', 'url' => BASE_URL . '/modules/suppliers/returns.php'],
        ['label' => 'Product Catalog', 'url' => BASE_URL . '/modules/suppliers/catalog.php'],
    ]],
    ['icon' => 'bi-cash-stack', 'label' => 'Finance', 'url' => '#', 'dir' => 'finance', 'children' => [
        ['label' => 'Overview', 'url' => BASE_URL . '/modules/finance/index.php'],
        ['label' => 'Cash Register', 'url' => BASE_URL . '/modules/finance/cash_register.php'],
        ['label' => 'Expenses', 'url' => BASE_URL . '/modules/finance/expenses.php'],
        ['label' => 'Profit & Loss', 'url' => BASE_URL . '/modules/finance/profit_loss.php'],
        ['label' => 'Taxes', 'url' => BASE_URL . '/modules/finance/taxes.php'],
        ['label' => 'Customer Credits', 'url' => BASE_URL . '/modules/finance/credits.php'],
        ['label' => 'Daily Summary', 'url' => BASE_URL . '/modules/finance/daily_summary.php'],
    ]],
    ['icon' => 'bi-building', 'label' => 'MoPH', 'url' => '#', 'dir' => 'moph', 'children' => [
        ['label' => 'Price List', 'url' => BASE_URL . '/modules/moph/price_list.php'],
        ['label' => 'Import Price List', 'url' => BASE_URL . '/modules/moph/import.php'],
        ['label' => 'Controlled Substances', 'url' => BASE_URL . '/modules/moph/controlled.php'],
        ['label' => 'Subsidy Tracking', 'url' => BASE_URL . '/modules/moph/subsidy.php'],
        ['label' => 'Compliance', 'url' => BASE_URL . '/modules/moph/compliance.php'],
    ]],
    ['icon' => 'bi-shield-plus', 'label' => 'Insurance', 'url' => '#', 'dir' => 'insurance', 'children' => [
        ['label' => 'Providers', 'url' => BASE_URL . '/modules/insurance/providers.php'],
        ['label' => 'Claims', 'url' => BASE_URL . '/modules/insurance/claims.php'],
        ['label' => 'Reconciliation', 'url' => BASE_URL . '/modules/insurance/reconciliation.php'],
    ]],
    ['icon' => 'bi-person-heart', 'label' => 'Patients', 'url' => '#', 'dir' => 'patients', 'children' => [
        ['label' => 'Patient Profiles', 'url' => BASE_URL . '/modules/patients/index.php'],
        ['label' => 'Customers', 'url' => BASE_URL . '/modules/pos/customers.php'],
        ['label' => 'Refill Reminders', 'url' => BASE_URL . '/modules/patients/reminders.php'],
        ['label' => 'Loyalty Program', 'url' => BASE_URL . '/modules/pos/loyalty.php'],
        ['label' => 'Medical History', 'url' => BASE_URL . '/modules/patients/medical_history.php'],
        ['label' => 'Vaccinations', 'url' => BASE_URL . '/modules/patients/vaccination.php'],
    ]],
    ['icon' => 'bi-shield-exclamation', 'label' => 'Drug Interactions', 'url' => BASE_URL . '/modules/interactions/index.php', 'dir' => 'interactions'],
    ['icon' => 'bi-file-earmark-bar-graph', 'label' => 'Reports', 'url' => '#', 'dir' => 'reports', 'children' => [
        ['label' => 'Daily Report', 'url' => BASE_URL . '/modules/reports/daily.php'],
        ['label' => 'Monthly Report', 'url' => BASE_URL . '/modules/reports/monthly.php'],
        ['label' => 'Inventory Valuation', 'url' => BASE_URL . '/modules/reports/inventory_valuation.php'],
        ['label' => 'ABC Analysis', 'url' => BASE_URL . '/modules/reports/abc_analysis.php'],
        ['label' => 'Expiry Forecast', 'url' => BASE_URL . '/modules/reports/expiry_forecast.php'],
        ['label' => 'Margin Analysis', 'url' => BASE_URL . '/modules/reports/margin_analysis.php'],
        ['label' => 'Sales Analytics', 'url' => BASE_URL . '/modules/reports/sales_analytics.php'],
        ['label' => 'Customer Analytics', 'url' => BASE_URL . '/modules/reports/customer_analytics.php'],
        ['label' => 'Supplier Analytics', 'url' => BASE_URL . '/modules/reports/supplier_analytics.php'],
        ['label' => 'Inventory Movement', 'url' => BASE_URL . '/modules/reports/inventory_movement.php'],
        ['label' => 'Waste Report', 'url' => BASE_URL . '/modules/reports/waste_report.php'],
        ['label' => 'Tax Report', 'url' => BASE_URL . '/modules/reports/tax_report.php'],
    ]],
    ['icon' => 'bi-bell', 'label' => 'Alerts', 'url' => '#', 'dir' => 'notifications', 'children' => [
        ['label' => 'Alert Dashboard', 'url' => BASE_URL . '/modules/notifications/index.php'],
        ['label' => 'Notification Center', 'url' => BASE_URL . '/modules/notifications/center.php'],
    ]],
    ['icon' => 'bi-gear', 'label' => 'Settings', 'url' => '#', 'dir' => 'settings', 'children' => [
        ['label' => 'General', 'url' => BASE_URL . '/modules/settings/index.php'],
        ['label' => 'User Activity', 'url' => BASE_URL . '/modules/settings/activity.php'],
        ['label' => 'Database Backup', 'url' => BASE_URL . '/modules/settings/backup.php'],
        ['label' => 'Shift Management', 'url' => BASE_URL . '/modules/settings/shifts.php'],
        ['label' => 'Pharmacy Profile', 'url' => BASE_URL . '/modules/settings/pharmacy_profile.php'],
    ]],
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
