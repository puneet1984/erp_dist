<?php
define('SECURE_ACCESS', true);
require_once '../core.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['q'])) {
    $search = $_GET['q'];
    $conn = getDbConnection();
    
    // Get unique supplier names from pharmacy_purchases
    $stmt = $conn->prepare("SELECT DISTINCT supplier_name as id, supplier_name as text 
                           FROM pharmacy_purchases 
                           WHERE supplier_name LIKE ? 
                           ORDER BY supplier_name 
                           LIMIT 10");
    $search = "%$search%";
    $stmt->bind_param('s', $search);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $distributors = [];
    while ($row = $result->fetch_assoc()) {
        $distributors[] = $row;
    }
    
    echo json_encode(['results' => $distributors]);
}
?>