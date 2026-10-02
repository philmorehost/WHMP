<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Reseller\ResellerMailIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Which sender a store's customer sees — decided with no database, no network and no
 * configuration, because it is a pure decision about a row.
 *
 * The property worth protecting is asymmetric: the NAME can always be the store's, but
 * the ADDRESS can only be used when it is well formed. Everything else here follows
 * from that, including the deliberate choice NOT to withhold a misaligned address (see
 * the class docblock on ResellerMailIdentity).
 */
final class ResellerMailIdentityTest extends TestCase
{
    private const PLATFORM = 'PhilmoreHost';

    /** @return array<string, mixed> */
    private function store(array $overrides = []): array
    {
        return $overrides + [
            'id' => 4,
            'brand_name' => 'Acme Hosting',
            'support_email' => null,
            'support_email_status' => null,
            'support_email_checked_at' => null,
            'support_email_detail' => null,
        ];
    }

    // ------------------------------------------------------------- the sender ---

    public function test_a_ticket_with_no_store_is_sent_as_the_platform(): void
    {
        $identity = ResellerMailIdentity::forStore(null, self::PLATFORM);

        $this->assertSame(['name' => self::PLATFORM], $identity);
        $this->assertArrayNotHasKey('email', $identity, 'No address means the configured one stays in use.');
    }

    public function test_a_store_without_an_address_still_gets_its_name_on_the_message(): void
    {
        // The important half of the promise: even with nothing configured, the customer
        // never sees the platform's NAME. Only the address, which must be deliverable,
        // falls back.
        $identity = ResellerMailIdentity::forStore($this->store(), self::PLATFORM);

        $this->assertSame(['name' => 'Acme Hosting'], $identity);
        $this->assertArrayNotHasKey('email', $identity);
    }

    public function test_a_store_with_its_own_address_sends_from_it(): void
    {
        $identity = ResellerMailIdentity::forStore(
            $this->store(['support_email' => 'support@acme.test']),
            self::PLATFORM
        );

        $this->assertSame(['name' => 'Acme Hosting', 'email' => 'support@acme.test'], $identity);
    }

    public function test_a_store_with_no_brand_falls_back_to_the_platform_name(): void
    {
        $identity = ResellerMailIdentity::forStore($this->store(['brand_name' => '   ']), self::PLATFORM);

        $this->assertSame(self::PLATFORM, $identity['name']);
    }

    // ------------------------------------------------------- the malformed case ---

    public function test_a_malformed_address_is_not_used_as_the_sender(): void
    {
        // Not a delivery preference — a mechanical one. SmtpMailer validates the sender,
        // so a typo here would throw and the customer would receive NOTHING. Losing the
        // message is worse than losing the address.
        foreach (['support@', 'not an address', 'a@b@c.test', '@acme.test'] as $broken) {
            $identity = ResellerMailIdentity::forStore($this->store(['support_email' => $broken]), self::PLATFORM);

            $this->assertSame(['name' => 'Acme Hosting'], $identity, "'{$broken}' must not be offered as a sender.");
        }
    }

    // ------------------------------------------------------------ the warnings ---

    public function test_nothing_is_warned_about_when_no_address_is_set(): void
    {
        $this->assertNull(ResellerMailIdentity::warning($this->store()));
        $this->assertNull(ResellerMailIdentity::warning(null));
    }

    public function test_nothing_is_warned_about_once_the_domain_is_confirmed_aligned(): void
    {
        $this->assertNull(ResellerMailIdentity::warning($this->store([
            'support_email' => 'support@acme.test',
            'support_email_status' => 'aligned',
        ])));

        $this->assertTrue(ResellerMailIdentity::isAligned($this->store([
            'support_email' => 'support@acme.test',
            'support_email_status' => 'aligned',
        ])));
    }

    public function test_an_address_that_failed_the_check_is_warned_about_with_the_date(): void
    {
        $warning = ResellerMailIdentity::warning($this->store([
            'support_email' => 'support@acme.test',
            'support_email_status' => 'misaligned',
            'support_email_checked_at' => '2026-10-02 09:00:00',
        ]));

        $this->assertNotNull($warning);
        $this->assertStringContainsString('not yet authorised', (string) $warning);
        $this->assertStringContainsString('2026-10-02 09:00:00', (string) $warning, 'A check result with no date reads as current forever.');
    }

    /**
     * The dangerous state is the one that looks fine.
     *
     * An address set but never checked produces no evidence either way, and it is used
     * for sending regardless — so it must produce a warning, and a DIFFERENT one from a
     * failed check, because "we have not looked" is not "we looked and it failed".
     */
    public function test_an_address_nobody_has_checked_is_warned_about_as_unverified(): void
    {
        $warning = ResellerMailIdentity::warning($this->store(['support_email' => 'support@acme.test']));

        $this->assertNotNull($warning);
        $this->assertStringContainsString('has not been checked', (string) $warning);
        $this->assertStringNotContainsString('not yet authorised', (string) $warning);
    }

    public function test_a_failed_check_carries_the_records_to_publish(): void
    {
        // A warning that does not say what to DO is half a warning.
        $store = $this->store([
            'support_email' => 'support@acme.test',
            'support_email_status' => 'misaligned',
            'support_email_detail' => 'v=spf1 include:spf.philmorehost.com ~all',
        ]);

        $this->assertSame('v=spf1 include:spf.philmorehost.com ~all', ResellerMailIdentity::remediation($store));
        $this->assertNull(ResellerMailIdentity::remediation($this->store()));
    }
}
