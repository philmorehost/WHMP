<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Reseller\ClientMigrationRepository;
use CodeVault\Reseller\ClientMigrationService;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Support\TicketAttachmentRepository;
use CodeVault\Support\TicketReplyRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Support\TicketService;
use CodeVault\Tests\Support\CapturingQueue;
use CodeVault\Tests\Support\ScriptedDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Moving a client between providers.
 *
 * The rules that matter most are about what a move must NOT do — touch a store's
 * money, tell a reseller about another store's customers, tell the old store where its
 * customer went — so several tests assert on the absence of a write or a word.
 *
 * The world: store 7 "Acme" (acme.test, owned by client 500) with customer 42; store 8
 * "Zeta" (zeta.<platform>, owned by client 600).
 */
final class ClientMigrationServiceTest extends TestCase
{
    private ?string $savedAppUrl = null;
    private ScriptedDatabase $db;
    /** @var int|null what the locked re-read reports as the owner (null = same as the row) */
    private ?int $ownerUnderLock = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedAppUrl = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = 'https://philmorehost.test';
        $this->db = $this->world();
    }

    protected function tearDown(): void
    {
        if ($this->savedAppUrl === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->savedAppUrl;
        }

        parent::tearDown();
    }

    private function world(): ScriptedDatabase
    {
        $stores = [
            7 => ['id' => 7, 'client_id' => 500, 'slug' => 'acme', 'status' => 'active', 'brand_name' => 'Acme',
                'custom_domain' => 'acme.test', 'domain_verified_at' => '2026-01-01 00:00:00'],
            8 => ['id' => 8, 'client_id' => 600, 'slug' => 'zeta', 'status' => 'active', 'brand_name' => 'Zeta',
                'custom_domain' => null, 'domain_verified_at' => null],
        ];
        $clients = [
            42 => ['id' => 42, 'reseller_id' => 7, 'email' => 'jane@customer.test', 'first_name' => 'Jane', 'last_name' => 'Doe'],
            500 => ['id' => 500, 'reseller_id' => null, 'email' => 'owner@acme.test', 'first_name' => 'Ann', 'last_name' => 'Owner'],
            600 => ['id' => 600, 'reseller_id' => null, 'email' => 'owner@zeta.test', 'first_name' => 'Zed', 'last_name' => 'Owner'],
        ];

        return (new ScriptedDatabase())
            ->on('/FOR UPDATE/', function (array $b) use ($clients): array {
                $row = $clients[(int) $b[0]] ?? null;

                if ($row !== null && $this->ownerUnderLock !== null) {
                    $row['reseller_id'] = $this->ownerUnderLock;
                }

                return $row === null ? [] : [$row];
            })
            ->on('/FROM clients c\b/', static fn (array $b): array => isset($clients[(int) $b[0]]) ? [$clients[(int) $b[0]]] : [])
            ->on('/FROM clients WHERE email/', static fn (array $b): array => array_values(array_filter($clients, static fn (array $c): bool => $c['email'] === $b[0])))
            ->on('/FROM resellers WHERE id/', static fn (array $b): array => isset($stores[(int) $b[0]]) ? [$stores[(int) $b[0]]] : [])
            ->on('/FROM resellers WHERE client_id/', static fn (array $b): array => array_values(array_filter($stores, static fn (array $s): bool => $s['client_id'] === (int) $b[0])))
            ->on('/FROM resellers WHERE slug/', static fn (array $b): array => array_values(array_filter($stores, static fn (array $s): bool => $s['slug'] === $b[0])))
            ->on('/FROM resellers WHERE custom_domain/', static fn (array $b): array => array_values(array_filter($stores, static fn (array $s): bool => $s['custom_domain'] === $b[0] && $s['domain_verified_at'] !== null)))
            ->on('/FROM departments/', [['id' => 1]])
            // Every ticket opened in these tests is on store 7's desk.
            ->on('/FROM tickets t\b/', static fn (array $b): array => [['id' => (int) $b[0], 'reseller_id' => 7]])
            ->on('/COUNT\(\*\) AS n FROM services/', [['n' => 3]])
            ->on('/COUNT\(\*\) AS n FROM domains/', [['n' => 2]]);
    }

    private function service(): ClientMigrationService
    {
        $db = $this->db;
        $config = new Config(sys_get_temp_dir() . '/codevault-migration-noenv-' . uniqid());
        $stores = new ResellerStoreRepository($db);
        $tickets = new TicketRepository($db);

        return new ClientMigrationService(
            $db,
            new ClientMigrationRepository($db),
            $stores,
            new ResellerStoreLocator($stores, $config),
            new ClientRepository($db),
            new TicketService($tickets, new TicketReplyRepository($db), new HookDispatcher(), new TicketAttachmentRepository($db)),
            $tickets,
            new EmailDispatcher(new EmailTemplateRepository($db), new EmailLogRepository($db), new CapturingQueue())
        );
    }

    /** @return array<int, string> SQL of every write */
    private function writes(): array
    {
        return array_map(static fn (array $w): string => (string) preg_replace('/\s+/', ' ', $w['sql']), $this->db->writes);
    }

    /** @return array{sql: string, bindings: array<int, mixed>}|null */
    private function write(string $pattern): ?array
    {
        foreach ($this->db->writes as $write) {
            if (preg_match($pattern, (string) preg_replace('/\s+/', ' ', $write['sql'])) === 1) {
                return $write;
            }
        }

        return null;
    }

    // ------------------------------------------------------------ destinations ---

    public function test_a_website_address_resolves_to_the_provider_serving_it(): void
    {
        $service = $this->service();

        $this->assertSame('platform', $service->resolveTarget('philmorehost.test')['target'] ?? null);
        $this->assertSame('platform', $service->resolveTarget('https://www.philmorehost.test/cart')['target'] ?? null);
        $this->assertSame(8, (int) ($service->resolveTarget('zeta.philmorehost.test')['store']['id'] ?? 0));
        $this->assertSame(7, (int) ($service->resolveTarget('https://Acme.test/store')['store']['id'] ?? 0));
        $this->assertFalse($service->resolveTarget('nowhere.test')['ok']);
        $this->assertFalse($service->resolveTarget('')['ok']);
    }

    // ----------------------------------------------------------------- preview ---

    public function test_a_store_owner_cannot_be_made_another_stores_customer(): void
    {
        $preview = $this->service()->preview(500, 'store', 8);

        $this->assertFalse($preview['ok']);
        $this->assertStringContainsString('runs a reseller store', (string) $preview['error']);
    }

    public function test_moving_a_client_to_where_they_already_are_is_refused(): void
    {
        $this->assertFalse($this->service()->preview(42, 'store', 7)['ok']);
    }

    public function test_the_preview_counts_what_will_move(): void
    {
        $preview = $this->service()->preview(42, 'store', 8);

        $this->assertTrue($preview['ok']);
        $this->assertSame(3, $preview['counts']['services']);
        $this->assertSame(2, $preview['counts']['domains']);
        $this->assertSame([], $this->db->writes, 'a preview must not write anything');
    }

    // ----------------------------------------------------------------- migrate ---

    public function test_a_move_re_points_the_client_and_their_tickets_and_records_it(): void
    {
        $result = $this->service()->migrate(42, 'store', 8, 1, null, 'asked by phone');

        $this->assertTrue($result['success'], (string) $result['error']);

        $client = $this->write('/^UPDATE clients SET reseller_id/');
        $this->assertNotNull($client);
        $this->assertSame(8, $client['bindings'][0]);

        $tickets = $this->write('/^UPDATE tickets SET reseller_id = \?/');
        $this->assertNotNull($tickets, 'the ticket history must move with the client');
        $this->assertSame(8, $tickets['bindings'][0]);
        // Guest tickets from the same address on the OLD store go too.
        $this->assertSame(['jane@customer.test', 7], array_slice($tickets['bindings'], -2));

        $audit = $this->write('/^INSERT INTO client_migrations/');
        $this->assertNotNull($audit);
        $this->assertContains('completed', $audit['bindings']);
        $this->assertContains('asked by phone', $audit['bindings']);
    }

    public function test_a_move_never_touches_money_the_old_store_earned(): void
    {
        $this->service()->migrate(42, 'store', 8, 1);

        foreach ($this->writes() as $sql) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(UPDATE|DELETE FROM|INSERT INTO) (orders|reseller_ledger|reseller_payouts|reseller_statements|invoices|services|domains)\b/',
                $sql,
                'a client move rewrote financial or service rows: ' . $sql
            );
        }
    }

    public function test_moving_to_the_platform_clears_store_escalations(): void
    {
        $this->assertTrue($this->service()->migrate(42, 'platform', null, 1)['success']);

        $tickets = $this->write('/^UPDATE tickets SET reseller_id = NULL, escalated_at = NULL/');
        $this->assertNotNull($tickets);
        $this->assertNull($this->write('/^UPDATE clients SET reseller_id/')['bindings'][0]);
    }

    public function test_an_owner_change_between_review_and_move_aborts_the_move(): void
    {
        // A checkout claimed the account for another store after the admin looked.
        $this->ownerUnderLock = 8;

        $result = $this->service()->migrate(42, 'platform', null, 1);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('changed provider', (string) $result['error']);
        $this->assertNull($this->write('/^UPDATE clients SET reseller_id/'));
    }

    // ---------------------------------------------------------------- requests ---

    public function test_a_clients_request_opens_an_escalated_ticket_that_does_not_name_the_destination(): void
    {
        $result = $this->service()->requestByClient(['id' => 42], 'zeta.philmorehost.test', 'Closer to home');

        $this->assertTrue($result['success'], (string) $result['error']);
        $this->assertNotNull($this->write('/^INSERT INTO tickets/'));
        $this->assertNotNull($this->write('/^UPDATE tickets SET escalated_at/'), 'only the platform can act, so the store ticket is handed up');

        $reply = $this->write('/^INSERT INTO ticket_replies/');
        $this->assertNotNull($reply);
        $message = (string) $reply['bindings'][4];
        $this->assertStringContainsString('Closer to home', $message);
        $this->assertStringNotContainsStringIgnoringCase('zeta', $message, 'the old store must not learn where its customer is going');

        // The destination IS recorded — on the request row, which only staff read.
        $this->assertContains('zeta.philmorehost.test', $this->write('/^INSERT INTO client_migrations/')['bindings']);
    }

    public function test_a_client_cannot_ask_to_move_to_where_they_already_are(): void
    {
        $result = $this->service()->requestByClient(['id' => 42], 'acme.test', '');

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->db->writes);
    }

    public function test_a_reseller_learns_nothing_about_whether_an_address_has_an_account(): void
    {
        $service = $this->service();

        // Jane is Acme's customer; nobody@ has no account at all. Zeta asks for both.
        $known = $service->requestByReseller(['id' => 600, 'email' => 'owner@zeta.test'], ['id' => 8, 'slug' => 'zeta', 'brand_name' => 'Zeta'], 'in', 'jane@customer.test', '', '');
        $unknown = $service->requestByReseller(['id' => 600, 'email' => 'owner@zeta.test'], ['id' => 8, 'slug' => 'zeta', 'brand_name' => 'Zeta'], 'in', 'nobody@x.test', '', '');

        $this->assertSame($known['success'], $unknown['success']);
        $this->assertSame($known['error'], $unknown['error']);
        $this->assertTrue($known['success']);
    }

    public function test_a_reseller_can_only_release_their_own_customers(): void
    {
        $result = $this->service()->requestByReseller(['id' => 600, 'email' => 'owner@zeta.test'], ['id' => 8, 'slug' => 'zeta'], 'out', 'jane@customer.test', 'philmorehost.test', '');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not a customer of your store', (string) $result['error']);
    }
}
