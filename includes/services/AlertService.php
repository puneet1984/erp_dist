<?php

require_once __DIR__.'/../helpers/messaging_helper.php';

// Global variable to store alert numbers
$alertNumbers = [];

// Initialize alert numbers from JSON file
function initializeAlertNumbers()
{
    global $alertNumbers;
    $jsonFile = __DIR__.'/../../assets/json/alert_numbers.json';
    if (file_exists($jsonFile)) {
        $config = json_decode(file_get_contents($jsonFile), true);
        if (JSON_ERROR_NONE === json_last_error()) {
            $alertNumbers = $config['stock_alerts'] ?? [];
        }
    }
}

// Initialize on inclusion
initializeAlertNumbers();

function getTemplate($templateName, $language = 'E')
{
    $conn = getDbConnection();
    $templateId = $templateName.'_'.$language;
    $stmt = $conn->prepare('SELECT * FROM sms_templates WHERE template_name = ?');
    $stmt->bind_param('s', $templateId);
    $stmt->execute();

    return $stmt->get_result()->fetch_assoc();
}

function logMessage($patientId, $number, $message, $templateId, $messageType, $smsType, $status, $error = null)
{
    $conn = getDbConnection();
    $stmt = $conn->prepare(
        'INSERT INTO sms_logs (patient_id, mobile_number, message, template_id, 
        message_type, sms_type, status, error_message, sent_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->bind_param(
        'ississss',
        $patientId,
        $number,
        $message,
        $templateId,
        $messageType,
        $smsType,
        $status,
        $error
    );

    return $stmt->execute();
}

function sendPatientRegistration($patientId, $patientName, $number, $language = 'H')
{
    $template = getTemplate('patient_registration', $language);
    if (!$template) {
        return false;
    }

    $message = str_replace(
        ['{patient_name}', '{patient_id}'],
        [$patientName, $patientId],
        $template['template_text']
    );

    $result = sendByBoth($number, $message);
    logMessage(
        $patientId,
        $number,
        $message,
        $template['template_id'],
        $result['medium'],
        'REGISTRATION',
        $result['status'],
        $result['error'] ?? null
    );

    return $result;
}

function sendAppointmentBooking($patientId, $data, $language = 'H')
{
    $template = getTemplate('appointment_booking', $language);
    if (!$template) {
        return false;
    }

    $message = str_replace(
        ['{patient_name}', '{doctor_name}', '{appointment_date}', '{appointment_time}'],
        [$data['patient_name'], $data['doctor_name'], $data['date'], $data['time']],
        $template['template_text']
    );

    $result = sendByBoth($data['number'], $message);
    logMessage(
        $patientId,
        $data['number'],
        $message,
        $template['template_id'],
        $result['medium'],
        'APPOINTMENT',
        $result['status'],
        $result['error'] ?? null
    );

    return $result;
}

function sendAppointmentReminder($patientId, $data, $language = 'H')
{
    $template = getTemplate('appointment_reminder', $language);
    if (!$template) {
        return false;
    }

    $message = str_replace(
        ['{patient_name}', '{doctor_name}', '{appointment_time}'],
        [$data['patient_name'], $data['doctor_name'], $data['time']],
        $template['template_text']
    );

    $result = sendByBoth($data['number'], $message);
    logMessage(
        $patientId,
        $data['number'],
        $message,
        $template['template_id'],
        $result['medium'],
        'REMINDER',
        $result['status'],
        $result['error'] ?? null
    );

    return $result;
}

function sendStockLowAlert($itemName, $quantity, $itemId)
{
    global $alertNumbers;

    if (empty($alertNumbers)) {
        return ['status' => 'failed', 'error' => 'No alert numbers configured'];
    }

    $template = getTemplate('stock_low_alert');
    if (!$template) {
        return false;
    }

    $message = str_replace(
        ['{item_name}', '{quantity}'],
        [$itemName, $quantity],
        $template['template_text']
    );

    $finalResult = ['status' => 'failed', 'sent_to' => []];
    foreach ($alertNumbers as $number) {
        $result = sendWhatsApp($number, $message);
        if ('success' === $result['status']) {
            $finalResult['sent_to'][] = $number;
        }

        logMessage(
            null,
            $number,
            $message,
            $template['template_id'],
            'WHATSAPP',
            'STOCK',
            $result['status'],
            $result['error'] ?? null
        );
    }

    // Update stock alert log if at least one message was sent
    if (!empty($finalResult['sent_to'])) {
        $conn = getDbConnection();
        $stmt = $conn->prepare(
            "INSERT INTO stock_alert_logs (item_id, alert_type, last_sent_at) 
            VALUES (?, 'LOW_STOCK', NOW()) 
            ON DUPLICATE KEY UPDATE last_sent_at = NOW()"
        );
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $finalResult['status'] = 'success';
    }

    return $finalResult;
}

function sendStockExpiryAlert($data)
{
    global $alertNumbers;

    if (empty($alertNumbers)) {
        return ['status' => 'failed', 'error' => 'No alert numbers configured'];
    }

    $template = getTemplate('stock_expiry_alert');
    if (!$template) {
        return false;
    }

    $message = str_replace(
        ['{item_name}', '{batch_number}', '{quantity}', '{expiry_date}'],
        [$data['item_name'], $data['batch'], $data['quantity'], $data['expiry']],
        $template['template_text']
    );

    $finalResult = ['status' => 'failed', 'sent_to' => []];
    foreach ($alertNumbers as $number) {
        $result = sendWhatsApp($number, $message);
        if ('success' === $result['status']) {
            $finalResult['sent_to'][] = $number;
        }

        logMessage(
            null,
            $number,
            $message,
            $template['template_id'],
            'WHATSAPP',
            'STOCK',
            $result['status'],
            $result['error'] ?? null
        );
    }

    // Update stock alert log if at least one message was sent
    if (!empty($finalResult['sent_to'])) {
        $conn = getDbConnection();
        $stmt = $conn->prepare(
            "INSERT INTO stock_alert_logs (item_id, alert_type, last_sent_at) 
            VALUES (?, 'EXPIRING_SOON', NOW()) 
            ON DUPLICATE KEY UPDATE last_sent_at = NOW()"
        );
        $stmt->bind_param('i', $data['item_id']);
        $stmt->execute();
        $finalResult['status'] = 'success';
    }

    return $finalResult;
}
