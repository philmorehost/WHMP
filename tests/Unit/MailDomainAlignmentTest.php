<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Reseller\MailDomainAlignment;
use PHPUnit\Framework\TestCase;

/**
 * Can a store's domain send as itself?
 *
 * No database and no network: the DNS lookup is injected, which is also what makes it
 * possible to test the states that matter most — a resolver that FAILS, a revoked DKIM
 * key, and an SPF record that looks like authorisation but is not.
 */
final class MailDomainAlignmentTest extends TestCase
{
    /**
     * @param array<string, array<int, string>|null> $zone
     */
    private function checker(array $zone): MailDomainAlignment
    {
        return new MailDomainAlignment(
            // array_key_exists, NOT `?? []`. The null coalescing operator treats a
            // NULL VALUE exactly like a MISSING KEY, so `$zone[$name] ?? []` can never
            // return null — which is the one answer this fake exists to be able to
            // give. The double would then quietly agree with the collapse the class
            // under test is written to prevent, and the test would pass for the wrong
            // reason while proving nothing.
            static fn (string $name): ?array => array_key_exists($name, $zone) ? $zone[$name] : [],
            'spf.philmorehost.test',
            'default'
        );
    }

    // ------------------------------------------------------------ the pass paths ---

    public function test_our_include_authorises_the_domain(): void
    {
        $result = $this->checker([
            'acme.test' => ['v=spf1 include:spf.philmorehost.test ~all'],
        ])->check('support@acme.test');

        $this->assertSame(MailDomainAlignment::ALIGNED, $result['status']);
    }

    /**
     * The case that is easy to get wrong, and the common one in practice.
     *
     * `a` authorises the domain's own A record. On our panel the store's domain points at
     * the server we send from, so the domain is ALREADY authorised — a reseller hosted
     * here is frequently aligned without ever adding our include. Reporting that as a
     * failure would send them editing DNS that was already correct.
     */
    public function test_a_bare_a_or_mx_mechanism_authorises_the_domain(): void
    {
        foreach (['v=spf1 a -all', 'v=spf1 mx -all', 'v=spf1 a:acme.test ~all'] as $record) {
            $result = $this->checker(['acme.test' => [$record]])->check('support@acme.test');

            $this->assertSame(MailDomainAlignment::ALIGNED, $result['status'], "'{$record}' authorises the domain's own host.");
        }
    }

    public function test_a_published_dkim_key_aligns_the_domain_on_its_own(): void
    {
        // The stronger path on a cPanel host: Exim signs with the domain's own key.
        $result = $this->checker([
            'default._domainkey.acme.test' => ['v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A'],
        ])->check('support@acme.test');

        $this->assertSame(MailDomainAlignment::ALIGNED, $result['status']);
        $this->assertTrue($result['dkim']);
    }

    // ----------------------------------------------------------- the fail paths ---

    /**
     * `~all` is a qualifier on what happens to everything ELSE. It grants nothing.
     */
    public function test_an_spf_record_that_does_not_mention_us_is_not_aligned(): void
    {
        $result = $this->checker([
            'acme.test' => ['v=spf1 include:someotherprovider.test ~all'],
        ])->check('support@acme.test');

        $this->assertSame(MailDomainAlignment::MISALIGNED, $result['status']);
        $this->assertStringContainsString('include:spf.philmorehost.test', (string) $result['detail'], 'The fix must be spelled out.');
    }

    public function test_a_domain_with_no_records_tells_the_reseller_exactly_what_to_add(): void
    {
        $result = $this->checker([])->check('support@acme.test');

        $this->assertSame(MailDomainAlignment::MISALIGNED, $result['status']);
        $this->assertStringContainsString('v=spf1 include:spf.philmorehost.test ~all', (string) $result['detail']);
    }

    /**
     * An EMPTY `p=` is a revoked key — a deliberate "do not trust this selector".
     * Reading its presence as alignment would be exactly backwards.
     */
    public function test_a_revoked_dkim_key_does_not_align_the_domain(): void
    {
        $result = $this->checker([
            'default._domainkey.acme.test' => ['v=DKIM1; k=rsa; p='],
        ])->check('support@acme.test');

        $this->assertSame(MailDomainAlignment::MISALIGNED, $result['status']);
        $this->assertFalse($result['dkim']);
    }

    // ------------------------------------------------ unavailable is NOT misaligned ---

    /**
     * The distinction this class exists to preserve.
     *
     * A resolver outage is not a verdict. Reporting it as misaligned sends the reseller
     * to edit DNS that may be perfectly correct, and it is the failure mode that would
     * be introduced by collapsing "we could not look" into "it failed".
     */
    public function test_a_lookup_that_could_not_run_is_unknown_rather_than_failed(): void
    {
        $result = $this->checker([
            'acme.test' => null,
            'default._domainkey.acme.test' => null,
        ])->check('support@acme.test');

        $this->assertSame(MailDomainAlignment::UNAVAILABLE, $result['status']);
        $this->assertStringContainsString('could not reach DNS', (string) $result['detail']);
        $this->assertStringNotContainsString('v=spf1', (string) $result['detail'], 'There is nothing to fix, so nothing should be suggested.');
    }

    /**
     * If ONE lookup ran and found nothing, we are entitled to a verdict.
     */
    public function test_one_successful_lookup_is_enough_to_decide(): void
    {
        $result = $this->checker([
            'acme.test' => ['v=spf1 include:spf.philmorehost.test ~all'],
            'default._domainkey.acme.test' => null,
        ])->check('support@acme.test');

        $this->assertSame(MailDomainAlignment::ALIGNED, $result['status']);
    }

    // ------------------------------------------------------------------ malformed ---

    public function test_an_address_that_is_not_an_address_is_reported_as_such(): void
    {
        $result = $this->checker([])->check('not-an-address');

        $this->assertSame(MailDomainAlignment::MISALIGNED, $result['status']);
        $this->assertStringContainsString('not an email address', (string) $result['detail']);
    }
}
