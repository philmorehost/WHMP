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

        $content = $this->view->render('domains.pricing-index', [
            'pricingList' => $this->pricing->all(),
            'registrars' => $this->registrars->allEnabled(),
            'error' => null,
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Domain Pricing',
            'content' => $content,
        ]));
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
