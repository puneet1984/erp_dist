<?php
define('SECURE_ACCESS', true);
require_once '../core.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['q'])) {
    $search = $_GET['q'];
    $conn = getDbConnection();
    
    // Get suppliers with proper id/text mapping
    $stmt = $conn->prepare("SELECT supplier_id as id, supplier_name as text 
                           FROM suppliers 
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

// Handle POST request for adding new supplier
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['supplier_name']) && !empty($data['supplier_name'])) {
        $conn = getDbConnection();
        
        // Check if supplier already exists
        $stmt = $conn->prepare("SELECT supplier_id FROM suppliers WHERE supplier_name = ?");
        $stmt->bind_param('s', $data['supplier_name']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            // Insert new supplier with just name (city is optional)
            $stmt = $conn->prepare("INSERT INTO suppliers (supplier_name) VALUES (?)");
            $stmt->bind_param('s', $data['supplier_name']);
            
            if ($stmt->execute()) {
                $supplier_id = $conn->insert_id;
                echo json_encode([
                    'success' => true,
                    'id' => $supplier_id,
                    'message' => 'Supplier added successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to add supplier'
                ]);
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Supplier already exists'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Supplier name is required'
        ]);
    }
}
?>