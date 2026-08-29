<?php
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function formatCurrency($amount, $currency = 'USD') {
    if ($currency === 'LBP') {
        return number_format($amount, 0, '.', ',') . ' L.L.';
    }
    return '$' . number_format($amount, 2);
}

function formatDate($date, $format = 'Y-m-d') {
    if (empty($date)) return '';
    return date($format, strtotime($date));
}

function generateInvoiceNumber() {
    $db = getDB();
    $stmt = $db->query("SELECT MAX(id) as max_id FROM sales");
    $row = $stmt->fetch();
    $next = ($row['max_id'] ?? 0) + 1;
    return 'INV-' . date('Ymd') . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

function generatePONumber() {
    $db = getDB();
    $stmt = $db->query("SELECT MAX(id) as max_id FROM purchase_orders");
    $row = $stmt->fetch();
    $next = ($row['max_id'] ?? 0) + 1;
    return 'PO-' . date('Ymd') . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

function generateClaimNumber() {
    $db = getDB();
    $stmt = $db->query("SELECT MAX(id) as max_id FROM insurance_claims");
    $row = $stmt->fetch();
    $next = ($row['max_id'] ?? 0) + 1;
    return 'CLM-' . date('Ymd') . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

function getExpiringMedicines($days = 90) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM medicines WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) AND expiry_date >= CURDATE() AND is_active = 1 ORDER BY expiry_date ASC");
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

function getExpiredMedicines() {
    $db = getDB();
    return $db->query("SELECT * FROM medicines WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND is_active = 1 ORDER BY expiry_date ASC")->fetchAll();
}

function getLowStockMedicines() {
    $db = getDB();
    return $db->query("SELECT * FROM medicines WHERE quantity_in_stock <= min_stock_level AND is_active = 1 ORDER BY quantity_in_stock ASC")->fetchAll();
}

function getOutOfStockMedicines() {
    $db = getDB();
    return $db->query("SELECT * FROM medicines WHERE quantity_in_stock = 0 AND is_active = 1 ORDER BY name ASC")->fetchAll();
}

function addStockMovement($medicineId, $type, $quantity, $notes = '', $refType = null, $refId = null) {
    $db = getDB();
    $userId = $_SESSION['user_id'] ?? null;
    $stmt = $db->prepare("INSERT INTO stock_movements (medicine_id, type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$medicineId, $type, $quantity, $refType, $refId, $notes, $userId]);
}

function updateStock($medicineId, $quantityChange) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE medicines SET quantity_in_stock = quantity_in_stock + ? WHERE id = ?");
    $stmt->execute([$quantityChange, $medicineId]);
}

function addAuditLog($action, $table, $recordId, $oldValues = null, $newValues = null) {
    try {
        $db = getDB();
        $userId = $_SESSION['user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $db->prepare("INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId, $action, $table, $recordId,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $ip
        ]);
    } catch (Exception $e) {
        // Silently fail audit logging
    }
}

function flashMessage($message, $type = 'success') {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function paginate($query, $params, $page, $perPage = 20) {
    $db = getDB();
    $countQuery = preg_replace('/SELECT .+? FROM/i', 'SELECT COUNT(*) as total FROM', $query, 1);
    $countQuery = preg_replace('/ORDER BY .+$/i', '', $countQuery);
    $countQuery = preg_replace('/LIMIT .+$/i', '', $countQuery);
    $stmt = $db->prepare($countQuery);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];

    $offset = ($page - 1) * $perPage;
    $query .= " LIMIT $perPage OFFSET $offset";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return [
        'data' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => ceil($total / $perPage),
    ];
}

function renderPagination($pagination, $baseUrl) {
    if ($pagination['total_pages'] <= 1) return '';
    $html = '<nav><ul class="pagination justify-content-center">';
    $sep = strpos($baseUrl, '?') !== false ? '&' : '?';
    for ($i = 1; $i <= $pagination['total_pages']; $i++) {
        $active = $i == $pagination['page'] ? ' active' : '';
        $html .= "<li class='page-item$active'><a class='page-link' href='{$baseUrl}{$sep}page=$i'>$i</a></li>";
    }
    $html .= '</ul></nav>';
    return $html;
}
