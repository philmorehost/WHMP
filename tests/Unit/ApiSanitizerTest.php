<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Api\ApiAuthenticator;
use CodeVault\Api\ApiResourceController;
use CodeVault\Api\ApiSanitizer;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Billing\TransactionRepository;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Clients\ClientRepository;
use CodeVault\Request;
use CodeVault\Support\TicketAttachmentRepository;
use CodeVault\Support\TicketReplyRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Support\TicketService;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * The REST API must never return credential material.
 *
 * The read endpoints return whole repository rows, and those rows carry the
 * very secrets an attacker would want: a client row's password_hash, TOTP
 * secret, recovery codes and security-PIN hash; a service row's hosting
 * account password. One leaked staff API key must not equal the credential
 * store of the entire install, so the boundary strips them — these tests
 * prove the strip end to end, through a real credential, a real row planted
 * with every secret, and the JSON that actually comes back.
 */
final class ApiSanitizerTest extends DatabaseTestCase
{
    private ApiResourceController $controller;
    private int $clientId;
    private int $serviceId;
    private string $key;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $clients = new ClientRepository($this->db);
        $services = new ServiceRepository($this->db);
        $tickets = new TicketRepository($this->db);

        $this->controller = new ApiResourceController(
            new ApiAuthenticator(new \CodeVault\Api\DatabaseApiCredentialRepository($this->db)),
            $clients,
            new InvoiceRepository($this->db),
            $services,
            new DomainRepository($this->db),
            $tickets,
            new TransactionRepository($this->db),
            new TicketService(
                $tickets,
                new TicketReplyRepository($this->db),
                new HookDispatcher(),
                new TicketAttachmentRepository($this->db)
            )
        );

        $this->clientId = $clients->create([
            'email' => 'apileak@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Api',
            'last_name' => 'Leak',
        ]);

        // Plant every credential field the schema actually has.
        $this->db->update(
            'UPDATE clients SET password_hash = ?, security_pin_hash = ?, two_factor_secret = ?, two_factor_recovery_codes = ? WHERE id = ?',
            ['$2y$10$plantedhash', '$2y$10$plantedpin', 'JBSWY3DPEHPK3PXP', '["8f3a-11bb"]', $this->clientId]
        );

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->serviceId = (int) $this->db->insert(
            'INSERT INTO services (client_id, product_name, billing_cycle, amount, status, username, password, domain, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, 'Starter Hosting', 'monthly', 10.0, 'active', 'apiuser', 's3cret-hosting-pw', 'apileak.example.test', $now, $now]
        );

        $issued = (new \CodeVault\Api\DatabaseApiCredentialRepository($this->db))->create(
            'Sanitizer test',
            ['clients.read', 'services.read']
        );
        $this->key = $issued['key'];
        $this->secret = $issued['secret'];
    }

    private function apiGet(string $uri): array
    {
        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
            'REMOTE_ADDR' => '127.0.0.1',
        ], ['Authorization' => 'Bearer ' . $this->key . '.' . $this->secret]);

        /** @var \CodeVault\Response $response */
        $response = match ($uri) {
            '/api/clients' => $this->controller->clients($request),
            '/api/services' => $this->controller->services($request),
            default => $this->controller->client($request, ['id' => (string) $this->clientId]),
        };

        $decoded = json_decode($response->body(), true);
        $this->assertIsArray($decoded, 'the API returned unparseable JSON');

        return $decoded;
    }

    public function test_the_sanitizer_strips_every_credential_field_from_a_client_row(): void
    {
        $row = [
            'id' => 1,
            'email' => 'a@b.test',
            'password_hash' => '$2y$10$secret',
            'security_pin_hash' => '$2y$10$pin',
            'two_factor_secret' => 'JBSWY3DP',
            'two_factor_recovery_codes' => '["one"]',
            'two_factor_enabled' => 1,
            'first_name' => 'A',
        ];

        $clean = ApiSanitizer::client($row);

        $this->assertArrayNotHasKey('password_hash', $clean);
        $this->assertArrayNotHasKey('security_pin_hash', $clean);
        $this->assertArrayNotHasKey('two_factor_secret', $clean);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $clean);
        // Non-credential data (including the 2FA FLAG) survives untouched.
        $this->assertSame(1, $clean['two_factor_enabled']);
        $this->assertSame('a@b.test', $clean['email']);
    }

    public function test_client_list_and_single_client_responses_carry_no_secrets(): void
    {
        foreach ([$this->apiGet('/api/clients'), $this->apiGet('/api/clients/' . $this->clientId)] as $payload) {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString('$2y$10$plantedhash', $body, 'password hash leaked through the API');
            $this->assertStringNotContainsString('$2y$10$plantedpin', $body, 'security PIN hash leaked through the API');
            $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $body, 'TOTP secret leaked through the API');
            $this->assertStringNotContainsString('8f3a-11bb', $body, '2FA recovery codes leaked through the API');
            $this->assertStringContainsString('apileak@example.test', $body);
        }
    }

    public function test_service_responses_do_not_leak_the_hosting_password(): void
    {
        $body = json_encode($this->apiGet('/api/services'), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('s3cret-hosting-pw', $body, 'hosting password leaked through the API');
        $this->assertStringContainsString('apileak.example.test', $body);
    }
}
