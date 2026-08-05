<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Modules\RegistrarModule;
use DateTimeImmutable;

/**
 * A fully-real registrar module with no external API — persists domain
 * state as JSON files on local disk instead of calling a real registry.
 * Mirrors LocalProvisioningModule's role from R6: this is what makes the
 * domain engine's orchestration end-to-end testable without live
 * registrar credentials, with genuine success/failure based on real prior
 * state (already-registered, not-found, etc.), not a stub that always
 * succeeds.
 */
final class LocalRegistrarModule implements RegistrarModule
{
    public function __construct(
        private readonly string $storageDir
    ) {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    public function metadata(): array
    {
        return [
            'name' => 'Local (no external registry)',
            'description' => 'Tracks domain state on local disk — for development and testing without a real registrar.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [];
    }

    public function register(array $params): array
    {
        $domain = (string) $params['domain'];

        if ($this->exists($domain)) {
            return ['success' => false, 'message' => "Domain \"{$domain}\" is already registered."];
        }

        $years = (int) ($params['years'] ?? 1);
        $now = new DateTimeImmutable();
        $expiry = $now->modify("+{$years} years");

        // Only persist nameservers that were actually supplied. Storing the
        // literal "ns1.codevault.invalid" placeholders used to be the fallback
        // here — a reserved testing TLD — which then surfaced on real domains
        // as fake nameservers whenever a registration carried none. An empty
        // list is honest: the buyer/admin configured nothing.
        $nameservers = array_values(array_filter(
            (array) ($params['nameservers'] ?? []),
            static fn ($ns) => trim((string) $ns) !== ''
        ));

        $this->write($domain, [
            'status' => 'active',
            'registered_at' => $now->format('Y-m-d'),
            'expiry' => $expiry->format('Y-m-d'),
            'nameservers' => $nameservers,
            'locked' => true,
            'id_protection' => (bool) ($params['id_protection'] ?? false),
            'contacts' => $params['contacts'] ?? [],
        ]);

        return ['success' => true, 'message' => "Domain \"{$domain}\" registered.", 'registrationDate' => $now->format('Y-m-d'), 'expiryDate' => $expiry->format('Y-m-d')];
    }

    public function transfer(array $params): array
    {
        $domain = (string) $params['domain'];

        if ($this->exists($domain)) {
            return ['success' => false, 'message' => "Domain \"{$domain}\" is already under this registrar."];
        }

        $now = new DateTimeImmutable();
        $expiry = $now->modify('+1 year');

        $this->write($domain, [
            'status' => 'active',
            'registered_at' => $now->format('Y-m-d'),
            'expiry' => $expiry->format('Y-m-d'),
            'nameservers' => [],
            'locked' => true,
            'id_protection' => false,
            'contacts' => [],
        ]);

        return ['success' => true, 'message' => "Domain \"{$domain}\" transferred in.", 'expiryDate' => $expiry->format('Y-m-d')];
    }

    public function renew(array $params): array
    {
        $domain = (string) $params['domain'];

        if (!$this->exists($domain)) {
            return ['success' => false, 'message' => "Domain \"{$domain}\" not found."];
        }

        $years = (int) ($params['years'] ?? 1);
        $data = $this->read($domain);
        $newExpiry = (new DateTimeImmutable($data['expiry']))->modify("+{$years} years");
        $data['expiry'] = $newExpiry->format('Y-m-d');
        $this->write($domain, $data);

        return ['success' => true, 'message' => "Domain \"{$domain}\" renewed.", 'expiryDate' => $data['expiry']];
    }

    public function getNameservers(array $params): array
    {
        $domain = (string) $params['domain'];

        if (!$this->exists($domain)) {
            return ['success' => false, 'nameservers' => []];
        }

        return ['success' => true, 'nameservers' => $this->read($domain)['nameservers']];
    }

    public function saveNameservers(array $params): array
    {
        $domain = (string) $params['domain'];

        if (!$this->exists($domain)) {
            return ['success' => false, 'message' => "Domain \"{$domain}\" not found."];
        }

        $data = $this->read($domain);
        $data['nameservers'] = array_values(array_filter([
            $params['ns1'] ?? null, $params['ns2'] ?? null, $params['ns3'] ?? null,
            $params['ns4'] ?? null, $params['ns5'] ?? null,
        ]));
        $this->write($domain, $data);

        return ['success' => true, 'message' => 'Nameservers updated.'];
    }

    public function getContactInfo(array $params): array
    {
        $domain = (string) $params['domain'];

        if (!$this->exists($domain)) {
            return ['success' => false, 'message' => "Domain \"{$domain}\" not found."];
        }

        return ['success' => true, 'contacts' => $this->read($domain)['contacts']];
    }

    public function saveContactInfo(array $params): array
    {
        $domain = (string) $params['domain'];

        if (!$this->exists($domain)) {
            return ['success' => false, 'message' => "Domain \"{$domain}\" not found."];
        }

        $data = $this->read($domain);
        $data['contacts'] = $params['contacts'] ?? $data['contacts'];
        $this->write($domain, $data);

        return ['success' => true, 'message' => 'Contact info updated.'];
    }

    public function getRegistrarLock(array $params): array
    {
        $domain = (string) $params['domain'];

        if (!$this->exists($domain)) {
            return ['success' => false, 'locked' => false];
        }

        return ['success' => true, 'locked' => $this->read($domain)['locked']];
    }

    public function setRegistrarLock(array $params): array
    {
        $domain = (string) $params['domain'];

        if (!$this->exists($domain)) {
            return ['success' => false, 'message' => "Domain \"{$domain}\" not found."];
        }

        $data = $this->read($domain);
        $data['locked'] = (bool) ($params['lock'] ?? true);
        $this->write($domain, $data);

        return ['success' => true, 'message' => $data['locked'] ? 'Domain locked.' : 'Domain unlocked.'];
    }

    public function getEppCode(array $params): array
    {
        $domain = (string) $params['domain'];

        if (!$this->exists($domain)) {
            return ['success' => false, 'message' => "Domain \"{$domain}\" not found."];
        }

        return ['success' => true, 'eppCode' => strtoupper(substr(hash('sha256', $domain), 0, 16)), 'message' => 'EPP code retrieved.'];
    }

    public function enableIdProtection(array $params): array
    {
        return $this->toggleIdProtection($params, true);
    }

    public function disableIdProtection(array $params): array
    {
        return $this->toggleIdProtection($params, false);
    }

    private function toggleIdProtection(array $params, bool $enabled): array
    {
        $domain = (string) $params['domain'];

        if (!$this->exists($domain)) {
            return ['success' => false, 'message' => "Domain \"{$domain}\" not found."];
        }

        $data = $this->read($domain);
        $data['id_protection'] = $enabled;
        $this->write($domain, $data);

        return ['success' => true, 'message' => $enabled ? 'ID protection enabled.' : 'ID protection disabled.'];
    }

    public function checkAvailability(array $params): array
    {
        $domain = (string) $params['domain'];

        return ['success' => true, 'available' => !$this->exists($domain)];
    }

    public function sync(array $params): array
    {
        $domain = (string) $params['domain'];

        if (!$this->exists($domain)) {
            return ['success' => false, 'message' => "Domain \"{$domain}\" not found."];
        }

        $data = $this->read($domain);

        return ['success' => true, 'status' => $data['status'], 'expiryDate' => $data['expiry']];
    }

    private function exists(string $domain): bool
    {
        return is_file($this->path($domain));
    }

    /** @return array<string, mixed> */
    private function read(string $domain): array
    {
        return json_decode((string) file_get_contents($this->path($domain)), true) ?? [];
    }

    /** @param array<string, mixed> $data */
    private function write(string $domain, array $data): void
    {
        file_put_contents($this->path($domain), json_encode($data, JSON_PRETTY_PRINT));
    }

    private function path(string $domain): string
    {
        return $this->storageDir . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $domain) . '.json';
    }
}
