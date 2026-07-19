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

    /** @param array{host: string, port: int, encryption: string, username: string, password: string} $config */
    private function connect(array $config): \IMAP\Connection
    {
        if (!extension_loaded('imap')) {
            throw new RuntimeException('The PHP imap extension is not installed or enabled.');
        }

        $flags = match ($config['encryption']) {
            'ssl' => '/imap/ssl',
            'tls' => '/imap/tls',
            default => '/imap/notls',
        };

        $mailbox = "{{$config['host']}:{$config['port']}{$flags}}INBOX";
        $stream = imap_open($mailbox, $config['username'], $config['password']);

        if ($stream === false) {
            throw new RuntimeException('Could not connect to mailbox: ' . imap_last_error());
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
