<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;
use CodeVault\Kernel;

final class DomainPricingController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly DomainPricingRepository $pricing,
        private readonly RegistrarRepository $registrars,
        private readonly Kernel $kernel
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $updated = $request->query('updated');
        $pricingList = $this->pricing->all();

        $content = $this->view->render('domains.pricing-index', [
            'pricingList' => $pricingList,
            'registrars' => $this->registrars->allEnabled(),
            'error' => null,
            'bulkUpdated' => $updated !== null ? max(0, (int) $updated) : null,
            'reordered' => $request->query('reordered') !== null,
            // Drives the "nothing is spinner-enabled" banner. spinner_enabled
            // defaults to 0 for every TLD (migration 0106) and is a deliberate
            // per-TLD opt-in, so an install can have dozens of correctly priced
            // TLDs — the "Browse extensions by category" list a client sees is
            // proof of that — and still send zero suggestions from
            // /domains/spin, because nobody ever separately turned the spinner
            // flag on. That reads as "the domain spinner is broken" from the
            // storefront with no obvious cause; the fix belongs here, on the
            // page where the setting actually lives.
            'spinnerEnabledCount' => count(array_filter($pricingList, static fn (array $p): bool => !empty($p['spinner_enabled']))),
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Domain Pricing',
            'content' => $content,
        ]));
    }

    /**
     * Apply one set of changes to many selected TLDs.
     *
     * Every field is "leave blank to keep unchanged" — the admin fills in only
     * what they want to change, so bumping renewal prices across 40 TLDs
     * doesn't silently blank their categories or flip their registrars. A
     * field is only included when it was actually filled in.
     */
    public function bulkUpdate(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $ids = array_map('intval', (array) $request->input('ids', []));
        $fields = [];

        $category = trim((string) $request->input('category', ''));
        if ($category !== '') {
            $fields['category'] = $category;
        }

        $registrar = trim((string) $request->input('registrar_slug', ''));
        if ($registrar !== '') {
            $fields['registrar_slug'] = $registrar;
        }

        foreach (['register_price', 'transfer_price', 'renew_price', 'redemption_fee'] as $priceField) {
            $raw = trim((string) $request->input($priceField, ''));

            // '' means untouched; '0' is a legitimate price (free TLD promo),
            // so test for an empty string rather than falsiness.
            if ($raw !== '' && is_numeric($raw)) {
                $fields[$priceField] = max(0.0, (float) $raw);
            }
        }

        foreach (['grace_period_days', 'redemption_period_days'] as $daysField) {
            $raw = trim((string) $request->input($daysField, ''));

            if ($raw !== '' && is_numeric($raw)) {
                $fields[$daysField] = max(0, (int) $raw);
            }
        }

        // '' = leave alone, '1' = enable, '0' = disable.
        $spinner = (string) $request->input('spinner_enabled', '');
        if ($spinner === '1' || $spinner === '0') {
            $fields['spinner_enabled'] = (int) $spinner;
        }

        $updated = $this->pricing->bulkUpdate($ids, $fields);

        return Response::redirect('/admin/domain-pricing?updated=' . $updated);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $tld = trim((string) $request->input('tld', ''));
        $registrarSlug = trim((string) $request->input('registrar_slug', ''));
        $registerPrice = (float) $request->input('register_price', 0.0);
        $transferPrice = (float) $request->input('transfer_price', 0.0);
        $renewPrice = (float) $request->input('renew_price', 0.0);

        if ($tld === '' || $registrarSlug === '') {
            $content = $this->view->render('domains.pricing-index', [
                'pricingList' => $this->pricing->all(),
                'registrars' => $this->registrars->allEnabled(),
                'error' => 'TLD and Registrar are required fields.',
            ]);

            return Response::html($this->view->render('layouts.admin', [
                'title' => 'CodeVault Admin — Domain Pricing',
                'content' => $content,
            ]));
        }

        $this->pricing->save([
            'tld' => $tld,
            'registrar_slug' => $registrarSlug,
            'register_price' => $registerPrice,
            'transfer_price' => $transferPrice,
            'renew_price' => $renewPrice,
            'grace_period_days' => max(0, (int) $request->input('grace_period_days', 30)),
            'redemption_period_days' => max(0, (int) $request->input('redemption_period_days', 30)),
            'redemption_fee' => max(0.0, (float) $request->input('redemption_fee', 0.0)),
            'spinner_enabled' => $request->input('spinner_enabled') ? 1 : 0,
            'category' => trim((string) $request->input('category', 'Popular')),
            'autosetup_registration' => (string) $request->input('autosetup_registration', 'payment'),
            'autosetup_transfer' => (string) $request->input('autosetup_transfer', 'payment'),
        ]);

        return Response::redirect('/admin/domain-pricing');
    }

    public function bulkStore(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $tldList = trim((string) $request->input('tld_list', ''));
        $registrarSlug = trim((string) $request->input('registrar_slug', ''));
        $registerPrice = (float) $request->input('register_price', 0.0);
        $transferPrice = (float) $request->input('transfer_price', 0.0);
        $renewPrice = (float) $request->input('renew_price', 0.0);

        if ($tldList === '' || $registrarSlug === '') {
            $content = $this->view->render('domains.pricing-index', [
                'pricingList' => $this->pricing->all(),
                'registrars' => $this->registrars->allEnabled(),
                'error' => 'TLD list and Registrar are required fields.',
            ]);

            return Response::html($this->view->render('layouts.admin', [
                'title' => 'CodeVault Admin — Domain Pricing',
                'content' => $content,
            ]));
        }

        $tlds = array_filter(
            array_map('trim', preg_split('/[\s,\n]+/', $tldList)),
            fn($t) => $t !== ''
        );

        if (empty($tlds)) {
            $content = $this->view->render('domains.pricing-index', [
                'pricingList' => $this->pricing->all(),
                'registrars' => $this->registrars->allEnabled(),
                'error' => 'No valid TLDs found in the input list.',
            ]);

            return Response::html($this->view->render('layouts.admin', [
                'title' => 'CodeVault Admin — Domain Pricing',
                'content' => $content,
            ]));
        }

        $baseData = [
            'registrar_slug' => $registrarSlug,
            'register_price' => $registerPrice,
            'transfer_price' => $transferPrice,
            'renew_price' => $renewPrice,
            'grace_period_days' => max(0, (int) $request->input('grace_period_days', 30)),
            'redemption_period_days' => max(0, (int) $request->input('redemption_period_days', 30)),
            'redemption_fee' => max(0.0, (float) $request->input('redemption_fee', 0.0)),
            'spinner_enabled' => $request->input('spinner_enabled') ? 1 : 0,
            'category' => trim((string) $request->input('category', 'Popular')),
            'autosetup_registration' => (string) $request->input('autosetup_registration', 'payment'),
            'autosetup_transfer' => (string) $request->input('autosetup_transfer', 'payment'),
        ];

        foreach ($tlds as $tld) {
            $this->pricing->save(array_merge($baseData, ['tld' => $tld]));
        }

        return Response::redirect('/admin/domain-pricing');
    }

    public function whmcsExtensions(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $config = $this->kernel->config();
        $whmcsHost = (string) ($config['whmcs_db_host'] ?? '');
        $whmcsPort = (int) ($config['whmcs_db_port'] ?? 3306);
        $whmcsDb = (string) ($config['whmcs_db_name'] ?? '');
        $whmcsUser = (string) ($config['whmcs_db_username'] ?? '');
        $whmcsPass = (string) ($config['whmcs_db_password'] ?? '');
        $whmcsPrefix = (string) ($config['whmcs_db_prefix'] ?? 'tbl');

        if ($whmcsHost === '' || $whmcsDb === '' || $whmcsUser === '') {
            return Response::json([
                'success' => false,
                'message' => 'WHMCS database credentials not configured.',
                'extensions' => [],
            ]);
        }

        try {
            $dsn = "mysql:host={$whmcsHost};port={$whmcsPort};dbname={$whmcsDb};charset=utf8mb4";
            $pdo = new \PDO($dsn, $whmcsUser, $whmcsPass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 5,
            ]);

            $query = "SELECT DISTINCT extension FROM {$whmcsPrefix}domainpricing WHERE extension IS NOT NULL AND extension != '' ORDER BY extension";
            $result = $pdo->query($query)->fetchAll();

            $extensions = array_map(fn($row) => trim($row['extension']), $result);
            $extensions = array_unique(array_filter($extensions));

            return Response::json([
                'success' => true,
                'extensions' => array_values($extensions),
            ]);
        } catch (\Throwable $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to fetch WHMCS extensions: ' . $e->getMessage(),
                'extensions' => [],
            ]);
        }
    }

    /**
     * Saves the "Order" numbers typed into the pricing table — one number
     * per TLD, submitted together (see the `form="tld-reorder-form"`
     * cross-reference in the view, same technique the bulk-select checkboxes
     * already use to live outside their own <form>).
     */
    public function reorder(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $sortOrders = [];

        foreach ((array) $request->input('sort_order', []) as $id => $order) {
            if (is_numeric($id) && is_numeric($order)) {
                $sortOrders[(int) $id] = (int) $order;
            }
        }

        $this->pricing->reorder($sortOrders);

        return Response::redirect('/admin/domain-pricing?reordered=1');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->pricing->delete((int) $params['id']);

        return Response::redirect('/admin/domain-pricing');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::DOMAINS_MANAGE)) {
            return Response::html('403 Forbidden — missing domains.manage permission', 403);
        }

        return null;
    }
}
