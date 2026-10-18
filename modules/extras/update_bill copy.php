<?php

define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('bills', 'edit')) {
    exit(json_encode(['status' => 'error', 'message' => 'Access Denied']));
}

if ('POST' !== $_SERVER['REQUEST_METHOD']) {
    exit(json_encode(['status' => 'error', 'message' => 'Invalid request method']));
}

$conn = getDbConnection();
$conn->begin_transaction();

try {
    $bill_id = $_POST['bill_id'];
    $patient_id = padPatientId($_POST['patient_id']);
    $doctor_id = $_POST['doctor_id'];
    $dept_id = $_POST['dept_id'];
    $services = json_decode($_POST['services'], true);
    $discount = floatval($_POST['discount']);

    // Get original bill details for audit
    $stmt = $conn->prepare('SELECT b.*, bi.* FROM bills b 
                           LEFT JOIN bill_items bi ON b.bill_id = bi.bill_id 
                           WHERE b.bill_id = ?');
    $stmt->bind_param('s', $bill_id);
    $stmt->execute();
    $original_bill = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Update patient ID and doctor if changed
    $stmt = $conn->prepare('UPDATE bills SET patient_id = ?, doctor_id = ?, dept_id = ? WHERE bill_id = ?');
    $stmt->bind_param('siis', $patient_id, $doctor_id, $dept_id, $bill_id);
    $stmt->execute();

    // Delete existing bill items
    $stmt = $conn->prepare('DELETE FROM bill_items WHERE bill_id = ?');
    $stmt->bind_param('s', $bill_id);
    $stmt->execute();

    // Insert updated bill items
    $stmt = $conn->prepare('INSERT INTO bill_items (bill_id, service_id, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)');

    $total_amount = 0;
    foreach ($services as $service) {
        $service_id = $service['service_id'];
        $quantity = $service['quantity'];
        $price = $service['price'];
        $total = $quantity * $price;
        $total_amount += $total;

        $stmt->bind_param('siids', $bill_id, $service_id, $quantity, $price, $total);
        $stmt->execute();
    }

    // Calculate final amount
    $final_amount = $total_amount - $discount;

    // Update main bill
    $stmt = $conn->prepare('UPDATE bills SET total_amount = ?, discount = ?, final_amount = ? WHERE bill_id = ?');
    $stmt->bind_param('ddds', $total_amount, $discount, $final_amount, $bill_id);
    $stmt->execute();

    // Update payment ledger
    $stmt = $conn->prepare('UPDATE payment_ledger SET amount_due = ? WHERE bill_id = ?');
    $stmt->bind_param('ds', $final_amount, $bill_id);
    $stmt->execute();

    // insert audit record
    $changes = [
        'original' => $original_bill,
        'modified' => [
            'patient_id' => $patient_id,
            'doctor_id' => $doctor_id,
            'dept_id' => $dept_id,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount,
            'services' => $services,
        ],
    ];
    $stmt = $conn->prepare('INSERT INTO billing_audit 
        (bill_id, action, modified_by, changes) 
        VALUES (?, ?, ?, ?)');
    $action = 'UPDATE';
    $changes_json = json_encode($changes);
    $stmt->bind_param('ssis', $bill_id, $action, $_SESSION['user_id'], $changes_json);
    $stmt->execute();

    // commit transaction
    $conn->commit();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
