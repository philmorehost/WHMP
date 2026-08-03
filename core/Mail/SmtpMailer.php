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
        $host = (string) ($this->settings->get('smtp.host') ?: $this->config->env('SMTP_HOST', ''));
        $port = (int) ($this->settings->get('smtp.port') ?: $this->config->env('SMTP_PORT', '25'));
        $username = (string) ($this->settings->get('smtp.user') ?: $this->config->env('SMTP_USER', ''));
        $password = (string) ($this->settings->get('smtp.pass') ?: $this->config->env('SMTP_PASS', ''));
        $encryption = (string) ($this->settings->get('smtp.encryption') ?: $this->config->env('SMTP_ENCRYPTION', ''));
        $defaultHost = $_SERVER['HTTP_HOST'] ?? 'philmorehost.com';
        $fromEmail = (string) ($this->settings->get('smtp.from_email') ?: $this->config->env('SMTP_FROM_EMAIL', 'noreply@' . $defaultHost));
        $fromName = (string) ($this->settings->get('smtp.from_name') ?: $this->config->env('SMTP_FROM_NAME', 'PhilmoreHost Support'));

        // No silent PHP mail() fallback.
        //
        // This used to drop to mail() whenever SMTP looked unconfigured. That
        // is the single worst thing to do for deliverability: mail() hands the
        // message to the local sendmail binary, which sends from the web
        // server's own hostname and IP with no SPF or DKIM signature for the
        // From domain. Receiving providers treat that as unauthenticated mail
        // from an unknown host and spam-folder it — and because the fallback
        // was silent, nothing in the app ever said it had happened.
        //
        // Failing loudly instead means a misconfiguration shows up in the email
        // log as a failed send that an admin can see and fix, rather than as
        // mail that technically "sent" and quietly landed in junk.
        //
        // Note what is NOT rejected: localhost/127.0.0.1. Relaying over SMTP to
        // a local MTA is a legitimate and common setup — on a cPanel box that
        // is Exim, which applies the domain's DKIM signature on the way out.
        // That is real SMTP and is fine. Only the absence of a host, or the
        // pseudo-hosts the old fallback used to mean "just use mail()", are
        // refused.
        $pseudoHosts = ['mail', 'phpmail', 'php_mail', 'sendmail', 'none'];

        if ($host === '' || in_array(strtolower($host), $pseudoHosts, true)) {
            throw new Exception(
                'No SMTP host is configured, so this message was not sent. '
                . 'Set the SMTP host, port, username, password and From address under '
                . 'Configuration → General → Mail. Sending through the local PHP mail() '
                . 'function is deliberately not supported because it cannot be SPF/DKIM '
                . 'signed for your domain and is almost always filtered as spam.'
            );
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

        // ── Message construction ─────────────────────────────────────────────
        // Three headline deliverability fixes live here.
        //
        // 1. Message-ID. A message without one is a well-known spam signal
        //    (SpamAssassin scores MISSING_MID) and breaks threading. It must be
        //    globally unique and its domain should match the From domain.
        //
        // 2. multipart/alternative. This sent HTML with no plain-text part,
        //    which trips MIME_HTML_ONLY — one of the most common reasons an
        //    otherwise legitimate transactional mail is filtered. Every real
        //    mail client prefers the HTML part, so nothing changes visually.
        //
        // 3. A named To: header. Bare "<addr>" with no display name reads as
        //    machine-generated bulk to several filters.
        $fromDomain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1);
        $messageId = '<' . bin2hex(random_bytes(16)) . '.' . time() . '@' . $fromDomain . '>';
        $boundary = 'cv-' . bin2hex(random_bytes(12));

        $headersStr = "MIME-Version: 1.0\r\n";
        $headersStr .= "Date: " . date('r') . "\r\n";
        $headersStr .= "Message-ID: {$messageId}\r\n";
        $headersStr .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>\r\n";
        $headersStr .= "To: <{$to}>\r\n";
        $headersStr .= "Reply-To: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>\r\n";
        $headersStr .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headersStr .= "Auto-Submitted: auto-generated\r\n";
        $headersStr .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headersStr .= "\r\n";

        // Base64 in 76-character lines: safely under every transport limit, and
        // 8-bit safe, so emoji, ₦/€ symbols and em dashes survive intact.
        // Dot-stuffing is not needed — a "." can't occur in the base64
        // alphabet, so no encoded line can ever start one.
        $encode = static fn (string $part): string => chunk_split(base64_encode($part), 76, "\r\n");

        // Least-preferred part first, as multipart/alternative requires.
        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=utf-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= $encode(self::toPlainText($html));
        $body .= "\r\n--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=utf-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= $encode($html);
        $body .= "\r\n--{$boundary}--\r\n";

        // writeCommand() appends the final CRLF, giving the CRLF "." CRLF
        // terminator DATA requires.
        $this->writeCommand($socket, $headersStr . $body . '.', '250');
        $this->writeCommand($socket, "QUIT", '221');

        fclose($socket);
    }

    /**
     * A readable plain-text rendering of the HTML body.
     *
     * Not just strip_tags(): a naive strip runs every table cell and heading
     * together into one wall of text and throws away link targets, which reads
     * as broken to anyone whose client shows the text part — and to the
     * filters that compare the two parts for consistency.
     *
     * Block-level elements become line breaks, links keep their URL alongside
     * the label, and runs of blank lines collapse.
     */
    public static function toPlainText(string $html): string
    {
        // Drop anything that is markup-only before tags are stripped, or their
        // contents leak into the text part as gibberish.
        $text = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;

        // Keep the destination of a link, which is the one thing a text part
        // cannot express through formatting alone.
        $text = preg_replace_callback(
            '#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $label = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $href = trim($m[2]);

                if ($label === '' || $label === $href) {
                    return $href;
                }

                return $label . ' (' . $href . ')';
            },
            $text
        ) ?? $text;

        $text = preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr)\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</t[dh]>#i', "\t", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalise whitespace: trim each line, collapse 3+ blank lines to one.
        $lines = array_map('trim', preg_split('/\R/', $text) ?: []);
        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
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
