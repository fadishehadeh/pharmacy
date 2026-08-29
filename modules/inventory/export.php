<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
$db = getDB();

$medicines = $db->query("SELECT m.*, c.name as category_name, s.shelf_number, cab.name as cabinet_name FROM medicines m LEFT JOIN categories c ON m.category_id = c.id LEFT JOIN shelves s ON m.shelf_id = s.id LEFT JOIN cabinets cab ON s.cabinet_id = cab.id WHERE m.is_active = 1 ORDER BY m.name")->fetchAll();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="pharmacy_inventory_' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Name', 'Generic Name', 'Barcode', 'Strength', 'Form', 'Category', 'Cabinet', 'Shelf', 'Manufacturer', 'Cost Price', 'Sell Price', 'Stock', 'Min Stock', 'Expiry Date', 'Batch', 'Prescription', 'Controlled', 'Subsidized']);

foreach ($medicines as $m) {
    fputcsv($out, [
        $m['name'], $m['generic_name'], $m['barcode'], $m['strength'], $m['form'],
        $m['category_name'], $m['cabinet_name'], $m['shelf_number'], $m['manufacturer'],
        $m['cost_price'], $m['sell_price'], $m['quantity_in_stock'], $m['min_stock_level'],
        $m['expiry_date'], $m['batch_number'],
        $m['requires_prescription'] ? 'Yes' : 'No',
        $m['is_controlled'] ? 'Yes' : 'No',
        $m['is_subsidized'] ? 'Yes' : 'No',
    ]);
}
fclose($out);
