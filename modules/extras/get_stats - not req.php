<?php

define('SECURE_ACCESS', true);
require_once '../core.php';

// Check if user has permission
if (!checkPermission('dashboard', 'view')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

$conn = getDbConnection();
$stats = getAllDashboardStats($conn);

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'today_patients' => $stats['today_patients'],
    'today_revenue' => $stats['today_revenue'],
    'pending_bills' => $stats['pending_bills']
]);
