<?php
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) {
    logActivity('LOGOUT', 'User logout dari sistem');
}

session_unset();
session_destroy();

header('Location: ' . BASE_URL . 'login.php');
exit;
