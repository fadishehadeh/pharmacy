<?php
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Session-based auth check without redirect
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Login required']);
    exit;
}

$db = getDB();
$stats = [];
$exchangeRate = floatval(getSetting('exchange_rate', '89500'));
$alertDays = intval(getSetting('expiry_warning_days', '90'));

// Today's sales: count and total
try {
    $stmt = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed'");
    $row = $stmt->fetch();
    $stats['today_sales'] = [
        'count' => intval($row['count']),
        'total' => floatval($row['total'])
    ];
} catch (Exception $e) {
    $stats['today_sales'] = ['count' => 0, 'total' => 0];
}

// Today's revenue by currency
try {
    $stmt = $db->query("SELECT currency, COALESCE(SUM(total_amount), 0) as total FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed' GROUP BY currency");
    $byCurrency = [];
    while ($row = $stmt->fetch()) {
        $byCurrency[$row['currency']] = floatval($row['total']);
    }
    $usdTotal = ($byCurrency['USD'] ?? 0) + (($byCurrency['LBP'] ?? 0) / $exchangeRate);
    $stats['today_revenue'] = [
        'total_usd' => round($usdTotal, 2),
        'total_lbp' => round($usdTotal * $exchangeRate, 0),
        'breakdown' => $byCurrency,
        'exchange_rate' => $exchangeRate
    ];
} catch (Exception $e) {
    $stats['today_revenue'] = ['total_usd' => 0, 'total_lbp' => 0, 'breakdown' => [], 'exchange_rate' => $exchangeRate];
}

// Low stock count
try {
    $stats['low_stock_count'] = intval($db->query("SELECT COUNT(*) FROM medicines WHERE is_active = 1 AND quantity_in_stock <= min_stock_level")->fetchColumn());
} catch (Exception $e) {
    $stats['low_stock_count'] = 0;
}

// Expiring count (within alert days)
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM medicines WHERE is_active = 1 AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) AND quantity_in_stock > 0");
    $stmt->execute([$alertDays]);
    $stats['expiring_count'] = intval($stmt->fetchColumn());
} catch (Exception $e) {
    $stats['expiring_count'] = 0;
}

// Expired count (with stock > 0)
try {
    $stats['expired_count'] = intval($db->query("SELECT COUNT(*) FROM medicines WHERE is_active = 1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE() AND quantity_in_stock > 0")->fetchColumn());
} catch (Exception $e) {
    $stats['expired_count'] = 0;
}

// Pending purchase orders
try {
    $stats['pending_orders'] = intval($db->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('draft','ordered','partial')")->fetchColumn());
} catch (Exception $e) {
    $stats['pending_orders'] = 0;
}

// Pending deliveries
try {
    $stats['pending_deliveries'] = intval($db->query("SELECT COUNT(*) FROM deliveries WHERE status IN ('pending','confirmed','in_transit')")->fetchColumn());
} catch (Exception $e) {
    $stats['pending_deliveries'] = 0;
}

// Due refill reminders
try {
    $stats['due_reminders'] = intval($db->query("SELECT COUNT(*) FROM refill_reminders WHERE estimated_runout <= CURDATE() AND is_notified = 0")->fetchColumn());
} catch (Exception $e) {
    $stats['due_reminders'] = 0;
}

// Recent sales (last 5)
try {
    $stmt = $db->query("SELECT s.id, s.invoice_number, s.total_amount, s.currency, s.payment_method, s.sale_date,
        c.name as customer_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        WHERE s.status = 'completed'
        ORDER BY s.sale_date DESC LIMIT 5");
    $recent = [];
    while ($row = $stmt->fetch()) {
        $recent[] = [
            'id' => intval($row['id']),
            'invoice' => $row['invoice_number'],
            'total' => floatval($row['total_amount']),
            'currency' => $row['currency'],
            'payment_method' => $row['payment_method'],
            'customer' => $row['customer_name'] ?: 'Walk-in',
            'date' => $row['sale_date']
        ];
    }
    $stats['recent_sales'] = $recent;
} catch (Exception $e) {
    $stats['recent_sales'] = [];
}

// Top medicines today (by qty sold)
try {
    $stmt = $db->query("SELECT m.id, m.name, SUM(si.quantity) as qty_sold, SUM(si.total_price) as revenue
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN medicines m ON si.medicine_id = m.id
        WHERE DATE(s.sale_date) = CURDATE() AND s.status = 'completed'
        GROUP BY m.id, m.name
        ORDER BY qty_sold DESC LIMIT 5");
    $topMeds = [];
    while ($row = $stmt->fetch()) {
        $topMeds[] = [
            'id' => intval($row['id']),
            'name' => $row['name'],
            'qty_sold' => intval($row['qty_sold']),
            'revenue' => floatval($row['revenue'])
        ];
    }
    $stats['top_medicines_today'] = $topMeds;
} catch (Exception $e) {
    $stats['top_medicines_today'] = [];
}

// Cash position (current register status)
try {
    $reg = $db->query("SELECT id, opening_amount, opening_lbp, closing_amount, closing_lbp,
        expected_amount, difference_amount, status, opened_at, closed_at
        FROM cash_register ORDER BY opened_at DESC LIMIT 1")->fetch();
    if ($reg) {
        $cashPos = [
            'register_id' => intval($reg['id']),
            'status' => $reg['status'],
            'opened_at' => $reg['opened_at'],
            'opening_usd' => floatval($reg['opening_amount']),
            'opening_lbp' => floatval($reg['opening_lbp'])
        ];
        if ($reg['status'] === 'open') {
            // Calculate current cash from sales since opening
            $stmt = $db->prepare("SELECT
                COALESCE(SUM(CASE WHEN currency='USD' AND payment_method='cash' THEN total_amount ELSE 0 END), 0) as cash_usd,
                COALESCE(SUM(CASE WHEN currency='LBP' AND payment_method='cash' THEN total_amount ELSE 0 END), 0) as cash_lbp
                FROM sales WHERE created_at >= ? AND status = 'completed'");
            $stmt->execute([$reg['opened_at']]);
            $salesCash = $stmt->fetch();
            $cashPos['current_cash_usd'] = round(floatval($reg['opening_amount']) + floatval($salesCash['cash_usd']), 2);
            $cashPos['current_cash_lbp'] = round(floatval($reg['opening_lbp']) + floatval($salesCash['cash_lbp']), 0);
        } else {
            $cashPos['closed_at'] = $reg['closed_at'];
            $cashPos['closing_usd'] = floatval($reg['closing_amount']);
            $cashPos['difference_usd'] = floatval($reg['difference_amount']);
        }
        $stats['cash_position'] = $cashPos;
    } else {
        $stats['cash_position'] = ['status' => 'no_register', 'message' => 'No cash register sessions found'];
    }
} catch (Exception $e) {
    $stats['cash_position'] = ['status' => 'unavailable', 'message' => 'Cash register table not available'];
}

// Timestamp
$stats['generated_at'] = date('Y-m-d H:i:s');
$stats['timezone'] = date_default_timezone_get();

echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
