<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Clients\ClientRepository;
use CodeVault\Cron\CronJob;
use CodeVault\Settings\SettingsRepository;

/**
 * IMAP mail piping (blueprint §4.4): polls a support mailbox and turns
 * each unseen message into either a reply on an existing ticket (matched
 * via a "[Ticket #N]" tag the outbound notification would carry in its
 * subject) or a brand-new ticket, routed to the department whose
 * configured email matches the message's "To" address.
 */
final class MailPipingJob implements CronJob
{
    public function __construct(
        private readonly MailboxClient $mailbox,
        private readonly SettingsRepository $settings,
        private readonly DepartmentRepository $departments,
        private readonly TicketRepository $tickets,
        private readonly TicketService $ticketService,
        private readonly ClientRepository $clients
    ) {
    }

    public function name(): string
    {
        return 'mail-piping';
    }

    public function frequencyMinutes(): int
    {
        return 5;
    }

    public function handle(): void
    {
        if ($this->settings->get('mail_piping.enabled', '0') !== '1') {
            return;
        }

        $config = $this->config();

        if ($config['host'] === '' || $config['username'] === '') {
            return;
        }

        foreach ($this->mailbox->fetchUnseen($config) as $message) {
            $this->processMessage($message);
            $this->mailbox->markSeen($config, $message['uid']);
        }
    }

    /** @return array{host: string, port: int, encryption: string, username: string, password: string} */
    private function config(): array
    {
        return [
            'host' => (string) $this->settings->get('mail_piping.host', ''),
            'port' => (int) $this->settings->get('mail_piping.port', '993'),
            'encryption' => (string) $this->settings->get('mail_piping.encryption', 'ssl'),
            'username' => (string) $this->settings->get('mail_piping.username', ''),
            'password' => (string) $this->settings->get('mail_piping.password', ''),
        ];
    }

    /** @param array{uid: int, from: string, to: string, subject: string, body: string} $message */
    private function processMessage(array $message): void
    {
        $fromEmail = $this->extractEmail($message['from']);

        if ($fromEmail === '') {
            return;
        }

        $ticketId = $this->extractTicketId($message['subject']);
        $ticket = $ticketId !== null ? $this->tickets->find($ticketId) : null;

        if ($ticket !== null) {
            $replyClientId = $ticket['client_id'] !== null ? (int) $ticket['client_id'] : null;
            $this->ticketService->reply((int) $ticket['id'], 'client', $replyClientId, $fromEmail, $message['body']);

            return;
        }

        $department = $this->departments->findByEmail($this->extractEmail($message['to'])) ?? $this->firstDepartment();

        if ($department === null) {
            return;
        }

        $client = $this->clients->findByEmail($fromEmail);
        $subject = $message['subject'] !== '' ? $message['subject'] : '(no subject)';

        $this->ticketService->open(
            $client !== null ? (int) $client['id'] : null,
            $fromEmail,
            (int) $department['id'],
            $subject,
            $fromEmail,
            $message['body']
        );
    }

    /** @return array<string, mixed>|null */
    private function firstDepartment(): ?array
    {
        return $this->departments->all()[0] ?? null;
    }

    private function extractTicketId(string $subject): ?int
    {
        if (preg_match('/\[Ticket\s*#(\d+)\]/i', $subject, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function extractEmail(string $address): string
    {
        if (preg_match('/<([^>]+)>/', $address, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($address);
    }
}
