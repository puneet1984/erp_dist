<?php

// AJAX endpoint to move selected sale bills from one batch to another for same medicine.

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

$itemId = sanitizeInteger($_POST['item_id'] ?? 0);
$sourceBatchId = sanitizeInteger($_POST['source_batch_id'] ?? 0);
$targetBatchId = sanitizeInteger($_POST['target_batch_id'] ?? 0);
$billIdsPayload = $_POST['bill_ids'] ?? '[]';

$billIds = json_decode($billIdsPayload, true);
if (!is_array($billIds)) {
    $billIds = [];
}

$billIds = array_values(array_unique(array_filter(array_map(function ($billId) {
    return sanitizeInput((string) $billId);
}, $billIds))));

if (!$itemId || !$sourceBatchId || !$targetBatchId || empty($billIds)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request payload',
    ]);
    exit;
}

if ($sourceBatchId === $targetBatchId) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Source and target batch cannot be same',
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

    // Lock source and target batches for consistent quantity checks.
    $stmt = $conn->prepare('SELECT batch_id, batch_number, current_quantity
                            FROM pharmacy_batches
                            WHERE batch_id = ? AND item_id = ?
                            FOR UPDATE');
    $stmt->bind_param('ii', $sourceBatchId, $itemId);
    $stmt->execute();
    $sourceBatch = $stmt->get_result()->fetch_assoc();

    if (!$sourceBatch) {
        throw new Exception('Source batch not found for selected medicine');
    }

    $stmt = $conn->prepare('SELECT batch_id, batch_number, current_quantity
                            FROM pharmacy_batches
                            WHERE batch_id = ? AND item_id = ?
                            FOR UPDATE');
    $stmt->bind_param('ii', $targetBatchId, $itemId);
    $stmt->execute();
    $targetBatch = $stmt->get_result()->fetch_assoc();

    if (!$targetBatch) {
        throw new Exception('Target batch not found for selected medicine');
    }

    $placeholders = implode(',', array_fill(0, count($billIds), '?'));
    $types = str_repeat('s', count($billIds));

    $sql = "SELECT ps.bill_id, ps.transaction_id, pti.quantity
            FROM pharmacy_sales ps
            JOIN pharmacy_transaction_items pti ON pti.transaction_id = ps.transaction_id
            JOIN pharmacy_batches pb ON pb.batch_id = pti.batch_id
            WHERE ps.bill_id IN ({$placeholders})
              AND pb.item_id = ?
              AND pti.batch_id = ?
              AND ps.payment_status != 'CANCELLED'
            FOR UPDATE";

    $stmt = $conn->prepare($sql);
    $params = array_merge($billIds, [(int) $itemId, (int) $sourceBatchId]);
    $bindTypes = $types.'ii';

    $bindArgs = [$bindTypes];
    foreach ($params as $key => $value) {
        $bindArgs[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindArgs);

    $stmt->execute();
    $result = $stmt->get_result();

    $rowsToMove = [];
    $transactionIds = [];
    $totalQuantity = 0;

    while ($row = $result->fetch_assoc()) {
        $rowsToMove[] = $row;
        $transactionIds[] = $row['transaction_id'];
        $totalQuantity += (int) $row['quantity'];
    }

    if (empty($rowsToMove)) {
        throw new Exception('No matching sale-bill rows found in selected source batch');
    }

    if (count($rowsToMove) !== count($billIds)) {
        throw new Exception('Some selected bills are missing or already moved; refresh and try again');
    }

    $targetAvailableQty = recalculateBatchQuantity($conn, (int) $targetBatchId);
    if ($targetAvailableQty < $totalQuantity) {
        throw new Exception('Insufficient quantity in target batch. Available: '.$targetAvailableQty.', Required: '.$totalQuantity);
    }

    $uniqueTransactionIds = array_values(array_unique($transactionIds));
    $txPlaceholders = implode(',', array_fill(0, count($uniqueTransactionIds), '?'));
    $txTypes = str_repeat('s', count($uniqueTransactionIds));

    $updateSql = "UPDATE pharmacy_transaction_items
                  SET batch_id = ?
                  WHERE transaction_id IN ({$txPlaceholders})
                    AND batch_id = ?";

    $stmt = $conn->prepare($updateSql);
    $updateParams = array_merge([(int) $targetBatchId], $uniqueTransactionIds, [(int) $sourceBatchId]);
    $updateTypes = 'i'.$txTypes.'i';

    $updateBindArgs = [$updateTypes];
    foreach ($updateParams as $key => $value) {
        $updateBindArgs[] = &$updateParams[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $updateBindArgs);

    if (!$stmt->execute()) {
        throw new Exception('Failed to update selected sale bills');
    }

    if ($stmt->affected_rows !== count($rowsToMove)) {
        throw new Exception('Could not update all selected sale bills; refresh and retry');
    }

    recalculateBatchQuantity($conn, (int) $sourceBatchId);
    recalculateBatchQuantity($conn, (int) $targetBatchId);

    $conn->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Moved '.count($rowsToMove).' bill(s) from batch '.$sourceBatch['batch_number'].' to '.$targetBatch['batch_number'],
        'moved_count' => count($rowsToMove),
        'moved_quantity' => $totalQuantity,
    ]);
} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }

    error_log('Error in update_batch_bills_bulk.php: '.$e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
}
