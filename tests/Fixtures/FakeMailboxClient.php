<?php

declare(strict_types=1);

namespace CodeVault\Tests\Fixtures;

use CodeVault\Support\MailboxClient;

/**
 * Scripted mailbox for MailPipingJob tests — lets the ticket-matching and
 * ticket-creation logic be verified without a live IMAP server.
 */
final class FakeMailboxClient implements MailboxClient
{
    /** @var array<int, array{uid: int, from: string, to: string, subject: string, body: string}> */
    private array $messages;

    /** @var array<int, int> */
    public array $markedSeen = [];

    /** @param array<int, array{uid: int, from: string, to: string, subject: string, body: string}> $messages */
    public function __construct(array $messages = [])
    {
        $this->messages = $messages;
    }

    public function fetchUnseen(array $config): array
    {
        return array_values(array_filter(
            $this->messages,
            fn (array $message) => !in_array($message['uid'], $this->markedSeen, true)
        ));
    }

    public function markSeen(array $config, int $uid): void
    {
        $this->markedSeen[] = $uid;
    }
}
