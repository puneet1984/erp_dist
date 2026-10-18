<?php

// WAAPI Configuration - Hardcoded defaults (override via environment if available)
$WAAPI_API_URL = getenv('WAAPI_API_URL') ?: 'http://192.168.1.61:3001';
$WAAPI_CLIENT_ID = getenv('WAAPI_CLIENT_ID') ?: 'default';
$WAAPI_API_KEY = getenv('WAAPI_API_KEY') ?: ''; // Set if AUTH_ENABLED=true on WAAPI server

// WAAPI Endpoints (minimal build)
$WAAPI_SEND_FILE_ENDPOINT = '/api/send-file-base64';
$WAAPI_SEND_TEXT_ENDPOINT = '/api/send';
$WAAPI_CHECK_CONTACT_ENDPOINT = '/api/check-contact';

function sendWhatsAppDocument($phoneNumber, $documentUrl, $caption = '', $filename = '', $mimetype = 'application/pdf')
{
    global $WAAPI_API_URL, $WAAPI_CLIENT_ID, $WAAPI_API_KEY, $WAAPI_SEND_FILE_ENDPOINT;

    // Format phone number for WAAPI (should include country code)
    $phoneNumber = formatPhoneNumber($phoneNumber);
    
    // Validate inputs
    if (empty($phoneNumber)) {
        return ['success' => false, 'error' => 'Phone number is required'];
    }
    
    if (empty($documentUrl)) {
        return ['success' => false, 'error' => 'Document URL is required'];
    }
    
    // Fetch the document and encode to base64 for WAAPI
    $fileContent = @file_get_contents($documentUrl);
    if (false === $fileContent) {
        return ['success' => false, 'error' => 'Unable to fetch document for WhatsApp'];
    }

    $base64Data = base64_encode($fileContent);

    if (empty($filename)) {
        $path = parse_url($documentUrl, PHP_URL_PATH);
        $filename = $path ? basename($path) : 'document.pdf';
    }

    $params = [
        'number' => $phoneNumber,
        'base64Data' => $base64Data,
        'mimetype' => $mimetype ?: 'application/octet-stream',
        'filename' => $filename,
        'caption' => $caption,
        'clientId' => $WAAPI_CLIENT_ID,
    ];

    // Log the request for debugging (without base64 data for brevity)
    $logParams = $params;
    $logParams['base64Data'] = 'omitted';
    error_log('WAAPI document send request: ' . json_encode($logParams));

    return sendWhatsAppPostRequest($WAAPI_API_URL, $WAAPI_SEND_FILE_ENDPOINT, $params, $WAAPI_API_KEY);
}

function sendWhatsAppMessage($phoneNumber, $message)
{
    global $WAAPI_API_URL, $WAAPI_CLIENT_ID, $WAAPI_API_KEY, $WAAPI_SEND_TEXT_ENDPOINT;

    $phoneNumber = formatPhoneNumber($phoneNumber);

    $params = [
        'number' => $phoneNumber,
        'message' => $message,
        'clientId' => $WAAPI_CLIENT_ID,
    ];

    return sendWhatsAppPostRequest($WAAPI_API_URL, $WAAPI_SEND_TEXT_ENDPOINT, $params, $WAAPI_API_KEY);
}

function formatPhoneNumber($phoneNumber)
{
    // Clean phone number (remove +, spaces, etc.)
    $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

    // Add country code if not present
    if (!str_starts_with($phoneNumber, '91') && 10 == strlen($phoneNumber)) {
        $phoneNumber = '91'.$phoneNumber;
    }

    return $phoneNumber;
}

// Add new POST request function
function sendWhatsAppPostRequest($apiUrl, $endpoint, $data, $apiKey = '')
{
    $url = $apiUrl . $endpoint;
    
    // Log the request for debugging
    error_log("WhatsApp API POST URL: " . $url);
    error_log("WhatsApp API POST Data: " . json_encode($data));

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    if (!empty($apiKey)) {
        $headers[] = 'x-api-key: ' . $apiKey;
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);
    
    // Log response for debugging
    error_log("WhatsApp API POST response - HTTP Code: $httpCode, Response: $response");
    
    if (!empty($curlError)) {
        error_log("WhatsApp API POST cURL error: $curlError");
        return ['success' => false, 'error' => $curlError];
    }

    $decodedResponse = json_decode($response, true);
    
    // Check if response is valid JSON
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("WhatsApp API POST invalid JSON response: $response");
        return [
            'success' => false, 
            'error' => 'Invalid JSON response from WhatsApp API',
            'http_code' => $httpCode,
            'raw_response' => $response
        ];
    }

    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'response' => $decodedResponse,
        'http_code' => $httpCode,
    ];
}

function checkWhatsAppContactExists($phoneNumber)
{
    global $WAAPI_API_URL, $WAAPI_CLIENT_ID, $WAAPI_API_KEY, $WAAPI_CHECK_CONTACT_ENDPOINT;

    $phoneNumber = formatPhoneNumber($phoneNumber);

    if (empty($phoneNumber)) {
        return ['success' => false, 'exists' => false, 'chatId' => null, 'error' => 'Phone number is required'];
    }

    $params = [
        'number' => $phoneNumber,
        'clientId' => $WAAPI_CLIENT_ID,
    ];

    $result = sendWhatsAppPostRequest($WAAPI_API_URL, $WAAPI_CHECK_CONTACT_ENDPOINT, $params, $WAAPI_API_KEY);

    if ($result['success'] && isset($result['response']['exists'])) {
        return [
            'success' => true,
            'exists' => (bool) $result['response']['exists'],
            'chatId' => $result['response']['chatId'] ?? null,
            'response' => $result['response'],
        ];
    }

    return [
        'success' => false,
        'exists' => false,
        'chatId' => null,
        'error' => $result['response']['error'] ?? 'Failed to check contact existence',
        'response' => $result['response'] ?? null,
    ];
}

function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    return $protocol . '://' . $host . $scriptPath;
}

function sendPrescriptionWhatsApp($prescriptionId, $patientPhone) {
    // This is a wrapper function that can be called from other scripts
    $postData = [
        'prescription_id' => $prescriptionId,
        'patient_phone' => $patientPhone
    ];
    
    // Make internal request to send_whatsapp_prescription.php
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, getBaseUrl() . '/send_whatsapp_prescription.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}