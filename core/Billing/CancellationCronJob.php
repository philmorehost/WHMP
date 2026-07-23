<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Cron\CronJob;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Activity\ActivityLogger;
use DateTimeImmutable;

final class CancellationCronJob implements CronJob
{
    public function __construct(
        private readonly CancellationRequestRepository $cancellations,
        private readonly ServiceRepository $services,
        private readonly ProvisioningService $provisioning,
        private readonly ActivityLogger $activity
    ) {
    }

    public function name(): string
    {
        return 'service-cancellations';
    }

    public function frequencyMinutes(): int
    {
        // Daily sweep
        return 1440;
    }

    public function handle(): void
    {
        $today = (new DateTimeImmutable())->format('Y-m-d');
        $requests = $this->cancellations->allPending();

        foreach ($requests as $request) {
            // Process if the next_due_date of the service is <= today
            if (isset($request['next_due_date']) && $request['next_due_date'] <= $today) {
                $serviceId = (int) $request['service_id'];

                // 1. Terminate the service on the provider's side
                $this->provisioning->terminate($serviceId);

                // 2. Mark locally as cancelled
                $this->services->cancel($serviceId);

                // 3. Mark the cancellation request as processed
                $this->cancellations->markProcessed((int) $request['id']);

                // 4. Log activity
                $this->activity->log(
                    'system',
                    null,
                    'service.cancelled_scheduled',
                    'service',
                    $serviceId,
                    "Automated system cancelled service #{$serviceId} on due date ({$request['next_due_date']}). Reason: {$request['reason']}"
                );
            }
        }
    }
}
