<?php

define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

$term = $_GET['term'] ?? '';
$dept_id = $_GET['dept_id'] ?? '';

if (empty($term)) {
    echo json_encode([]);
    exit;
}

$conn = getDbConnection();
$stmt = $conn->prepare("
    SELECT 
        service_id as id, 
        name as label, 
        category,
        base_charge as price
    FROM services 
    WHERE name LIKE ? 
    ORDER BY name
");

$search = "%$term%";
$stmt->bind_param("s", $search);
$stmt->execute();
$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

header('Content-Type: application/json');
echo json_encode($result);
