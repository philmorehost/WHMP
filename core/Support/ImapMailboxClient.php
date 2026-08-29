<?php

declare(strict_types=1);

namespace CodeVault\Support;

use RuntimeException;

/**
 * Real IMAP mail piping (blueprint §4.4). Requires the PHP `imap`
 * extension — not compiled into every PHP build, so this throws a clear
 * error rather than a fatal "undefined function" when it's missing.
 * MailPipingJob's cron run catches per-job errors, so a missing
 * extension shows up as a failed job, not a crashed cron process.
 *
 * Connection flags explained:
 *  - `/norsh` — never fall back to an rsh/ssh subprocess for the connection.
 *  - `/novalidate-cert` — cPanel/shared-hosting mail servers commonly present
 *    a certificate the server's CA bundle does not trust. Without this flag
 *    the TLS handshake fails silently and IMAP reports "[CLOSED] IMAP
 *    connection broken (authenticate)" even when the credentials are correct.
 *    It is applied unless the admin explicitly opts in to strict validation
 *    (`validate_cert = true`).
 */
final class ImapMailboxClient implements MailboxClient
{
    public function fetchUnseen(array $config): array
    {
        $stream = $this->connect($config);

        try {
            $uids = imap_search($stream, 'UNSEEN', SE_UID);

            if ($uids === false) {
                return [];
            }

            $messages = [];

            foreach ($uids as $uid) {
                $messages[] = $this->readMessage($stream, (int) $uid);
            }

            return $messages;
        } finally {
            imap_close($stream);
        }
    }

    public function markSeen(array $config, int $uid): void
    {
        $stream = $this->connect($config);

        try {
            imap_setflag_full($stream, (string) $uid, '\\Seen', ST_UID);
        } finally {
            imap_close($stream);
        }
    }

    public function testConnection(array $config): array
    {
        try {
            $stream = $this->connect($config);
            imap_close($stream);

            return ['ok' => true, 'message' => 'Connected and authenticated successfully.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * The mailbox connection string, exposed separately so the flag-building
     * logic is unit-testable without the (optional) imap extension.
     *
     * @param array{host: string, port: int, encryption: string, username: string, password: string, validate_cert?: bool} $config
     */
    public static function mailboxString(array $config): string
    {
        $flags = match ($config['encryption']) {
            'ssl' => '/imap/ssl',
            'tls' => '/imap/tls',
            default => '/imap/notls',
        };

        if (empty($config['validate_cert'])) {
            $flags .= '/novalidate-cert';
        }

        return "{{$config['host']}:{$config['port']}{$flags}/norsh}INBOX";
    }

    /** @param array{host: string, port: int, encryption: string, username: string, password: string, validate_cert?: bool} $config */
    private function connect(array $config): \IMAP\Connection
    {
        if (!extension_loaded('imap')) {
            throw new RuntimeException('The PHP imap extension is not installed or enabled.');
        }

        $mailbox = self::mailboxString($config);

        // One retry: "[CLOSED] IMAP connection broken (authenticate)" is
        // frequently a transient TLS/socket hiccup rather than bad
        // credentials, so reconnect once after a short pause before failing.
        $attempts = 0;
        $stream = false;
        $lastError = '';

        while ($stream === false && $attempts < 2) {
            $attempts++;
            $stream = @imap_open($mailbox, $config['username'], $config['password']);
            $lastError = (string) (imap_last_error() ?: '');

            if ($stream === false && $attempts < 2) {
                usleep(250000);
            }
        }

        if ($stream === false) {
            throw new RuntimeException(sprintf(
                'Could not connect to mailbox %s:%d as "%s": %s',
                $config['host'],
                $config['port'],
                $config['username'],
                $lastError !== '' ? $lastError : 'unknown IMAP error'
            ));
        }

        return $stream;
    }

    /** @return array{uid: int, from: string, to: string, subject: string, body: string} */
    private function readMessage(\IMAP\Connection $stream, int $uid): array
    {
        $header = imap_headerinfo($stream, imap_msgno($stream, $uid));
        $from = $header !== false && isset($header->fromaddress) ? $header->fromaddress : '';
        $to = $header !== false && isset($header->toaddress) ? $header->toaddress : '';
        $subject = $header !== false && isset($header->subject)
            ? imap_utf8($header->subject)
            : '';

        $body = imap_fetchbody($stream, $uid, '1', FT_UID) ?: '';

        if (trim($body) === '') {
            $body = imap_body($stream, $uid, FT_UID) ?: '';
        }

        return [
            'uid' => $uid,
            'from' => trim($from),
            'to' => trim($to),
            'subject' => trim($subject),
            'body' => trim($body),
        ];
    }
}
