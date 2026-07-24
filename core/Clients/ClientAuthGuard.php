<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Session\SessionManager;

/**
 * Client-area session guard — deliberately separate from Auth\AuthGuard
 * (admin), using its own session key (`client_id`) so an admin and a
 * client can never be confused for one another, and so nothing here can
 * accidentally grant admin access.
 */
final class ClientAuthGuard
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly ClientRepository $clients
    ) {
    }

    /** @return array<string, mixed>|null */
    public function currentClient(): ?array
    {
        $id = $this->session->get('client_id');

        return $id === null ? null : $this->clients->find((int) $id);
    }

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        return $this->currentClient();
    }

    public function check(): bool
    {
        return $this->currentClient() !== null;
    }

    /** @param array<string, mixed> $client */
    public function login(array $client): void
    {
        $this->session->regenerate();
        $this->session->set('client_id', $client['id']);
    }

    public function logout(): void
    {
        $this->session->remove('client_id');
        $this->session->regenerate();
    }
}
