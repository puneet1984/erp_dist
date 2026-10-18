<?php

function getCurrentFinancialYear($date = null)
{
    $timezone = new DateTimeZone('Asia/Kolkata');

    if (!empty($date)) {
        $dateTime = new DateTime($date, $timezone);
    } else {
        $dateTime = new DateTime('now', $timezone);
    }

    $currentMonth = (int) $dateTime->format('m');
    $currentYear = (int) $dateTime->format('Y');

    if ($currentMonth < FINANCIAL_YEAR_START_MONTH) {
        return ($currentYear - 1).'-'.substr($currentYear, 2);
    }

    return $currentYear.'-'.substr($currentYear + 1, 2);
}

function getNextBillNumber($type, $conn, $billDate = null)
{
    $fy = getCurrentFinancialYear($billDate);
    // All bills are stored in the bills table
    $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(bill_id, '/', -1) AS UNSIGNED)) as last_num 
                           FROM bills 
                           WHERE bill_id LIKE ? AND status = 'ACTIVE'");
    $pattern = HOSPITAL_CODE.'/'.$fy.'/'.$type.'/%';
    $stmt->bind_param('s', $pattern);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return ($result['last_num'] ?? 0) + 1;
}

function generateHospitalBillId($conn, $billDate = null)
{
    $fy = getCurrentFinancialYear($billDate);
    $billNo = getNextBillNumber('H', $conn, $billDate);

    return sprintf(
        '%s/%s/H/%d',
        HOSPITAL_CODE,
        $fy,
        $billNo
    );
}

function generateTransactionId($prefix = 'txn')
{
    // Remove any non-alphanumeric characters from prefix
    $prefix = preg_replace('/[^a-zA-Z0-9]/', '', $prefix);

    // Ensure prefix is not empty
    if (empty($prefix)) {
        $prefix = 'txn';
    }

    return $prefix.'_'.uniqid('', true);
}

// function generateTransactionId()
// {
//     return uniqid('txn_', true);
// }

function generatePharmacyBillId($conn, $saleDate = null)
{
    $fy = getCurrentFinancialYear($saleDate);
    // Get max bill number from pharmacy_sales table
    $pattern = HOSPITAL_CODE.'/'.$fy.'/P/%';

    $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(bill_id, '/', -1) AS UNSIGNED)) as last_num 
                           FROM pharmacy_sales 
                           WHERE bill_id LIKE ? AND payment_status != 'CANCELLED'");
    $stmt->bind_param('s', $pattern);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    $nextNumber = ($result['last_num'] ?? 0) + 1;

    return sprintf('%s/%s/P/%d', HOSPITAL_CODE, $fy, $nextNumber);
}

function generateIPDBillId($conn, $billDate = null)
{
    $fy = getCurrentFinancialYear($billDate);
    // $pattern = HOSPITAL_CODE . "/%/IPD/%";
    $pattern = HOSPITAL_CODE.'/'.$fy.'/IPD/%';

    // Get the last bill number for current financial year
    $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(bill_id, '/', -1) AS UNSIGNED)) as last_num 
                           FROM bills 
                           WHERE bill_id LIKE ? AND status = 'ACTIVE'");
    $stmt->bind_param('s', $pattern);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    $nextNumber = ($result['last_num'] ?? 0) + 1;

    return sprintf('%s/%s/IPD/%d', HOSPITAL_CODE, $fy, $nextNumber);
}

function getServiceCategories()
{
    static $categories = null;
    if (null === $categories) {
        $categories_json = file_get_contents('../../config/service_categories.json');
        $categories = json_decode($categories_json, true);
        uasort($categories, fn ($a, $b) => $a['order'] - $b['order']);
    }

    return $categories;
}

function getTaxRate($category)
{
    static $tax_rates = null;
    if (null === $tax_rates) {
        $tax_rates = json_decode(file_get_contents('../../config/tax_rates.json'), true);
    }

    return $tax_rates[$category] ?? $tax_rates['OTHER'];
}

function calculateTax($amount, $category)
{
    $tax_info = getTaxRate($category);
    $tax_rate = $tax_info['gst'] / 100;

    return [
        'rate' => $tax_info['gst'],
        'amount' => $amount * $tax_rate,
        'total' => $amount * (1 + $tax_rate),
    ];
}

function getBillSettings()
{
    $config_file = __DIR__.'/../../config/bill_settings.json';
    if (file_exists($config_file)) {
        $settings = json_decode(file_get_contents($config_file), true);

        return $settings ?: [];
    }

    return [];
}

function generateRefundId()
{
    $prefix = 'REF';
    $timestamp = date('YmdHis');
    $random = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 4);

    return $prefix.$timestamp.$random;
}


function checkDuplicateBill($conn, $patient_id, $doctor_id, $bill_date, $services)
{
    // Get start and end of the bill date
    $date_start = date('Y-m-d 00:00:00', strtotime($bill_date));
    $date_end = date('Y-m-d 23:59:59', strtotime($bill_date));

    // First check if there's any bill for this patient on the same date
    $stmt = $conn->prepare("
        SELECT b.bill_id, b.bill_date, GROUP_CONCAT(bi.service_id) as services
        FROM bills b
        JOIN bill_items bi ON b.bill_id = bi.bill_id
        WHERE b.patient_id = ?
        AND b.doctor_id = ?
        AND b.bill_date BETWEEN ? AND ?
        AND b.status != 'CANCELLED'
        GROUP BY b.bill_id
    ");

    $stmt->bind_param('ssss', $patient_id, $doctor_id, $date_start, $date_end);
    $stmt->execute();
    $result = $stmt->get_result();

    if (0 === $result->num_rows) {
        return false; // No duplicate found
    }

    // Get array of service IDs from current bill
    $current_services = array_map(function ($service) {
        return $service['service_id'];
    }, $services);
    sort($current_services);

    // Check each bill from the same date
    while ($row = $result->fetch_assoc()) {
        $bill_services = explode(',', $row['services']);
        sort($bill_services);

        // If services match exactly, it's likely a duplicate
        if ($current_services === $bill_services) {
            return [
                'duplicate' => true,
                'existing_bill' => $row['bill_id'],
                'bill_date' => date('Y-m-d H:i', strtotime($row['bill_date'])),
            ];
        }
    }

    return false;
}
