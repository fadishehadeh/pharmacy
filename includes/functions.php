<?php

// ── Language / i18n ──────────────────────────────────────────────────────────
function initLang() {
    if (!session_id()) session_start();
    if (isset($_GET['setlang']) && in_array($_GET['setlang'], ['en', 'ar'])) {
        $_SESSION['lang'] = $_GET['setlang'];
    }
    $lang = $_SESSION['lang'] ?? 'en';
    $file = __DIR__ . '/../lang/' . $lang . '.php';
    $GLOBALS['_lang']     = file_exists($file) ? require $file : [];
    $GLOBALS['_lang_code'] = $lang;
    $GLOBALS['_is_rtl']   = ($lang === 'ar');
}

function t(string $key, string $fallback = ''): string {
    $s = $GLOBALS['_lang'][$key] ?? ($fallback ?: $key);
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function tr(string $key, string $fallback = ''): string {
    return $GLOBALS['_lang'][$key] ?? ($fallback ?: $key);
}

function isRtl(): bool { return $GLOBALS['_is_rtl'] ?? false; }
function langCode(): string { return $GLOBALS['_lang_code'] ?? 'en'; }
// ────────────────────────────────────────────────────────────────────────────

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

function _nextSeq($seqKey) {
    // Atomically increment a named counter stored in the settings table.
    // Uses MySQL's LAST_INSERT_ID(expr) trick — safe under concurrent requests.
    $db = getDB();
    $db->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, 1)
         ON DUPLICATE KEY UPDATE setting_value = LAST_INSERT_ID(setting_value + 1)"
    )->execute([$seqKey]);
    $seq = (int)$db->query("SELECT LAST_INSERT_ID()")->fetchColumn();
    return $seq ?: 1;
}

function generateInvoiceNumber() {
    return 'INV-' . date('Ymd') . '-' . str_pad(_nextSeq('_seq_invoice'), 4, '0', STR_PAD_LEFT);
}

function generatePONumber() {
    return 'PO-' . date('Ymd') . '-' . str_pad(_nextSeq('_seq_po'), 4, '0', STR_PAD_LEFT);
}

function generateClaimNumber() {
    return 'CLM-' . date('Ymd') . '-' . str_pad(_nextSeq('_seq_claim'), 4, '0', STR_PAD_LEFT);
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
    // Wrap in a subquery so the count survives GROUP BY, subqueries in SELECT, CTEs, etc.
    $stripped = preg_replace('/\s+ORDER\s+BY\s+.+$/is', '', $query);
    $countQuery = "SELECT COUNT(*) as total FROM ($stripped) AS _pag_sub";
    $stmt = $db->prepare($countQuery);
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare($query . " LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return [
        'data' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => (int)ceil($total / $perPage),
    ];
}

function renderPagination($pagination, $baseUrl) {
    if ($pagination['total_pages'] <= 1) return '';
    $safeUrl = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
    $sep = strpos($baseUrl, '?') !== false ? '&amp;' : '?';
    $current = (int)$pagination['page'];
    $total   = (int)$pagination['total_pages'];

    // Build the sparse set of page numbers to show: first, last, current±2.
    $show = [];
    for ($i = 1; $i <= $total; $i++) {
        if ($i === 1 || $i === $total || abs($i - $current) <= 2) {
            $show[$i] = true;
        }
    }

    $html = '<nav><ul class="pagination justify-content-center">';
    $prev = 0;
    foreach ($show as $i => $_) {
        if ($prev && $i - $prev > 1) {
            $html .= "<li class='page-item disabled'><span class='page-link'>&hellip;</span></li>";
        }
        $active = $i === $current ? ' active' : '';
        $html .= "<li class='page-item$active'><a class='page-link' href='{$safeUrl}{$sep}page=$i'>$i</a></li>";
        $prev = $i;
    }
    $html .= '</ul></nav>';
    return $html;
}
