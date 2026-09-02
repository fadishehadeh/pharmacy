<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
if (!hasRole('pharmacist')) { flashMessage('Access denied. Pharmacist or Admin role required.', 'danger'); header('Location: ' . BASE_URL . '/index.php'); exit; }
$db = getDB();

$id = intval($_GET['id'] ?? 0);
$quote = $db->prepare("SELECT q.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address, u.full_name as user_name
    FROM quotations q LEFT JOIN customers c ON q.customer_id = c.id LEFT JOIN users u ON q.created_by = u.id WHERE q.id = ?");
$quote->execute([$id]);
$quote = $quote->fetch();
if (!$quote) { echo 'Quotation not found'; exit; }

$items = $db->prepare("SELECT qi.*, m.name as med_name, m.strength FROM quotation_items qi JOIN medicines m ON qi.medicine_id = m.id WHERE qi.quotation_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();

$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');
$pharmacyNameAr = getSetting('pharmacy_name_ar');
$pharmacyAddr = getSetting('pharmacy_address');
$pharmacyPhone = getSetting('pharmacy_phone');
$pharmacyLicense = getSetting('pharmacy_license');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Quotation <?= sanitize($quote['quote_number']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; font-size: 14px; }
        .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 20px; }
        .header h2 { margin: 0; }
        .header .ar { font-size: 16px; direction: rtl; }
        .info-grid { display: flex; justify-content: space-between; margin-bottom: 20px; }
        .info-block { }
        .info-block strong { display: block; margin-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 8px; border: 1px solid #ddd; text-align: left; }
        th { background: #f5f5f5; font-weight: bold; }
        .totals { text-align: right; }
        .totals td { border: none; }
        .totals .final { font-size: 16px; font-weight: bold; }
        .footer { margin-top: 30px; font-size: 12px; color: #666; text-align: center; }
        .status { display: inline-block; padding: 2px 10px; border-radius: 4px; font-weight: bold; text-transform: uppercase; }
        .status-active { background: #dbeafe; color: #1d4ed8; }
        .status-expired { background: #f3f4f6; color: #6b7280; }
        @media print { body { margin: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
<div class="no-print" style="margin-bottom:20px"><button onclick="window.print()" style="padding:8px 16px;cursor:pointer">Print</button> <button onclick="window.close()" style="padding:8px 16px;cursor:pointer">Close</button></div>

<div class="header">
    <h2><?= sanitize($pharmacyName) ?></h2>
    <?php if ($pharmacyNameAr): ?><div class="ar"><?= sanitize($pharmacyNameAr) ?></div><?php endif; ?>
    <?php if ($pharmacyAddr): ?><div><?= sanitize($pharmacyAddr) ?></div><?php endif; ?>
    <?php if ($pharmacyPhone): ?><div>Tel: <?= sanitize($pharmacyPhone) ?></div><?php endif; ?>
    <?php if ($pharmacyLicense): ?><div>License: <?= sanitize($pharmacyLicense) ?></div><?php endif; ?>
    <h3 style="margin-top:10px">QUOTATION <span class="status status-<?= $quote['status'] ?>"><?= ucfirst($quote['status']) ?></span></h3>
</div>

<div class="info-grid">
    <div class="info-block">
        <strong>Quote Details</strong>
        Quote #: <?= sanitize($quote['quote_number']) ?><br>
        Date: <?= formatDate($quote['created_at'], 'M d, Y') ?><br>
        Valid Until: <?= formatDate($quote['valid_until'], 'M d, Y') ?><br>
        Prepared by: <?= sanitize($quote['user_name'] ?? '-') ?>
    </div>
    <div class="info-block">
        <strong>Customer</strong>
        <?= sanitize($quote['customer_name'] ?? 'Walk-in Customer') ?><br>
        <?php if ($quote['customer_phone']): ?>Tel: <?= sanitize($quote['customer_phone']) ?><br><?php endif; ?>
        <?php if ($quote['customer_address']): ?><?= sanitize($quote['customer_address']) ?><?php endif; ?>
    </div>
</div>

<table>
    <thead><tr><th>#</th><th>Medicine</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
    <tbody>
        <?php foreach ($items as $i => $item): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= sanitize($item['med_name']) ?> <?= $item['strength'] ? '(' . sanitize($item['strength']) . ')' : '' ?></td>
            <td><?= $item['quantity'] ?></td>
            <td><?= formatCurrency($item['unit_price']) ?></td>
            <td><?= formatCurrency($item['total_price']) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<table class="totals">
    <tr><td>Subtotal:</td><td><?= formatCurrency($quote['subtotal']) ?></td></tr>
    <?php if ($quote['discount'] > 0): ?><tr><td>Discount:</td><td>-<?= formatCurrency($quote['discount']) ?></td></tr><?php endif; ?>
    <tr class="final"><td>Total:</td><td><?= formatCurrency($quote['total']) ?></td></tr>
    <tr><td>LBP Equivalent:</td><td><?= formatCurrency($quote['total'] * floatval(getSetting('exchange_rate', 89500)), 'LBP') ?></td></tr>
</table>

<?php if ($quote['notes']): ?><p><strong>Notes:</strong> <?= nl2br(sanitize($quote['notes'])) ?></p><?php endif; ?>

<div class="footer">
    <p>This quotation is valid until <?= formatDate($quote['valid_until'], 'F d, Y') ?>. Prices are subject to change after the validity period.</p>
    <p><?= sanitize($pharmacyName) ?> | <?= sanitize($pharmacyAddr) ?> | Tel: <?= sanitize($pharmacyPhone) ?></p>
</div>
</body>
</html>
