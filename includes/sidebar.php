<?php
// role helpers: 'a'=admin, 'p'=pharmacist, 'c'=cashier (no key = everyone logged in)
// items/children with 'role' key are filtered here; module files also enforce at server level
function sidebarCan($minRole) {
    if (!$minRole) return true;
    return hasRole($minRole);
}

$menuSections = [
    [
        'label' => tr('new_sale', 'Main'),
        'items' => [
            ['icon' => 'bi-cart3', 'color' => 'icon-blue', 'label' => tr('new_sale', 'New Sale'), 'url' => BASE_URL . '/modules/pos/index.php', 'dir' => 'pos_main'],
        ],
    ],
    [
        'label' => tr('inventory', 'Operations'),
        'items' => [
            ['icon' => 'bi-box-seam', 'color' => 'icon-green', 'label' => tr('inventory', 'Inventory'), 'url' => '#', 'dir' => 'inventory', 'children' => [
                ['label' => 'All Medicines',       'url' => BASE_URL . '/modules/inventory/index.php'],
                ['label' => 'Add Medicine',        'url' => BASE_URL . '/modules/inventory/add.php',              'role' => 'pharmacist'],
                ['label' => 'Batch Tracking',      'url' => BASE_URL . '/modules/inventory/batches.php',          'role' => 'pharmacist'],
                ['label' => 'Categories',          'url' => BASE_URL . '/modules/inventory/categories.php',       'role' => 'pharmacist'],
                ['label' => 'Shelves & Cabinets',  'url' => BASE_URL . '/modules/inventory/shelves.php',          'role' => 'pharmacist'],
                ['label' => 'Alternatives',        'url' => BASE_URL . '/modules/inventory/alternatives.php',     'role' => 'pharmacist'],
                ['label' => 'Expiry Alerts',       'url' => BASE_URL . '/modules/inventory/alerts.php',           'role' => 'pharmacist'],
                ['label' => 'Stock Movements',     'url' => BASE_URL . '/modules/inventory/movements.php',        'role' => 'pharmacist'],
                ['label' => 'Stock Count',         'url' => BASE_URL . '/modules/inventory/stock_count.php',      'role' => 'pharmacist'],
                ['label' => 'Stock Transfers',     'url' => BASE_URL . '/modules/inventory/transfer.php',         'role' => 'pharmacist'],
                ['label' => 'Waste & Disposal',    'url' => BASE_URL . '/modules/inventory/disposal.php',         'role' => 'pharmacist'],
                ['label' => 'Print Labels',        'url' => BASE_URL . '/modules/inventory/labels.php',           'role' => 'pharmacist'],
                ['label' => 'Smart Reorder',       'url' => BASE_URL . '/modules/inventory/reorder.php',          'role' => 'pharmacist'],
                ['label' => 'Reorder Levels',      'url' => BASE_URL . '/modules/inventory/reorder_levels.php',   'role' => 'pharmacist'],
                ['label' => 'Export Inventory',    'url' => BASE_URL . '/modules/inventory/export.php',           'role' => 'pharmacist'],
                ['label' => 'Import Medicines',    'url' => BASE_URL . '/modules/inventory/import.php',           'role' => 'pharmacist'],
                ['label' => 'Price History',       'url' => BASE_URL . '/modules/inventory/price_history.php',    'role' => 'pharmacist'],
                ['label' => 'Barcode Generator',   'url' => BASE_URL . '/modules/inventory/barcode_generator.php','role' => 'pharmacist'],
                ['label' => 'Stocktake',           'url' => BASE_URL . '/modules/inventory/stocktake.php',        'role' => 'pharmacist'],
                ['label' => 'Near-Expiry Deals',   'url' => BASE_URL . '/modules/inventory/near_expiry_deals.php','role' => 'pharmacist'],
                ['label' => 'Expiry Calendar',     'url' => BASE_URL . '/modules/inventory/expiry_calendar.php',  'role' => 'pharmacist'],
                ['label' => 'Medicine Photos',     'url' => BASE_URL . '/modules/inventory/medicine_photos.php',  'role' => 'pharmacist'],
            ]],
            ['icon' => 'bi-cart3', 'color' => 'icon-purple', 'label' => 'Point of Sale', 'url' => '#', 'dir' => 'pos', 'children' => [
                ['label' => 'New Sale',            'url' => BASE_URL . '/modules/pos/index.php'],
                ['label' => 'Receipt Templates',   'url' => BASE_URL . '/modules/pos/receipt_templates.php',      'role' => 'pharmacist'],
                ['label' => 'Process Return',      'url' => BASE_URL . '/modules/pos/returns.php'],
            ]],
            ['icon' => 'bi-file-medical', 'color' => 'icon-teal', 'label' => 'Prescriptions', 'url' => '#', 'dir' => 'prescriptions', 'children' => [
                ['label' => 'All Prescriptions',   'url' => BASE_URL . '/modules/prescriptions/index.php'],
                ['label' => 'Refills',             'url' => BASE_URL . '/modules/prescriptions/refills.php'],
            ]],
            ['icon' => 'bi-receipt', 'color' => 'icon-orange', 'label' => tr('sales', 'Sales'), 'url' => '#', 'dir' => 'sales', 'children' => [
                ['label' => 'Sales History',       'url' => BASE_URL . '/modules/sales/index.php'],
                ['label' => 'Returns',             'url' => BASE_URL . '/modules/sales/returns.php'],
                ['label' => 'Quotations',          'url' => BASE_URL . '/modules/sales/quotations.php',           'role' => 'pharmacist'],
                ['label' => 'Deliveries',          'url' => BASE_URL . '/modules/sales/deliveries.php',           'role' => 'pharmacist'],
                ['label' => 'Reports',             'url' => BASE_URL . '/modules/sales/reports.php',              'role' => 'pharmacist'],
            ]],
            ['icon' => 'bi-truck', 'color' => 'icon-amber', 'label' => 'Suppliers', 'url' => '#', 'dir' => 'suppliers', 'role' => 'pharmacist', 'children' => [
                ['label' => 'All Suppliers',       'url' => BASE_URL . '/modules/suppliers/index.php'],
                ['label' => 'Purchase Orders',     'url' => BASE_URL . '/modules/suppliers/orders.php'],
                ['label' => 'Performance',         'url' => BASE_URL . '/modules/suppliers/performance.php'],
                ['label' => 'Returns',             'url' => BASE_URL . '/modules/suppliers/returns.php'],
                ['label' => 'Product Catalog',     'url' => BASE_URL . '/modules/suppliers/catalog.php'],
            ]],
        ],
    ],
    [
        'label' => 'Finance & Compliance',
        'items' => [
            ['icon' => 'bi-cash-stack', 'color' => 'icon-emerald', 'label' => 'Finance', 'url' => '#', 'dir' => 'finance', 'children' => [
                ['label' => 'Overview',            'url' => BASE_URL . '/modules/finance/index.php',              'role' => 'pharmacist'],
                ['label' => 'Cash Register',       'url' => BASE_URL . '/modules/finance/cash_register.php'],
                ['label' => 'Expenses',            'url' => BASE_URL . '/modules/finance/expenses.php'],
                ['label' => 'Profit & Loss',       'url' => BASE_URL . '/modules/finance/profit_loss.php',        'role' => 'pharmacist'],
                ['label' => 'Taxes',               'url' => BASE_URL . '/modules/finance/taxes.php',              'role' => 'pharmacist'],
                ['label' => 'Customer Credits',    'url' => BASE_URL . '/modules/finance/credits.php'],
                ['label' => 'Daily Summary',       'url' => BASE_URL . '/modules/finance/daily_summary.php',      'role' => 'pharmacist'],
            ]],
            ['icon' => 'bi-building', 'color' => 'icon-indigo', 'label' => 'MoPH', 'url' => '#', 'dir' => 'moph', 'role' => 'pharmacist', 'children' => [
                ['label' => 'Price List',          'url' => BASE_URL . '/modules/moph/price_list.php'],
                ['label' => 'Import Price List',   'url' => BASE_URL . '/modules/moph/import.php'],
                ['label' => 'Controlled Substances','url'=> BASE_URL . '/modules/moph/controlled.php'],
                ['label' => 'Subsidy Tracking',    'url' => BASE_URL . '/modules/moph/subsidy.php'],
                ['label' => 'Compliance',          'url' => BASE_URL . '/modules/moph/compliance.php'],
            ]],
            ['icon' => 'bi-shield-plus', 'color' => 'icon-sky', 'label' => 'Insurance', 'url' => '#', 'dir' => 'insurance', 'role' => 'pharmacist', 'children' => [
                ['label' => 'Providers',           'url' => BASE_URL . '/modules/insurance/providers.php'],
                ['label' => 'Claims',              'url' => BASE_URL . '/modules/insurance/claims.php'],
                ['label' => 'Reconciliation',      'url' => BASE_URL . '/modules/insurance/reconciliation.php'],
            ]],
        ],
    ],
    [
        'label' => 'Clinical',
        'items' => [
            ['icon' => 'bi-person-heart', 'color' => 'icon-rose', 'label' => 'Patients', 'url' => '#', 'dir' => 'patients', 'children' => [
                ['label' => 'Patient Profiles',    'url' => BASE_URL . '/modules/patients/index.php'],
                ['label' => 'Customers',           'url' => BASE_URL . '/modules/pos/customers.php'],
                ['label' => 'Refill Reminders',    'url' => BASE_URL . '/modules/patients/reminders.php'],
                ['label' => 'Loyalty Program',     'url' => BASE_URL . '/modules/pos/loyalty.php'],
                ['label' => 'Medical History',     'url' => BASE_URL . '/modules/patients/medical_history.php'],
                ['label' => 'Vaccinations',        'url' => BASE_URL . '/modules/patients/vaccination.php',       'role' => 'pharmacist'],
            ]],
            ['icon' => 'bi-shield-exclamation', 'color' => 'icon-red', 'label' => 'Drug Interactions', 'url' => BASE_URL . '/modules/interactions/index.php', 'dir' => 'interactions', 'role' => 'pharmacist'],
        ],
    ],
    [
        'label' => 'Analytics',
        'items' => [
            ['icon' => 'bi-bar-chart-line', 'color' => 'icon-violet', 'label' => 'Reports', 'url' => '#', 'dir' => 'reports', 'role' => 'pharmacist', 'children' => [
                ['label' => 'Analytics Overview',  'url' => BASE_URL . '/modules/reports/dashboard.php'],
                ['label' => 'Daily Report',        'url' => BASE_URL . '/modules/reports/daily.php'],
                ['label' => 'Monthly Report',      'url' => BASE_URL . '/modules/reports/monthly.php'],
                ['label' => 'Inventory Valuation', 'url' => BASE_URL . '/modules/reports/inventory_valuation.php'],
                ['label' => 'ABC Analysis',        'url' => BASE_URL . '/modules/reports/abc_analysis.php'],
                ['label' => 'Expiry Forecast',     'url' => BASE_URL . '/modules/reports/expiry_forecast.php'],
                ['label' => 'Margin Analysis',     'url' => BASE_URL . '/modules/reports/margin_analysis.php'],
                ['label' => 'Sales Analytics',     'url' => BASE_URL . '/modules/reports/sales_analytics.php'],
                ['label' => 'Customer Analytics',  'url' => BASE_URL . '/modules/reports/customer_analytics.php'],
                ['label' => 'Supplier Analytics',  'url' => BASE_URL . '/modules/reports/supplier_analytics.php'],
                ['label' => 'Inventory Movement',  'url' => BASE_URL . '/modules/reports/inventory_movement.php'],
                ['label' => 'Waste Report',        'url' => BASE_URL . '/modules/reports/waste_report.php'],
                ['label' => 'Tax Report',          'url' => BASE_URL . '/modules/reports/tax_report.php'],
                ['label' => 'Period Comparison',   'url' => BASE_URL . '/modules/reports/comparison.php'],
                ['label' => 'Print Summary',       'url' => BASE_URL . '/modules/reports/dashboard_print.php'],
            ]],
            ['icon' => 'bi-bell', 'color' => 'icon-orange', 'label' => 'Alerts', 'url' => '#', 'dir' => 'notifications', 'role' => 'pharmacist', 'children' => [
                ['label' => 'Alert Dashboard',     'url' => BASE_URL . '/modules/notifications/index.php'],
                ['label' => 'Notification Center', 'url' => BASE_URL . '/modules/notifications/center.php'],
            ]],
        ],
    ],
    [
        'label' => 'System',
        'items' => [
            ['icon' => 'bi-gear', 'color' => 'icon-slate', 'label' => tr('settings', 'Settings'), 'url' => '#', 'dir' => 'settings', 'role' => 'admin', 'children' => [
                ['label' => 'General',             'url' => BASE_URL . '/modules/settings/index.php'],
                ['label' => 'User Activity',       'url' => BASE_URL . '/modules/settings/activity.php'],
                ['label' => 'Database Backup',     'url' => BASE_URL . '/modules/settings/backup.php'],
                ['label' => 'Shift Management',    'url' => BASE_URL . '/modules/settings/shifts.php'],
                ['label' => 'Pharmacy Profile',    'url' => BASE_URL . '/modules/settings/pharmacy_profile.php'],
                ['label' => 'Data Cleanup',        'url' => BASE_URL . '/modules/settings/data_cleanup.php'],
            ]],
        ],
    ],
];
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a class="sidebar-brand" href="<?= BASE_URL ?>/index.php">
            <div class="sidebar-brand-icon"><i class="bi bi-capsule"></i></div>
            <div>
                <div class="sidebar-brand-name"><?= APP_NAME ?></div>
                <div class="sidebar-pharmacy-name"><?= sanitize(getSetting('pharmacy_name', 'My Pharmacy')) ?></div>
            </div>
        </a>
    </div>

    <nav class="sidebar-nav" id="sidebarAccordion">
        <?php foreach ($menuSections as $section): ?>
            <?php
            // Filter items by role before rendering the section
            $visibleItems = array_filter($section['items'], function($item) {
                return sidebarCan($item['role'] ?? null);
            });
            if (empty($visibleItems)) continue;
            ?>
            <div class="sidebar-section-label"><?= $section['label'] ?></div>
            <?php foreach ($visibleItems as $item):
                $isActive = ($currentDir === $item['dir'])
                    || ($item['dir'] === '' && $currentPage === 'index' && $currentDir === basename(BASE_URL));
                $hasChildren = isset($item['children']);
                $visibleChildren = $hasChildren
                    ? array_filter($item['children'], function($c) { return sidebarCan($c['role'] ?? null); })
                    : [];
            ?>
                <?php if ($hasChildren): ?>
                <div class="nav-item">
                    <a class="nav-link <?= $isActive ? 'active' : '' ?>"
                       data-bs-toggle="collapse"
                       href="#menu-<?= $item['dir'] ?>"
                       role="button"
                       aria-expanded="<?= $isActive ? 'true' : 'false' ?>">
                        <span class="nav-icon <?= $item['color'] ?>"><i class="bi <?= $item['icon'] ?>"></i></span>
                        <span class="flex-grow-1"><?= $item['label'] ?></span>
                        <i class="bi bi-chevron-down chevron"></i>
                    </a>
                    <div class="collapse <?= $isActive ? 'show' : '' ?>"
                         id="menu-<?= $item['dir'] ?>"
                         data-bs-parent="#sidebarAccordion">
                        <div class="sidebar-children">
                            <?php foreach ($visibleChildren as $child): ?>
                            <a class="nav-link" href="<?= $child['url'] ?>"><?= $child['label'] ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <a class="nav-link <?= $isActive ? 'active' : '' ?>" href="<?= $item['url'] ?>">
                    <span class="nav-icon <?= $item['color'] ?>"><i class="bi <?= $item['icon'] ?>"></i></span>
                    <span class="flex-grow-1"><?= $item['label'] ?></span>
                </a>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
</div>
