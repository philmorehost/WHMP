<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Config;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Queue\Job;
use CodeVault\Support\App;
use Throwable;

/**
 * The deferred half of a client changing their cPanel password.
 *
 * A live WHM passwd call can be slow, and a client waiting in the browser
 * used to be at risk of hitting request timeouts mid-action. The client
 * service page now dispatches this job and returns immediately; the worker
 * runs the password change and emails the client the exact outcome.
 *
 * The local `services.password` record is only rewritten AFTER the module
 * confirms success — a module failure must never leave the local record
 * claiming a password the server doesn't actually have (same ordering rule
 * as ProvisioningService::changeDomain()).
 */
final class ChangeServicePasswordJob implements Job
{
    public function __construct(
        public readonly int $serviceId,
        public readonly string $newPassword
    ) {
    }

    public function queue(): string
    {
        return 'default';
    }

    public function handle(): void
    {
        $c = App::container();

        /** @var ProvisioningService $provisioning */
        $provisioning = $c->make(ProvisioningService::class);
        /** @var ServiceRepository $services */
        $services = $c->make(ServiceRepository::class);
        /** @var ActivityLogger $activity */
        $activity = $c->make(ActivityLogger::class);

        $service = $services->find($this->serviceId);

        if ($service === null) {
            return;
        }

        try {
            $result = $provisioning->changePassword($this->serviceId, $this->newPassword);

            if ($result['success']) {
                // Only now — the server really has the new password — record it.
                $services->updateDetails($this->serviceId, ['password' => $this->newPassword]);

                $activity->log(
                    'client',
                    (int) $service['client_id'],
                    'service.password_changed',
                    'service',
                    $this->serviceId,
                    "Client changed the password for service #{$this->serviceId} ({$service['product_name']})",
                    'queue'
                );

                $domain = (string) ($service['domain'] ?: $service['hostname'] ?: '');

                $this->notifyClient($service, 'service_password_changed', [
                    'first_name' => (string) ($service['first_name'] ?? ''),
                    'service_name' => (string) ($service['product_name'] ?? ''),
                    'domain_label' => $domain !== '' ? ' (' . $domain . ')' : '',
                    'username' => (string) ($service['username'] ?? ''),
                    'service_url' => $this->serviceUrl(),
                    'company_name' => brand_name(),
                ]);
            } else {
                $activity->log(
                    'client',
                    (int) $service['client_id'],
                    'service.password_change_failed',
                    'service',
                    $this->serviceId,
                    "Password change FAILED for service #{$this->serviceId}: {$result['message']}",
                    'queue'
                );

                $this->notifyClient($service, 'service_password_change_failed', [
                    'first_name' => (string) ($service['first_name'] ?? ''),
                    'service_name' => (string) ($service['product_name'] ?? ''),
                    'error' => (string) $result['message'],
                    'service_url' => $this->serviceUrl(),
                    'company_name' => brand_name(),
                ]);
            }
        } catch (Throwable $e) {
            $activity->log(
                'client',
                (int) $service['client_id'],
                'service.password_change_failed',
                'service',
                $this->serviceId,
                'Password change FAILED for service #' . $this->serviceId . ': ' . $e->getMessage(),
                'queue'
            );

            $this->notifyClient($service, 'service_password_change_failed', [
                'first_name' => (string) ($service['first_name'] ?? ''),
                'service_name' => (string) ($service['product_name'] ?? ''),
                'error' => $e->getMessage(),
                'service_url' => $this->serviceUrl(),
                'company_name' => brand_name(),
            ]);

            // Let the worker surface it on STDERR too.
            throw $e;
        }
    }

    private function serviceUrl(): string
    {
        return rtrim((string) App::container()->make(Config::class)->env('APP_URL', 'http://localhost'), '/')
            . "/client/services/{$this->serviceId}";
    }

    /** @param array<string, mixed> $service @param array<string, string> $variables */
    private function notifyClient(array $service, string $templateKey, array $variables): void
    {
        $email = trim((string) ($service['client_email'] ?? ''));

        if ($email === '') {
            return;
        }

        try {
            App::container()->make(EmailDispatcher::class)
                ->sendTemplate($templateKey, $email, $variables, (int) $service['client_id']);
        } catch (Throwable) {
            // A failed notification must never crash the worker over an email
            // — the password change itself is already done by this point.
        }
    }
}
