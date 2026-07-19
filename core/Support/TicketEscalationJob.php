<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Cron\CronJob;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;

/**
 * SLA escalation sweep (blueprint §4.4 "escalation rules, SLA priority"):
 * a ticket the client is waiting on for longer than the threshold gets
 * bumped to high priority and fires TicketEscalated. Only acts on tickets
 * not already high-priority, so a ticket escalates once rather than
 * re-firing the hook every run while it sits unanswered.
 */
final class TicketEscalationJob implements CronJob
{
    public const AWAITING_REPLY_MINUTES = 240;

    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly HookDispatcher $hooks
    ) {
    }

    public function name(): string
    {
        return 'ticket-escalation';
    }

    public function frequencyMinutes(): int
    {
        return 15;
    }

    public function handle(): void
    {
        foreach ($this->tickets->awaitingReplyLongerThan(self::AWAITING_REPLY_MINUTES) as $ticket) {
            if ($ticket['priority'] === 'high') {
                continue;
            }

            $this->tickets->setPriority((int) $ticket['id'], 'high');
            $this->hooks->fire(HookPoints::TICKET_ESCALATED, ['ticketId' => $ticket['id']]);
        }
    }
}
