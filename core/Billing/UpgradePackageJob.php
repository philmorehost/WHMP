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
 * The deferred half of a package-plan upgrade on a cPanel shared hosting
 * service.
 *
 * ProrationService::upgrade() changes the billing record (product/price)
 * synchronously and quickly, but switching the account's WHM package via
 * changepackage is a live server call that can be slow. ServiceController
 * dispatches this job so the browser isn't blocked, and the worker reports
 * the outcome to every admin by email — success or the exact failure reason.
 */
final class UpgradePackageJob implements Job
{
    public function __construct(
        public readonly int $serviceId,
        public readonly string $package,
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
            $this->notifyAdmins('service_package_upgrade_failed', [
                'service_id' => (string) $this->serviceId,
                'error' => 'Service not found.',
                'service_url' => $this->serviceUrl(),
                'company_name' => brand_name(),
            ]);

            return;
        }

        try {
            $result = $provisioning->changePackage($this->serviceId, $this->package);

            $activity->log(
                'admin',
                $this->adminId,
                'service.package_changed',
                'service',
                $this->serviceId,
                $result['success']
                    ? "Changed hosting package for service #{$this->serviceId} to \"{$this->package}\": {$result['message']}"
                    : "Change hosting package FAILED for service #{$this->serviceId}: {$result['message']}",
                $this->adminIp
            );

            if ($result['success']) {
                $this->notifyAdmins('service_package_upgraded', [
                    'service_id' => (string) $this->serviceId,
                    'service_name' => (string) ($service['product_name'] ?? ''),
                    'client' => trim((string) ($service['first_name'] ?? '') . ' ' . (string) ($service['last_name'] ?? '')),
                    'package' => $this->package,
                    'message' => (string) $result['message'],
                    'service_url' => $this->serviceUrl(),
                    'company_name' => brand_name(),
                ]);
            } else {
                $this->notifyAdmins('service_package_upgrade_failed', [
                    'service_id' => (string) $this->serviceId,
                    'service_name' => (string) ($service['product_name'] ?? ''),
                    'error' => (string) $result['message'],
                    'service_url' => $this->serviceUrl(),
                    'company_name' => brand_name(),
                ]);
            }
        } catch (Throwable $e) {
            $this->notifyAdmins('service_package_upgrade_failed', [
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
