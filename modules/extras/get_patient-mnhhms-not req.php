<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Add error handling for database connection
try {
    $conn = getHmsConnection();
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

// Function to map gender (copied from dbcopy.php)
function mapGender($gender)
{
    $gender = strtoupper(trim($gender));
    return ($gender == "MALE") ? "M" : (($gender == "FEMALE") ? "F" : "O");
}

if (isset($_GET['term'])) {
    $term = trim($_GET['term']);
    
    // Validate input
    if (empty($term)) {
        echo json_encode(['status' => 'error', 'message' => 'Search term required']);
        exit;
    }
    
    // Remove MNH prefix if present
    // $term = str_replace('MNH', '', $term);
    $customerId = 'MNH' . str_pad($term, 8, '0', STR_PAD_LEFT);
    $stmt = $conn->prepare("SELECT auto_number, customercode, customername, age, gender, mobilenumber 
                           FROM master_customer 
                           WHERE customercode = ?");
    $search_term = $customerId;
    $stmt->bind_param("s", $search_term);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $patient_id = intval(str_replace("MNH", "", $row['customercode']));
        echo json_encode([
            'status' => 'success',
            'patient' => [
                'id' => $patient_id,
                'name' => trim($row['customername']),
                'age' => intval($row['age']),
                'gender' => mapGender($row['gender']),
                'payment_type' => 'CASH'
            ]
        ]);
    } else {
        echo json_encode(['status' => 'not_found']);
    }
    $conn->close();
    exit;
}

$search = $_GET['search'] ?? '';

// Validate search parameter
if (empty($search)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Search parameter required']);
    exit;
}

$search_term = "MNH" . padPatientId($search);

$conn = getHmsConnection(); // Get HMS database connection
$stmt = $conn->prepare("SELECT auto_number, customercode, customername, age, gender, 
                       mobilenumber, address1, city, state 
                       FROM master_customer 
                       WHERE customercode = ?");

$stmt->bind_param("s", $search_term);
$stmt->execute();
$result = $stmt->get_result();

$response = ['found' => false];
if ($row = $result->fetch_assoc()) {
    $patient_id = intval(str_replace("MNH", "", $row['customercode']));
    $response = [
        'found' => true,
        'patient' => [
            'id' => formatPatientId($patient_id),
            'name' => trim($row['customername']),
            'age' => intval(trim($row['age'])),
            'gender' => mapGender($row['gender']),
            'contact' => (preg_match('/^\d{10}$/', $row['mobilenumber'])) ? $row['mobilenumber'] : '9999999999',
            'address' => trim($row['address1'] . ' ' . $row['city'] . ' ' . $row['state']),
            'payment_type' => 'CASH' // Default value since it's not in master_customer
        ]
    ];
}

$conn->close();
header('Content-Type: application/json');
echo json_encode($response);