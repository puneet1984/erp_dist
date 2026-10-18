<?php
// Check for secure access
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
    require_once __DIR__ . '/../../includes/core.php';
}

// Get database connection
$conn = getDbConnection();

// Patient ID formatting function - centralized here
function formatPatientId($id)
{
    return 'MNH' . $id;
}

function formatPatientIdPadded($id) 
{
    // Remove any existing MNH prefix
    $cleanId = str_replace('MNH', '', $id);
    
    // Remove leading zeros
    $cleanId = ltrim($cleanId, '0');
    
    // Validate numeric
    if (!is_numeric($cleanId) || $cleanId < 1) {
        return false;
    }
    
    // Pad with zeros to make it 8 digits
    $paddedId = str_pad($cleanId, 8, '0', STR_PAD_LEFT);
    
    return 'MNH' . $paddedId;
}
function patientIdClean($id)
{
    return str_replace('MNH', '', $id);
}
function validatePatientId($id) 
{
    // Remove MNH prefix and leading zeros
    $id = patientIdClean($id);
    $id = ltrim($id, '0');
    
    // Return the cleaned ID if it's numeric and greater than 0
    if (is_numeric($id) && intval($id) > 0) {
        return intval($id);
    }
    
    return false;
}

function padPatientId($id)
{
    // Remove 'MNH' prefix if present and pad with zeros
    $id = patientIdClean($id);
    return str_pad($id, 8, '0', STR_PAD_LEFT);
}

// Validation functions
function validateMobile($mobile)
{
    return preg_match('/^[6-9]\d{9}$/', $mobile);
}

function validateAge($age)
{
    return is_numeric($age) && $age > 0 && $age < 150;
}

function validateAadhar($aadhar)
{
    return preg_match('/^\d{12}$/', $aadhar);
}

function validateAbha($abha)
{
    return preg_match('/^\d{14}$/', $abha);
}

function validateAyushmaan($ayushmaan)
{
    // PM-JAY ID format: combination of letters and numbers, 8-20 characters
    return preg_match('/^[A-Z0-9]{8,20}$/i', $ayushmaan);
}

function sanitizeInput($input)
{
    // Handle null/empty input
    if ($input === null || $input === '') {
        return '';
    }
    return trim(htmlspecialchars((string)$input));
}

function sanitizeNumeric($input) {
    return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

function sanitizeDate($input) {
    return date('Y-m-d', strtotime($input));
}


function sanitizeInteger($input, $min = null, $max = null) {
    // Remove any non-numeric characters except minus sign
    $sanitized = preg_replace('/[^\d-]/', '', $input);
    
    // Convert to integer
    $number = filter_var($sanitized, FILTER_VALIDATE_INT);
    
    // Return null if not a valid integer
    if ($number === false) {
        return null;
    }
    
    // Check minimum value
    if ($min !== null && $number < $min) {
        return null;
    }
    
    // Check maximum value
    if ($max !== null && $number > $max) {
        return null;
    }
    
    return $number;
}

function capitalizeWords($string) {
    // Handle null/empty input
    if (empty($string)) {
        return '';
    }
    
    // Split on spaces and hyphens but preserve original string
    $originalWords = preg_split('/[\s-]+/', $string);
    
    // Process each word
    $words = array_map(function($word) {
        // Skip empty strings
        if (empty($word)) return '';
        
        // Check if original word was two capital letters together
        if (strlen($word) === 2 && 
            ctype_upper($word[0]) && 
            ctype_upper($word[1])) {
            return $word; // Keep original case if both letters were uppercase
        }
        
        // Convert to lowercase for normal processing
        $word = strtolower($word);
        
        // Handle special cases like O'Brien, McDonald's
        if (strpos($word, "'") !== false) {
            $parts = explode("'", $word);
            return implode("'", array_map('ucfirst', $parts));
        }
        
        return ucfirst($word);
    }, $originalWords);
    
    // Join words back together
    return implode(' ', $words);
}

function makeUpperCase($string) {
    // Handle null/empty input
    if ($string === null || $string === '') {
        return '';
    }
    
    // Convert to uppercase and trim any whitespace
    return trim(strtoupper((string)$string));
}

function makeUpperCaseMixed($string) {
    // Handle null/empty input
    if ($string === null || $string === '') {
        return '';
    }
    
    // Split string into parts preserving digits
    return preg_replace_callback(
        '/([a-zA-Z]+)|([0-9]+)/', 
        function($matches) {
            // If it's a letter sequence, convert to uppercase
            if (!empty($matches[1])) {
                return strtoupper($matches[1]);
            }
            // If it's a number sequence, keep as is
            return $matches[2];
        },
        trim($string)
    );
}

// Check if direct access or AJAX request
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // Verify AJAX request
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        die('Direct access not permitted');
    }
    
    // Check if action parameter exists
    if (!isset($_GET['action'])) {
        echo json_encode(['status' => 'error', 'message' => 'No action specified']);
        exit;
    }

    $action = $_GET['action'];
    
    // Handle actions
    switch ($action) {
        case 'check_admission':
            $patient_id = sanitizeInput($_GET['patient_id']);
            $stmt = $conn->prepare("SELECT a.admission_id, a.admission_date, p.name as patient_name 
                                   FROM ipd_admissions a
                                   JOIN patients p ON a.patient_id = p.patient_id 
                                   WHERE a.patient_id = ? 
                                   AND a.status = 'Admitted' 
                                   ORDER BY a.admission_date DESC 
                                   LIMIT 1");
            $stmt->bind_param("s", $patient_id);
            $stmt->execute();
            $admission = $stmt->get_result()->fetch_assoc();
            
            echo json_encode([
                'status' => 'success',
                'admission' => $admission
            ]);
            break;

        case 'search_patient':
            $patient_id = sanitizeInput($_GET['patient_id']);
            $stmt = $conn->prepare("SELECT patient_id, name FROM patients WHERE patient_id = ?");
            $stmt->bind_param("s", $patient_id);
            $stmt->execute();
            $patient = $stmt->get_result()->fetch_assoc();
            
            if ($patient) {
                echo json_encode([
                    'status' => 'success',
                    'patient' => $patient
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Patient not found'
                ]);
            }
            break;
    }
}