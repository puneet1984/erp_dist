<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('bills', 'cancel')) {
    exit(json_encode(['status' => 'error', 'message' => 'Access Denied']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit(json_encode(['status' => 'error', 'message' => 'Invalid request method']));
}

$conn = getDbConnection();
$conn->begin_transaction();

try {
    $bill_id = $_POST['bill_id'];
    $cancel_type = $_POST['cancel_type'];
    $cancel_reason = $_POST['cancel_reason'];
    $cancel_notes = $_POST['cancel_notes'];
    $refund_ref = $_POST['refund_ref'] ?? null;

    // Update bill with cancellation details
    $status = $cancel_type; // Use the selected cancel type as status
    $stmt = $conn->prepare('UPDATE bills SET 
        status = ?,
        cancellation_reason = ?,
        cancellation_notes = ?,
        refund_reference = ?,
        cancelled_by = ?,
        cancelled_at = NOW()
        WHERE bill_id = ?');

    $stmt->bind_param('ssssss', 
        $status,
        $cancel_reason,
        $cancel_notes,
        $refund_ref,
        $_SESSION['user_id'],
        $bill_id
    );
    $stmt->execute();

    // Update payment ledger status based on cancellation type
    $payment_status = ($cancel_type === 'REFUND') ? 'REFUNDED' : 'CANCELLED';
    $stmt = $conn->prepare('UPDATE payment_ledger SET 
        payment_status = ?,
        transaction_date = NOW()
        WHERE bill_id = ?');
    $stmt->bind_param('ss', $payment_status, $bill_id);
    $stmt->execute();

    $conn->commit();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
