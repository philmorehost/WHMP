<?php

declare(strict_types=1);

namespace CodeVault\Affiliates;

use CodeVault\Billing\InvoiceRepository;

/**
 * Affiliate engine orchestration (blueprint §4.4): referral attribution
 * at signup, commission accrual on paid invoices, and payout requests.
 * Commissions accrue on every paid invoice from a referred client (a
 * recurring-commission model), not just their first order — simpler to
 * reason about than tracking "first invoice only" and a legitimate,
 * common affiliate-program shape in its own right.
 */
final class AffiliateService
{
    public function __construct(
        private readonly AffiliateRepository $affiliates,
        private readonly AffiliateReferralRepository $referrals,
        private readonly AffiliateCommissionRepository $commissions,
        private readonly AffiliatePayoutRequestRepository $payoutRequests,
        private readonly InvoiceRepository $invoices
    ) {
    }

    /**
     * Attributes a newly registered client to the affiliate whose code
     * was in the referral link. No-op if the code is blank/unknown, the
     * affiliate is a client referring themselves, or the client is
     * already attributed (shouldn't happen for a brand-new client, but
     * cheap to guard).
     */
    public function registerReferral(?string $code, int $referredClientId): void
    {
        if ($code === null || $code === '') {
            return;
        }

        $affiliate = $this->affiliates->findByCode($code);

        if ($affiliate === null || (int) $affiliate['client_id'] === $referredClientId) {
            return;
        }

        if ($this->referrals->findByReferredClient($referredClientId) !== null) {
            return;
        }

        $this->referrals->create((int) $affiliate['id'], $referredClientId);
    }

    public function accrueCommission(int $invoiceId): void
    {
        $invoice = $this->invoices->find($invoiceId);

        if ($invoice === null) {
            return;
        }

        $referral = $this->referrals->findByReferredClient((int) $invoice['client_id']);

        if ($referral === null) {
            return;
        }

        $affiliate = $this->affiliates->find((int) $referral['affiliate_id']);

        if ($affiliate === null || $affiliate['status'] !== 'active') {
            return;
        }

        if ($this->commissions->existsForInvoice((int) $affiliate['id'], $invoiceId)) {
            return;
        }

        $amount = round((float) $invoice['total'] * ((float) $affiliate['commission_rate'] / 100), 2);

        if ($amount <= 0) {
            return;
        }

        $this->commissions->create((int) $affiliate['id'], $invoiceId, $amount);
    }

    /** @return array{success: bool, error?: string, payoutRequestId?: int} */
    public function requestPayout(int $affiliateId): array
    {
        if ($this->payoutRequests->hasOutstanding($affiliateId)) {
            return ['success' => false, 'error' => 'You already have a payout request pending review.'];
        }

        $minPayout = (float) \CodeVault\Support\App::container()->make(\CodeVault\Settings\SettingsRepository::class)->get('affiliates.min_payout', '50.00');

        if ($pending < $minPayout) {
            return ['success' => false, 'error' => "The minimum commission balance required for a payout request is $" . number_format($minPayout, 2) . "."];
        }

        $this->commissions->markRequested($affiliateId);
        $id = $this->payoutRequests->create($affiliateId, $pending);

        return ['success' => true, 'payoutRequestId' => $id];
    }

    public function approvePayout(int $payoutRequestId): void
    {
        $request = $this->payoutRequests->find($payoutRequestId);

        if ($request === null || $request['status'] !== 'requested') {
            return;
        }

        $this->commissions->markPaidForAffiliate((int) $request['affiliate_id']);
        $this->payoutRequests->updateStatus($payoutRequestId, 'paid');
    }

    public function rejectPayout(int $payoutRequestId): void
    {
        $request = $this->payoutRequests->find($payoutRequestId);

        if ($request === null || $request['status'] !== 'requested') {
            return;
        }

        $this->commissions->revertRequestedToPending((int) $request['affiliate_id']);
        $this->payoutRequests->updateStatus($payoutRequestId, 'rejected');
    }
}
