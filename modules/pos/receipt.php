<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
$db = getDB();

$id = intval($_GET['id'] ?? 0);
$sale = $db->prepare("SELECT s.*, c.name as customer_name, u.full_name as cashier_name
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN users u ON s.created_by = u.id
    WHERE s.id = ?");
$sale->execute([$id]);
$sale = $sale->fetch();
if (!$sale) die('Sale not found');

$items = $db->prepare("SELECT si.*, m.name as medicine_name, m.strength FROM sale_items si JOIN medicines m ON si.medicine_id = m.id WHERE si.sale_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();

$pharmacyName    = getSetting('pharmacy_name',    'My Pharmacy');
$pharmacyPhone   = getSetting('pharmacy_phone',   '');
$pharmacyAddress = getSetting('pharmacy_address', '');
$pharmacyLicense = getSetting('pharmacy_license', '');
$receiptFooter   = getSetting('receipt_footer',   '');
$autoPrint       = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';

// Build WhatsApp message text (plain, compact)
$waLines = [];
$waLines[] = $pharmacyName;
$waLines[] = 'Invoice: ' . $sale['invoice_number'];
$waLines[] = 'Date: ' . date('Y-m-d H:i', strtotime($sale['sale_date'] ?? 'now'));
$waLines[] = str_repeat('-', 28);
foreach ($items as $item) {
    $label = $item['medicine_name'] . ($item['strength'] ? ' ' . $item['strength'] : '');
    $waLines[] = $label;
    $waLines[] = '  ' . $item['quantity'] . ' x $' . number_format($item['unit_price'], 2) . ' = $' . number_format($item['total_price'], 2);
}
$waLines[] = str_repeat('-', 28);
if ($sale['discount_amount'] > 0) {
    $waLines[] = 'Discount: -$' . number_format($sale['discount_amount'], 2);
}
$waLines[] = 'TOTAL: $' . number_format($sale['total_amount'], 2);
$waLines[] = 'LBP: ' . number_format($sale['total_amount'] * $sale['exchange_rate'], 0) . ' L.L.';
$waLines[] = 'Payment: ' . ucfirst($sale['payment_method']);
if ($receiptFooter) $waLines[] = $receiptFooter;
$waText = implode("\n", $waLines);
$waUrl  = 'https://wa.me/?text=' . rawurlencode($waText);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt - <?= sanitize($sale['invoice_number']) ?></title>
    <style>
        /* ── Base ── */
        * { box-sizing: border-box; }

        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            background: #f0f0f0;
            margin: 0;
            padding: 16px;
            color: #000;
        }

        /* ── Receipt wrapper ── */
        #receipt {
            max-width: 298px;   /* 80 mm ≈ 302 px at 96 dpi; keep ≤ 298 for safe margins */
            width: 100%;
            margin: 0 auto;
            background: #fff;
            padding: 10px 8px;
            color: #000;
        }

        /* ── Typography helpers ── */
        .tc  { text-align: center; }
        .tr  { text-align: right; }
        .tl  { text-align: left; }
        .b   { font-weight: bold; }
        .sm  { font-size: 10px; }
        .lg  { font-size: 14px; }

        /* ── Divider ── */
        .div {
            border: none;
            border-top: 1px dashed #555;
            margin: 5px 0;
        }
        .div-solid {
            border: none;
            border-top: 1px solid #000;
            margin: 5px 0;
        }

        /* ── Info table ── */
        .info-table { width: 100%; border-collapse: collapse; }
        .info-table td { padding: 1px 0; vertical-align: top; }
        .info-table .val { text-align: right; }

        /* ── Items table ── */
        .items-table { width: 100%; border-collapse: collapse; }
        .items-table td { padding: 1px 0; vertical-align: top; }
        .items-table .qty-col { text-align: right; white-space: nowrap; width: 55%; }

        /* ── Totals ── */
        .totals-table { width: 100%; border-collapse: collapse; }
        .totals-table td { padding: 2px 0; vertical-align: top; }
        .totals-table .val { text-align: right; }
        .total-row td { font-weight: bold; font-size: 13px; }
        .llp-row  td { font-size: 10px; color: #333; }

        /* ── Action buttons (screen only) ── */
        .actions {
            max-width: 298px;
            margin: 14px auto 0;
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        .btn-print {
            padding: 7px 20px;
            font-size: 13px;
            cursor: pointer;
            background: #fff;
            border: 1px solid #333;
            border-radius: 4px;
        }
        .btn-wa {
            padding: 7px 20px;
            font-size: 13px;
            cursor: pointer;
            background: #25D366;
            color: #fff;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* ── Print styles ── */
        @media print {
            body {
                background: #fff;
                padding: 0;
                margin: 0;
            }
            #receipt {
                max-width: 100%;
                padding: 0;
            }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div id="receipt">

    <!-- Header -->
    <div class="tc">
        <span class="b lg"><?= sanitize($pharmacyName) ?></span><br>
        <?php if ($pharmacyAddress): ?>
        <span class="sm"><?= nl2br(sanitize($pharmacyAddress)) ?></span><br>
        <?php endif; ?>
        <?php if ($pharmacyPhone): ?>
        <span class="sm">Tel: <?= sanitize($pharmacyPhone) ?></span><br>
        <?php endif; ?>
        <?php if ($pharmacyLicense): ?>
        <span class="sm">License: <?= sanitize($pharmacyLicense) ?></span>
        <?php endif; ?>
    </div>

    <hr class="div-solid">

    <!-- Sale info -->
    <table class="info-table">
        <tr><td>Invoice:</td><td class="val"><?= sanitize($sale['invoice_number']) ?></td></tr>
        <tr><td>Date:</td><td class="val"><?= date('Y-m-d H:i', strtotime($sale['sale_date'] ?? 'now')) ?></td></tr>
        <?php if (!empty($sale['cashier_name'])): ?>
        <tr><td>Cashier:</td><td class="val"><?= sanitize($sale['cashier_name']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($sale['customer_name'])): ?>
        <tr><td>Customer:</td><td class="val"><?= sanitize($sale['customer_name']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($sale['prescription_number'])): ?>
        <tr><td>Rx #:</td><td class="val"><?= sanitize($sale['prescription_number']) ?></td></tr>
        <?php endif; ?>
    </table>

    <hr class="div">

    <!-- Column headings -->
    <table class="items-table">
        <tr>
            <td class="b">Item / Strength</td>
            <td class="qty-col b">Qty x Price&nbsp;&nbsp;&nbsp;Total</td>
        </tr>
    </table>

    <hr class="div">

    <!-- Line items -->
    <?php foreach ($items as $item): ?>
    <table class="items-table">
        <tr>
            <td colspan="2"><?= sanitize($item['medicine_name']) ?><?= $item['strength'] ? ' ' . sanitize($item['strength']) : '' ?></td>
        </tr>
        <tr>
            <td></td>
            <td class="qty-col"><?= (int)$item['quantity'] ?> x $<?= number_format($item['unit_price'], 2) ?>&nbsp;&nbsp;$<?= number_format($item['total_price'], 2) ?></td>
        </tr>
    </table>
    <?php endforeach; ?>

    <hr class="div">

    <!-- Totals -->
    <table class="totals-table">
        <tr>
            <td>Subtotal:</td>
            <td class="val">$<?= number_format($sale['subtotal'], 2) ?></td>
        </tr>
        <?php if ($sale['discount_amount'] > 0): ?>
        <tr>
            <td>Discount:</td>
            <td class="val">-$<?= number_format($sale['discount_amount'], 2) ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <hr class="div-solid">

    <table class="totals-table">
        <tr class="total-row">
            <td>TOTAL:</td>
            <td class="val">$<?= number_format($sale['total_amount'], 2) ?></td>
        </tr>
        <tr class="llp-row">
            <td>Equiv. L.L.:</td>
            <td class="val"><?= number_format($sale['total_amount'] * $sale['exchange_rate'], 0) ?> L.L.</td>
        </tr>
        <tr>
            <td>Payment:</td>
            <td class="val"><?= ucfirst(sanitize($sale['payment_method'])) ?></td>
        </tr>
        <?php if (!empty($sale['amount_paid']) && $sale['amount_paid'] != $sale['total_amount']): ?>
        <tr>
            <td>Paid:</td>
            <td class="val">$<?= number_format($sale['amount_paid'], 2) ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <hr class="div">

    <!-- Footer -->
    <div class="tc sm">
        <?php if ($receiptFooter): ?>
        <?= nl2br(sanitize($receiptFooter)) ?><br>
        <?php endif; ?>
        <br>
        Thank you &ndash; &#x634;&#x643;&#x631;&#x627;&#x64B;
    </div>

</div><!-- #receipt -->

<!-- Action buttons (hidden on print) -->
<div class="actions no-print">
    <button class="btn-print" onclick="window.print()">&#128438; Print</button>
    <a class="btn-wa" href="<?= htmlspecialchars($waUrl) ?>" target="_blank" rel="noopener">
        <!-- WhatsApp SVG icon (inline) -->
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
            <path d="M13.601 2.326A7.85 7.85 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.9 7.9 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.9 7.9 0 0 0 13.6 2.326zM7.994 14.521a6.6 6.6 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.56 6.56 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592m3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.065-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.73.73 0 0 0-.529.247c-.182.198-.691.677-.691 1.654s.71 1.916.81 2.049c.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.904.129 1.246.08.38-.058 1.171-.48 1.338-.943.164-.464.164-.86.114-.943-.049-.084-.182-.133-.38-.232"/>
        </svg>
        Send via WhatsApp
    </a>
</div>

<?php if ($autoPrint): ?>
<script>window.addEventListener('load', function() { setTimeout(function() { window.print(); }, 400); });</script>
<?php endif; ?>

</body>
</html>
