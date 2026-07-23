<?php

declare(strict_types=1);

namespace CodeVault\Mail;

use CodeVault\Settings\SettingsRepository;
use CodeVault\Config;
use Exception;

final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Config $config
    ) {
    }

    public function send(string $to, string $subject, string $html): void
    {
        $host = $this->settings->get('smtp.host') ?: $this->config->env('SMTP_HOST', 'localhost');
        $port = (int) ($this->settings->get('smtp.port') ?: $this->config->env('SMTP_PORT', '25'));
        $username = $this->settings->get('smtp.user') ?: $this->config->env('SMTP_USER', '');
        $password = $this->settings->get('smtp.pass') ?: $this->config->env('SMTP_PASS', '');
        $encryption = $this->settings->get('smtp.encryption') ?: $this->config->env('SMTP_ENCRYPTION', '');
        $fromEmail = $this->settings->get('smtp.from_email') ?: $this->config->env('SMTP_FROM_EMAIL', 'noreply@codevault.com');
        $fromName = $this->settings->get('smtp.from_name') ?: $this->config->env('SMTP_FROM_NAME', 'CodeVault Support');

        if ($host === 'localhost' && $username === '') {
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=utf-8',
                "From: {$fromName} <{$fromEmail}>",
                "Reply-To: {$fromEmail}",
                'X-Mailer: PHP/' . phpversion()
            ];
            $success = mail($to, $subject, $html, implode("\r\n", $headers));
            if (!$success) {
                throw new Exception("mail() failed to deliver the message.");
            }
            return;
        }

        $targetHost = $host;
        if (strtolower($encryption) === 'ssl') {
            $targetHost = 'ssl://' . $host;
        }

        $socket = @fsockopen($targetHost, $port, $errno, $errstr, 10.0);
        if (!$socket) {
            throw new Exception("Could not connect to SMTP host {$host}:{$port} ({$errno} - {$errstr})");
        }

        $this->readResponse($socket, '220');

        $appUrlHost = parse_url($this->config->env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';
        $this->writeCommand($socket, "EHLO " . $appUrlHost, '250');

        if (strtolower($encryption) === 'tls') {
            $this->writeCommand($socket, "STARTTLS", '220');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception("STARTTLS negotiation failed.");
            }
            $this->writeCommand($socket, "EHLO " . $appUrlHost, '250');
        }

        if ($username !== '') {
            $this->writeCommand($socket, "AUTH LOGIN", '334');
            $this->writeCommand($socket, base64_encode($username), '334');
            $this->writeCommand($socket, base64_encode($password), '235');
        }

        $this->writeCommand($socket, "MAIL FROM: <{$fromEmail}>", '250');
        $this->writeCommand($socket, "RCPT TO: <{$to}>", '250');
        $this->writeCommand($socket, "DATA", '354');

        $headersStr = "MIME-Version: 1.0\r\n";
        $headersStr .= "Content-Type: text/html; charset=utf-8\r\n";
        $headersStr .= "To: <{$to}>\r\n";
        $headersStr .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>\r\n";
        $headersStr .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headersStr .= "Date: " . date('r') . "\r\n";
        $headersStr .= "\r\n";

        $body = str_replace("\r\n.", "\r\n..", $html);
        $this->writeCommand($socket, $headersStr . $body . "\r\n.", '250');
        $this->writeCommand($socket, "QUIT", '221');

        fclose($socket);
    }

    private function readResponse($socket, string $expectedCode): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        $code = substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new Exception("SMTP protocol error. Expected {$expectedCode}, got: " . trim($response));
        }
        return $response;
    }

    private function writeCommand($socket, string $command, string $expectedCode): void
    {
        fwrite($socket, $command . "\r\n");
        $this->readResponse($socket, $expectedCode);
    }
}
