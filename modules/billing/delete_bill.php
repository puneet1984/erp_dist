<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('bills', 'delete')) {
    exit('Access Denied');
}

$billId = sanitizeInput($_GET['id'] ?? '');
if ('' === $billId) {
    header('Location: bills_list.php?error='.urlencode('Bill ID not provided'));
    exit;
}

$conn = getDbConnection();
$conn->begin_transaction();

try {
    // Lock bill and ledger row so status check and delete stay consistent.
    $stmt = $conn->prepare('SELECT b.bill_id, COALESCE(pl.payment_status, "PENDING") AS payment_status
                            FROM bills b
                            LEFT JOIN payment_ledger pl ON b.bill_id = pl.bill_id
                            WHERE b.bill_id = ?
                            FOR UPDATE');
    $stmt->bind_param('s', $billId);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();

    if (!$bill) {
        throw new Exception('Bill not found');
    }

    $allowedStatuses = ['PENDING', 'CANCELLED'];
    if (!in_array($bill['payment_status'], $allowedStatuses, true)) {
        throw new Exception('Only pending or cancelled bills can be deleted');
    }

    $stmt = $conn->prepare('DELETE FROM payment_collections WHERE bill_id = ?');
    $stmt->bind_param('s', $billId);
    $stmt->execute();

    $stmt = $conn->prepare('DELETE FROM bill_items WHERE bill_id = ?');
    $stmt->bind_param('s', $billId);
    $stmt->execute();

    $stmt = $conn->prepare('DELETE FROM payment_ledger WHERE bill_id = ?');
    $stmt->bind_param('s', $billId);
    $stmt->execute();

    $stmt = $conn->prepare('DELETE FROM bills WHERE bill_id = ?');
    $stmt->bind_param('s', $billId);
    if (!$stmt->execute() || 0 === $stmt->affected_rows) {
        throw new Exception('Failed to delete bill');
    }

    $conn->commit();
    header('Location: bills_list.php?msg='.urlencode('Bill deleted successfully'));
    exit;
} catch (Exception $e) {
    $conn->rollback();
    error_log('Error deleting hospital bill '.$billId.': '.$e->getMessage());
    header('Location: bills_list.php?error='.urlencode($e->getMessage()));
    exit;
}
