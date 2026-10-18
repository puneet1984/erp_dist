<?php

function sendSMSViaFlask($message, $phoneNumber)
{
    $flaskEndpoint = 'http://127.0.0.1:5000/send-sms';

    $data = json_encode([
        'message' => $message,
        'phone_number' => $phoneNumber,
    ]);

    $ch = curl_init($flaskEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log('Flask API Error: '.curl_error($ch));
        curl_close($ch);

        return false;
    }

    curl_close($ch);
    $result = json_decode($response, true);

    return isset($result['status']) && 'success' === $result['status'];
}

function sendRegistrationSMS($patientId, $patientName, $mobileNumber, $language = 'H')
{
    $conn = getDbConnection();

    // Check if SMS was already sent
    $stmt = $conn->prepare('SELECT sms_sent FROM patients WHERE patient_id = ?');
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    if ($result['sms_sent']) {
        return true;
    }

    // Get registration template
    $templateName = 'patient_registration_'.$language;
    $stmt = $conn->prepare('SELECT * FROM sms_templates WHERE template_name = ?');
    $stmt->bind_param('s', $templateName);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();

    if (!$template) {
        return false;
    }

    $message = str_replace(
        ['{patient_name}', '{patient_id}'],
        [$patientName, $patientId],
        $template['template_text']
    );

    // Send SMS using Flask API
    $result = sendSMSViaFlask($message, $mobileNumber);

    if ($result) {
        $conn->begin_transaction();
        try {
            // Update patient record
            $stmt = $conn->prepare('UPDATE patients SET sms_sent = 1 WHERE patient_id = ?');
            $stmt->bind_param('i', $patientId);
            $stmt->execute();

            // Log SMS
            $stmt = $conn->prepare("INSERT INTO sms_logs (patient_id, mobile_number, message, template_id, 
                                 message_type, sms_type, status, sent_at) 
                                 VALUES (?, ?, ?, ?, 'SMS', 'REGISTRATION', 'SENT', NOW())");
            $stmt->bind_param('issi', $patientId, $mobileNumber, $message, $template['template_id']);
            $stmt->execute();

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log('SMS Log Error: '.$e->getMessage());

            return false;
        }
    }

    return $result;
}

function sendAppointmentReminder(
    $patientId,
    $patientName,
    $mobileNumber,
    $doctorName,
    $appointmentDate,
    $appointmentTime,
    $language = 'H'
) {
    $conn = getDbConnection();

    // Get reminder template
    $templateName = 'appointment_reminder_'.$language;
    $stmt = $conn->prepare('SELECT * FROM sms_templates WHERE template_name = ?');
    $stmt->bind_param('s', $templateName);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();

    if (!$template) {
        return false;
    }

    $message = str_replace(
        ['{patient_name}', '{doctor_name}', '{appointment_date}', '{appointment_time}'],
        [$patientName, $doctorName, $appointmentDate, $appointmentTime],
        $template['template_text']
    );

    $conn->begin_transaction();
    try {
        // Log SMS before sending
        $stmt = $conn->prepare("INSERT INTO sms_logs (patient_id, mobile_number, message, template_id, 
                             message_type, sms_type, status) 
                             VALUES (?, ?, ?, ?, 'SMS', 'APPOINTMENT', 'PENDING')");
        $stmt->bind_param('issi', $patientId, $mobileNumber, $message, $template['template_id']);
        $stmt->execute();
        $logId = $conn->insert_id;

        // Send SMS using Flask API
        $result = sendSMSViaFlask($message, $mobileNumber);

        // Update log status
        $status = $result ? 'SENT' : 'FAILED';
        $stmt = $conn->prepare('UPDATE sms_logs SET status = ?, sent_at = NOW() WHERE log_id = ?');
        $stmt->bind_param('si', $status, $logId);
        $stmt->execute();

        $conn->commit();

        return $result;
    } catch (Exception $e) {
        $conn->rollback();
        error_log('SMS Error: '.$e->getMessage());

        return false;
    }
}
