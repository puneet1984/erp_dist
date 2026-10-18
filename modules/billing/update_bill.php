<?php

define('SECURE_ACCESS', true);
require_once '../../includes/core.php';
require_once '../../includes/helpers/credit_helper.php';

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
    $bill_date = $_POST['bill_date'];
    $services = json_decode($_POST['services'], true);
    $discount = floatval($_POST['discount']);
    $new_payment_type_id = isset($_POST['payment_type_id']) ? (int) $_POST['payment_type_id'] : 1;
    $company_name = isset($_POST['company_name']) ? sanitizeInput($_POST['company_name']) : '';
    $company_ref = isset($_POST['company_ref']) ? sanitizeInput($_POST['company_ref']) : '';

    // Get current bill and ledger details
    $stmt = $conn->prepare('SELECT b.*, pl.ledger_id, pl.company_id as pl_company_id, pl.amount_paid as pl_amount_paid, pl.payment_status as pl_payment_status
                           FROM bills b 
                           LEFT JOIN payment_ledger pl ON b.bill_id = pl.bill_id 
                           WHERE b.bill_id = ?');
    $stmt->bind_param('s', $bill_id);
    $stmt->execute();
    $current_bill = $stmt->get_result()->fetch_assoc();
    if (!$current_bill) {
        throw new Exception('Bill not found');
    }

    $old_payment_type_id = (int) $current_bill['payment_type_id'];
    $old_final_amount = (float) $current_bill['final_amount'];
    $old_company_id = !empty($current_bill['pl_company_id']) ? (int) $current_bill['pl_company_id'] : null;

    // Get original bill details for audit
    $stmt = $conn->prepare('SELECT b.*, bi.* FROM bills b 
                           LEFT JOIN bill_items bi ON b.bill_id = bi.bill_id 
                           WHERE b.bill_id = ?');
    $stmt->bind_param('s', $bill_id);
    $stmt->execute();
    $original_bill = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Update patient ID, doctor and date if changed
    $stmt = $conn->prepare('UPDATE bills SET patient_id = ?, doctor_id = ?, dept_id = ?, bill_date = ? WHERE bill_id = ?');
    $stmt->bind_param('siiss', $patient_id, $doctor_id, $dept_id, $bill_date, $bill_id);
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

    // Handle Payment Mode & Status transitions
    if (2 === $new_payment_type_id) { // COMPANY_CREDIT
        if (empty($company_name)) {
            throw new Exception('Please select a company for Company Credit');
        }

        $stmt = $conn->prepare("SELECT * FROM company_accounts WHERE company_name = ? AND status = 'ACTIVE'");
        $stmt->bind_param('s', $company_name);
        $stmt->execute();
        $company = $stmt->get_result()->fetch_assoc();
        if (!$company) {
            throw new Exception('Selected company not found or is inactive');
        }
        $new_company_id = (int) $company['company_id'];
        $payment_days = (int) ($company['payment_days'] ?: 30);

        // If previously cash/card/upi/etc. was marked PAID, remove payment collection records
        if (2 !== $old_payment_type_id) {
            $stmt = $conn->prepare('DELETE FROM payment_collections WHERE bill_id = ?');
            $stmt->bind_param('s', $bill_id);
            $stmt->execute();

            // Check company credit limit
            if ($company['credit_limit'] > 0 && ($company['current_balance'] + $final_amount) > $company['credit_limit']) {
                throw new Exception('Company credit limit exceeded. Available credit: ₹' . number_format($company['credit_limit'] - $company['current_balance'], 2));
            }

            // Add amount to company balance
            if (!updateCompanyCredit($conn, $new_company_id, $final_amount, 'ADD')) {
                throw new Exception('Failed to update company credit balance');
            }
        } else {
            // Already was company credit
            if ($old_company_id && $old_company_id !== $new_company_id) {
                // Company changed: deduct from old, add to new
                updateCompanyCredit($conn, $old_company_id, $old_final_amount, 'DEDUCT');
                if ($company['credit_limit'] > 0 && ($company['current_balance'] + $final_amount) > $company['credit_limit']) {
                    throw new Exception('Company credit limit exceeded. Available credit: ₹' . number_format($company['credit_limit'] - $company['current_balance'], 2));
                }
                if (!updateCompanyCredit($conn, $new_company_id, $final_amount, 'ADD')) {
                    throw new Exception('Failed to update company credit balance');
                }
            } else {
                // Same company: adjust difference if amount changed
                $diff = $final_amount - $old_final_amount;
                if ($diff != 0) {
                    if ($diff > 0 && $company['credit_limit'] > 0 && ($company['current_balance'] + $diff) > $company['credit_limit']) {
                        throw new Exception('Company credit limit exceeded. Available credit: ₹' . number_format($company['credit_limit'] - $company['current_balance'], 2));
                    }
                    updateCompanyCredit($conn, $new_company_id, abs($diff), $diff > 0 ? 'ADD' : 'DEDUCT');
                }
            }
        }

        $payment_details_json = json_encode([
            'mode' => 'COMPANY_CREDIT',
            'company_name' => $company['company_name'],
            'reference_no' => $company_ref,
            'credit_limit' => $company['credit_limit'],
            'current_balance' => $company['current_balance'],
            'payment_days' => $payment_days,
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $conn->prepare('UPDATE bills SET company_id = ? WHERE bill_id = ?');
        $stmt->bind_param('is', $new_company_id, $bill_id);
        $stmt->execute();

        // Update payment ledger to PENDING and assign company
        if (!empty($current_bill['ledger_id'])) {
            $stmt = $conn->prepare('UPDATE payment_ledger 
                SET payment_type_id = 2,
                    company_id = ?,
                    amount_due = ?,
                    amount_paid = 0.00,
                    payment_status = "PENDING",
                    payment_due_date = DATE_ADD(CURRENT_DATE, INTERVAL ? DAY)
                WHERE bill_id = ?');
            $stmt->bind_param('idis', $new_company_id, $final_amount, $payment_days, $bill_id);
            $stmt->execute();
        } else {
            $transaction_id = $current_bill['transaction_id'] ?: generateTransactionId();
            $stmt = $conn->prepare('INSERT INTO payment_ledger 
                (bill_id, transaction_id, transaction_date, amount_due, amount_paid, payment_type_id, payment_status, payment_due_date, created_by, company_id)
                VALUES (?, ?, NOW(), ?, 0.00, 2, "PENDING", DATE_ADD(CURRENT_DATE, INTERVAL ? DAY), ?, ?)');
            $stmt->bind_param('ssdiisi', $bill_id, $transaction_id, $final_amount, $payment_days, $_SESSION['user_id'], $new_company_id);
            $stmt->execute();
        }

        // Update bills table
        $stmt = $conn->prepare('UPDATE bills 
            SET total_amount = ?,
                discount = ?,
                final_amount = ?,
                payment_type_id = 2,
                payment_status = "PENDING",
                payment_details = ?
            WHERE bill_id = ?');
        $stmt->bind_param('dddss', $total_amount, $discount, $final_amount, $payment_details_json, $bill_id);
        $stmt->execute();

    } else {
        // Staying or switching to non-company credit (e.g. CASH)
        if ($old_payment_type_id === 2 && $old_company_id) {
            // Deduct previous balance from company
            updateCompanyCredit($conn, $old_company_id, $old_final_amount, 'DEDUCT');
            $stmt = $conn->prepare('UPDATE payment_ledger SET company_id = NULL WHERE bill_id = ?');
            $stmt->bind_param('s', $bill_id);
            $stmt->execute();
        }

        // Update main bill
        $stmt = $conn->prepare('UPDATE bills SET total_amount = ?, discount = ?, final_amount = ?, payment_type_id = ?, company_id = NULL WHERE bill_id = ?');
        $stmt->bind_param('dddis', $total_amount, $discount, $final_amount, $new_payment_type_id, $bill_id);
        $stmt->execute();

        // Update payment ledger amount_due
        $stmt = $conn->prepare('UPDATE payment_ledger SET amount_due = ?, payment_type_id = ? WHERE bill_id = ?');
        $stmt->bind_param('dis', $final_amount, $new_payment_type_id, $bill_id);
        $stmt->execute();

        // If previously PAID in full and remains CASH, update amount_paid and payment_collection amount
        if ('PAID' === $current_bill['payment_status'] && 1 === $new_payment_type_id) {
            $stmt = $conn->prepare('UPDATE payment_ledger SET amount_paid = ? WHERE bill_id = ?');
            $stmt->bind_param('ds', $final_amount, $bill_id);
            $stmt->execute();

            $stmt = $conn->prepare('UPDATE payment_collections SET amount = ? WHERE bill_id = ? ORDER BY collection_id DESC LIMIT 1');
            $stmt->bind_param('ds', $final_amount, $bill_id);
            $stmt->execute();
        }
    }

    // insert audit record
    $changes = [
        'original' => $original_bill,
        'modified' => [
            'patient_id' => $patient_id,
            'doctor_id' => $doctor_id,
            'dept_id' => $dept_id,
            'bill_date' => $bill_date,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount,
            'payment_type_id' => $new_payment_type_id,
            'payment_status' => (2 === $new_payment_type_id) ? 'PENDING' : $current_bill['payment_status'],
            'company_name' => (2 === $new_payment_type_id) ? $company_name : '',
            'services' => $services,
        ],
    ];
    $stmt = $conn->prepare('INSERT INTO billing_audit 
        (bill_id, action, modified_by, changes) 
        VALUES (?, ?, ?, ?)');
    $action = 'UPDATE';
    $changes_json = json_encode($changes, JSON_UNESCAPED_UNICODE);
    $stmt->bind_param('ssis', $bill_id, $action, $_SESSION['user_id'], $changes_json);
    $stmt->execute();

    // commit transaction
    $conn->commit();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
