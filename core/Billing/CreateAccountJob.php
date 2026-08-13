<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AdminRepository;
use CodeVault\Config;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Queue\Job;
use CodeVault\Support\App;
use Throwable;

/**
 * The deferred half of the admin "Create Account" action on a cPanel shared
 * hosting service.
 *
 * createacct (DNS zone, mail, AutoSSL) can take minutes and used to block the
 * admin's browser — repeatedly hitting PHP time/memory limits mid-request.
 * ServiceController::createAccount() now returns immediately and pushes this
 * job, so the queue worker runs provision() instead.
 *
 * Completion or failure is self-reported to every admin by email, so a
 * backgrounded create never silently vanishes: on success the WHM result is
 * included, on failure the exact reason. (Worker itself only logs to STDERR,
 * which nobody reads on a supervised box.)
 */
final class CreateAccountJob implements Job
{
    public function __construct(
        public readonly int $serviceId,
        public readonly int $adminId,
        public readonly string $adminIp
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
            $this->notifyAdmins('service_account_create_failed', [
                'service_id' => (string) $this->serviceId,
                'error' => 'Service not found.',
                'service_url' => $this->serviceUrl(),
                'company_name' => brand_name(),
            ]);

            return;
        }

        try {
            $result = $provisioning->provision($this->serviceId);

            $activity->log(
                'admin',
                $this->adminId,
                'service.create_account',
                'service',
                $this->serviceId,
                $result['success']
                    ? "Created cPanel account for service #{$this->serviceId}: {$result['message']}"
                    : "Create cPanel account FAILED for service #{$this->serviceId}: {$result['message']}",
                $this->adminIp
            );

            if ($result['success']) {
                $this->notifyAdmins('service_account_created', [
                    'service_id' => (string) $this->serviceId,
                    'service_name' => (string) ($service['product_name'] ?? ''),
                    'client' => trim((string) ($service['first_name'] ?? '') . ' ' . (string) ($service['last_name'] ?? '')),
                    'message' => (string) $result['message'],
                    'service_url' => $this->serviceUrl(),
                    'company_name' => brand_name(),
                ]);
            } else {
                $this->notifyAdmins('service_account_create_failed', [
                    'service_id' => (string) $this->serviceId,
                    'service_name' => (string) ($service['product_name'] ?? ''),
                    'error' => (string) $result['message'],
                    'service_url' => $this->serviceUrl(),
                    'company_name' => brand_name(),
                ]);
            }
        } catch (Throwable $e) {
            $this->notifyAdmins('service_account_create_failed', [
                'service_id' => (string) $this->serviceId,
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
            . "/admin/services/{$this->serviceId}";
    }

    /** @param array<string, string> $variables */
    private function notifyAdmins(string $templateKey, array $variables): void
    {
        try {
            $c = App::container();
            /** @var EmailDispatcher $mail */
            $mail = $c->make(EmailDispatcher::class);
            /** @var AdminRepository $adminRepo */
            $adminRepo = $c->make(AdminRepository::class);

            foreach ($adminRepo->all() as $admin) {
                if (!empty($admin['email'])) {
                    $mail->sendTemplate($templateKey, (string) $admin['email'], $variables);
                }
            }
        } catch (Throwable) {
            // A failed notification must never crash the worker over an email
            // — the job's actual work is already done by this point.
        }
    }
}
