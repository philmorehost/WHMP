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
