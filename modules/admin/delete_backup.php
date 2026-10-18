<?php

define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$response = ['status' => 'error', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $filename = $_POST['filename'] ?? '';
    $backup_dir = __DIR__ . '/../../database/backup/';
    $file_path = $backup_dir . basename($filename);

    if (file_exists($file_path) && is_file($file_path)) {
        if (unlink($file_path)) {
            $response['status'] = 'success';
        } else {
            $response['message'] = 'Failed to delete file';
        }
    } else {
        $response['message'] = 'File not found';
    }
}

header('Content-Type: application/json');
echo json_encode($response);
