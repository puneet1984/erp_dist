<?php

define('SECURE_ACCESS', true);
require_once '../../includes/core.php';
require_once '../../includes/helpers/send_sms.php';
require_once '../../includes/helpers.php';

if (!checkPermission('admin', 'edit')) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit;
}

$provider = $_POST['provider'] ?? '';
$testNumber = sanitizeInput($_POST['test_number'] ?? '');
$testNumber = validateMobile($testNumber);

// Validate phone number
if (empty($testNumber)) {
    echo json_encode(['status' => 'error', 'message' => 'Test number is required']);
    exit;
}

$response = ['status' => 'error', 'message' => 'Unknown provider'];

switch ($provider) {
    case 'local_api':
        // Prepare the message
        $message = "This is a test SMS from your system";
        // Send SMS
        $smsResponse = sendSMS($message, $testNumber);

        if ($smsResponse) {
            $response = ['status' => 'success', 'message' => 'Test SMS sent successfully'];
        } else {
            $response = ['status' => 'error', 'message' => 'Failed to send test SMS'];
        }
        break;

    case 'textlocal':
        // Implement Textlocal test
        break;

    case 'twilio':
        // Implement Twilio test
        break;

    case 'msg91':
        // Implement MSG91 test
        break;
}

header('Content-Type: application/json');
echo json_encode($response);
