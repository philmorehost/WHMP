<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Api\ApiAuthenticator;
use CodeVault\Api\ApiAuthException;
use CodeVault\Api\ApiCredential;
use CodeVault\Api\ApiCredentialRepository;
use CodeVault\Api\DatabaseApiCredentialRepository;
use CodeVault\Database\Migrator;
use CodeVault\Request;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * External REST API credentials (blueprint §3 — "scoped API credentials/
 * roles"). Exercises the DB-backed repository and the Bearer authenticator
 * that gates every /api/* route. The interface + authenticator existed
 * since R0 with no implementation; these tests lock in the real one.
 */
final class ApiCredentialTest extends DatabaseTestCase
{
    private DatabaseApiCredentialRepository $repository;
    private ApiAuthenticator $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $migrator = new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations');
        $migrator->run();

        $this->repository = new DatabaseApiCredentialRepository($this->db);
        $this->auth = new ApiAuthenticator($this->repository);
    }

    private function requestWithToken(string $token): Request
    {
        return new Request(
            [],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/clients'],
            ['Authorization' => 'Bearer ' . $token],
            [],
            ''
        );
    }

    public function test_create_stores_only_a_hash_and_returns_plaintext_once(): void
    {
        $created = $this->repository->create('Test integration', ['clients.read']);

        $this->assertNotEmpty($created['key']);
        $this->assertNotEmpty($created['secret']);
        $this->assertNotSame($created['secret'], $created['key']);

        $row = $this->db->selectOne('SELECT * FROM api_credentials WHERE id = ?', [$created['id']]);
        $this->assertNotNull($row);
        // The plaintext secret must never be stored — only its hash.
        $this->assertNotSame($created['secret'], $row['secret_hash']);
        $this->assertTrue(password_verify($created['secret'], $row['secret_hash']));
    }

    public function test_authenticate_accepts_valid_bearer_token(): void
    {
        $created = $this->repository->create('Test integration', ['clients.read', 'invoices.read']);

        $credential = $this->auth->authenticate($this->requestWithToken($created['key'] . '.' . $created['secret']));

        $this->assertInstanceOf(ApiCredential::class, $credential);
        $this->assertSame($created['id'], $credential->id);
        $this->assertSame(['clients.read', 'invoices.read'], $credential->scopes);
    }

    public function test_authenticate_rejects_missing_and_wrong_tokens(): void
    {
        $created = $this->repository->create('Test integration', ['clients.read']);

        $this->expectException(ApiAuthException::class);
        $this->auth->authenticate(new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/clients'], [], [], ''));
    }

    public function test_authenticate_rejects_wrong_secret(): void
    {
        $created = $this->repository->create('Test integration', ['clients.read']);

        $this->expectException(ApiAuthException::class);
        $this->auth->authenticate($this->requestWithToken($created['key'] . '.wrong-secret'));
    }

    public function test_authorize_grants_matching_and_wildcard_scopes(): void
    {
        $scoped = $this->repository->create('Scoped', ['clients.read']);
        $credential = $this->auth->authenticate($this->requestWithToken($scoped['key'] . '.' . $scoped['secret']));

        // Matching scope is allowed.
        $this->auth->authorize($credential, 'clients.read');

        // A scope the credential lacks is denied.
        $this->expectException(ApiAuthException::class);
        $this->auth->authorize($credential, 'invoices.read');
    }

    public function test_deactivated_credential_fails_authentication(): void
    {
        $created = $this->repository->create('Test integration', ['clients.read']);
        $this->repository->setActive((int) $created['id'], false);

        $this->expectException(ApiAuthException::class);
        $this->auth->authenticate($this->requestWithToken($created['key'] . '.' . $created['secret']));
    }

    public function test_delete_removes_the_credential(): void
    {
        $created = $this->repository->create('Test integration', ['clients.read']);
        $this->repository->delete((int) $created['id']);

        $this->assertNull($this->repository->find((int) $created['id']));
    }

    public function test_repository_resolves_through_the_interface_binding(): void
    {
        // The Kernel binds ApiCredentialRepository to the DB implementation,
        // so the authenticator can be built from the container in production.
        $kernel = new \CodeVault\Kernel(dirname(__DIR__, 2));
        $resolved = $kernel->container->make(ApiCredentialRepository::class);

        $this->assertInstanceOf(DatabaseApiCredentialRepository::class, $resolved);
    }
}
