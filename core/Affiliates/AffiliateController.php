<?php

declare(strict_types=1);

namespace CodeVault\Affiliates;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

/**
 * The client-facing Affiliate Area (blueprint §4.2 client-area list).
 * Any client can opt in; there's no separate approval step today — a
 * hosting company that wants gated affiliate signup can add one later
 * without redesigning the underlying tracking/commission tables.
 */
final class AffiliateController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly AffiliateRepository $affiliates,
        private readonly AffiliateReferralRepository $referrals,
        private readonly AffiliateCommissionRepository $commissions,
        private readonly AffiliatePayoutRequestRepository $payoutRequests,
        private readonly AffiliateService $affiliateService
    ) {
    }

    public function index(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $affiliate = $this->affiliates->findByClient((int) $client['id']);

        if ($affiliate === null) {
            return $this->page('affiliates.client-join', []);
        }

        return $this->page('affiliates.client-dashboard', [
            'affiliate' => $affiliate,
            'referrals' => $this->referrals->forAffiliate((int) $affiliate['id']),
            'pendingBalance' => $this->commissions->pendingTotal((int) $affiliate['id']),
            'lifetimeTotal' => $this->commissions->lifetimeTotal((int) $affiliate['id']),
            'payoutRequests' => $this->payoutRequests->forAffiliate((int) $affiliate['id']),
        ]);
    }

    public function join(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        if ($this->affiliates->findByClient((int) $client['id']) === null) {
            $this->affiliates->create((int) $client['id']);
        }

        return Response::redirect('/client/affiliate');
    }

    public function requestPayout(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $affiliate = $this->affiliates->findByClient((int) $client['id']);

        if ($affiliate !== null) {
            $this->affiliateService->requestPayout((int) $affiliate['id']);
        }

        return Response::redirect('/client/affiliate');
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Affiliate Area',
            'content' => $content,
        ]));
    }
}
