<?php

declare(strict_types=1);

namespace CodeVault\Integrity;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Single-domain self-integrity/activation state: one record bound to this
 * install's own domain, checked periodically against a remote management
 * server so a tampered or unauthorized copy can be remotely suspended.
 *
 * Flow: kill-switch file wins immediately; otherwise a cached DB row is
 * reused within CACHE_TTL_SECONDS; past that, a remote check runs. If the
 * remote server is unreachable (not: reachable-and-says-invalid), the last
 * known-good state is trusted for up to GRACE_SECONDS before the install is
 * treated as suspended.
 */
final class IntegrityManager
{
    public const CACHE_TTL_SECONDS = 21600; // 6h
    public const GRACE_SECONDS = 172800;    // 48h
    private const REQUEST_TIMEOUT_SECONDS = 8;

    private Database $db;
    private IntegrityHttpClient $http;
    private IntegrityTokenCipher $cipher;
    private string $domain;
    private string $remoteUrl;
    private string $tokenFilePath;
    private string $killSwitchFilePath;

    public function __construct(
        Database $db,
        IntegrityHttpClient $http,
        IntegrityTokenCipher $cipher,
        string $domain,
        string $remoteUrl,
        string $tokenFilePath,
        string $killSwitchFilePath
    ) {
        $this->db = $db;
        $this->http = $http;
        $this->cipher = $cipher;
        $this->domain = $domain;
        $this->remoteUrl = $remoteUrl;
        $this->tokenFilePath = $tokenFilePath;
        $this->killSwitchFilePath = $killSwitchFilePath;
    }

    public function isKilled(): bool
    {
        return is_file($this->killSwitchFilePath);
    }

    public function kill(): void
    {
        @mkdir(dirname($this->killSwitchFilePath), 0755, true);
        file_put_contents($this->killSwitchFilePath, (string) time());
    }

    public function release(): void
    {
        if (is_file($this->killSwitchFilePath)) {
            unlink($this->killSwitchFilePath);
        }
    }

    public function storeActivationKey(string $activationKey): void
    {
        @mkdir(dirname($this->tokenFilePath), 0755, true);
        file_put_contents($this->tokenFilePath, $this->cipher->encrypt($activationKey));
    }

    public function activationKey(): ?string
    {
        if (!is_file($this->tokenFilePath)) {
            return null;
        }

        $encoded = file_get_contents($this->tokenFilePath);

        return $encoded === false ? null : $this->cipher->decrypt($encoded);
    }

    public function validateKeyRemotely(string $activationKey): bool
    {
        $response = $this->http->post($this->remoteUrl, [
            'key' => $activationKey,
            'domain' => $this->domain,
        ], self::REQUEST_TIMEOUT_SECONDS);

        return $response['ok'] && (int) ($response['body']['status'] ?? 0) === 1;
    }

    /**
     * @return array{status: IntegrityStatus, message: string, graceEndsAt: ?int}
     */
    public function check(bool $force = false): array
    {
        if ($this->isKilled()) {
            return $this->result(IntegrityStatus::Suspended, 'System suspended (kill switch active).');
        }

        $row = $this->currentRow();

        if (!$force && $this->withinSeconds($row['last_checked_at'] ?? null, self::CACHE_TTL_SECONDS)) {
            return $this->result(
                IntegrityStatus::from($row['status']),
                'Using cached activation status.',
                $this->graceEndsAt($row)
            );
        }

        $activationKey = $this->activationKey();

        if ($activationKey === null) {
            $this->persist($row['id'], IntegrityStatus::Pending, $row['last_valid_at'] ?? null, null);

            return $this->result(IntegrityStatus::Pending, 'No activation key on file yet.');
        }

        $response = $this->http->post($this->remoteUrl, [
            'key' => $activationKey,
            'domain' => $this->domain,
        ], self::REQUEST_TIMEOUT_SECONDS);

        if ($response['ok'] && (int) ($response['body']['status'] ?? 0) === 1) {
            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->persist($row['id'], IntegrityStatus::Active, $now, json_encode($response['body']));

            return $this->result(IntegrityStatus::Active, 'Activation validated.');
        }

        if ($response['ok']) {
            // Server was reachable and explicitly rejected the key — not a
            // connectivity problem, so no soft-grace.
            $this->persist($row['id'], IntegrityStatus::Suspended, $row['last_valid_at'] ?? null, json_encode($response['body']));

            return $this->result(IntegrityStatus::Suspended, (string) ($response['body']['message'] ?? 'Activation rejected by server.'));
        }

        // Server unreachable — fall back to soft-grace on the last known-good check.
        if ($this->withinSeconds($row['last_valid_at'] ?? null, self::GRACE_SECONDS)) {
            $this->persist($row['id'], IntegrityStatus::Grace, $row['last_valid_at'] ?? null, $row['cached_response'] ?? null);

            return $this->result(
                IntegrityStatus::Grace,
                'Activation server unreachable — running on grace period.',
                $this->graceEndsAt(['last_valid_at' => $row['last_valid_at'] ?? null])
            );
        }

        $this->persist($row['id'], IntegrityStatus::Suspended, $row['last_valid_at'] ?? null, $row['cached_response'] ?? null);

        return $this->result(IntegrityStatus::Suspended, 'Activation server unreachable and grace period has expired.');
    }

    /** @return array{status: IntegrityStatus, message: string, graceEndsAt: ?int} */
    private function result(IntegrityStatus $status, string $message, ?int $graceEndsAt = null): array
    {
        return ['status' => $status, 'message' => $message, 'graceEndsAt' => $graceEndsAt];
    }

    /** @return array<string, mixed> */
    private function currentRow(): array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM system_activation WHERE domain = ? ORDER BY id DESC LIMIT 1',
            [$this->domain]
        );

        if ($row !== null) {
            return $row;
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $id = $this->db->insert(
            'INSERT INTO system_activation (domain, status, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$this->domain, IntegrityStatus::Pending->value, $now, $now]
        );

        return [
            'id' => $id,
            'domain' => $this->domain,
            'status' => IntegrityStatus::Pending->value,
            'last_checked_at' => null,
            'last_valid_at' => null,
            'cached_response' => null,
        ];
    }

    private function persist(int|string $id, IntegrityStatus $status, ?string $lastValidAt, ?string $cachedResponse): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->update(
            'UPDATE system_activation SET status = ?, last_checked_at = ?, last_valid_at = ?, cached_response = ?, updated_at = ? WHERE id = ?',
            [$status->value, $now, $lastValidAt, $cachedResponse, $now, $id]
        );
    }

    private function withinSeconds(?string $datetime, int $seconds): bool
    {
        if ($datetime === null) {
            return false;
        }

        $then = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime);

        return $then !== false && (time() - $then->getTimestamp()) < $seconds;
    }

    private function graceEndsAt(array $row): ?int
    {
        $lastValidAt = $row['last_valid_at'] ?? null;

        if ($lastValidAt === null) {
            return null;
        }

        $then = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lastValidAt);

        return $then === false ? null : $then->getTimestamp() + self::GRACE_SECONDS;
    }
}
