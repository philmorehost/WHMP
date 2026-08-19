<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AdminRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Config;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Domains\DomainRepository;
use CodeVault\Domains\DomainService;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServiceDetailsNotifier;
use CodeVault\Queue\Job;
use CodeVault\Support\App;
use Throwable;

/**
 * The deferred half of order acceptance (blueprint §4.3 + §3 "Queue/worker").
 *
 * OrderController::accept() marks the order active and pushes this job so the
 * HTTP request returns immediately instead of blocking on whatever the
 * registrars / provisioning modules do — live API calls that used to make
 * "Accept Order" take minutes and sometimes crash the request. The worker
 * process then runs the same work acceptance used to do inline, in exactly
 * the same order.
 *
 * Plain-data payload (order id + who accepted it, for activity log rows and
 * the completion email) — resolved from the container at handle() time so it
 * survives serialization to a different worker process.
 *
 * Completion/failure is self-reported to every admin by email, so a
 * backgrounded accept doesn't silently vanish: on success a summary carrying
 * the exact per-service/domain failure reasons, or the thrown exception
 * message if the job crashes before finishing. (Worker itself only logs to
 * STDERR, which nobody reads on a supervised box.)
 */
final class AcceptOrderJob implements Job
{
    public function __construct(
        public readonly int $orderId,
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

        /** @var ServiceRepository $services */
        $services = $c->make(ServiceRepository::class);
        /** @var ProvisioningService $provisioning */
        $provisioning = $c->make(ProvisioningService::class);
        /** @var ServiceDetailsNotifier $serviceDetails */
        $serviceDetails = $c->make(ServiceDetailsNotifier::class);
        /** @var ActivityLogger $activity */
        $activity = $c->make(ActivityLogger::class);
        /** @var HookDispatcher $hooks */
        $hooks = $c->make(HookDispatcher::class);
        /** @var DomainRepository $domainRepo */
        $domainRepo = $c->make(DomainRepository::class);
        /** @var DomainService $domainService */
        $domainService = $c->make(DomainService::class);
        /** @var DomainPricingRepository $domainPricing */
        $domainPricing = $c->make(DomainPricingRepository::class);

        /** @var array<int, string> $failures */
        $failures = [];

        try {
            foreach ($services->forOrder($this->orderId) as $service) {
                // Only provision services still awaiting it — accept can be
                // called again (retry, double submit) without re-running
                // create() against an already-active or cancelled service.
                if ($service['status'] !== 'pending') {
                    continue;
                }

                // Respect the product's own setup mode. "off" means the admin
                // provisions this product by hand — the service simply stays
                // pending until the admin sets it live from the service page.
                if ($this->isManualSetup($service)) {
                    $activity->log(
                        'admin',
                        $this->adminId,
                        'service.manual_setup_required',
                        'service',
                        (int) $service['id'],
                        "Order accepted; service #{$service['id']} is set to manual setup and was not sent to a provisioning module.",
                        $this->adminIp
                    );

                    continue;
                }

                try {
                    $result = $provisioning->provision((int) $service['id']);

                    if (!$result['success']) {
                        $failures[] = "Service #{$service['id']} ({$service['product_name']}): {$result['message']}";
                        $activity->log('admin', $this->adminId, 'service.provisioning_failed', 'service', (int) $service['id'], "Provisioning failed: {$result['message']}", $this->adminIp);
                    }
                } catch (Throwable $e) {
                    // A module throwing (HTTP layer, malformed response, …)
                    // must never abort the rest of the order — the other
                    // services AND every domain still need to be provisioned,
                    // otherwise "accept" silently skips the remaining invoice
                    // items. Record it as a failure and carry on.
                    $failures[] = "Service #{$service['id']} ({$service['product_name']}): {$e->getMessage()}";
                    $activity->log('admin', $this->adminId, 'service.provisioning_failed', 'service', (int) $service['id'], "Provisioning threw an exception: {$e->getMessage()}", $this->adminIp);
                }

                // Tell the client how to get in. Read after provisioning, not
                // before — a module that just created the account will have
                // written the username/password we are about to send. Silent
                // when there is nothing to send yet; never fatal.
                try {
                    $notified = $serviceDetails->sendForService((int) $service['id']);

                    if ($notified['sent']) {
                        $activity->log('admin', $this->adminId, 'service.details_emailed', 'service', (int) $service['id'], $notified['reason'], $this->adminIp);
                    }
                } catch (Throwable $e) {
                    $activity->log('admin', $this->adminId, 'service.details_email_failed', 'service', (int) $service['id'], 'Could not email service details: ' . $e->getMessage(), $this->adminIp);
                }
            }

            foreach ($domainRepo->forOrder($this->orderId) as $domain) {
                if ($domain['status'] !== 'pending') {
                    continue;
                }

                // Same rule as services: a TLD set to manual registration is
                // not sent to a registrar on acceptance.
                $tldPricing = $domainPricing->findByTld((string) $domain['tld']);

                if (($tldPricing['autosetup_registration'] ?? 'payment') === 'off') {
                    $activity->log(
                        'admin',
                        $this->adminId,
                        'domain.manual_setup_required',
                        'domain',
                        (int) $domain['id'],
                        "Order accepted; domain #{$domain['id']} is set to manual registration and was not sent to a registrar.",
                        $this->adminIp
                    );

                    continue;
                }

                try {
                    $result = $domainService->register((int) $domain['id']);

                    if (!$result['success']) {
                        $failures[] = "Domain {$domain['domain_name']}: {$result['message']}";
                        $activity->log('admin', $this->adminId, 'domain.registration_failed', 'domain', (int) $domain['id'], "Domain registration failed: {$result['message']}", $this->adminIp);
                    }
                } catch (Throwable $e) {
                    // Same hardening as the service loop: a registrar module
                    // throwing must not stop the remaining domains (or leave
                    // the rest of the order's items unprovisioned).
                    $failures[] = "Domain {$domain['domain_name']}: {$e->getMessage()}";
                    $activity->log('admin', $this->adminId, 'domain.registration_failed', 'domain', (int) $domain['id'], "Domain registration threw an exception: {$e->getMessage()}", $this->adminIp);
                }
            }

            $hooks->fire(HookPoints::ORDER_ACCEPTED, ['orderId' => $this->orderId]);
            $activity->log('admin', $this->adminId, 'order.accepted', 'order', $this->orderId, "Accepted order #{$this->orderId}", $this->adminIp);

            $this->notifyAdmins('order_acceptance_completed', [
                'order_id' => (string) $this->orderId,
                'summary' => $this->summaryHtml($failures),
                'order_url' => rtrim((string) $c->make(Config::class)->env('APP_URL', 'http://localhost'), '/') . "/admin/orders/{$this->orderId}",
                'company_name' => brand_name(),
            ]);
        } catch (Throwable $e) {
            $this->notifyAdmins('order_acceptance_failed', [
                'order_id' => (string) $this->orderId,
                'error' => $e->getMessage(),
                'company_name' => brand_name(),
            ]);

            // Let the worker surface it on STDERR too.
            throw $e;
        }
    }

    /**
     * Whether this service's product is provisioned by hand rather than
     * through a module — products whose autosetup is "off". Mirrors
     * OrderController::isManualSetup().
     *
     * @param array<string, mixed> $service
     */
    private function isManualSetup(array $service): bool
    {
        $product = App::container()
            ->make(ProductRepository::class)
            ->find((int) $service['product_id']);

        return ($product['autosetup'] ?? 'payment') === 'off';
    }

    /** @param array<int, string> $failures */
    private function summaryHtml(array $failures): string
    {
        if ($failures === []) {
            return '<p>All services and domains were provisioned successfully.</p>';
        }

        $lines = '';
        foreach ($failures as $failure) {
            $lines .= '<li>' . htmlspecialchars($failure, ENT_QUOTES, 'UTF-8') . '</li>';
        }

        return '<p>Some items could not be provisioned — the exact reasons are below. Retry them from the relevant service/domain pages:</p><ul>' . $lines . '</ul>';
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
