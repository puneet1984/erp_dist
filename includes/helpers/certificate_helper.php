<?php

// Allow access if secure parameter is set or if called from a secure context

if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

require_once '../../includes/core.php';

// Add this helper function after initial includes
function formatDateDMY($date)
{
    if (empty($date) || null === $date) {
        return '';
    }

    return date('d-m-Y', strtotime($date));
}

// Verify AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    || 'xmlhttprequest' !== strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    http_response_code(403);
    exit(json_encode(['error' => 'Invalid request method']));
}

// Check session and permissions
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    exit(json_encode(['error' => 'Session expired', 'redirect' => '../../login.php']));
}

if (!isDoctor() && !isAdmin()) {
    header('Content-Type: application/json');
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized access']));
}

// Set JSON content type for all responses
header('Content-Type: application/json');

// Load certificate templates
function loadTemplates()
{
    $file = dirname(__FILE__, 3).'/assets/json/certificate_templates.json';

    return file_exists($file) ? json_decode(file_get_contents($file), true) : [];
}

$conn = getDbConnection();

function getPatientDetails($conn, $patientId)
{
    $stmt = $conn->prepare("SELECT patient_id, name, age, gender, 
                           CONCAT_WS(', ', address, city, state) as full_address,
                           contact as phone
                           FROM patients WHERE patient_id = ?");
    $stmt->bind_param('s', $patientId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($patient = $result->fetch_assoc()) {
        return [
            'patient_id' => formatPatientId($patient['patient_id']),
            'name' => capitalizeWords($patient['name']),
            'age' => $patient['age'],
            'gender' => $patient['gender'],
            'address' => $patient['full_address'],
            'phone' => $patient['phone'],
        ];
    }

    return null;
}

function getDoctorDetails($conn, $doctorId)
{
    $stmt = $conn->prepare('SELECT d.name, d.registration_no, d.dept_id, dp.name as department 
                           FROM doctors d 
                           LEFT JOIN departments dp ON d.dept_id = dp.dept_id 
                           WHERE d.doctor_id = ?');
    $stmt->bind_param('i', $doctorId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_assoc();
}

function getCertificateTemplate($type, $patientData = null)
{
    $templates = loadTemplates();
    if (!isset($templates[$type])) {
        return null;
    }

    global $conn;
    $doctorId = $_POST['doctorId'] ?? $_SESSION['user_id'] ?? null;
    $doctorDetails = $doctorId ? getDoctorDetails($conn, $doctorId) : null;

    $template = $templates[$type];
    if ($patientData) {
        $template['content'] = strtr($template['content'], [
            '{{PATIENT_NAME}}' => $patientData['name'] ?? '',
            '{{PATIENT_AGE}}' => $patientData['age'] ?? '',
            '{{PATIENT_GENDER}}' => $patientData['gender'] ?? '',
            '{{PATIENT_ADDRESS}}' => $patientData['address'] ?? '',
            '{{PATIENT_ID}}' => $patientData['patient_id'] ?? '',
            '{{CURRENT_DATE}}' => date('d-m-Y'),
            '{{DOCTOR_NAME}}' => $doctorDetails['name'] ?? '',
            '{{DOCTOR_REG}}' => $doctorDetails['registration_no'] ?? '',
            '{{DEPARTMENT}}' => $doctorDetails['department'] ?? '',
            '{{ISSUE_DATE}}' => $_POST['issueDate'] ?? date('d-m-Y'),
            '{{LEAVE_DAYS}}' => $_POST['leaveDays'] ?? '',
            '{{LEAVE_START}}' => $_POST['leaveStartDate'] ?? '',
            '{{LEAVE_END}}' => $_POST['endDate'] ?? '',
            '{{HOSPITAL_NAME}}' => HOSPITAL_NAME,
            '{{HOSPITAL_ADDRESS}}' => HOSPITAL_ADDRESS,
            '{{DIAGNOSIS}}' => $_POST['diagnosis'] ?? '',
            '{{CERTIFICATE_NUMBER}}' => $_POST['certificateNumber'] ?? '',
            '{{SURGERY_DATE}}' => $_POST['surgeryDate'] ?? '',
            '{{PROCEDURE_PERFORMED}}' => $_POST['procedurePerformed'] ?? '',
            '{{IMPLANT_CHECK_DATE}}' => $_POST['implantCheckDate'] ?? '',

        ]);
    }

    return $template;
}

function generateCertificateNumber($conn)
{
    // Get fiscal year in YYYY-YY format
    $currentFY = getCurrentFinancialYear(); // e.g., "2023-24"
    $fy = substr($currentFY, 0, 4).'-'.substr($currentFY, -2); // Convert to "2023-24"

    // Get max serial number for current financial year pattern
    $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(certificate_number, '/', -1) AS UNSIGNED)) as max_number 
                           FROM certificates 
                           WHERE certificate_number LIKE ?");

    $pattern = "CERT/MNH/{$fy}/%";
    $stmt->bind_param('s', $pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    // Start from 1 if no certificates exist for this FY, otherwise increment max
    $serial = $row['max_number'] ? intval($row['max_number']) + 1 : 1;

    // Format: CERT/MNH/YYYY-YY/serial_number
    return sprintf('CERT/MNH/%s/%d', $fy, $serial);
}

// Handle API requests
if ('GET' === $_SERVER['REQUEST_METHOD'] && isset($_GET['search'])) {
    $patientId = htmlspecialchars($_GET['search'] ?? '', ENT_QUOTES, 'UTF-8');
    $patient = getPatientDetails($conn, $patientId);
    exit(json_encode([
        'found' => null !== $patient,
        'patient' => $patient,
    ]));
}

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $conn = getDbConnection();
    $action = htmlspecialchars($_POST['action'] ?? '', ENT_QUOTES, 'UTF-8');
    $patientId = htmlspecialchars($_POST['patientId'] ?? '', ENT_QUOTES, 'UTF-8');

    switch ($action) {
        case 'getPatient':
            exit(json_encode($patientId ? getPatientDetails($conn, $patientId) : ['error' => 'Invalid patient ID']));

        case 'getTemplate':
            $type = htmlspecialchars($_POST['type'] ?? '', ENT_QUOTES, 'UTF-8');
            $templates = loadTemplates();
            if (!$type || !isset($templates[$type])) {
                exit(json_encode(['error' => 'Invalid template type']));
            }
            $patientData = $patientId ? getPatientDetails($conn, $patientId) : null;
            $certificateNumber = generateCertificateNumber($conn);
            exit(json_encode([
                'template' => getCertificateTemplate($type, $patientData),
                'certificateNumber' => $certificateNumber,
            ]));

        case 'createCertificate':
            $certificateNumber = generateCertificateNumber($conn);
            $doctorId = $_POST['doctorId'] ?? $_SESSION['user_id'] ?? null;
            $certificateType = htmlspecialchars($_POST['certificateType'] ?? '', ENT_QUOTES, 'UTF-8');
            $issueDate = formatDateDMY($_POST['issueDate']);
            $diagnosis = htmlspecialchars($_POST['diagnosis'] ?? '', ENT_QUOTES, 'UTF-8');
            $startDate = formatDateDMY($_POST['startDate']);
            $endDate = formatDateDMY($_POST['endDate']);
            $details = htmlspecialchars($_POST['details'] ?? '', ENT_QUOTES, 'UTF-8');

            $stmt = $conn->prepare('INSERT INTO certificates (certificate_number, patient_id, doctor_id, certificate_type, issue_date, diagnosis, start_date, end_date, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssissssss', $certificateNumber, $patientId, $doctorId, $certificateType, $issueDate, $diagnosis, $startDate, $endDate, $details);
            $stmt->execute();

            exit(json_encode(['success' => true, 'certificateNumber' => $certificateNumber]));

        case 'deleteCertificate':
            $certificateId = htmlspecialchars($_POST['certificateId'] ?? '', ENT_QUOTES, 'UTF-8');
            if (!$certificateId) {
                exit(json_encode(['error' => 'Invalid certificate ID']));
            }

            $stmt = $conn->prepare('DELETE FROM certificates WHERE id = ?');
            $stmt->bind_param('i', $certificateId);
            if ($stmt->execute()) {
                exit(json_encode(['success' => true]));
            }
            exit(json_encode(['error' => 'Failed to delete certificate']));


        case 'editCertificate':
            $certificateId = htmlspecialchars($_POST['certificateId'] ?? '', ENT_QUOTES, 'UTF-8');
            $diagnosis = htmlspecialchars($_POST['diagnosis'] ?? '', ENT_QUOTES, 'UTF-8');
            $startDate = htmlspecialchars($_POST['startDate'] ?? '', ENT_QUOTES, 'UTF-8');
            $endDate = htmlspecialchars($_POST['endDate'] ?? '', ENT_QUOTES, 'UTF-8');
            $details = htmlspecialchars($_POST['details'] ?? '', ENT_QUOTES, 'UTF-8');

            if (!$certificateId) {
                exit(json_encode(['error' => 'Invalid certificate ID']));
            }

            $stmt = $conn->prepare('UPDATE certificates SET diagnosis = ?, start_date = ?, end_date = ?, details = ? WHERE id = ?');
            $stmt->bind_param('ssssi', $diagnosis, $startDate, $endDate, $details, $certificateId);
            if ($stmt->execute()) {
                exit(json_encode(['success' => true]));
            }
            exit(json_encode(['error' => 'Failed to update certificate']));


        case 'getCertificates':
            try {
                $patientId = htmlspecialchars($_POST['patientId'] ?? '', ENT_QUOTES, 'UTF-8');
                if (!$patientId) {
                    throw new Exception('Invalid patient ID');
                }

                $stmt = $conn->prepare('SELECT 
                    DATE_FORMAT(start_date, "%d-%m-%Y") as start_date,
                    DATE_FORMAT(end_date, "%d-%m-%Y") as end_date,
                    diagnosis,
                    DATE_FORMAT(issue_date, "%d-%m-%Y") as issue_date,
                    certificate_type 
                    FROM certificates 
                    WHERE patient_id = ? 
                    ORDER BY issue_date DESC');

                if (!$stmt) {
                    throw new Exception('Database error: '.$conn->error);
                }

                $stmt->bind_param('s', $patientId);

                if (!$stmt->execute()) {
                    throw new Exception('Failed to execute query');
                }

                $result = $stmt->get_result();
                $certificates = [];

                while ($row = $result->fetch_assoc()) {
                    $certificates[] = $row;
                }

                echo json_encode([
                    'success' => true,
                    'certificates' => $certificates,
                ]);
                exit;

            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                ]);
                exit;
            }

        case 'saveCertificate':
            try {
                $certificateNumber = htmlspecialchars($_POST['certificateNumber'] ?? '', ENT_QUOTES, 'UTF-8');
                $doctorId = $_POST['doctorId'] ?? $_SESSION['user_id'] ?? null;
                $certificateType = htmlspecialchars($_POST['certificateType'] ?? '', ENT_QUOTES, 'UTF-8');
                $issueDate = date('Y-m-d', strtotime($_POST['issueDate']));
                $diagnosis = htmlspecialchars($_POST['diagnosis'] ?? '', ENT_QUOTES, 'UTF-8');

                // Handle leave certificate specific fields
                $startDate = null;
                $endDate = null;

                if ('leave' === $certificateType) {
                    $startDate = date('Y-m-d', strtotime($_POST['leaveStartDate']));
                    $endDate = date('Y-m-d', strtotime($_POST['endDate']));
                }

                // Build details JSON
                $details = json_encode([
                    'type' => $certificateType,
                    'leaveDays' => $_POST['leaveDays'] ?? null,
                    'surgeryDate' => $_POST['surgeryDate'] ?? null,
                    'procedurePerformed' => $_POST['procedurePerformed'] ?? null,
                    'implantCheckDate' => $_POST['implantCheckDate'] ?? null,
                ]);

                $stmt = $conn->prepare('INSERT INTO certificates (
                    certificate_number, 
                    patient_id, 
                    doctor_id, 
                    certificate_type,
                    issue_date,
                    diagnosis,
                    start_date,
                    end_date,
                    details
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

                $stmt->bind_param(
                    'ssissssss',
                    $certificateNumber,
                    $patientId,
                    $doctorId,
                    $certificateType,
                    $issueDate,
                    $diagnosis,
                    $startDate,
                    $endDate,
                    $details
                );

                if (!$stmt->execute()) {
                    throw new Exception('Failed to save certificate: '.$stmt->error);
                }

                exit(json_encode([
                    'success' => true,
                    'message' => 'Certificate saved successfully',
                    'certificateId' => $conn->insert_id,
                ]));

            } catch (Exception $e) {
                http_response_code(500);
                exit(json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                ]));
            }

        case 'getCertificateDetails':
            try {
                $certificateId = htmlspecialchars($_POST['certificateId'] ?? '', ENT_QUOTES, 'UTF-8');
                if (!$certificateId) {
                    throw new Exception('Invalid certificate ID');
                }

                $stmt = $conn->prepare('SELECT 
                    id,
                    diagnosis,
                    DATE_FORMAT(start_date, "%Y-%m-%d") as start_date,
                    DATE_FORMAT(end_date, "%Y-%m-%d") as end_date,
                    details
                    FROM certificates 
                    WHERE id = ?');

                if (!$stmt) {
                    throw new Exception('Database error: '.$conn->error);
                }

                $stmt->bind_param('i', $certificateId);

                if (!$stmt->execute()) {
                    throw new Exception('Failed to execute query');
                }

                $result = $stmt->get_result();
                $certificate = $result->fetch_assoc();

                if (!$certificate) {
                    throw new Exception('Certificate not found');
                }

                echo json_encode([
                    'success' => true,
                    'certificate' => $certificate,
                ]);
                exit;

            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                ]);
                exit;
            }

        case 'getCertificateForPrint':
            try {
                header('Content-Type: application/json');

                $certificateId = htmlspecialchars($_POST['certificateId'] ?? '', ENT_QUOTES, 'UTF-8');
                $type = htmlspecialchars($_POST['type'] ?? '', ENT_QUOTES, 'UTF-8');

                if (!$certificateId || !$type) {
                    throw new Exception('Invalid certificate details');
                }

                $stmt = $conn->prepare('SELECT c.*, p.name as patient_name, p.age, 
                                      CONCAT_WS(", ", p.address, p.city, p.state) as full_address,
                                      d.name as doctor_name, d.registration_no, dp.name as department
                                      FROM certificates c
                                      LEFT JOIN patients p ON c.patient_id = p.patient_id
                                      LEFT JOIN doctors d ON c.doctor_id = d.doctor_id
                                      LEFT JOIN departments dp ON d.dept_id = dp.dept_id
                                      WHERE c.id = ?');

                if (!$stmt) {
                    throw new Exception('Database error: '.$conn->error);
                }

                $stmt->bind_param('i', $certificateId);

                if (!$stmt->execute()) {
                    throw new Exception('Failed to execute query: '.$stmt->error);
                }

                $result = $stmt->get_result();
                $certificate = $result->fetch_assoc();

                if (!$certificate) {
                    throw new Exception('Certificate not found');
                }

                $templates = loadTemplates();
                if (!isset($templates[$type])) {
                    throw new Exception('Template not found');
                }

                $template = $templates[$type];
                $template['content'] = strtr($template['content'], [
                    '{{CERTIFICATE_NUMBER}}' => $certificate['certificate_number'],
                    '{{PATIENT_NAME}}' => $certificate['patient_name'],
                    '{{PATIENT_AGE}}' => $certificate['age'],
                    '{{PATIENT_ADDRESS}}' => $certificate['full_address'],
                    '{{DIAGNOSIS}}' => $certificate['diagnosis'],
                    '{{ISSUE_DATE}}' => formatDateDMY($certificate['issue_date']),
                    '{{DOCTOR_NAME}}' => $certificate['doctor_name'],
                    '{{DOCTOR_REG}}' => $certificate['registration_no'],
                    '{{DEPARTMENT}}' => $certificate['department'],
                    '{{HOSPITAL_NAME}}' => HOSPITAL_NAME,
                    '{{HOSPITAL_ADDRESS}}' => HOSPITAL_ADDRESS,
                    '{{LEAVE_DAYS}}' => json_decode($certificate['details'], true)['leaveDays'] ?? '',
                    '{{LEAVE_START}}' => formatDateDMY($certificate['start_date']),
                    '{{LEAVE_END}}' => formatDateDMY($certificate['end_date']),
                ]);

                echo json_encode([
                    'success' => true,
                    'template' => $template,
                ]);
                exit;

            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                ]);
                exit;
            }
    }
}
