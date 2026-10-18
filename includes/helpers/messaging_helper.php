<?php

require_once __DIR__ . '/../sms_service/send_sms.php';
require_once __DIR__ . '/../wa_service/whatsapp_sender.php';

function formatMobileNumber($number) {
    $number = preg_replace('/[^0-9]/', '', $number);
    
    if (strlen($number) === 10) {
        return $number;
    }
    
if (12 === strlen($number) && '91' === substr($number, 0, 2)) {
    return substr($number, 2);
}
    
    return $number;
}

function sendWhatsApp($number, $message) {
    try {
        $result = sendWhatsAppMessage($number, $message);
        return ['status' => ($result['success'] ?? false) ? 'success' : 'failed', 'medium' => 'whatsapp'];
    } catch (Exception $e) {
        return ['status' => 'failed', 'error' => $e->getMessage(), 'medium' => 'whatsapp'];
    }
}

function sendSMS1($number, $message) {
    try {
        $number = formatMobileNumber($number);
        $result = sendSMS($message, $number);
        return ['status' => $result ? 'success' : 'failed', 'medium' => 'sms'];
    } catch (Exception $e) {
        return ['status' => 'failed', 'error' => $e->getMessage(), 'medium' => 'sms'];
    }
}

function sendByBoth($number, $message) {
    $number = formatMobileNumber($number);
    
    // Try WhatsApp first
    $waResult = sendWhatsApp($number, $message);
    if ($waResult['status'] === 'success') {
        return $waResult;
    }
    
    // Fallback to SMS if WhatsApp fails
    return sendSMS1($number, $message);
}
