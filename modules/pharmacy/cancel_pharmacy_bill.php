<?php

define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('bills', 'cancel')) {
    exit('Access Denied');
}

if (!isset($_GET['id'])) {
    exit('Transaction ID not provided');
}

$transaction_id = sanitizeInput($_GET['id']);
$conn = getDbConnection();

// Start transaction
$conn->begin_transaction();

try {
    // Check if bill exists and is paid
    $stmt = $conn->prepare('SELECT payment_status FROM pharmacy_sales WHERE transaction_id = ?');
    $stmt->bind_param('s', $transaction_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $sale = $result->fetch_assoc();

    if (!$sale) {
        throw new Exception('Bill not found');
    }

    if ('PAID' !== $sale['payment_status']) {
        throw new Exception('Only paid bills can be cancelled');
    }

    // Update sale status to CANCELLED
    $stmt = $conn->prepare("
        UPDATE pharmacy_sales 
        SET payment_status = 'CANCELLED'
        WHERE transaction_id = ?
    ");
    $stmt->bind_param('s', $transaction_id);
    $stmt->execute();

    // Restore stock quantities
    $stmt = $conn->prepare('
        UPDATE pharmacy_batches pb
        JOIN pharmacy_transaction_items pti ON pb.batch_id = pti.batch_id
        SET pb.current_quantity = pb.current_quantity + pti.quantity
        WHERE pti.transaction_id = ?
    ');
    $stmt->bind_param('s', $transaction_id);
    $stmt->execute();

    $conn->commit();
    header('Location: ../billing/bills_list.php?type=pharmacy&msg=Bill cancelled successfully');

} catch (Exception $e) {
    $conn->rollback();
    exit('Error: '.$e->getMessage());
}
