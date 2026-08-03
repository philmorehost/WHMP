<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Cron\CronJob;

/**
 * Processes cancellation requests that have reached their scheduled date.
 *
 * Originally written against an API this codebase doesn't have — it declared
 * `extends CronJob` (CronJob is an interface) plus static identifier()/
 * schedule() methods — which made the class impossible to load and took the
 * whole of bin/cron.php down with it, since the entry point instantiates
 * every registered job. Restated against the real CronJob contract:
 * name()/frequencyMinutes()/handle(). The original '0 * * * *' schedule
 * expression meant hourly, which is what frequencyMinutes() now returns.
 */
final class CancellationCronJob implements CronJob
{
    public function __construct(private readonly CancellationRequestService $service)
    {
    }

    public function name(): string
    {
        return 'cancellation-processor';
    }

    public function frequencyMinutes(): int
    {
        return 60;
    }

    public function handle(): void
    {
        $this->service->processDueCancellations();
    }
}
