<?php

function updateCompanyCredit($conn, $company_id, $amount, $type = 'ADD')
{
    $stmt = $conn->prepare("UPDATE company_accounts 
        SET current_balance = current_balance + ? 
        WHERE company_id = ? AND 
        (? + current_balance <= credit_limit OR ? < 0)");

    $adjustment = ($type === 'ADD') ? $amount : -$amount;
    $stmt->bind_param("didi", $adjustment, $company_id, $adjustment, $adjustment);
    return $stmt->execute();
}

function getCompanyCreditStatus($company_id)
{
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT ca.*, 
            COUNT(DISTINCT pl.bill_id) as total_bills,
            SUM(pl.amount_due - pl.amount_paid) as total_pending
        FROM company_accounts ca
        LEFT JOIN payment_ledger pl ON ca.company_id = pl.company_id
        WHERE ca.company_id = ?
        GROUP BY ca.company_id");

    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function getCompanyPendingBills($company_id)
{
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT b.bill_id, b.bill_date, b.total_amount, b.final_amount,
               pl.amount_paid, pl.balance_amount, pl.payment_due_date,
               p.name as patient_name, p.patient_id
        FROM bills b
        JOIN payment_ledger pl ON b.bill_id = pl.bill_id
        JOIN patients p ON b.patient_id = p.patient_id
        WHERE pl.company_id = ? AND pl.payment_status != 'PAID'
        ORDER BY pl.payment_due_date ASC");

    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
