<?php

session_start();
require_once __DIR__ . '/../config/config.php';
require_once 'auth_helper.php';

// Check if accessing directly
if (!defined('SECURE_ACCESS')) {
    redirectToLogin();
}

// Check session and authentication
if (!isset($_SESSION['user_id'])) {
    redirectToLogin();
}

// Check session timeout
if (!checkSessionTimeout()) {
    redirectToLogin('timeout=1');
}
