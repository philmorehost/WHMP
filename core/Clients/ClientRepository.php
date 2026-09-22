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
     * @param array<string, string> $filters sanitised `filters[]` bag (see Table\TableFilters)
     * @param array<string, string> $filters sanitised `filters[]` bag (see Table\TableFilters)
     * @param array{column: string, dir: string}|null $sort
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function paginate(string $search = '', int $page = 1, int $perPage = 20, array $filters = [], ?array $sort = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $bindings = [];

        if ($search !== '') {
            $conditions[] = '(c.email LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company_name LIKE ?)';
            $needle = "%{$search}%";
            $bindings = array_merge($bindings, [$needle, $needle, $needle, $needle]);
        }

        [$filterWhere, $filterBindings] = \CodeVault\Table\TableFilters::where($filters, [
            'id'      => ['c.id', 'number'],
            'name'    => [['c.first_name', 'c.last_name', 'c.email'], 'like'],
            'email'   => ['c.email', 'like'],
            'company' => ['c.company_name', 'like'],
            'group'   => ['g.name', 'like'],
            'status'  => ['c.status', 'eq'],
        ]);

        if ($filterWhere !== '') {
            $conditions[] = $filterWhere;
            $bindings = array_merge($bindings, $filterBindings);
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $sortable = [
            'name'    => 'c.last_name',
            'email'   => 'c.email',
            'company' => 'c.company_name',
            'group'   => 'g.name',
            'status'  => 'c.status',
            'joined'  => 'c.created_at',
        ];
        $orderBy = \CodeVault\Table\TableFilters::orderBy($sortable, $sort);
        if ($orderBy === '') {
            $orderBy = 'ORDER BY c.id DESC';
        }

        $total = (int) ($this->db->selectOne(
            "SELECT COUNT(*) AS c FROM clients c LEFT JOIN client_groups g ON g.id = c.client_group_id {$where}",
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
            {$orderBy}
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

    /**
     * Lightweight autocomplete lookup for admin order forms — returns just
     * the id/name/email fields, capped at $limit, matching the same name/
     * email/company filter as paginate(). This stays a separate method (not
     * paginate() with a big perPage) so a keystroke-search endpoint can never
     * pull the heavy GROUP BY subqueries that paginate()'s list view needs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $search = '', int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        $where = '';
        $bindings = [];

        if ($search !== '') {
            $where = 'WHERE email LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR company_name LIKE ?';
            $needle = "%{$search}%";
            $bindings = [$needle, $needle, $needle, $needle];
        }

        $bindings[] = $limit;

        return $this->db->select(
            "SELECT id, email, first_name, last_name, company_name FROM clients {$where} ORDER BY last_name ASC, first_name ASC LIMIT ?",
            $bindings
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

    /**
     * Active clients holding no active service and no active domain — the
     * "account with nothing live" audience for re-engagement campaigns.
     *
     * "Active" is interpreted strictly as the row's own status: a service or
     * domain must be in the `active` state to disqualify a client, so someone
     * whose only product is suspended, cancelled, terminated or expired still
     * qualifies. A client with nothing but a pending order also qualifies —
     * they have not been provisioned anything yet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeWithoutProductsOrDomains(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT c.*
            FROM clients c
            WHERE c.status = 'active'
              AND NOT EXISTS (SELECT 1 FROM services s WHERE s.client_id = c.id AND s.status = 'active')
              AND NOT EXISTS (SELECT 1 FROM domains d WHERE d.client_id = c.id AND d.status = 'active')
            ORDER BY c.last_name ASC, c.first_name ASC
            SQL
        );
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

    /**
     * Recalculates everything a client is billed for into a new currency.
     *
     * This is the *deliberate* admin action from the Edit Client screen (see
     * setCurrencyPreference() for the passive storefront one). Because every
     * stored figure on this install is denominated in the client's own
     * currency, moving the client has to move the figures with it: services,
     * domains, invoices, orders, quotes, credit notes, recurring-invoice
     * templates, pending charges and the transactions that settled an invoice
     * are all converted so they keep meaning the same amount of money, now
     * expressed in the new currency.
     *
     * Two storage conventions have to be told apart per row, and conflating
     * them is what made the old implementation display nonsense:
     *
     * - **Denominated** (`currency_id` set AND `currency_rate` = 1.0, the shape
     *   denominateColumns() writes): the amount is already in that currency,
     *   so it converts by the ratio between the two rates — `$factor`.
     * - **Base** (`currency_id IS NULL`, or a `currency_rate` other than 1.0
     *   from the older lockColumns() convention): the amount is in the *base*
     *   currency and any rate on the row is display-only, so it converts at the
     *   target currency's own rate. Multiplying such a row by `$factor` divided
     *   it by the old currency's rate — an imported base-currency invoice on a
     *   naira account would have come out 1490x too small.
     *
     * Every row lands denominated in the target currency: `currency_rate` 1.0
     * and `currency_id` NULL for the base currency, matching
     * denominateColumns(). The old code wrote the raw ratio into
     * `currency_rate` instead, which made every display path multiply the
     * already-converted amount a second time — the invoice list, the invoice
     * detail page, the order list, the revenue reports and the amount
     * PaymentCallbackController asks the gateway for all read that column as
     * "stored figure x rate".
     */
    public function updateCurrency(int $id, int $currencyId): void
    {
        $client = $this->db->selectOne('SELECT currency_id FROM clients WHERE id = ?', [$id]);
        $oldCurrencyId = $client !== null && $client['currency_id'] !== null ? (int) $client['currency_id'] : null;

        if ($oldCurrencyId === $currencyId) {
            return;
        }

        $oldRate = $this->effectiveRate($oldCurrencyId);
        $newRate = $this->effectiveRate($currencyId);
        $factor = $oldRate > 0 ? $newRate / $oldRate : 1.0;

        // The shape every re-denominated row ends up in.
        $documentCurrencyId = $this->isBaseCurrency($currencyId) ? null : $currencyId;
        $scale = $this->scaleSql();

        $this->db->transaction(function () use ($id, $currencyId, $factor, $newRate, $documentCurrencyId, $scale) {
            $this->db->update(
                'UPDATE clients SET currency_id = ?, updated_at = ? WHERE id = ?',
                [$currencyId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
            );

            // Amounts with no currency column of their own are denominated in
            // the client's currency by definition, so they move by the ratio.
            foreach (['services', 'domains', 'billable_items'] as $table) {
                $this->db->update("UPDATE {$table} SET amount = amount * ? WHERE client_id = ?", [$factor, $id]);
            }

            // Line items and the transactions that settled an invoice are scaled
            // BEFORE the invoice itself is relabelled: their multiplier is read
            // from the invoice's currency columns as they stand right now. Run
            // this after the invoices UPDATE and every row would look
            // denominated in the target currency and be scaled as such.
            $this->db->update(
                'UPDATE invoice_items ii JOIN invoices i ON i.id = ii.invoice_id
                 SET ii.amount = ii.amount * ' . $this->scaleSql('i') . '
                 WHERE i.client_id = ?',
                [$factor, $newRate, $id]
            );

            $this->db->update(
                'UPDATE transactions t JOIN invoices i ON i.id = t.invoice_id
                 SET t.amount = t.amount * ' . $this->scaleSql('i') . '
                 WHERE i.client_id = ?',
                [$factor, $newRate, $id]
            );

            // discount_amount is converted with the rest: leaving it behind
            // broke the invoice's own subtotal + tax - discount = total
            // identity, so the detail page no longer added up.
            $this->db->update(
                "UPDATE invoices SET
                    subtotal = subtotal * {$scale},
                    tax_amount = tax_amount * {$scale},
                    discount_amount = discount_amount * {$scale},
                    total = total * {$scale},
                    currency_id = ?,
                    currency_rate = 1.0000
                 WHERE client_id = ?",
                [
                    $factor, $newRate,
                    $factor, $newRate,
                    $factor, $newRate,
                    $factor, $newRate,
                    $documentCurrencyId, $id,
                ]
            );

            $this->db->update(
                'UPDATE order_items oi JOIN orders o ON o.id = oi.order_id
                 SET oi.unit_price = oi.unit_price * ' . $this->scaleSql('o') . ',
                     oi.setup_fee = oi.setup_fee * ' . $this->scaleSql('o') . '
                 WHERE o.client_id = ?',
                [$factor, $newRate, $factor, $newRate, $id]
            );

            $this->db->update(
                "UPDATE orders SET
                    total = total * {$scale},
                    discount_amount = discount_amount * {$scale},
                    currency_id = ?,
                    currency_rate = 1.0000
                 WHERE client_id = ?",
                [$factor, $newRate, $factor, $newRate, $documentCurrencyId, $id]
            );

            // Quotes and credit notes are still written through
            // lockedColumnsFor(), i.e. base-currency amounts with a display
            // rate, so the CASE above converts them rather than the ratio.
            $this->db->update(
                'UPDATE credit_note_items cni JOIN credit_notes cn ON cn.id = cni.credit_note_id
                 SET cni.amount = cni.amount * ' . $this->scaleSql('cn') . '
                 WHERE cn.client_id = ?',
                [$factor, $newRate, $id]
            );

            $this->db->update(
                "UPDATE credit_notes SET total = total * {$scale}, currency_id = ?, currency_rate = 1.0000 WHERE client_id = ?",
                [$factor, $newRate, $documentCurrencyId, $id]
            );

            $this->db->update(
                'UPDATE quote_items qi JOIN quotes q ON q.id = qi.quote_id
                 SET qi.amount = qi.amount * ' . $this->scaleSql('q') . '
                 WHERE q.client_id = ?',
                [$factor, $newRate, $id]
            );

            $this->db->update(
                "UPDATE quotes SET total = total * {$scale}, currency_id = ?, currency_rate = 1.0000 WHERE client_id = ?",
                [$factor, $newRate, $documentCurrencyId, $id]
            );

            foreach ($this->db->select('SELECT id, currency_id, currency_rate, items FROM recurring_invoices WHERE client_id = ?', [$id]) as $template) {
                $rowFactor = $this->rowConvertsByRatio($template) ? $factor : $newRate;
                $encoded = (string) ($template['items'] ?? '[]');
                $items = json_decode($encoded, true);

                if (is_array($items)) {
                    foreach ($items as $index => $item) {
                        if (is_array($item) && isset($item['amount'])) {
                            $items[$index]['amount'] = round((float) $item['amount'] * $rowFactor, 2);
                        }
                    }

                    $encoded = (string) json_encode($items);
                }

                $this->db->update(
                    'UPDATE recurring_invoices SET items = ?, amount = amount * ?, currency_id = ?, currency_rate = 1.00000000 WHERE id = ?',
                    [$encoded, $rowFactor, $documentCurrencyId, (int) $template['id']]
                );
            }
        });
    }

    /**
     * Records the client's preferred currency WITHOUT touching a single stored
     * amount.
     *
     * The storefront currency widget calls this. Picking a different currency
     * in the header is a change of *view* — it re-prices the catalog for that
     * visitor and persists as the client's preference — but routing it through
     * updateCurrency() also re-denominated the whole account, and back again on
     * the next click, losing a little to rounding on every round trip. Only the
     * deliberate admin change converts.
     */
    public function setCurrencyPreference(int $id, int $currencyId): void
    {
        $this->db->update(
            'UPDATE clients SET currency_id = ?, updated_at = ? WHERE id = ?',
            [$currencyId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * A currency's live rate against the base, with the base's own rate pinned
     * at 1.0 — the same rule CurrencyService::rateFor() enforces.
     *
     * The `currencies` table has no constraint tying the default row's
     * exchange_rate to 1, so an install that seeded NGN=1490 and later promoted
     * NGN to default keeps the 1490. Reading that column raw (as this method
     * used to) made the conversion factor wrong by three orders of magnitude.
     * NULL means "no preference", i.e. the base currency.
     */
    private function effectiveRate(?int $currencyId): float
    {
        if ($currencyId === null) {
            return 1.0;
        }

        $row = $this->db->selectOne('SELECT exchange_rate, is_default FROM currencies WHERE id = ?', [$currencyId]);

        if ($row === null) {
            return 1.0;
        }

        if ((int) $row['is_default'] === 1) {
            return 1.0;
        }

        $rate = (float) $row['exchange_rate'];

        return $rate > 0 ? $rate : 1.0;
    }

    private function isBaseCurrency(int $currencyId): bool
    {
        $row = $this->db->selectOne('SELECT is_default FROM currencies WHERE id = ?', [$currencyId]);

        return $row !== null && (int) $row['is_default'] === 1;
    }

    /**
     * SQL for "does this row convert by the ratio between the two rates, or at
     * the target's own rate?" — bound with the ratio first, then the rate.
     * Assumes the row's currency columns are still the pre-conversion ones.
     */
    private function scaleSql(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';

        return "CASE WHEN {$prefix}currency_id IS NOT NULL AND {$prefix}currency_rate = 1.0 THEN ? ELSE ? END";
    }

    /** @param array<string, mixed> $row */
    private function rowConvertsByRatio(array $row): bool
    {
        return ($row['currency_id'] ?? null) !== null && (float) ($row['currency_rate'] ?? 0) === 1.0;
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
