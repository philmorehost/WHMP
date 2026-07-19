<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

use CodeVault\Modules\ProvisioningModule;

/**
 * A fully-real provisioning module with no external API — it persists
 * account state as JSON files under storage instead of calling a panel.
 * This is what makes the provisioning *orchestration engine* end-to-end
 * testable in this environment (no cPanel/CyberPanel server is reachable
 * here): every lifecycle transition genuinely succeeds/fails based on
 * real prior state, the same as a real module would, just against local
 * disk instead of a remote API.
 */
final class LocalProvisioningModule implements ProvisioningModule
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
            'name' => 'Local (no external panel)',
            'description' => 'Tracks provisioning state on local disk — for development and testing without a real control panel.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [];
    }

    public function create(array $params): array
    {
        $username = (string) ($params['username'] ?? '');

        if ($username === '') {
            return ['success' => false, 'message' => 'Missing username.'];
        }

        if ($this->exists($username)) {
            return ['success' => false, 'message' => "Account \"{$username}\" already exists."];
        }

        $this->write($username, [
            'status' => 'active',
            'package' => $params['product_name'] ?? '',
            'created_at' => date('c'),
        ]);

        return ['success' => true, 'message' => "Account \"{$username}\" created."];
    }

    public function suspend(array $params): array
    {
        return $this->transition($params, 'suspended', 'suspended');
    }

    public function unsuspend(array $params): array
    {
        return $this->transition($params, 'active', 'unsuspended');
    }

    public function terminate(array $params): array
    {
        $username = (string) ($params['username'] ?? '');

        if (!$this->exists($username)) {
            return ['success' => false, 'message' => "Account \"{$username}\" not found."];
        }

        unlink($this->path($username));

        return ['success' => true, 'message' => "Account \"{$username}\" terminated."];
    }

    public function changePassword(array $params): array
    {
        return $this->transition($params, null, 'password changed');
    }

    public function changePackage(array $params): array
    {
        $username = (string) ($params['username'] ?? '');

        if (!$this->exists($username)) {
            return ['success' => false, 'message' => "Account \"{$username}\" not found."];
        }

        $data = $this->read($username);
        $data['package'] = $params['product_name'] ?? $data['package'];
        $this->write($username, $data);

        return ['success' => true, 'message' => "Account \"{$username}\" package changed."];
    }

    public function singleSignOn(array $params): array
    {
        $username = (string) ($params['username'] ?? '');

        if (!$this->exists($username)) {
            return ['success' => false, 'message' => "Account \"{$username}\" not found."];
        }

        return ['success' => true, 'url' => "https://local.invalid/sso/{$username}", 'message' => 'SSO link generated.'];
    }

    public function usage(array $params): array
    {
        $username = (string) ($params['username'] ?? '');

        if (!$this->exists($username)) {
            return ['success' => false, 'message' => "Account \"{$username}\" not found."];
        }

        // Deterministic placeholder numbers — a real module reads these
        // from the panel's usage API.
        return [
            'success' => true,
            'diskUsedMb' => 128,
            'diskLimitMb' => 5120,
            'bandwidthUsedMb' => 512,
            'bandwidthLimitMb' => 51200,
        ];
    }

    public function testConnection(array $params): array
    {
        return ['success' => true, 'message' => 'Local module — no connection needed.'];
    }

    /** @return array{success: bool, message: string} */
    private function transition(array $params, ?string $newStatus, string $verb): array
    {
        $username = (string) ($params['username'] ?? '');

        if (!$this->exists($username)) {
            return ['success' => false, 'message' => "Account \"{$username}\" not found."];
        }

        if ($newStatus !== null) {
            $data = $this->read($username);
            $data['status'] = $newStatus;
            $this->write($username, $data);
        }

        return ['success' => true, 'message' => "Account \"{$username}\" {$verb}."];
    }

    private function exists(string $username): bool
    {
        return is_file($this->path($username));
    }

    /** @return array<string, mixed> */
    private function read(string $username): array
    {
        return json_decode((string) file_get_contents($this->path($username)), true) ?? [];
    }

    /** @param array<string, mixed> $data */
    private function write(string $username, array $data): void
    {
        file_put_contents($this->path($username), json_encode($data, JSON_PRETTY_PRINT));
    }

    private function path(string $username): string
    {
        return $this->storageDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $username) . '.json';
    }
}
