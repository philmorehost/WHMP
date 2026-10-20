<?php

declare(strict_types=1);

namespace CodeVault\Catalog;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\Support\App;
use CodeVault\View;

/**
 * Admin → Bulk Price Updates: adjust many product prices (products picked by
 * their category, optionally narrowed per product) and many domain prices
 * (picked by TLD) in one pass, then email a price-review notice to every
 * client who subscribes to an affected product at a changed cycle or holds a
 * domain under a changed extension.
 *
 * The two halves are permission-split like the pages they extend: touching
 * products needs products.manage, touching TLDs needs domains.manage — a
 * submission that mixes both needs both, and a submission touching neither
 * is rejected outright.
 */
final class BulkPriceController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ProductGroupRepository $groups,
        private readonly ProductRepository $products,
        private readonly ProductPricingRepository $pricing,
        private readonly DomainPricingRepository $domainPricing,
        private readonly ActivityLogger $activity,
        private readonly Database $db
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requireAccess(true)) {
            return $denied;
        }

        $content = $this->view->render('catalog.bulk-pricing', [
            'groups' => $this->groups->all(),
            'productsByGroup' => $this->products->allGroupedByGroup(),
            'pricingByProduct' => $this->allPricingByProduct(),
            'tldPricing' => $this->domainPricing->all(),
            'cycles' => BillingCycle::labels(),
            'error' => $request->query('error'),
            'result' => [
                'productsChanged' => max(0, (int) $request->query('products_changed', '0')),
                'domainsChanged' => max(0, (int) $request->query('domains_changed', '0')),
                'notified' => max(0, (int) $request->query('notified', '0')),
                'noImpact' => max(0, (int) $request->query('no_impact', '0')),
                'failed' => max(0, (int) $request->query('failed', '0')),
            ],
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Bulk Price Updates',
            'content' => $content,
        ]));
    }

    public function apply(Request $request): Response
    {
        $productIds = array_values(array_filter(array_map('intval', (array) $request->input('products', [])), static fn ($id) => $id > 0));
        $tlds = array_values(array_filter(array_map('strval', (array) $request->input('tlds', [])), static fn ($tld) => trim((string) $tld) !== ''));
        $cycles = array_values(array_filter(array_map('strval', (array) $request->input('cycles', []))));
        $fields = array_values(array_filter(array_map('strval', (array) $request->input('domain_fields', []))));
        $mode = (string) $request->input('mode', 'percent') === 'fixed' ? 'fixed' : 'percent';
        $direction = (string) $request->input('direction', 'increase') === 'decrease' ? 'decrease' : 'increase';
        $amount = round((float) $request->input('amount', 0), 2);
        $includeSetup = (string) $request->input('include_setup', '') === '1';

        if ($productIds === [] && $tlds === []) {
            return $this->back('Select at least one product or one domain extension to update.');
        }

        if ($amount <= 0) {
            return $this->back('Enter an adjustment amount greater than zero.');
        }

        if ($productIds !== [] && ($denied = $this->requireAccess(false, PermissionRegistry::PRODUCTS_MANAGE)) !== null) {
            return $denied;
        }

        if ($tlds !== [] && ($denied = $this->requireAccess(false, PermissionRegistry::DOMAINS_MANAGE)) !== null) {
            return $denied;
        }

        $service = $this->service();

        $productResult = ['changes' => [], 'skipped' => 0];
        $domainResult = ['changes' => [], 'skipped' => 0];

        if ($productIds !== []) {
            $productResult = $service->updateProducts($productIds, $mode, $amount, $direction, $cycles, $includeSetup);
        }

        if ($tlds !== []) {
            $domainResult = $service->updateDomainPricing($tlds, $mode, $amount, $direction, $fields);
        }

        $changesTotal = count($productResult['changes']) + count($domainResult['changes']);

        if ($changesTotal === 0) {
            return $this->back('Nothing was changed — the selected items had no price rows matching the chosen cycles/fields, or the adjustment rounded to the same prices.');
        }

        // The price review notice: one email per affected client, listing only
        // their own changed lines. A mail outage must not read as a failed
        // update — the prices are already committed — so failures are counted
        // and surfaced, never thrown.
        $notify = ['notified' => 0, 'no_impact' => 0, 'failed' => 0];

        try {
            $notify = $service->notifySubscribers($productResult['changes'], $domainResult['changes'], $this->clientAreaUrls());
        } catch (\Throwable $e) {
            $notify['failed'] = -1;
            $this->activity->log('admin', $this->adminId(), 'pricing.notify_error', 'system', null, 'Bulk price update succeeded but the price review emails could not be queued: ' . $e->getMessage(), $request->ip());
        }

        $adjustment = $mode === 'percent'
            ? sprintf('%s%.2f%%', $direction === 'increase' ? '+' : '-', $amount)
            : sprintf('%s%s flat', $direction === 'increase' ? '+' : '-', number_format($amount, 2));

        $this->activity->log(
            'admin',
            $this->adminId(),
            'pricing.bulk_update',
            'system',
            null,
            sprintf(
                'Bulk price update (%s): %d product price row(s) and %d TLD price field(s) changed; %d price review email(s) queued.',
                $adjustment,
                count($productResult['changes']),
                count($domainResult['changes']),
                $notify['notified']
            ),
            $request->ip()
        );

        return Response::redirect('/admin/bulk-pricing?products_changed=' . count($productResult['changes'])
            . '&domains_changed=' . count($domainResult['changes'])
            . '&notified=' . max(0, $notify['notified'])
            . '&no_impact=' . $notify['no_impact']
            . '&failed=' . max(0, $notify['failed']));
    }

    /**
     * @return array<int, array<int, array<string, mixed>>> product id => cycle => pricing row
     */
    private function allPricingByProduct(): array
    {
        $rows = $this->db->select('SELECT * FROM product_pricing');
        $byProduct = [];

        foreach ($rows as $row) {
            $byProduct[(int) $row['product_id']][(string) $row['billing_cycle']] = $row;
        }

        return $byProduct;
    }

    private function service(): BulkPriceService
    {
        /** @var EmailDispatcher $mail */
        $mail = App::container()->make(EmailDispatcher::class);

        return new BulkPriceService($this->db, $this->products, $this->pricing, $this->domainPricing, $mail);
    }

    /** @return array{services: string, domains: string} */
    private function clientAreaUrls(): array
    {
        try {
            /** @var Config $config */
            $config = App::container()->make(Config::class);
            $base = rtrim((string) $config->env('APP_URL', 'http://localhost'), '/');
        } catch (\Throwable) {
            $base = '';
        }

        return ['services' => $base . '/client/services', 'domains' => $base . '/client/domains'];
    }

    private function adminId(): int
    {
        $admin = $this->guard->currentAdmin();

        return (int) ($admin['id'] ?? 0);
    }

    private function back(string $error): Response
    {
        return Response::redirect('/admin/bulk-pricing?error=' . urlencode($error));
    }

    /**
     * Page access needs EITHER permission (each half checks its own on the
     * POST); a targeted check needs the one given.
     */
    private function requireAccess(bool $page = false, ?string $permission = null): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        $needed = $permission ?? ($page ? null : PermissionRegistry::PRODUCTS_MANAGE);

        if ($needed === null) {
            if (!$this->guard->can(PermissionRegistry::PRODUCTS_MANAGE) && !$this->guard->can(PermissionRegistry::DOMAINS_MANAGE)) {
                return Response::html('403 Forbidden — missing products.manage or domains.manage permission', 403);
            }

            return null;
        }

        if (!$this->guard->can($needed)) {
            return Response::html('403 Forbidden — missing ' . $needed . ' permission', 403);
        }

        return null;
    }
}
