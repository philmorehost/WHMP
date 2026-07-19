<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Security\BruteGuard;

/**
 * Client-area registration/login. Reuses BruteGuard as-is (blueprint §5
 * doesn't scope BruteGuard to admins only — IP-track/username-track
 * protection applies to any login endpoint). The account-lock-by-admin-id
 * path is simply skipped by passing adminId: null, since client accounts
 * don't share the admins table's FK.
 */
final class ClientAuthManager
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly BruteGuard $bruteGuard
    ) {
    }

    /**
     * `country`/`vatNumber` (R30) are optional and unvalidated here — a
     * business client can supply them at signup the same way they'd type
     * an address, and verify the VAT number for real (via VIES) afterward
     * from their account page, the same two-step pattern R22 already
     * established for admin-entered VAT numbers.
     *
     * @return array{success: bool, client?: array<string, mixed>, error?: string}
     */
    public function register(string $email, string $password, string $firstName, string $lastName, string $ip, string $country = '', string $vatNumber = ''): array
    {
        if ($this->clients->findByEmail($email) !== null) {
            return ['success' => false, 'error' => 'An account with that email already exists.'];
        }

        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
        }

        $id = $this->clients->create([
            'email' => $email,
            'password' => $password,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'country' => $country !== '' ? $country : null,
            'vat_number' => $vatNumber !== '' ? $vatNumber : null,
        ]);

        $client = $this->clients->find($id);
        $this->bruteGuard->recordSuccessfulAttempt($ip, $email);

        return ['success' => true, 'client' => $client];
    }

    public function attempt(string $email, string $password, string $ip): ClientAuthResult
    {
        $verdict = $this->bruteGuard->preCheck($ip);

        if (!$verdict->allowed) {
            return ClientAuthResult::blocked((string) $verdict->reason);
        }

        $client = $this->clients->findByEmail($email);

        if ($client === null || !password_verify($password, $client['password_hash'])) {
            $this->bruteGuard->recordFailedAttempt($ip, $email, userExists: $client !== null);

            return ClientAuthResult::invalid();
        }

        if ($client['status'] === 'closed') {
            return ClientAuthResult::invalid();
        }

        $this->bruteGuard->recordSuccessfulAttempt($ip, $email);

        if ((int) $client['two_factor_enabled'] === 1) {
            return ClientAuthResult::needsTwoFactor($client);
        }

        return ClientAuthResult::success($client);
    }
}
