<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Affiliates\AffiliateCommissionRepository;
use CodeVault\Affiliates\AffiliatePayoutRequestRepository;
use CodeVault\Affiliates\AffiliateReferralRepository;
use CodeVault\Affiliates\AffiliateRepository;
use CodeVault\Affiliates\AffiliateService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class AffiliateServiceTest extends DatabaseTestCase
{
    private AffiliateRepository $affiliates;
    private AffiliateReferralRepository $referrals;
    private AffiliateCommissionRepository $commissions;
    private AffiliatePayoutRequestRepository $payoutRequests;
    private ClientRepository $clients;
    private AffiliateService $service;
    private int $affiliateClientId;
    private int $affiliateId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->affiliates = new AffiliateRepository($this->db);
        $this->referrals = new AffiliateReferralRepository($this->db);
        $this->commissions = new AffiliateCommissionRepository($this->db);
        $this->payoutRequests = new AffiliatePayoutRequestRepository($this->db);
        $this->clients = new ClientRepository($this->db);
        $this->service = new AffiliateService(
            $this->affiliates,
            $this->referrals,
            $this->commissions,
            $this->payoutRequests,
            new InvoiceRepository($this->db),
            new SettingsRepository($this->db)
        );

        // The lifecycle tests below are about request -> approve/reject -> status,
        // NOT about the minimum. The seeded default is 50.00 (migration 0115) and
        // their fixtures earn 20.00, so leaving the minimum unset made them fail
        // for a reason they do not intend to test. It is set to "no minimum" here,
        // and the minimum rule has its own test below.
        (new SettingsRepository($this->db))->set('affiliates.min_payout', '0.00');

        $this->affiliateClientId = $this->clients->create([
            'email' => 'affiliate@example.test',
            'password' => 'secret123',
            'first_name' => 'Affi',
            'last_name' => 'Liate',
        ]);
        $this->affiliateId = $this->affiliates->create($this->affiliateClientId, 10.00);
    }

    private function referredClient(string $email = 'referred@example.test'): int
    {
        return $this->clients->create([
            'email' => $email,
            'password' => 'secret123',
            'first_name' => 'Referred',
            'last_name' => 'Client',
        ]);
    }

    private function paidInvoice(int $clientId, float $total): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at, paid_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, 'paid', $total, 0, $total, substr($now, 0, 10), $now, $now, $now]
        );
    }

    public function test_valid_referral_code_attributes_the_new_client_to_the_affiliate(): void
    {
        $affiliate = $this->affiliates->find($this->affiliateId);
        $referredId = $this->referredClient();

        $this->service->registerReferral($affiliate['code'], $referredId);

        $referral = $this->referrals->findByReferredClient($referredId);
        $this->assertNotNull($referral);
        $this->assertSame($this->affiliateId, (int) $referral['affiliate_id']);
    }

    public function test_unknown_referral_code_is_a_silent_no_op(): void
    {
        $referredId = $this->referredClient();

        $this->service->registerReferral('NOTAREALCODE', $referredId);

        $this->assertNull($this->referrals->findByReferredClient($referredId));
    }

    public function test_blank_referral_code_is_a_silent_no_op(): void
    {
        $referredId = $this->referredClient();

        $this->service->registerReferral('', $referredId);
        $this->service->registerReferral(null, $referredId);

        $this->assertNull($this->referrals->findByReferredClient($referredId));
    }

    public function test_paid_invoice_from_a_referred_client_accrues_commission_at_the_configured_rate(): void
    {
        $affiliate = $this->affiliates->find($this->affiliateId);
        $referredId = $this->referredClient();
        $this->service->registerReferral($affiliate['code'], $referredId);

        $invoiceId = $this->paidInvoice($referredId, 100.00);
        $this->service->accrueCommission($invoiceId);

        $this->assertSame(10.0, $this->commissions->pendingTotal($this->affiliateId));
    }

    public function test_invoice_from_a_non_referred_client_accrues_nothing(): void
    {
        $unreferredId = $this->referredClient('unreferred@example.test');
        $invoiceId = $this->paidInvoice($unreferredId, 100.00);

        $this->service->accrueCommission($invoiceId);

        $this->assertSame(0.0, $this->commissions->pendingTotal($this->affiliateId));
    }

    public function test_accruing_twice_for_the_same_invoice_does_not_double_count(): void
    {
        $affiliate = $this->affiliates->find($this->affiliateId);
        $referredId = $this->referredClient();
        $this->service->registerReferral($affiliate['code'], $referredId);
        $invoiceId = $this->paidInvoice($referredId, 100.00);

        $this->service->accrueCommission($invoiceId);
        $this->service->accrueCommission($invoiceId);

        $this->assertSame(10.0, $this->commissions->pendingTotal($this->affiliateId));
    }

    public function test_suspended_affiliate_does_not_accrue_commission(): void
    {
        $affiliate = $this->affiliates->find($this->affiliateId);
        $referredId = $this->referredClient();
        $this->service->registerReferral($affiliate['code'], $referredId);
        $this->affiliates->setStatus($this->affiliateId, 'suspended');

        $invoiceId = $this->paidInvoice($referredId, 100.00);
        $this->service->accrueCommission($invoiceId);

        $this->assertSame(0.0, $this->commissions->pendingTotal($this->affiliateId));
    }

    public function test_full_payout_lifecycle_request_then_approve_zeroes_pending_balance(): void
    {
        $affiliate = $this->affiliates->find($this->affiliateId);
        $referredId = $this->referredClient();
        $this->service->registerReferral($affiliate['code'], $referredId);
        $this->service->accrueCommission($this->paidInvoice($referredId, 200.00));

        $this->assertSame(20.0, $this->commissions->pendingTotal($this->affiliateId));

        $result = $this->service->requestPayout($this->affiliateId);
        $this->assertTrue($result['success']);
        $this->assertSame(0.0, $this->commissions->pendingTotal($this->affiliateId));

        $payout = $this->payoutRequests->find($result['payoutRequestId']);
        // Compared numerically, not as a string: migration 0126 widened money
        // columns to 6dp for currency precision, so this reads '20.000000'. What
        // the test is asserting is the AMOUNT, not a column's display scale.
        $this->assertSame(20.0, (float) $payout['amount']);
        $this->assertSame('requested', $payout['status']);

        $this->service->approvePayout($result['payoutRequestId']);

        $payout = $this->payoutRequests->find($result['payoutRequestId']);
        $this->assertSame('paid', $payout['status']);
    }

    public function test_rejecting_a_payout_returns_commissions_to_pending(): void
    {
        $affiliate = $this->affiliates->find($this->affiliateId);
        $referredId = $this->referredClient();
        $this->service->registerReferral($affiliate['code'], $referredId);
        $this->service->accrueCommission($this->paidInvoice($referredId, 200.00));

        $result = $this->service->requestPayout($this->affiliateId);
        $this->assertSame(0.0, $this->commissions->pendingTotal($this->affiliateId));

        $this->service->rejectPayout($result['payoutRequestId']);

        $this->assertSame(20.0, $this->commissions->pendingTotal($this->affiliateId));
        $payout = $this->payoutRequests->find($result['payoutRequestId']);
        $this->assertSame('rejected', $payout['status']);
    }

    public function test_requesting_payout_with_zero_balance_fails(): void
    {
        $result = $this->service->requestPayout($this->affiliateId);

        $this->assertFalse($result['success']);
    }

    /**
     * The rule the undefined-$pending bug was silently blocking: a healthy
     * balance above the minimum must be withdrawable, and a balance below it
     * must be refused WITHOUT consuming anything.
     */
    public function test_a_balance_below_the_configured_minimum_cannot_be_withdrawn(): void
    {
        (new SettingsRepository($this->db))->set('affiliates.min_payout', '50.00');

        $affiliate = $this->affiliates->find($this->affiliateId);
        $referredId = $this->referredClient();
        $this->service->registerReferral($affiliate['code'], $referredId);
        $this->service->accrueCommission($this->paidInvoice($referredId, 200.00));

        $this->assertSame(20.0, $this->commissions->pendingTotal($this->affiliateId));

        $result = $this->service->requestPayout($this->affiliateId);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('minimum', (string) $result['error']);

        // A refusal must not consume the balance: the commission is still
        // pending, so it can be withdrawn once it clears the minimum.
        $this->assertSame(20.0, $this->commissions->pendingTotal($this->affiliateId));
    }

    public function test_a_balance_above_the_minimum_is_withdrawable(): void
    {
        (new SettingsRepository($this->db))->set('affiliates.min_payout', '10.00');

        $affiliate = $this->affiliates->find($this->affiliateId);
        $referredId = $this->referredClient();
        $this->service->registerReferral($affiliate['code'], $referredId);
        $this->service->accrueCommission($this->paidInvoice($referredId, 200.00));

        $result = $this->service->requestPayout($this->affiliateId);

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $this->assertSame(20.0, (float) $this->payoutRequests->find($result['payoutRequestId'])['amount']);
    }

    public function test_cannot_request_a_second_payout_while_one_is_outstanding(): void
    {
        $affiliate = $this->affiliates->find($this->affiliateId);
        $referredId = $this->referredClient();
        $this->service->registerReferral($affiliate['code'], $referredId);
        $this->service->accrueCommission($this->paidInvoice($referredId, 100.00));

        $first = $this->service->requestPayout($this->affiliateId);
        $this->assertTrue($first['success']);

        $second = $this->service->requestPayout($this->affiliateId);
        $this->assertFalse($second['success']);
    }
}
