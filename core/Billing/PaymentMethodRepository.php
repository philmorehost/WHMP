<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Stored reusable payment methods (gateway tokens) for auto-charge. Never
 * holds a raw card number — only the gateway's reusable token plus
 * display-safe brand/last4/expiry. See migration 0107.
 */
final class PaymentMethodRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function forClient(int $clientId): array
    {
        return $this->db->select(
            "SELECT * FROM client_payment_methods WHERE client_id = ? AND status = 'active' ORDER BY is_default DESC, id DESC",
            [$clientId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM client_payment_methods WHERE id = ?', [$id]);
    }

    /**
     * The method the auto-charge job should use for a client: their default
     * if set, otherwise the most recently added active one.
     */
    public function defaultForClient(int $clientId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM client_payment_methods WHERE client_id = ? AND status = 'active' ORDER BY is_default DESC, id DESC LIMIT 1",
            [$clientId]
        );
    }

    /**
     * Save a reusable token, or refresh the display metadata if this exact
     * (client, gateway, token) is already stored — so repeatedly paying with
     * the same saved card doesn't create duplicate rows. The first method a
     * client stores becomes their default automatically.
     *
     * @param array{brand?: ?string, last4?: ?string, exp_month?: ?string, exp_year?: ?string} $card
     */
    public function store(int $clientId, string $gatewaySlug, string $token, array $card): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $existing = $this->db->selectOne(
            'SELECT id FROM client_payment_methods WHERE client_id = ? AND gateway_slug = ? AND token = ?',
            [$clientId, $gatewaySlug, $token]
        );

        if ($existing !== null) {
            $this->db->update(
                "UPDATE client_payment_methods SET card_brand = ?, card_last4 = ?, card_exp_month = ?, card_exp_year = ?, status = 'active', updated_at = ? WHERE id = ?",
                [$card['brand'] ?? null, $card['last4'] ?? null, $card['exp_month'] ?? null, $card['exp_year'] ?? null, $now, (int) $existing['id']]
            );

            return (int) $existing['id'];
        }

        $hasDefault = $this->db->selectOne(
            "SELECT id FROM client_payment_methods WHERE client_id = ? AND status = 'active' AND is_default = 1",
            [$clientId]
        );
        $isDefault = $hasDefault === null ? 1 : 0;

        return (int) $this->db->insert(
            'INSERT INTO client_payment_methods (client_id, gateway_slug, token, card_brand, card_last4, card_exp_month, card_exp_year, is_default, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $gatewaySlug, $token, $card['brand'] ?? null, $card['last4'] ?? null, $card['exp_month'] ?? null, $card['exp_year'] ?? null, $isDefault, 'active', $now, $now]
        );
    }

    /** Makes one method the client's default, clearing the flag on their others. */
    public function makeDefault(int $clientId, int $methodId): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update('UPDATE client_payment_methods SET is_default = 0, updated_at = ? WHERE client_id = ?', [$now, $clientId]);
        $this->db->update('UPDATE client_payment_methods SET is_default = 1, updated_at = ? WHERE id = ? AND client_id = ?', [$now, $methodId, $clientId]);
    }

    /**
     * Soft-remove a method (keeps the row so historical transactions still
     * reference a real token, but it's no longer offered or auto-charged). If
     * it was the default, promotes the next active method so the client keeps
     * an auto-charge method where possible.
     */
    public function deactivate(int $clientId, int $methodId): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $method = $this->find($methodId);

        if ($method === null || (int) $method['client_id'] !== $clientId) {
            return;
        }

        $this->db->update(
            "UPDATE client_payment_methods SET status = 'inactive', is_default = 0, updated_at = ? WHERE id = ? AND client_id = ?",
            [$now, $methodId, $clientId]
        );

        if ((int) $method['is_default'] === 1) {
            $next = $this->defaultForClient($clientId);
            if ($next !== null) {
                $this->makeDefault($clientId, (int) $next['id']);
            }
        }
    }
}
