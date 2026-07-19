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
     * @param array{host: string, port: int, encryption: string, username: string, password: string} $config
     * @return array<int, array{uid: int, from: string, to: string, subject: string, body: string}>
     */
    public function fetchUnseen(array $config): array;

    /**
     * @param array{host: string, port: int, encryption: string, username: string, password: string} $config
     */
    public function markSeen(array $config, int $uid): void;
}
