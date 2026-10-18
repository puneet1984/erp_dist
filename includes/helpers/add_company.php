<!-- used in add_patient.php -->

<?php
define('SECURE_ACCESS', true);
require_once '../core.php';

if ('POST' !== $_SERVER['REQUEST_METHOD']) {
    http_response_code(405);
    exit('Method not allowed');
}

try {
    $conn = getDbConnection();
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    $company_name = sanitizeInput($input['company_name']);
    $credit_limit = isset($input['credit_limit']) ? floatval($input['credit_limit']) : 50000;
    $payment_days = isset($input['payment_days']) ? intval($input['payment_days']) : 30;
    
    if (empty($company_name)) {
        throw new Exception('Company name is required');
    }
    
    $stmt = $conn->prepare("INSERT INTO company_accounts 
        (company_name, credit_limit, payment_days, status) 
        VALUES (?, ?, ?, 'ACTIVE')");
        
    $stmt->bind_param('sdi', $company_name, $credit_limit, $payment_days);
    
    if ($stmt->execute()) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Company added successfully',
            'id' => $stmt->insert_id,
            'company_name' => $company_name
        ]);
    } else {
        throw new Exception($stmt->error);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
