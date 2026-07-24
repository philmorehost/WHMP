<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Database;
use DateTimeImmutable;

final class ClientRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function paginate(string $search = '', int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = '';
        $bindings = [];

        if ($search !== '') {
            $where = 'WHERE c.email LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company_name LIKE ?';
            $needle = "%{$search}%";
            $bindings = [$needle, $needle, $needle, $needle];
        }

        $total = (int) ($this->db->selectOne(
            "SELECT COUNT(*) AS c FROM clients c {$where}",
            $bindings
        )['c'] ?? 0);

        $data = $this->db->select(
            <<<SQL
            SELECT c.*, g.name AS group_name,
                (SELECT COUNT(*) FROM services s WHERE s.client_id = c.id) AS services_total,
                (SELECT COUNT(*) FROM services s WHERE s.client_id = c.id AND s.status = 'active') AS services_active
            FROM clients c
            LEFT JOIN client_groups g ON g.id = c.client_group_id
            {$where}
            ORDER BY c.id DESC
            LIMIT {$perPage} OFFSET {$offset}
            SQL,
            $bindings
        );

        return ['data' => $data, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /** Dashboard tiles (R17) — bare COUNTs rather than paginate(...)['total'], which also runs the full row query for a page it never uses. */
    public function countAll(): int
    {
        $row = $this->db->selectOne('SELECT COUNT(*) AS c FROM clients');

        return (int) ($row['c'] ?? 0);
    }

    public function countNewThisMonth(): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM clients WHERE created_at >= ?',
            [(new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00')]
        );

        return (int) ($row['c'] ?? 0);
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            <<<'SQL'
            SELECT c.*, g.name AS group_name, cu.symbol AS currency_symbol, cu.code AS currency_code
            FROM clients c
            LEFT JOIN client_groups g ON g.id = c.client_group_id
            LEFT JOIN currencies cu ON cu.id = c.currency_id
            WHERE c.id = ?
            SQL,
            [$id]
        );
    }

    /** @return array<int, array<string, mixed>> every client in the system */
    public function all(): array
    {
        return $this->db->select("SELECT id, email, first_name, last_name, company_name FROM clients ORDER BY last_name ASC, first_name ASC");
    }

    /**
     * Full rows for a bulk CSV export (marketing use case: email + phone
     * lists) — same optional search filter as paginate(), but no LIMIT.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allForExport(string $search = ''): array
    {
        $where = '';
        $bindings = [];

        if ($search !== '') {
            $where = 'WHERE email LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR company_name LIKE ?';
            $needle = "%{$search}%";
            $bindings = [$needle, $needle, $needle, $needle];
        }

        return $this->db->select(
            "SELECT id, email, first_name, last_name, company_name, phone, country, status, created_at FROM clients {$where} ORDER BY id DESC",
            $bindings
        );
    }

    /** @return array<int, array<string, mixed>> every active client, optionally filtered to one group — for mass-mail audience resolution */
    public function activeForGroup(?int $groupId): array
    {
        if ($groupId === null) {
            return $this->db->select("SELECT * FROM clients WHERE status = 'active'");
        }

        return $this->db->select("SELECT * FROM clients WHERE status = 'active' AND client_group_id = ?", [$groupId]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->selectOne('SELECT * FROM clients WHERE email = ?', [$email]);
    }

    /** @param array<string, mixed> $fields */
    public function create(array $fields): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $defaultCurrency = $this->db->selectOne("SELECT id FROM currencies WHERE is_default = 1 LIMIT 1");
        $defaultCurrencyId = $defaultCurrency !== null ? (int) $defaultCurrency['id'] : null;

        return (int) $this->db->insert(
            <<<'SQL'
            INSERT INTO clients (client_group_id, email, password_hash, security_pin_hash, first_name, last_name, company_name, address1, address2, city, state, postcode, country, vat_number, phone, currency_id, status, notes, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            SQL,
            [
                $fields['client_group_id'] ?? null,
                $fields['email'],
                password_hash($fields['password'] ?? bin2hex(random_bytes(8)), PASSWORD_ARGON2ID),
                isset($fields['security_pin']) && $fields['security_pin'] !== '' ? password_hash($fields['security_pin'], PASSWORD_ARGON2ID) : null,
                $fields['first_name'],
                $fields['last_name'],
                $fields['company_name'] ?? null,
                $fields['address1'] ?? null,
                $fields['address2'] ?? null,
                $fields['city'] ?? null,
                $fields['state'] ?? null,
                $fields['postcode'] ?? null,
                $fields['country'] ?? null,
                $fields['vat_number'] ?? null,
                $fields['phone'] ?? null,
                $fields['currency_id'] ?? $defaultCurrencyId,
                $fields['status'] ?? 'active',
                $fields['notes'] ?? null,
                $now,
                $now,
            ]
        );
    }

    public function updateCurrency(int $id, int $currencyId): void
    {
        $client = $this->db->selectOne('SELECT currency_id FROM clients WHERE id = ?', [$id]);
        $oldCurrencyId = $client !== null ? ($client['currency_id'] !== null ? (int) $client['currency_id'] : null) : null;

        if ($oldCurrencyId === $currencyId) {
            return;
        }

        $oldRate = 1.0000;
        if ($oldCurrencyId !== null) {
            $oldCurr = $this->db->selectOne('SELECT exchange_rate FROM currencies WHERE id = ?', [$oldCurrencyId]);
            if ($oldCurr !== null) {
                $oldRate = (float) $oldCurr['exchange_rate'];
            }
        } else {
            $defaultCurr = $this->db->selectOne('SELECT exchange_rate FROM currencies WHERE is_default = 1 LIMIT 1');
            if ($defaultCurr !== null) {
                $oldRate = (float) $defaultCurr['exchange_rate'];
            }
        }

        $newRate = 1.0000;
        $newCurr = $this->db->selectOne('SELECT exchange_rate FROM currencies WHERE id = ?', [$currencyId]);
        if ($newCurr !== null) {
            $newRate = (float) $newCurr['exchange_rate'];
        }

        $factor = $newRate / $oldRate;

        $this->db->transaction(function() use ($id, $currencyId, $factor) {
            $this->db->update(
                'UPDATE clients SET currency_id = ?, updated_at = ? WHERE id = ?',
                [$currencyId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
            );

            $this->db->update(
                'UPDATE services SET amount = amount * ? WHERE client_id = ?',
                [$factor, $id]
            );

            $this->db->update(
                'UPDATE domains SET amount = amount * ? WHERE client_id = ?',
                [$factor, $id]
            );

            $this->db->update(
                'UPDATE invoices SET subtotal = subtotal * ?, tax_amount = tax_amount * ?, total = total * ?, currency_id = ?, currency_rate = ? WHERE client_id = ?',
                [$factor, $factor, $factor, $currencyId, $factor, $id]
            );

            // transactions has no client_id column of its own — it's
            // scoped to a client only via the invoice it paid.
            $this->db->update(
                'UPDATE transactions SET amount = amount * ? WHERE invoice_id IN (SELECT id FROM invoices WHERE client_id = ?)',
                [$factor, $id]
            );

            $this->db->update(
                'UPDATE orders SET total = total * ?, currency_id = ?, currency_rate = ? WHERE client_id = ?',
                [$factor, $currencyId, $factor, $id]
            );
        });
    }

    /** Lazily populated the first time a registrar module (e.g. ConnectReseller) has to create a customer record on its own side for this client. */
    public function updateRegistrarClientId(int $id, string $registrarClientId): void
    {
        $this->db->update(
            'UPDATE clients SET registrar_client_id = ?, updated_at = ? WHERE id = ?',
            [$registrarClientId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function updateLanguage(int $id, int $languageId): void
    {
        $this->db->update(
            'UPDATE clients SET language_id = ?, updated_at = ? WHERE id = ?',
            [$languageId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function update(int $id, array $fields): void
    {
        $this->db->update(
            <<<'SQL'
            UPDATE clients SET
                client_group_id = ?, email = ?, first_name = ?, last_name = ?, company_name = ?,
                address1 = ?, address2 = ?, city = ?, state = ?, postcode = ?, country = ?, vat_number = ?,
                phone = ?, status = ?, notes = ?, updated_at = ?
            WHERE id = ?
            SQL,
            [
                $fields['client_group_id'] ?? null,
                $fields['email'],
                $fields['first_name'],
                $fields['last_name'],
                $fields['company_name'] ?? null,
                $fields['address1'] ?? null,
                $fields['address2'] ?? null,
                $fields['city'] ?? null,
                $fields['state'] ?? null,
                $fields['postcode'] ?? null,
                $fields['country'] ?? null,
                $fields['vat_number'] ?? null,
                $fields['phone'] ?? null,
                $fields['status'] ?? 'active',
                $fields['notes'] ?? null,
                (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                $id,
            ]
        );
    }

    /** Persists a live VIES verification outcome separately from the editable profile fields. */
    public function recordVatVerification(int $id, bool $valid, ?string $companyName): void
    {
        $this->db->update(
            'UPDATE clients SET vat_verified_at = ?, vat_verified_valid = ?, vat_verified_name = ? WHERE id = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $valid ? 1 : 0, $companyName, $id]
        );
    }

    /** A prior VIES verification no longer applies once the underlying VAT number changes — R30's self-service profile update calls this whenever vat_number is edited. */
    public function clearVatVerification(int $id): void
    {
        $this->db->update(
            'UPDATE clients SET vat_verified_at = NULL, vat_verified_valid = NULL, vat_verified_name = NULL WHERE id = ?',
            [$id]
        );
    }

    public function close(int $id): void
    {
        $this->db->update('UPDATE clients SET status = ?, updated_at = ? WHERE id = ?', ['closed', (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * GDPR erasure (blueprint §4.4 "export/erase requests") — scrubs every
     * PII-bearing field and closes the account, but does NOT delete the row
     * itself: invoices/transactions FK to clients.id and must survive for
     * legal/financial retention, so "erasure" here means the row stops
     * identifying a real person, not that it disappears. password_hash is
     * overwritten with a random, never-typeable hash so the erased account
     * can never authenticate again, independent of the closed status check.
     */
    public function anonymize(int $id): void
    {
        $this->db->update(
            <<<'SQL'
            UPDATE clients SET
                email = ?, password_hash = ?, two_factor_secret = NULL, two_factor_enabled = 0, two_factor_recovery_codes = NULL,
                first_name = ?, last_name = ?, company_name = NULL,
                address1 = NULL, address2 = NULL, city = NULL, state = NULL, postcode = NULL, phone = NULL, notes = NULL,
                status = ?, updated_at = ?
            WHERE id = ?
            SQL,
            [
                "deleted-{$id}@erased.invalid",
                password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID),
                'Erased', 'Client',
                'closed',
                (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                $id,
            ]
        );
    }

    /**
     * Self-service contact-details update — deliberately narrower than
     * update() (no client_group_id/status/notes, which are admin-only
     * fields a client must never be able to grant themselves). `vat_number`
     * (R30) is fine to include here — it's the client's own tax-ID
     * declaration, the same kind of self-reported field as their address.
     *
     * @param array<string, mixed> $fields
     */
    public function updateContactDetails(int $id, array $fields): void
    {
        $this->db->update(
            <<<'SQL'
            UPDATE clients SET
                email = ?, first_name = ?, last_name = ?, company_name = ?,
                address1 = ?, address2 = ?, city = ?, state = ?, postcode = ?, country = ?, vat_number = ?, phone = ?,
                updated_at = ?
            WHERE id = ?
            SQL,
            [
                $fields['email'],
                $fields['first_name'],
                $fields['last_name'],
                $fields['company_name'] ?? null,
                $fields['address1'] ?? null,
                $fields['address2'] ?? null,
                $fields['city'] ?? null,
                $fields['state'] ?? null,
                $fields['postcode'] ?? null,
                $fields['country'] ?? null,
                $fields['vat_number'] ?? null,
                $fields['phone'] ?? null,
                (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                $id,
            ]
        );
    }

    public function updatePassword(int $id, string $plainPassword): void
    {
        $this->db->update(
            'UPDATE clients SET password_hash = ?, updated_at = ? WHERE id = ?',
            [password_hash($plainPassword, PASSWORD_ARGON2ID), (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function updateSecurityPin(int $id, string $plainPin): void
    {
        $this->db->update(
            'UPDATE clients SET security_pin_hash = ?, updated_at = ? WHERE id = ?',
            [password_hash($plainPin, PASSWORD_ARGON2ID), (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * Stores a freshly-generated secret + recovery codes but does NOT yet
     * flag 2FA enabled — that only happens once the client proves they can
     * actually generate a valid code with it (confirmTwoFactor()).
     */
    public function pendingTwoFactorSecret(int $id, string $secret, string $hashedRecoveryCodes): void
    {
        $this->db->update(
            'UPDATE clients SET two_factor_secret = ?, two_factor_recovery_codes = ?, two_factor_enabled = 0, updated_at = ? WHERE id = ?',
            [$secret, $hashedRecoveryCodes, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function confirmTwoFactor(int $id): void
    {
        $this->db->update(
            'UPDATE clients SET two_factor_enabled = 1, updated_at = ? WHERE id = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function disableTwoFactor(int $id): void
    {
        $this->db->update(
            'UPDATE clients SET two_factor_secret = NULL, two_factor_enabled = 0, two_factor_recovery_codes = NULL, updated_at = ? WHERE id = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function updateRecoveryCodes(int $id, string $hashedRecoveryCodes): void
    {
        $this->db->update(
            'UPDATE clients SET two_factor_recovery_codes = ?, updated_at = ? WHERE id = ?',
            [$hashedRecoveryCodes, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function delete(int $id): bool
    {
        $this->db->delete('DELETE FROM clients WHERE id = ?', [$id]);
        return true;
    }

    /** @param array<int, int> $ids */
    public function bulkDelete(array $ids): int
    {
        $cleanIds = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
        if (empty($cleanIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        return $this->db->delete("DELETE FROM clients WHERE id IN ({$placeholders})", array_values($cleanIds));
    }
}
