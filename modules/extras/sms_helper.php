<?php

// SMS helper functions

function sendRegistrationSMS($patientId, $patientName, $mobileNumber, $language = 'H')
{
    $conn = getDbConnection();

    // Check if SMS was already sent
    $stmt = $conn->prepare('SELECT sms_sent FROM patients WHERE patient_id = ?');
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    if ($result['sms_sent']) {
        return true; // SMS already sent
    }

    // Get registration template with language suffix
    $templateName = 'patient_registration_' . $language;
    $stmt = $conn->prepare("SELECT * FROM sms_templates WHERE template_name = ?");
    $stmt->bind_param('s', $templateName);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();

    if (!$template) {
        return false;
    }

    // Replace variables in template
    $message = str_replace(
        ['{patient_name}', '{patient_id}'],
        [$patientName, $patientId],
        $template['template_text']
    );

    // Send SMS
    $result = sendSMS($message, $mobileNumber);

    if ($result) {
        // Begin transaction for related updates
        $conn->begin_transaction();
        
        try {
            // Update patient record
            $stmt = $conn->prepare('UPDATE patients SET sms_sent = 1 WHERE patient_id = ?');
            $stmt->bind_param('i', $patientId);
            $stmt->execute();

            // Log SMS with message_type
            $stmt = $conn->prepare("INSERT INTO sms_logs (patient_id, mobile_number, message, template_id, 
                                 message_type, sms_type, status, sent_at) 
                                 VALUES (?, ?, ?, ?, 'SMS', 'REGISTRATION', 'SENT', NOW())");
            $stmt->bind_param('issi', $patientId, $mobileNumber, $message, $template['template_id']);
            $stmt->execute();

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log('SMS Log Error: ' . $e->getMessage());
            return false;
        }
    }

    return $result;
}

function sendAppointmentReminder($patientId, $patientName, $mobileNumber, $doctorName, 
                               $appointmentDate, $appointmentTime, $language = 'H')
{
    $conn = getDbConnection();

    // Get reminder template with language suffix
    $templateName = 'appointment_reminder_' . $language;
    $stmt = $conn->prepare("SELECT * FROM sms_templates WHERE template_name = ?");
    $stmt->bind_param('s', $templateName);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();

    if (!$template) {
        return false;
    }

    // Replace variables in template
    $message = str_replace(
        ['{patient_name}', '{doctor_name}', '{appointment_date}', '{appointment_time}'],
        [$patientName, $doctorName, $appointmentDate, $appointmentTime],
        $template['template_text']
    );

    // Begin transaction for logging
    $conn->begin_transaction();
    
    try {
        // Log SMS before sending
        $stmt = $conn->prepare("INSERT INTO sms_logs (patient_id, mobile_number, message, template_id, 
                             message_type, sms_type, status) 
                             VALUES (?, ?, ?, ?, 'SMS', 'APPOINTMENT', 'PENDING')");
        $stmt->bind_param('issi', $patientId, $mobileNumber, $message, $template['template_id']);
        $stmt->execute();
        $logId = $conn->insert_id;

        // Send SMS
        $result = sendSMS($message, $mobileNumber);

        // Update log status
        $status = $result ? 'SENT' : 'FAILED';
        $stmt = $conn->prepare('UPDATE sms_logs SET status = ?, sent_at = NOW() WHERE log_id = ?');
        $stmt->bind_param('si', $status, $logId);
        $stmt->execute();

        $conn->commit();
        return $result;
    } catch (Exception $e) {
        $conn->rollback();
        error_log('SMS Error: ' . $e->getMessage());
        return false;
    }
}
