<?php

// Ajax handler to fetch bills containing a specific medicine for the pharmacy module
// Checks user permissions, retrieves medicine details, and fetches all bills using that medicine
// Returns data in JSON format, used by correct_bill_batches.php

define('SECURE_ACCESS', true);
require_once '../core.php';

header('Content-Type: application/json');

// Check permission
if (!canAccessBillBatchCorrections()) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Permission denied',
    ]);
    exit;
}

$item_id = sanitizeInteger($_POST['item_id'] ?? 0);
$medicine_name = sanitizeInput($_POST['medicine_name'] ?? '');

if (!$item_id) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid item ID',
    ]);
    exit;
}

try {
    $conn = getDbConnection();

    // Get item details
    $stmt = $conn->prepare('SELECT item_id, name, category, active_ingredient, dosage_form, manufacturer 
                           FROM pharmacy_items WHERE item_id = ?');
    $stmt->bind_param('i', $item_id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();

    if (!$item) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Medicine not found',
        ]);
        exit;
    }

    // Get all batches for this medicine with calculated usage
    $batches_sql = "
        SELECT 
            pb.batch_id,
            pb.batch_number,
            pb.expiry_date,
            pb.initial_quantity,
            pb.current_quantity,
            pb.retail_price,
            COALESCE(bs.total_sold, 0) as total_sold,
            (pb.initial_quantity - COALESCE(bs.total_sold, 0)) as expected_quantity,
            CASE 
                WHEN pb.current_quantity < 0 THEN 'Negative Stock'
                WHEN pb.current_quantity = 0 THEN 'Out of Stock'
                WHEN pb.expiry_date < NOW() THEN 'Expired'
                WHEN pb.expiry_date < DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 'Expiring Soon'
                ELSE 'In Stock'
            END as batch_status
        FROM pharmacy_batches pb
        LEFT JOIN (
            SELECT 
                pti.batch_id,
                SUM(pti.quantity) as total_sold
            FROM pharmacy_transaction_items pti
            WHERE EXISTS (
                SELECT 1
                FROM pharmacy_sales ps
                WHERE ps.transaction_id = pti.transaction_id
                  AND ps.payment_status != 'CANCELLED'
            )
            GROUP BY pti.batch_id
        ) bs ON pb.batch_id = bs.batch_id
        WHERE pb.item_id = ?
        ORDER BY pb.expiry_date ASC
    ";

    $stmt = $conn->prepare($batches_sql);
    $stmt->bind_param('i', $item_id);
    $stmt->execute();
    $batches_result = $stmt->get_result();

    $batches = [];
    while ($row = $batches_result->fetch_assoc()) {
        // Add discrepancy flag
        $row['has_discrepancy'] = ($row['current_quantity'] != $row['expected_quantity']);
        $batches[] = $row;
    }

    // Get all bills containing this medicine
    $bills_sql = "
        SELECT 
            ps.bill_id,
            ps.sale_date,
            COALESCE(p.name, 'Direct Sale') as patient_name,
            p.patient_id,
            pb.batch_id,
            pb.batch_number,
            pb.expiry_date,
            pb.item_id,
            pti.quantity,
            pti.unit_price,
            pti.final_amount,
            ps.payment_status
        FROM pharmacy_sales ps
        JOIN pharmacy_transaction_items pti ON ps.transaction_id = pti.transaction_id
        JOIN pharmacy_batches pb ON pti.batch_id = pb.batch_id
        LEFT JOIN patients p ON ps.patient_id = p.patient_id
        WHERE pb.item_id = ? AND ps.payment_status != 'CANCELLED'
        ORDER BY ps.sale_date DESC
    ";

    $stmt = $conn->prepare($bills_sql);
    $stmt->bind_param('i', $item_id);
    $stmt->execute();
    $bills_result = $stmt->get_result();

    $bills = [];
    while ($row = $bills_result->fetch_assoc()) {
        $bills[] = $row;
    }

    echo json_encode([
        'status' => 'success',
        'item' => $item,
        'batches' => $batches,
        'bills' => $bills,
        'message' => 'Batches and bills fetched successfully',
    ]);

} catch (Exception $e) {
    error_log('Error in get_bill_medicines.php: '.$e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Error: '.$e->getMessage(),
    ]);
}
