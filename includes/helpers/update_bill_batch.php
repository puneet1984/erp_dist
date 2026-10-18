<?php

// AJAX endpoint to update batch number in pharmacy bills
// Updates pharmacy_transaction_items and pharmacy_batches quantities
// used by correct_bill_batches.php

define('SECURE_ACCESS', true);
require_once '../core.php';

header('Content-Type: application/json');

if (!canEditBillBatchCorrections()) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Permission denied',
    ]);
    exit;
}

$bill_id = sanitizeInput($_POST['bill_id'] ?? '');
$new_batch = sanitizeInput($_POST['new_batch'] ?? '');
$item_id = sanitizeInteger($_POST['item_id'] ?? 0);

if (!$bill_id || !$new_batch || !$item_id) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid parameters',
    ]);
    exit;
}

/**
 * Recalculate and persist current_quantity using initial_quantity - sold_quantity.
 */
function recalculateBatchQuantity(mysqli $conn, int $batchId): int
{
    $stmt = $conn->prepare('SELECT initial_quantity FROM pharmacy_batches WHERE batch_id = ? FOR UPDATE');
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $batchRow = $stmt->get_result()->fetch_assoc();

    if (!$batchRow) {
        throw new Exception('Batch not found while recalculating quantity');
    }

    $stmt = $conn->prepare('SELECT COALESCE(SUM(pti.quantity), 0) AS total_sold
                            FROM pharmacy_transaction_items pti
                            JOIN pharmacy_sales ps ON ps.transaction_id = pti.transaction_id
                            WHERE pti.batch_id = ?
                              AND ps.payment_status != "CANCELLED"');
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $soldRow = $stmt->get_result()->fetch_assoc();

    $initialQty = (int) $batchRow['initial_quantity'];
    $totalSold = (int) ($soldRow['total_sold'] ?? 0);
    $calculatedQty = $initialQty - $totalSold;

    $stmt = $conn->prepare('UPDATE pharmacy_batches SET current_quantity = ? WHERE batch_id = ?');
    $stmt->bind_param('ii', $calculatedQty, $batchId);
    if (!$stmt->execute()) {
        throw new Exception('Failed to persist recalculated batch quantity');
    }

    return $calculatedQty;
}

try {
    $conn = getDbConnection();
    $conn->begin_transaction();

    // Get the pharmacy_sales transaction_id from bill_id
    $stmt = $conn->prepare('SELECT transaction_id FROM pharmacy_sales WHERE bill_id = ?');
    $stmt->bind_param('s', $bill_id);
    $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();

    if (!$sale) {
        throw new Exception('Bill not found');
    }

    $transaction_id = $sale['transaction_id'];

    // Get current bill transaction item details (old batch)
    $stmt = $conn->prepare('
        SELECT pti.batch_id, pti.quantity, pb.batch_number
        FROM pharmacy_transaction_items pti
        JOIN pharmacy_batches pb ON pti.batch_id = pb.batch_id
        WHERE pti.transaction_id = ? AND pb.item_id = ?
    ');
    $stmt->bind_param('si', $transaction_id, $item_id);
    $stmt->execute();
    $current_item = $stmt->get_result()->fetch_assoc();

    if (!$current_item) {
        throw new Exception('Transaction item not found');
    }

    $old_batch_id = $current_item['batch_id'];
    $quantity_used = (int) $current_item['quantity'];

    // Lock old batch row before quantity restore to keep stock movements atomic.
    $stmt = $conn->prepare('SELECT batch_id, current_quantity FROM pharmacy_batches WHERE batch_id = ? FOR UPDATE');
    $stmt->bind_param('i', $old_batch_id);
    $stmt->execute();
    $old_batch_row = $stmt->get_result()->fetch_assoc();

    if (!$old_batch_row) {
        throw new Exception('Old batch not found');
    }

    // Get new batch details
    $stmt = $conn->prepare('SELECT batch_id, current_quantity FROM pharmacy_batches WHERE batch_number = ? AND item_id = ? FOR UPDATE');
    $stmt->bind_param('si', $new_batch, $item_id);
    $stmt->execute();
    $new_batch_row = $stmt->get_result()->fetch_assoc();

    if (!$new_batch_row) {
        throw new Exception('New batch not found');
    }

    $new_batch_id = (int) $new_batch_row['batch_id'];

    // Check availability from a fresh calculated quantity instead of stale current_quantity.
    $targetAvailableQty = recalculateBatchQuantity($conn, $new_batch_id);
    if ($targetAvailableQty < $quantity_used) {
        throw new Exception('Insufficient quantity in new batch. Available: '.$targetAvailableQty.', Required: '.$quantity_used);
    }

    // Prevent updating to the same batch
    if ((int) $old_batch_id === $new_batch_id) {
        throw new Exception('Cannot update to the same batch');
    }

    // Update pharmacy_transaction_items with new batch_id
    $stmt = $conn->prepare('UPDATE pharmacy_transaction_items SET batch_id = ? WHERE transaction_id = ? AND batch_id = ?');
    $stmt->bind_param('isi', $new_batch_id, $transaction_id, $old_batch_id);
    if (!$stmt->execute() || 0 === $stmt->affected_rows) {
        throw new Exception('Failed to update transaction item');
    }

    // Recalculate both batches from actual sold rows so quantities stay consistent.
    recalculateBatchQuantity($conn, (int) $old_batch_id);
    recalculateBatchQuantity($conn, (int) $new_batch_id);

    $conn->commit();

    echo json_encode([
        'status' => 'success',
        'message' => "Batch updated successfully from {$current_item['batch_number']} to {$new_batch}",
    ]);

} catch (Exception $e) {
    $conn->rollback();
    error_log('Error in update_bill_batch.php: '.$e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
}
