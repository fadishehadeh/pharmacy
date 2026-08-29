<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
$db = getDB();

$id = intval($_GET['id'] ?? 0);
$sale = $db->prepare("SELECT s.*, c.name as customer_name FROM sales s LEFT JOIN customers c ON s.customer_id = c.id WHERE s.id = ?");
$sale->execute([$id]);
$sale = $sale->fetch();
if (!$sale) die('Sale not found');

$items = $db->prepare("SELECT si.*, m.name as medicine_name, m.strength FROM sale_items si JOIN medicines m ON si.medicine_id = m.id WHERE si.sale_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();

$pharmacyName = getSetting('pharmacy_name', 'My Pharmacy');
$pharmacyPhone = getSetting('pharmacy_phone', '');
$pharmacyAddress = getSetting('pharmacy_address', '');
$footer = getSetting('receipt_footer', 'Thank you!');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt - <?= sanitize($sale['invoice_number']) ?></title>
    <style>
        body { font-family: 'Courier New', monospace; font-size: 12px; max-width: 300px; margin: 0 auto; padding: 10px; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .divider { border-top: 1px dashed #000; margin: 5px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 2px 0; }
        .total-line { font-weight: bold; font-size: 14px; }
        @media print { .no-print { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="text-center">
        <strong style="font-size:16px"><?= sanitize($pharmacyName) ?></strong><br>
        <?php if ($pharmacyAddress): ?><?= sanitize($pharmacyAddress) ?><br><?php endif; ?>
        <?php if ($pharmacyPhone): ?>Tel: <?= sanitize($pharmacyPhone) ?><br><?php endif; ?>
    </div>

    <div class="divider"></div>

    <table>
        <tr><td>Invoice:</td><td class="text-right"><?= sanitize($sale['invoice_number']) ?></td></tr>
        <tr><td>Date:</td><td class="text-right"><?= formatDate($sale['sale_date'], 'Y-m-d H:i') ?></td></tr>
        <?php if ($sale['customer_name']): ?>
        <tr><td>Customer:</td><td class="text-right"><?= sanitize($sale['customer_name']) ?></td></tr>
        <?php endif; ?>
        <?php if ($sale['prescription_number']): ?>
        <tr><td>Rx #:</td><td class="text-right"><?= sanitize($sale['prescription_number']) ?></td></tr>
        <?php endif; ?>
    </table>

    <div class="divider"></div>

    <table>
        <tr><td><strong>Item</strong></td><td class="text-center"><strong>Qty</strong></td><td class="text-right"><strong>Price</strong></td></tr>
    </table>
    <div class="divider"></div>

    <?php foreach ($items as $item): ?>
    <table>
        <tr>
            <td colspan="3"><?= sanitize($item['medicine_name']) ?><?= $item['strength'] ? " ({$item['strength']})" : '' ?></td>
        </tr>
        <tr>
            <td>&nbsp;</td>
            <td class="text-center"><?= $item['quantity'] ?> x $<?= number_format($item['unit_price'], 2) ?></td>
            <td class="text-right">$<?= number_format($item['total_price'], 2) ?></td>
        </tr>
    </table>
    <?php endforeach; ?>

    <div class="divider"></div>

    <table>
        <tr><td>Subtotal:</td><td class="text-right">$<?= number_format($sale['subtotal'], 2) ?></td></tr>
        <?php if ($sale['discount_amount'] > 0): ?>
        <tr><td>Discount:</td><td class="text-right">-$<?= number_format($sale['discount_amount'], 2) ?></td></tr>
        <?php endif; ?>
        <tr class="total-line"><td>TOTAL:</td><td class="text-right">$<?= number_format($sale['total_amount'], 2) ?></td></tr>
        <tr><td>In LBP:</td><td class="text-right"><?= number_format($sale['total_amount'] * $sale['exchange_rate'], 0) ?> L.L.</td></tr>
        <tr><td>Payment:</td><td class="text-right"><?= ucfirst($sale['payment_method']) ?></td></tr>
    </table>

    <div class="divider"></div>

    <div class="text-center">
        <?= sanitize($footer) ?><br>
        <small><?= date('Y-m-d H:i:s') ?></small>
    </div>

    <div class="text-center no-print" style="margin-top:20px">
        <button onclick="window.print()" style="padding:8px 20px;font-size:14px;cursor:pointer">Print Receipt</button>
    </div>
</body>
</html>
