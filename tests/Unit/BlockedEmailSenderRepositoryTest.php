<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database\Migrator;
use CodeVault\Support\BlockedEmailSenderRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * The blocked-sender list mail piping consults before turning an incoming
 * message into a ticket or reply. Entries are lowercased, deduped on the
 * pattern, and matched exact-or-wildcard against the From address.
 */
final class BlockedEmailSenderRepositoryTest extends DatabaseTestCase
{
    private BlockedEmailSenderRepository $blockedSenders;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->blockedSenders = new BlockedEmailSenderRepository($this->db);
    }

    public function test_block_lowercases_and_stores_the_pattern(): void
    {
        $id = $this->blockedSenders->block('Mailer-Daemon@Whiterider.PMHSERVER.Name.ng');

        $this->assertGreaterThan(0, $id);
        $this->assertSame('mailer-daemon@whiterider.pmhserver.name.ng', $this->blockedSenders->all()[0]['pattern']);
    }

    public function test_block_is_deduplicated_on_the_pattern(): void
    {
        $first = $this->blockedSenders->block('spam@example.com');
        $second = $this->blockedSenders->block(' SPAM@example.COM ');

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->blockedSenders->all());
    }

    public function test_block_returns_zero_for_an_empty_pattern(): void
    {
        $this->assertSame(0, $this->blockedSenders->block('   '));
        $this->assertSame([], $this->blockedSenders->all());
    }

    public function test_exact_pattern_matches_only_that_address(): void
    {
        $this->blockedSenders->block('mailer-daemon@whiterider.pmhserver.name.ng');

        $this->assertTrue($this->blockedSenders->isBlocked('Mailer-Daemon@whiterider.pmhserver.name.ng'));
        $this->assertFalse($this->blockedSenders->isBlocked('mailer-daemon@pmhserver.name.ng'));
    }

    public function test_wildcard_pattern_matches_any_address_on_the_domain(): void
    {
        $this->blockedSenders->block('*@pmhserver.name.ng');

        $this->assertTrue($this->blockedSenders->isBlocked('mailer-daemon@whiterider.pmhserver.name.ng'));
        $this->assertTrue($this->blockedSenders->isBlocked('MAILER-DAEMON@pmhserver.name.ng'));
        $this->assertFalse($this->blockedSenders->isBlocked('someone@example.com'));
    }

    public function test_matching_pattern_returns_the_blocking_entry(): void
    {
        $this->blockedSenders->block('*@pmhserver.name.ng');

        $this->assertSame('*@pmhserver.name.ng', $this->blockedSenders->matchingPattern('mailer-daemon@whiterider.pmhserver.name.ng'));
        $this->assertNull($this->blockedSenders->matchingPattern('someone@example.com'));
        $this->assertNull($this->blockedSenders->matchingPattern(''));
    }

    public function test_delete_removes_the_entry(): void
    {
        $id = $this->blockedSenders->block('spam@example.com');

        $this->assertTrue($this->blockedSenders->delete($id));
        $this->assertFalse($this->blockedSenders->isBlocked('spam@example.com'));
        $this->assertFalse($this->blockedSenders->delete($id));
    }
}
