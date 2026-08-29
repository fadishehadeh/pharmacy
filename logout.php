<?php
session_start();
if (isset($_SESSION['login_log_id'])) {
    try {
        require_once __DIR__ . '/config/database.php';
        $db = getDB();
        $db->prepare("UPDATE login_log SET logout_time = NOW() WHERE id = ?")->execute([$_SESSION['login_log_id']]);
    } catch (Exception $e) {}
}
session_destroy();
header('Location: /pharmacy/login.php');
exit;
