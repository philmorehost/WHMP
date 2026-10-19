<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

final class PaymentGatewayRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM payment_gateways ORDER BY sort_order, name');
    }

    /** @return array<int, array<string, mixed>> */
    public function allEnabled(): array
    {
        return $this->db->select('SELECT * FROM payment_gateways WHERE is_enabled = 1 ORDER BY sort_order, name');
    }

    /**
     * The gateways a given CLIENT may see and pay with: everything enabled,
     * minus the ones the admin switched off for reseller customers.
     *
     * "Reseller customer" is a clients row with reseller_id set — the store
     * the account belongs to, the same distinction PLATFORM_ONLY draws
     * everywhere else. A gateway hidden this way stays fully available on
     * the platform's own site; only reseller storefronts' clients are
     * affected. A null/missing client row is treated as a platform client
     * (fail open to the full list — the payment initiator re-checks with
     * the real client row anyway).
     *
     * @param array<string, mixed>|null $client the clients row, or null
     * @return array<int, array<string, mixed>>
     */
    public function allEnabledForClient(?array $client): array
    {
        $gateways = $this->allEnabled();

        if (!self::isResellerClient($client)) {
            return $gateways;
        }

        return array_values(array_filter(
            $gateways,
            static fn (array $gateway): bool => (int) ($gateway['reseller_enabled'] ?? 1) === 1
        ));
    }

    /**
     * True when the account belongs to a reseller's store rather than the
     * platform itself. Shared so the listing surfaces and the payment
     * initiator cannot disagree about who is a reseller client.
     *
     * @param array<string, mixed>|null $client
     */
    public static function isResellerClient(?array $client): bool
    {
        return $client !== null && ($client['reseller_id'] ?? null) !== null;
    }

    public function setResellerEnabled(string $slug, bool $enabled): void
    {
        $this->db->update(
            'UPDATE payment_gateways SET reseller_enabled = ?, updated_at = ? WHERE slug = ?',
            [$enabled ? 1 : 0, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $slug]
        );
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->selectOne('SELECT * FROM payment_gateways WHERE slug = ?', [$slug]);
    }

    public function setEnabled(string $slug, bool $enabled): void
    {
        $this->db->update(
            'UPDATE payment_gateways SET is_enabled = ?, updated_at = ? WHERE slug = ?',
            [$enabled ? 1 : 0, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $slug]
        );
    }
}
