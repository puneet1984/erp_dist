<?php

define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Common response function
function sendResponse($status, $data = [], $message = '')
{
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'patient' => $data,
    ]);
    exit;
}

// Route the request based on module parameter
if (isset($_GET['term'])) {
    $conn = getDbConnection();
    $term = trim($_GET['term']);
    $module = $_GET['module'] ?? 'default';

    // Validate and sanitize patient ID
    $term = preg_replace('/[^0-9]/', '', $term); // Remove non-numeric chars including MNH
    
    if (empty($term)) {
        sendResponse('error', [], 'Invalid patient ID format');
        exit;
    }

    // Convert to integer and validate range
    $term = intval($term);
    if ($term < 1 || $term > 99999999) { // Assuming 8-digit max ID
        sendResponse('error', [], 'Patient ID out of valid range');
        exit;
    }

    switch ($module) {
        case 'billing':
            handleBillingQuery($conn, $term);
            break;

        case 'ipd':
            handleIPDQuery($conn, $term);
            break;

        default:
            handleDefaultQuery($conn, $term);
    }
}

function handleBillingQuery($conn, $term)
{
    $stmt = $conn->prepare('SELECT 
        p.patient_id, p.name, p.age, p.gender, p.payment_type_id,
        pt.type_name as payment_type
        FROM patients p
        LEFT JOIN payment_types pt ON p.payment_type_id = pt.payment_type_id
        WHERE p.patient_id = ?');

    $stmt->bind_param('i', $term);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        sendResponse('success', [
            'id' => $row['patient_id'],
            'name' => $row['name'],
            'age' => $row['age'],
            'gender' => $row['gender'],
            'payment_type_id' => $row['payment_type_id'],
            'payment_type' => $row['payment_type'],
        ]);
    }

    sendResponse('error', [], 'Patient not found');
}

function handleIPDQuery($conn, $term)
{
    $stmt = $conn->prepare('
        SELECT p.*, a.admission_id, a.admission_date, a.ward_id,
               d.name as doctor_name, d.doctor_id,
               dp.name as dept_name, dp.dept_id
        FROM patients p
        LEFT JOIN ipd_admissions a ON p.patient_id = a.patient_id AND a.status = "Admitted"
        LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
        LEFT JOIN departments dp ON d.dept_id = dp.dept_id
        WHERE p.patient_id = ?');

    $stmt->bind_param('i', $term);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $data = [
            'id' => $row['patient_id'],
            'name' => $row['name'],
            'age' => $row['age'],
            'gender' => $row['gender'],
            'address' => $row['address'],
            'contact' => $row['contact'] ?? '',
            'city' => $row['city'] ?? '',
            'state' => $row['state'] ?? '',
        ];

        // Add active admission details if exists
        if ($row['admission_id']) {
            $data['admission'] = [
                'admission_id' => $row['admission_id'],
                'admission_date' => $row['admission_date'],
                'ward_id' => $row['ward_id'],
                'doctor_name' => $row['doctor_name'],
                'doctor_id' => $row['doctor_id'],
                'dept_name' => $row['dept_name'],
                'dept_id' => $row['dept_id'],
            ];
        }

        sendResponse('success', $data);
    }

    sendResponse('error', [], 'Patient not found');
}

function handleDefaultQuery($conn, $term)
{
    $stmt = $conn->prepare('SELECT patient_id, name, age, gender, contact, address, city, state FROM patients WHERE patient_id = ?');
    $stmt->bind_param('i', $term);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $response = [
            'id' => $row['patient_id'],
            'name' => $row['name'],
            'age' => $row['age'],
            'gender' => $row['gender'],
            'contact' => $row['contact'] ?? '',
            'address' => $row['address'],
            'city' => $row['city'] ?? '',
            'state' => $row['state'] ?? '',
        ];

        // Check if admission details are requested
        if (isset($_GET['check_admission'])) {
            $admission_query = $conn->prepare(
                "SELECT a.admission_date, a.admission_id,
                        d.name as doctor_name, d.doctor_id,
                        dp.name as dept_name, dp.dept_id
                 FROM ipd_admissions a 
                 JOIN doctors d ON a.doctor_id = d.doctor_id 
                 JOIN departments dp ON d.dept_id = dp.dept_id 
                 WHERE a.patient_id = ? 
                 AND a.status = 'Admitted'
                 ORDER BY a.admission_date DESC 
                 LIMIT 1"
            );

            $admission_query->bind_param('i', $row['patient_id']);
            $admission_query->execute();
            $admission_result = $admission_query->get_result();

            if ($admission = $admission_result->fetch_assoc()) {
                $response['admission'] = $admission;
            }
        }

        sendResponse('success', $response);
    }

    sendResponse('error', [], 'Patient not found');
}
