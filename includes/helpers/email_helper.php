<?php

if (!defined('SECURE_ACCESS')) {
    exit('Direct access not allowed');
}

class SmtpMailer
{
    private $host;
    private $port;
    private $username;
    private $password;
    private $encryption;
    private $fromEmail;
    private $fromName;
    private $timeout;
    private $socket = null;

    public function __construct($config)
    {
        $this->host = $config['host'] ?? '';
        $this->port = (int) ($config['port'] ?? 587);
        $this->username = $config['username'] ?? '';
        $this->password = $config['password'] ?? '';
        $this->encryption = strtolower($config['encryption'] ?? 'tls');
        $this->fromEmail = $config['from_email'] ?? $this->username;
        $this->fromName = $config['from_name'] ?? '';
        $this->timeout = (int) ($config['timeout'] ?? 15);
    }

    public function send($to, $subject, $bodyHtml, $bodyText = '', $cc = [], $bcc = [], $replyTo = [])
    {
        $toList = $this->normalizeAddresses($to);
        $ccList = $this->normalizeAddresses($cc);
        $bccList = $this->normalizeAddresses($bcc);

        $this->connect();
        $this->ehlo();

        if ('tls' === $this->encryption) {
            $this->sendCommand('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
            $this->ehlo();
        }

        if ('' !== $this->username) {
            $this->authenticate();
        }

        $this->sendCommand('MAIL FROM:<'.$this->fromEmail.'>', 250);

        $allRecipients = array_merge($toList, $ccList, $bccList);
        foreach ($allRecipients as $addr => $_name) {
            $this->sendCommand('RCPT TO:<'.$addr.'>', [250, 251]);
        }

        $this->sendCommand('DATA', 354);
        $message = $this->buildMessage($subject, $toList, $ccList, $replyTo, $bodyHtml, $bodyText);
        fwrite($this->socket, $message."\r\n.\r\n");
        $this->readResponse(250);

        $this->sendCommand('QUIT', 221);
        fclose($this->socket);
        $this->socket = null;
    }

    private function connect()
    {
        $address = 'ssl' === $this->encryption
            ? 'ssl://'.$this->host.':'.$this->port
            : 'tcp://'.$this->host.':'.$this->port;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $this->socket = stream_socket_client($address, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$this->socket) {
            throw new RuntimeException('SMTP connect failed ('.$errno.'): '.$errstr);
        }

        stream_set_timeout($this->socket, $this->timeout);
        $this->readResponse(220);
    }

    private function ehlo()
    {
        $host = gethostname() ?: 'localhost';
        $this->sendCommand('EHLO '.$host, 250);
    }

    private function authenticate()
    {
        $this->sendCommand('AUTH LOGIN', 334);
        fwrite($this->socket, base64_encode($this->username)."\r\n");
        $this->readResponse(334);
        fwrite($this->socket, base64_encode($this->password)."\r\n");
        $this->readResponse(235);
    }

    private function sendCommand($command, $expected)
    {
        fwrite($this->socket, $command."\r\n");

        return $this->readResponse($expected);
    }

    private function readResponse($expected)
    {
        $response = '';
        while ($line = fgets($this->socket, 512)) {
            $response .= $line;
            if (strlen($line) >= 4 && ' ' === $line[3]) {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        $expectedCodes = (array) $expected;

        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('SMTP error: '.$response);
        }

        return $response;
    }

    private function buildMessage($subject, $to, $cc, $replyTo, $html, $text)
    {
        $boundary = 'SMTP_'.bin2hex(random_bytes(10));
        $from = $this->fromName
            ? '=?UTF-8?B?'.base64_encode($this->fromName).'?= <'.$this->fromEmail.'>'
            : $this->fromEmail;

        $headers = "From: {$from}\r\n";
        $headers .= 'To: '.$this->formatAddressList($to)."\r\n";

        if (!empty($cc)) {
            $headers .= 'Cc: '.$this->formatAddressList($cc)."\r\n";
        }
        if (!empty($replyTo)) {
            $headers .= 'Reply-To: '.$this->formatAddressList($this->normalizeAddresses($replyTo))."\r\n";
        }

        $headers .= 'Subject: =?UTF-8?B?'.base64_encode($subject)."?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= 'Date: '.date('r')."\r\n";
        $headers .= 'Message-ID: <'.bin2hex(random_bytes(16)).'@'.(gethostname() ?: 'localhost').">\r\n";

        if ('' !== $text) {
            $headers .= 'Content-Type: multipart/alternative; boundary="'.$boundary.'"' . "\r\n";
            $body = '--'.$boundary."\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($text))."\r\n";
            $body .= '--'.$boundary."\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($html))."\r\n";
            $body .= '--'.$boundary.'--';
        } else {
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n";
            $body = chunk_split(base64_encode($html));
        }

        $body = preg_replace('/^\./m', '..', $body);

        return $headers."\r\n".$body;
    }

    private function normalizeAddresses($addresses)
    {
        if (is_string($addresses)) {
            return [$addresses => ''];
        }

        $result = [];
        foreach ($addresses as $key => $val) {
            if (is_int($key)) {
                $result[$val] = '';
            } else {
                $result[$key] = $val;
            }
        }

        return $result;
    }

    private function formatAddressList($addresses)
    {
        $parts = [];
        foreach ($addresses as $email => $name) {
            $parts[] = $name
                ? '=?UTF-8?B?'.base64_encode($name).'?= <'.$email.'>'
                : $email;
        }

        return implode(', ', $parts);
    }
}

function sendEmail($to, $subject, $body, $attachments = [], $isHtml = true)
{
    try {
        if (!empty($attachments)) {
            throw new Exception('Attachments are not supported in current SMTP helper');
        }

        $smtpConfig = [
            'host' => defined('SMTP_HOST') ? SMTP_HOST : '',
            'port' => defined('SMTP_PORT') ? SMTP_PORT : 587,
            'username' => defined('SMTP_USERNAME') ? SMTP_USERNAME : '',
            'password' => defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '',
            'encryption' => defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls',
            'from_email' => defined('EMAIL_FROM') ? EMAIL_FROM : (defined('SMTP_USERNAME') ? SMTP_USERNAME : ''),
            'from_name' => defined('APP_NAME') ? APP_NAME : 'ERP',
            'timeout' => 20,
        ];

        if ('' === $smtpConfig['host'] || '' === $smtpConfig['username'] || '' === $smtpConfig['password']) {
            throw new Exception('SMTP settings are missing. Set SMTP_HOST, SMTP_USERNAME, and SMTP_PASSWORD.');
        }

        $mailer = new SmtpMailer($smtpConfig);
        if ($isHtml) {
            $mailer->send($to, $subject, $body, strip_tags($body));
        } else {
            $mailer->send($to, $subject, nl2br($body), $body);
        }

        return [
            'status' => 'success',
            'message' => 'Email sent successfully',
            'code' => 200,
        ];
    } catch (Exception $e) {
        error_log('Email sending failed: '.$e->getMessage());

        return [
            'status' => 'error',
            'message' => $e->getMessage(),
            'code' => 500,
        ];
    }
}
