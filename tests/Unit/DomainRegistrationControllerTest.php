<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Domains\DomainRegistrationController;
use CodeVault\Kernel;
use CodeVault\Tests\Support\DatabaseTestCase;
use ReflectionMethod;

/**
 * A domain search only ever accepts a single label — "sub.example.com" or
 * "sub.example.com.ng" is a subdomain, not a registrable domain, and must
 * be rejected with a clear explanation rather than silently mangled into
 * whatever the first-dot split happened to produce. Exercises search()
 * (private) directly via reflection — the same seam every candidate row on
 * the search page and the JSON availability endpoint both go through.
 */
final class DomainRegistrationControllerTest extends DatabaseTestCase
{
    private DomainRegistrationController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        new Kernel(dirname(__DIR__, 2));
        \CodeVault\Support\App::container()->instance(\CodeVault\Database::class, $this->db);

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $pricing = new DomainPricingRepository($this->db);
        $pricing->save(['tld' => '.com', 'registrar_slug' => 'local', 'register_price' => 10.00, 'transfer_price' => 10.00, 'renew_price' => 10.00]);
        $pricing->save(['tld' => '.com.ng', 'registrar_slug' => 'local', 'register_price' => 15.00, 'transfer_price' => 15.00, 'renew_price' => 15.00]);
        $pricing->save(['tld' => '.ng', 'registrar_slug' => 'local', 'register_price' => 8.00, 'transfer_price' => 8.00, 'renew_price' => 8.00]);

        $this->controller = \CodeVault\Support\App::container()->make(DomainRegistrationController::class);
    }

    /** @return array<int, array{tld: string, domain: string, price: float, offered: bool, message: string}> */
    private function search(string $query): array
    {
        $method = new ReflectionMethod($this->controller, 'search');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $query);
    }

    public function test_a_plain_name_and_tld_is_offered(): void
    {
        $results = $this->search('mybrand.com');

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['offered']);
        $this->assertSame('mybrand.com', $results[0]['domain']);
    }

    public function test_a_name_with_a_hyphen_is_offered(): void
    {
        $results = $this->search('my-brand.com');

        $this->assertTrue($results[0]['offered']);
        $this->assertSame('my-brand.com', $results[0]['domain']);
    }

    public function test_a_compound_tld_is_recognised_as_one_unit_not_a_subdomain(): void
    {
        $results = $this->search('mybrand.com.ng');

        $this->assertTrue($results[0]['offered']);
        $this->assertSame('mybrand.com.ng', $results[0]['domain']);
        $this->assertSame('.com.ng', $results[0]['tld']);
    }

    public function test_a_subdomain_of_a_dot_com_is_rejected_with_a_guide_message(): void
    {
        $results = $this->search('subdomain.domain.com');

        $this->assertFalse($results[0]['offered']);
        $this->assertStringContainsString('subdomain', $results[0]['message']);
        $this->assertStringContainsString('hyphen', $results[0]['message']);
    }

    public function test_a_subdomain_of_a_compound_tld_is_rejected_with_a_guide_message(): void
    {
        $results = $this->search('subdomain.domain.com.ng');

        $this->assertFalse($results[0]['offered']);
        $this->assertStringContainsString('subdomain', $results[0]['message']);
    }

    public function test_a_two_label_query_matching_no_configured_tld_is_not_offered_not_reported_as_a_subdomain(): void
    {
        // "sub.domain" isn't a subdomain claim here — ".domain" simply
        // isn't a TLD this install sells, so the generic "not offered"
        // message is the correct (and only honest) one to show.
        $results = $this->search('sub.domain');

        $this->assertFalse($results[0]['offered']);
        $this->assertStringContainsString("isn't offered here", $results[0]['message']);
    }

    public function test_a_name_with_no_dot_and_no_tld_lists_every_configured_tld(): void
    {
        $results = $this->search('mybrand');

        // Migration 0097 seeds .com/.net/.org/.com.ng/.ng by default —
        // assert the ones this test explicitly configured are present and
        // offered, rather than hardcoding a total count that would break
        // if the default seed list ever changes.
        $tlds = array_column($results, 'tld');
        $this->assertContains('.com', $tlds);
        $this->assertContains('.com.ng', $tlds);
        $this->assertContains('.ng', $tlds);
        foreach ($results as $result) {
            $this->assertTrue($result['offered']);
        }
    }

    public function test_an_unconfigured_tld_is_rejected_as_not_offered_not_as_a_subdomain(): void
    {
        $results = $this->search('mybrand.xyz');

        $this->assertFalse($results[0]['offered']);
        $this->assertStringContainsString("isn't offered here", $results[0]['message']);
    }
}
