<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Security\BruteGuard;
use CodeVault\Security\PhpassHasher;

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
        private readonly BruteGuard $bruteGuard,
        private readonly \CodeVault\Settings\SettingsRepository $settings,
        private readonly PhpassHasher $phpass
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
    public function register(string $email, string $password, string $firstName, string $lastName, string $ip, string $country = '', string $vatNumber = '', string $phone = '', string $address1 = '', string $city = '', string $postcode = '', string $securityPin = ''): array
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
            'phone' => $phone !== '' ? $phone : null,
            'address1' => $address1 !== '' ? $address1 : null,
            'city' => $city !== '' ? $city : null,
            'postcode' => $postcode !== '' ? $postcode : null,
            'security_pin' => $securityPin,
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

        if ($client === null) {
            $this->bruteGuard->recordFailedAttempt($ip, $email, userExists: false);

            return ClientAuthResult::invalid();
        }

        $passwordHash = (string) $client['password_hash'];

        $passwordOk = $passwordHash !== '' && password_verify($password, $passwordHash);

        if (!$passwordOk && $passwordHash !== '' && $this->phpass->isPhpassHash($passwordHash)) {
            $passwordOk = $this->phpass->verify($password, $passwordHash);
        }

        if (!$passwordOk) {
            $this->bruteGuard->recordFailedAttempt($ip, $email, userExists: true);

            return ClientAuthResult::invalid();
        }

        if ($client['status'] === 'closed') {
            return ClientAuthResult::invalid();
        }

        $this->bruteGuard->recordSuccessfulAttempt($ip, $email);

        if ($this->phpass->isPhpassHash($passwordHash)) {
            $this->clients->updatePassword((int) $client['id'], $password);
            $client = $this->clients->find((int) $client['id']);
        }

        $is2faGloballyEnabled = $this->settings->get('security.2fa_enabled', '1') === '1';

        if ($is2faGloballyEnabled && (int) $client['two_factor_enabled'] === 1) {
            return ClientAuthResult::needsTwoFactor($client);
        }

        return ClientAuthResult::success($client);
    }

    /**
     * Verifies a client's security PIN for BruteGuard recovery.
     * If valid, clears the IP block.
     */
    public function verifySecurityPin(string $email, string $pin, string $ip): bool
    {
        $client = $this->clients->findByEmail($email);
        
        if ($client === null || empty($client['security_pin_hash'])) {
            return false;
        }

        if (password_verify($pin, $client['security_pin_hash'])) {
            $this->bruteGuard->clearIpBlock($ip);
            return true;
        }

        return false;
    }
}
