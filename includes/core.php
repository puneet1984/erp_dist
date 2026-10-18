<?php

// Set timezone first
date_default_timezone_set('Asia/Kolkata');

if ('production' === getenv('APP_ENV')) {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL);
    ini_set('log_errors', 1);
} else {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'./../logs/php_errors.log');

require_once __DIR__.'/session_config.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/auth_helper.php';

// First run: send everything to the setup wizard until hospital details are saved
if (!defined('SETUP_PAGE') && !isHospitalConfigured()) {
    header('Location: '.BASE_PATH.'/modules/setup/index.php');
    exit;
}

date_default_timezone_set(getSetting('timezone', 'Asia/Kolkata') ?: 'Asia/Kolkata');


// Set the timezone for the database connection
$conn = getDbConnection();
$conn->query("SET time_zone = '+05:30'");

if (PHP_SESSION_NONE === session_status()) {
    session_start();
}

// Security checks
if (!defined('SECURE_ACCESS')) {
    redirectToLogin();
}

// Skip authentication checks for login page and other auth-related pages
if (!defined('SKIP_AUTH')) {
    // Prevent browser/proxy from caching authenticated pages.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

    // Check session and authentication
    if (!isset($_SESSION['user_id'])) {
        redirectToLogin();
    }

    // Check session timeout
    if (!checkSessionTimeout()) {
        redirectToLogin('timeout=1');
    }
}
