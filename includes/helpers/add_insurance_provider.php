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

    $tpa_name = sanitizeInput($input['tpa_name']);
    $insurance_company = sanitizeInput($input['insurance_company']);

    // Validate inputs
    if (empty($tpa_name) || empty($insurance_company)) {
        throw new Exception('Both TPA name and Insurance company are required');
    }

    $stmt = $conn->prepare('INSERT INTO insurance_providers 
                           (TPA_name, insurance_comp_name) 
                           VALUES (?, ?)');

    $stmt->bind_param('ss', $tpa_name, $insurance_company);

    if ($stmt->execute()) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Insurance provider added successfully',
            'id' => $stmt->insert_id,
        ]);
    } else {
        throw new Exception($stmt->error);
    }

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
}
