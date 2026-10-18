<?php

// Get item details function
function getItemDetails($item_id)
{
    try {
        $conn = getDbConnection();

        if (!$item_id || !is_numeric($item_id)) {
            error_log('Invalid item_id provided: '.var_export($item_id, true));

            return false;
        }

        // Get item details
        $item = $conn->prepare('SELECT * FROM pharmacy_items WHERE item_id = ?');
        if (!$item) {
            error_log('Failed to prepare item query: '.$conn->error);

            return false;
        }

        $item->bind_param('i', $item_id);
        if (!$item->execute()) {
            error_log('Failed to execute item query: '.$item->error);

            return false;
        }

        $item_result = $item->get_result()->fetch_assoc();
        if (!$item_result) {
            error_log('No item found with ID: '.$item_id);

            return false;
        }

        // Get all batches (including zero stock ones)
        $batches = $conn->prepare('SELECT *, 
                                  CASE WHEN current_quantity = 0 THEN "Out of Stock" ELSE "In Stock" END as stock_status
                                  FROM pharmacy_batches 
                                  WHERE item_id = ? 
                                  ORDER BY expiry_date, batch_number');
        $batches->bind_param('i', $item_id);
        $batches->execute();
        $batches_result = $batches->get_result()->fetch_all(MYSQLI_ASSOC);

        // Get purchase history using new schema
        $purchases = $conn->prepare('SELECT 
            pp.purchase_date as transaction_date,
            pti.batch_id,
            pti.quantity,
            pti.unit_price,
            pti.final_amount as net_amount,
            "PURCHASE" as transaction_type,
            pp.invoice_number,
            pp.supplier_id,
            pp.payment_status
            FROM pharmacy_transaction_items pti
            JOIN pharmacy_purchases pp ON pp.transaction_id = pti.transaction_id
            JOIN pharmacy_batches b ON b.batch_id = pti.batch_id
            WHERE b.item_id = ?
            ORDER BY pp.purchase_date DESC
            LIMIT 10');

        $purchases->bind_param('i', $item_id);
        $purchases->execute();
        $purchases_result = $purchases->get_result()->fetch_all(MYSQLI_ASSOC);

        // Get sales history using new schema
        $sales = $conn->prepare('SELECT 
            ps.sale_date as transaction_date,
            pti.batch_id,
            pti.quantity,
            pti.unit_price,
            pti.final_amount as net_amount,
            "SALE" as transaction_type,
            ps.bill_id,
            ps.payment_status,
            COALESCE(p.name, "DIRECT") as patient_name,
            pb.batch_number
            FROM pharmacy_transaction_items pti
            JOIN pharmacy_sales ps ON ps.transaction_id = pti.transaction_id
            JOIN pharmacy_batches pb ON pb.batch_id = pti.batch_id
            LEFT JOIN patients p ON p.patient_id = ps.patient_id
            WHERE pb.item_id = ? 
            AND ps.payment_status != "CANCELLED"
            ORDER BY ps.sale_date DESC
            LIMIT 100');

        $sales->bind_param('i', $item_id);
        $sales->execute();
        $sales_result = $sales->get_result()->fetch_all(MYSQLI_ASSOC);

        return [
            'item' => $item_result,
            'batches' => $batches_result,
            'purchases' => $purchases_result,
            'sales' => $sales_result,
        ];

    } catch (Exception $e) {
        error_log('Error in getItemDetails: '.$e->getMessage());

        return false;
    }
}

// AJAX endpoint for item details
if (isset($_GET['action']) && 'get_details' === $_GET['action']) {
    // Start output buffering at the very beginning
    ob_start();

    try {
        if (!isset($_SESSION['user_id'])) {
            throw new Exception('Session expired');
        }

        if (!checkPermission('pharmacy', 'view')) {
            throw new Exception('Unauthorized access');
        }

        if (!isset($_GET['id'])) {
            throw new Exception('Item ID required');
        }

        $item_id = sanitizeInteger($_GET['id']);
        $details = getItemDetails($item_id);

        if (false === $details) {
            throw new Exception('Failed to fetch item details');
        }

        // Clean any existing output and send response
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Set headers after cleaning buffer
        header('Content-Type: application/json');
        echo json_encode($details, JSON_THROW_ON_ERROR);

    } catch (Exception $e) {
        // Clean any existing output and send error response
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Set headers after cleaning buffer
        header('Content-Type: application/json');
        http_response_code('Session expired' === $e->getMessage() ? 401 : 500);
        echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}
