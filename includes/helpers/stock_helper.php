<?php

function getLowStockItems($twoWeeksAgo)
{
    $conn = getDbConnection();

    $query = "
        SELECT i.item_id, i.name, b.batch_number, b.current_quantity, b.expiry_date,
               sal.sent_at
        FROM pharmacy_items i
        JOIN pharmacy_batches b ON i.item_id = b.item_id
        LEFT JOIN stock_alert_logs sal ON i.item_id = sal.item_id 
            AND sal.alert_type = 'LOW_STOCK'
        WHERE b.current_quantity <= i.reorder_level
        AND (sal.sent_at IS NULL OR sal.sent_at < ?)";

    $stmt = $conn->prepare($query);
    $stmt->bind_param('s', $twoWeeksAgo);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getExpiringItems($currentDate, $threeMonthsFromNow, $twoWeeksAgo)
{
    $conn = getDbConnection();

    $query = "
        SELECT i.item_id, i.name, b.batch_number, b.current_quantity, b.expiry_date,
               sal.sent_at
        FROM pharmacy_items i
        JOIN pharmacy_batches b ON i.item_id = b.item_id
        LEFT JOIN stock_alert_logs sal ON i.item_id = sal.item_id 
            AND sal.alert_type = 'EXPIRING_SOON'
        WHERE b.expiry_date BETWEEN ? AND ?
        AND b.current_quantity > 0
        AND (sal.sent_at IS NULL OR sal.sent_at < ?)";

    $stmt = $conn->prepare($query);
    $stmt->bind_param('sss', $currentDate, $threeMonthsFromNow, $twoWeeksAgo);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function updateStockAlertLog($itemId, $alertType)
{
    $conn = getDbConnection();

    $stmt = $conn->prepare('
        INSERT INTO stock_alert_logs (item_id, alert_type, sent_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE sent_at = NOW()
    ');
    $stmt->bind_param('is', $itemId, $alertType);

    return $stmt->execute();
}

function getTemplate($templateName, $language)
{
    $conn = getDbConnection();

    $fullTemplateName = $templateName.'_'.$language;
    $stmt = $conn->prepare('SELECT * FROM sms_templates WHERE template_name = ?');
    $stmt->bind_param('s', $fullTemplateName);
    $stmt->execute();

    return $stmt->get_result()->fetch_assoc();
}

function logMessage($patientId, $mobileNumber, $templateId, $message, $messageType, $smsType, $status, $errorMessage = null)
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
        $mobileNumber,
        $message,
        $templateId,
        $messageType,
        $smsType,
        $status,
        $errorMessage
    );

    return $stmt->execute();
}
