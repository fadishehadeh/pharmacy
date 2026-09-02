<?php
ob_start();
session_start();

/* Detect environment */
$_isLocalhost = isset($_SERVER['HTTP_HOST']) && (
    in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1']) ||
    strpos($_SERVER['HTTP_HOST'], 'localhost') !== false
);

/* Force HTTPS on production — handles LiteSpeed reverse proxy */
if (!$_isLocalhost) {
    $_isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
        || (isset($_SERVER['SERVER_PORT']) && intval($_SERVER['SERVER_PORT']) === 443);
    if (!$_isHttps) {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
        exit;
    }
}

define('APP_NAME', 'PharmaSys');
define('APP_VERSION', '1.0.0');
/* On localhost the app lives at /pharmacy; on production it is the domain root */
define('BASE_URL', $_isLocalhost ? '/pharmacy' : '');
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/');

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/functions.php';

date_default_timezone_set('Asia/Beirut');
initLang();

// Use a global so updateSetting() can invalidate the cache.
$GLOBALS['__settings_cache'] = null;

function getSetting($key, $default = '') {
    if ($GLOBALS['__settings_cache'] === null) {
        try {
            $db = getDB();
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
            $GLOBALS['__settings_cache'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            $GLOBALS['__settings_cache'] = [];
        }
    }
    return $GLOBALS['__settings_cache'][$key] ?? $default;
}

function updateSetting($key, $value) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$key, $value, $value]);
    // Keep cache in sync so callers within the same request see the new value.
    if (is_array($GLOBALS['__settings_cache'])) {
        $GLOBALS['__settings_cache'][$key] = $value;
    }
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    // Re-verify the user still exists and is active on each request.
    static $verified = false;
    if (!$verified) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
            $stmt->execute([$_SESSION['user_id']]);
            $fresh = $stmt->fetch();
            if (!$fresh) {
                session_destroy();
                header('Location: ' . BASE_URL . '/login.php');
                exit;
            }
            $_SESSION['user'] = $fresh;
        } catch (Exception $e) {}
        $verified = true;
    }
}

function currentUser() {
    return $_SESSION['user'] ?? null;
}

function hasRole($role) {
    $user = currentUser();
    if (!$user) return false;
    $roles = ['admin' => 4, 'pharmacist' => 3, 'cashier' => 2, 'viewer' => 1];
    return ($roles[$user['role']] ?? 0) >= ($roles[$role] ?? 0);
}

// ----- CSRF helpers -----

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify() {
    $token = $_SESSION['csrf_token'] ?? '';
    // Accept token from a hidden POST field or from the X-CSRF-Token request header (for AJAX).
    $provided = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!$token || !hash_equals($token, $provided)) {
        http_response_code(403);
        die('Invalid or missing CSRF token. Please go back and try again.');
    }
}
