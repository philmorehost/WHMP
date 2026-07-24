<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Cron\CronJob;

final class CancellationCronJob extends CronJob
{
    public static function identifier(): string
    {
        return 'cancellation-processor';
    }

    public static function schedule(): string
    {
        return '0 * * * *';
    }

    public function __construct(private readonly CancellationRequestService $service)
    {
    }

    public function handle(): void
    {
        $this->service->processDueCancellations();
    }
}
