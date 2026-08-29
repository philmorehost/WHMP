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
    /**
     * How many open (non-closed) tickets a single sender may hold before the
     * piping job stops opening new ones from that address — stops one sender
     * (or a script) from flooding the admin queue. Overridable per-install
     * via the `mail_piping.max_open_per_sender` setting.
     */
    private const DEFAULT_MAX_OPEN_PER_SENDER = 5;

    public function __construct(
        private readonly MailboxClient $mailbox,
        private readonly SettingsRepository $settings,
        private readonly DepartmentRepository $departments,
        private readonly TicketRepository $tickets,
        private readonly TicketService $ticketService,
        private readonly ClientRepository $clients,
        private readonly BlockedEmailSenderRepository $blockedSenders
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

    /** @return array{host: string, port: int, encryption: string, username: string, password: string, validate_cert: bool} */
    private function config(): array
    {
        return [
            'host' => (string) $this->settings->get('mail_piping.host', ''),
            'port' => (int) $this->settings->get('mail_piping.port', '993'),
            'encryption' => (string) $this->settings->get('mail_piping.encryption', 'ssl'),
            'username' => (string) $this->settings->get('mail_piping.username', ''),
            'password' => (string) $this->settings->get('mail_piping.password', ''),
            'validate_cert' => $this->settings->get('mail_piping.validate_cert', '0') === '1',
        ];
    }

    /** @param array{uid: int, from: string, to: string, subject: string, body: string} $message */
    private function processMessage(array $message): void
    {
        $fromEmail = $this->extractEmail($message['from']);

        if ($fromEmail === '') {
            return;
        }

        // Bounce / auto-reply / delivery-failure messages are never support
        // requests. Before the blocked-sender check, because a Mailer-Daemon
        // address varies by host and wouldn't necessarily be on the list —
        // turning each failed outbound email's bounce into a fresh "open"
        // ticket is exactly how the admin queue floods. Skipped and marked
        // seen upstream so the sweep doesn't re-process it.
        if ($this->isBounceMessage($message)) {
            return;
        }

        // Blocked senders (bounce loops, spam, wrong-party mail) are skipped
        // entirely — no ticket, no reply — but still marked seen upstream so
        // the same message isn't re-processed on the next sweep.
        if ($this->blockedSenders->isBlocked($fromEmail)) {
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

        // Flood guard: cap the number of open tickets one sender can hold so
        // a single address (or an automated loop) can't keep opening new
        // tickets. Replying to an existing tagged ticket is unaffected — only
        // brand-new ticket creation is gated.
        $maxOpenPerSender = max(1, (int) $this->settings->get('mail_piping.max_open_per_sender', (string) self::DEFAULT_MAX_OPEN_PER_SENDER));
        if ($this->tickets->countOpenByEmail($fromEmail) >= $maxOpenPerSender) {
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

    /**
     * Whether a piped message is an automated delivery-status / bounce /
     * auto-reply rather than a real support request. Bounces are sent by the
     * mail server itself (Mailer-Daemon / postmaster) or carry an obvious
     * delivery-failure subject; auto-replies (out-of-office etc.) likewise
     * never need a ticket.
     *
     * @param array{uid: int, from: string, to: string, subject: string, body: string} $message
     */
    private function isBounceMessage(array $message): bool
    {
        $from = strtolower($this->extractEmail($message['from']));
        $local = $from;
        $at = strpos($local, '@');
        if ($at !== false) {
            $local = substr($local, 0, $at);
        }
        $local = trim($local);

        if (in_array($local, [
            'mailer-daemon', 'postmaster', 'root', 'noreply', 'no-reply',
            'do-not-reply', 'donotreply', 'auto-reply', 'autoreply', 'mdaemon',
        ], true)) {
            return true;
        }

        $subject = strtolower(trim((string) $message['subject']));
        foreach ([
            'mail delivery failed',
            'delivery status notification (failure)',
            'undelivered mail returned to sender',
            'delivery has failed',
            'message delivery failure',
            'returned mail:',
            'returned to sender',
            'failure notice',
            'auto reply:',
            'autoreply:',
            'out of office',
        ] as $marker) {
            if (str_contains($subject, $marker)) {
                return true;
            }
        }

        return false;
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
