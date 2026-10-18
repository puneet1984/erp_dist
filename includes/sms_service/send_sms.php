<?php

function sendSMS($message, $phoneNumber)
{
    // API Credentials
    $apiUsername = 'sms';
    $apiPassword = 'hfjZLcDY';
    $deviceLocalIP = '192.168.1.101';

    // Format data for the API
    $data = [
        'message' => $message,
        'phoneNumbers' => [$phoneNumber],
    ];

    $url = "http://{$deviceLocalIP}:8080/message";

    // Initialize cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$apiUsername}:{$apiPassword}");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    // Execute the API request
    $response = curl_exec($ch);

    // Check for cURL errors
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        error_log("SMS API Error: {$error}"); // Log the error

        return false; // Indicate failure
    }

    // Close cURL
    curl_close($ch);

    // Decode and analyze the API response
    $responseData = json_decode($response, true);

    // Handle the API response
    if (isset($responseData['status']) && 'success' === $responseData['status']) {
        return true; // Indicate success
    }
    $errorMessage = $responseData['error'] ?? 'Unknown error';
    error_log("SMS API Response Error: {$errorMessage}"); // Log the API response error

    return false; // Indicate failure

}
