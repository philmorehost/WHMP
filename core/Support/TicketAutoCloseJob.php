<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Cron\CronJob;

/**
 * Auto-close sweep (blueprint §4.4 "auto-close on inactivity"): an
 * answered ticket the client never replied to gets closed after the
 * inactivity threshold, same as WHMCS's "Auto Close Tickets" setting.
 */
final class TicketAutoCloseJob implements CronJob
{
    public const INACTIVE_DAYS = 7;

    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly TicketService $ticketService
    ) {
    }

    public function name(): string
    {
        return 'ticket-auto-close';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    public function handle(): void
    {
        foreach ($this->tickets->inactiveLongerThan(self::INACTIVE_DAYS) as $ticket) {
            $this->ticketService->close((int) $ticket['id']);
        }
    }
}
