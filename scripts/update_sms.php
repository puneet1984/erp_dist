<?php
define('SECURE_ACCESS', true);
require_once '../includes/core.php';
// require_once '../../includes/core.php';

$conn = getDbConnection();

// Find SMS logs with empty messages
$query = "SELECT sl.log_id, sl.patient_id, sl.mobile_number, p.name 
          FROM sms_logs sl 
          INNER JOIN patients p ON p.patient_id = sl.patient_id 
          WHERE sl.message IS NULL OR sl.message = ''";

$result = $conn->query($query);

if ($result->num_rows > 0) {
    // Get registration template
    $template_name = 'patient_registration_H';
    $template_stmt = $conn->prepare("SELECT template_text FROM sms_templates WHERE template_name = ?");
    $template_stmt->bind_param('s', $template_name);
    $template_stmt->execute();
    $template_result = $template_stmt->get_result()->fetch_assoc();
    
    if (!$template_result) {
        die("SMS template not found!");
    }

    // Prepare update statement
    $update_stmt = $conn->prepare("UPDATE sms_logs SET message = ? WHERE log_id = ?");

    while ($row = $result->fetch_assoc()) {
        $message = str_replace(
            ['{patient_name}', '{patient_id}'],
            [
                $row['name'],
                'MNH'.str_pad($row['patient_id'], 8, '0', STR_PAD_LEFT)
            ],
            $template_result['template_text']
        );

        $update_stmt->bind_param('si', $message, $row['log_id']);
        $update_stmt->execute();
        
        echo "Updated SMS log for patient ID: {$row['patient_id']}\n";
    }

    echo "\nUpdate completed successfully!";
} else {
    echo "No SMS logs found with empty messages.";
}

$conn->close();
