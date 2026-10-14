<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Database;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;
use Throwable;

/**
 * Super-admin settings for the Free Reseller programme: whether it is open, where
 * it is advertised, the landing page's words, and the demo stores prospects see.
 *
 * The programme's RULES (discounts, holding period, payout minimum, cost billing)
 * are not edited here — they already have their own pages, and the landing page
 * reads them live. This page links to them so the admin can see the whole offer.
 */
final class AdminFreeResellerController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly FreeResellerProgramme $programme,
        private readonly ResellerSettings $resellerSettings,
        private readonly ResellerLedgerService $ledger,
        private readonly ResellerStoreLocator $locator,
        private readonly ActivityLogger $activity,
        private readonly ?Database $db = null
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $content = $this->view->render('reseller.admin-free-programme', [
            'enabled' => $this->programme->enabled(),
            // The switches as SAVED, not as effective: with the programme closed both
            // adverts are off in effect, but the admin's choice must survive reopening.
            'clientAdvert' => $this->programme->switchOn(FreeResellerProgramme::KEY_CLIENT_ADVERT),
            'publicBanner' => $this->programme->switchOn(FreeResellerProgramme::KEY_PUBLIC_BANNER),
            'headline' => $this->programme->headline(),
            'tagline' => $this->programme->tagline(),
            'bannerText' => $this->programme->bannerText(),
            'demoLinks' => $this->programme->demoLinks(),
            'stats' => $this->stats(),
            'rules' => [
                'serviceDiscount' => $this->resellerSettings->serviceDiscount(),
                'domainDiscount' => $this->resellerSettings->domainDiscount(),
                'holdingDays' => $this->ledger->holdingDays(),
                'payoutMinimum' => number_format($this->ledger->payoutMinimum(), 2) . ' ' . $this->ledger->payoutMinimumCurrency(),
                'storeDomain' => $this->locator->storeDomain(),
            ],
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Free Reseller programme',
            'content' => $content,
        ]));
    }

    public function save(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $labels = $request->input('demo_label', []);
        $urls = $request->input('demo_url', []);

        $result = $this->programme->save(
            (string) $request->input('enabled', '0') === '1',
            (string) $request->input('client_advert', '0') === '1',
            (string) $request->input('public_banner', '0') === '1',
            (string) $request->input('headline', ''),
            (string) $request->input('tagline', ''),
            (string) $request->input('banner_text', ''),
            is_array($labels) ? $labels : [],
            is_array($urls) ? $urls : []
        );

        if ($result['errors'] !== []) {
            $this->session->flash('reseller_error', 'Saved, with corrections: ' . implode(' ', $result['errors']));
        } else {
            $this->session->flash('reseller_notice', 'Free Reseller programme settings saved.');
        }

        $admin = $this->guard->current();
        $this->activity->log(
            'admin',
            $admin === null ? null : (int) $admin['id'],
            'reseller.free_programme.settings',
            null,
            null,
            'Updated the Free Reseller programme settings (' . ($this->programme->enabled() ? 'open' : 'closed') . ', '
                . count($this->programme->demoLinks()) . ' demo link(s))',
            $request->ip()
        );

        return Response::redirect('/admin/resellers/free-programme');
    }

    /** @return array{stores: int, last30: int, pendingDomains: int} */
    private function stats(): array
    {
        $stats = ['stores' => 0, 'last30' => 0, 'pendingDomains' => 0];

        if ($this->db === null) {
            return $stats;
        }

        try {
            $row = $this->db->selectOne(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recent,
                        SUM(CASE WHEN domain_status = 'pending' THEN 1 ELSE 0 END) AS pending
                   FROM resellers"
            );
            $stats = [
                'stores' => (int) ($row['total'] ?? 0),
                'last30' => (int) ($row['recent'] ?? 0),
                'pendingDomains' => (int) ($row['pending'] ?? 0),
            ];
        } catch (Throwable) {
        }

        return $stats;
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::RESELLERS_MANAGE)) {
            return Response::html('403 Forbidden — missing resellers.manage permission', 403);
        }

        return null;
    }
}
