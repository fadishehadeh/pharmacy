<?php
session_start();
session_destroy();
header('Location: ' . '/pharmacy/login.php');
exit;
