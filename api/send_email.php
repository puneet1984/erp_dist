<?php

define('SECURE_ACCESS', true);
define('SKIP_AUTH', true);

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/email_service/send_email.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ('OPTIONS' === $_SERVER['REQUEST_METHOD']) {
    http_response_code(204);
    exit;
}

if ('POST' !== $_SERVER['REQUEST_METHOD']) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!defined('EMAIL_API_KEY') || '' === EMAIL_API_KEY || !hash_equals(EMAIL_API_KEY, $apiKey)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$to = $input['to'] ?? '';
$subject = trim((string) ($input['subject'] ?? ''));
$html = (string) ($input['html'] ?? '');
$text = (string) ($input['text'] ?? '');

if (empty($to) || '' === $subject || '' === $html) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: to, subject, html',
    ]);
    exit;
}

$smtpConfig = [
    'host'       => defined('SMTP_HOST') ? SMTP_HOST : '',
    'port'       => defined('SMTP_PORT') ? SMTP_PORT : 587,
    'username'   => defined('SMTP_USERNAME') ? SMTP_USERNAME : '',
    'password'   => defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '',
    'encryption' => defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls',
    'from_email' => defined('EMAIL_FROM') ? EMAIL_FROM : (defined('SMTP_USERNAME') ? SMTP_USERNAME : ''),
    'from_name'  => defined('APP_NAME') ? APP_NAME : 'ERP',
    'timeout'    => 20,
];

if ('' === $smtpConfig['host'] || '' === $smtpConfig['username'] || '' === $smtpConfig['password']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'SMTP settings are missing']);
    exit;
}

try {
    $mailer = new SmtpMailer($smtpConfig);

    $cc = is_array($input['cc'] ?? null) ? $input['cc'] : [];
    $bcc = is_array($input['bcc'] ?? null) ? $input['bcc'] : [];
    $replyTo = is_array($input['reply_to'] ?? null) ? $input['reply_to'] : [];

    $mailer->send($to, $subject, $html, $text, $cc, $bcc, $replyTo);

    echo json_encode([
        'success' => true,
        'message' => 'Email sent successfully',
    ]);
} catch (Throwable $e) {
    error_log('Email API error: '.$e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to send email',
        'details' => $e->getMessage(),
    ]);
}
