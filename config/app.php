<?php
ob_start();
session_start();

define('APP_NAME', 'PharmaSys');
define('APP_VERSION', '1.0.0');
define('BASE_URL', '/pharmacy');
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/');

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/functions.php';

date_default_timezone_set('Asia/Beirut');

function getSetting($key, $default = '') {
    static $settings = null;
    if ($settings === null) {
        try {
            $db = getDB();
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            $settings = [];
        }
    }
    return $settings[$key] ?? $default;
}

function updateSetting($key, $value) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$key, $value, $value]);
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
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
