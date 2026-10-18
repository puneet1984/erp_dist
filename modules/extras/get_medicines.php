<?php

// required in generate_bill.php

define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('inventory', 'view')) {
    // For API endpoints, still use JSON response
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

require_once '../../config/config.php';
require_once '../../includes/helpers.php';

$term = $_GET['term'] ?? '';

$conn = getDbConnection();
$stmt = $conn->prepare("
    SELECT i.item_id, i.name, i.generic_name,
           b.batch_id, b.batch_number, b.quantity as batch_quantity,
           b.unit_price, b.expiry_date
    FROM pharmacy_items i
    JOIN pharmacy_batches b ON i.item_id = b.item_id
    WHERE (i.name LIKE ? OR i.generic_name LIKE ?)
    AND b.quantity > 0
    ORDER BY i.name, b.expiry_date ASC
    LIMIT 15");

$term = "%$term%";
$stmt->bind_param("ss", $term, $term);
$stmt->execute();
$result = $stmt->get_result();

$medicines = [];
while ($row = $result->fetch_assoc()) {
    $expiry = new DateTime($row['expiry_date']);
    $today = new DateTime();
    $days_to_expiry = $expiry->diff($today)->days;

    $medicines[] = [
        'id' => $row['item_id'],
        'batch_id' => $row['batch_id'],
        'label' => sprintf(
            "%s (Batch: %s, Exp: %s, Stock: %d)",
            $row['name'],
            $row['batch_number'],
            formatDisplayDate($row['expiry_date']),
            $row['batch_quantity']
        ),
        'value' => $row['name'],
        'price' => $row['unit_price'],
        'stock' => $row['batch_quantity'],
        'batch_number' => $row['batch_number'],
        'expiry_date' => formatDisplayDate($row['expiry_date']),
        'expiry_date_raw' => $row['expiry_date'],
        'days_to_expiry' => $days_to_expiry
    ];
}

header('Content-Type: application/json');
echo json_encode($medicines);
