<?php

define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

$category = $_GET['category'] ?? 'OTHER';
$tax_info = getTaxRate($category);

header('Content-Type: application/json');
echo json_encode($tax_info);
