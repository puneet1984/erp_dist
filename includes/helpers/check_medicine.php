<?php

define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

$name = $_POST['name'] ?? '';
$category = $_POST['category'] ?? 'MEDICINE';
error_log("Checking {$category}: {$name}");

$conn = getDbConnection();

// Modified query to match new schema and category check
$stmt = $conn->prepare("SELECT 
    item_id,
    name,
    category,
    active_ingredient,
    dosage_form,
    manufacturer,
    reorder_level
    FROM pharmacy_items 
    WHERE name = ? AND category = ?");

$stmt->bind_param("ss", $name, $category);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    error_log("Item found: " . json_encode($row));
    $response = [
        'exists' => true,
        'item_id' => $row['item_id'],
        'active_ingredient' => $row['active_ingredient'],
        'dosage_form' => $row['dosage_form'],
        'manufacturer' => $row['manufacturer'],
        'reorder_level' => $row['reorder_level']
    ];
    
    echo json_encode($response);
} else {
    error_log("Item not found: {$category} - {$name}");
    echo json_encode(['exists' => false]);
}
