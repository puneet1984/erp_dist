<?php
// AJAX endpoint to fix batch quantity discrepancies
// Recalculates and updates batch quantity based on actual bills
//used by correct_bill_batches.php

define('SECURE_ACCESS', true);
require_once '../core.php';

header('Content-Type: application/json');

if (!canEditBillBatchCorrections()) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Permission denied'
    ]);
    exit;
}

$batch_id = sanitizeInteger($_POST['batch_id'] ?? 0);
$expected_quantity = sanitizeInteger($_POST['expected_quantity'] ?? 0);

if (!$batch_id) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid batch ID'
    ]);
    exit;
}

try {
    $conn = getDbConnection();

    // Get batch details
    $stmt = $conn->prepare('SELECT batch_number, initial_quantity, current_quantity FROM pharmacy_batches WHERE batch_id = ?');
    $stmt->bind_param('i', $batch_id);
    $stmt->execute();
    $batch = $stmt->get_result()->fetch_assoc();

    if (!$batch) {
        throw new Exception('Batch not found');
    }

    // Calculate actual quantity sold from bills
    $stmt = $conn->prepare('
        SELECT COALESCE(SUM(pti.quantity), 0) as total_sold
        FROM pharmacy_transaction_items pti
        WHERE pti.batch_id = ?
          AND EXISTS (
              SELECT 1
              FROM pharmacy_sales ps
              WHERE ps.transaction_id = pti.transaction_id
                AND ps.payment_status != "CANCELLED"
          )
    ');
    $stmt->bind_param('i', $batch_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $total_sold = $result['total_sold'];

    // Calculate correct quantity
    $correct_quantity = $batch['initial_quantity'] - $total_sold;

    // Validate
    if ($correct_quantity < 0) {
        throw new Exception('Calculated quantity is negative. Data integrity issue detected.');
    }

    if ($correct_quantity == $batch['current_quantity']) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Quantity is already correct (no change needed)'
        ]);
        exit;
    }

    // Update the batch quantity
    $stmt = $conn->prepare('UPDATE pharmacy_batches SET current_quantity = ? WHERE batch_id = ?');
    $stmt->bind_param('ii', $correct_quantity, $batch_id);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update batch quantity');
    }

    echo json_encode([
        'status' => 'success',
        'message' => sprintf(
            'Batch %s quantity fixed: %d → %d (based on %d units sold)',
            $batch['batch_number'],
            $batch['current_quantity'],
            $correct_quantity,
            $total_sold
        )
    ]);

} catch (Exception $e) {
    error_log('Error in fix_batch_quantity.php: ' . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
