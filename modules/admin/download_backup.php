<?php

define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$filename = $_GET['file'] ?? '';
$backup_dir = __DIR__ . '/../../database/backup/';
$file_path = $backup_dir . basename($filename);

if (!file_exists($file_path) || !is_file($file_path)) {
    die('Backup file not found');
}

header('Content-Type: application/x-gzip');
header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
header('Content-Length: ' . filesize($file_path));
readfile($file_path);
