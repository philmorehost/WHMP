<?php

declare(strict_types=1);

namespace CodeVault\Support;

/**
 * The seam between mail-piping's message-processing logic and the actual
 * mailbox — mirrors the HttpClient pattern so MailPipingJob's ticket
 * matching/creation logic is unit-testable without a live IMAP server.
 */
interface MailboxClient
{
    /**
     * @param array{host: string, port: int, encryption: string, username: string, password: string, validate_cert?: bool} $config
     * @return array<int, array{uid: int, from: string, to: string, subject: string, body: string}>
     */
    public function fetchUnseen(array $config): array;

    /**
     * @param array{host: string, port: int, encryption: string, username: string, password: string, validate_cert?: bool} $config
     */
    public function markSeen(array $config, int $uid): void;

    /**
     * Verify the mailbox can be reached and the credentials accepted. Used by
     * the admin Mail Piping settings page to surface a clear "can't connect"
     * reason (bad credentials, TLS/cert mismatch, host unreachable, missing
     * imap extension) without waiting for the next cron sweep to fail.
     *
     * @param array{host: string, port: int, encryption: string, username: string, password: string, validate_cert?: bool} $config
     * @return array{ok: bool, message: string}
     */
    public function testConnection(array $config): array;
}
