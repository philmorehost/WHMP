<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Database;
use DateTimeImmutable;

final class DomainRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM domains WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByName(string $domainName): ?array
    {
        return $this->db->selectOne('SELECT * FROM domains WHERE domain_name = ?', [$domainName]);
    }

    /** @return array<int, array<string, mixed>> */
    public function forClient(int $clientId): array
    {
        return $this->db->select('SELECT * FROM domains WHERE client_id = ? ORDER BY id DESC', [$clientId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function forOrder(int $orderId): array
    {
        return $this->db->select('SELECT * FROM domains WHERE order_id = ?', [$orderId]);
    }

    /** @return array{all: int, active: int, pending: int, expired: int} */
    public function countByStatus(): array
    {
        $rows = $this->db->select('SELECT status, COUNT(*) AS count FROM domains GROUP BY status');
        $counts = ['all' => 0, 'active' => 0, 'pending' => 0, 'expired' => 0];

        foreach ($rows as $row) {
            $cnt = (int) $row['count'];
            $counts['all'] += $cnt;
            $status = (string) $row['status'];
            if (isset($counts[$status])) {
                $counts[$status] += $cnt;
            }
        }

        return $counts;
    }

    /** @return array<string, int> registrar_slug => active_domain_count */
    public function countActiveByRegistrar(): array
    {
        $rows = $this->db->select("SELECT registrar_slug, COUNT(*) AS count FROM domains WHERE status = 'active' GROUP BY registrar_slug");
        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row['registrar_slug']] = (int) $row['count'];
        }

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(?string $status = null): array
    {
        $where = $status !== null ? 'WHERE d.status = ?' : '';
        $bindings = $status !== null ? [$status] : [];

        return $this->db->select(
            <<<SQL
            SELECT d.*, c.email AS client_email, c.first_name, c.last_name
            FROM domains d
            JOIN clients c ON c.id = d.client_id
            {$where}
            ORDER BY d.next_due_date
            SQL,
            $bindings
        );
    }

    /**
     * Domains due for renewal within `$daysAhead` days — active only,
     * same shape as ServiceRepository::dueForBilling().
     *
     * @return array<int, array<string, mixed>>
     */
    public function dueForRenewal(int $daysAhead): array
    {
        $cutoff = (new DateTimeImmutable("+{$daysAhead} days"))->format('Y-m-d');

        return $this->db->select(
            "SELECT * FROM domains WHERE status = 'active' AND auto_renew = 1 AND next_due_date <= ?",
            [$cutoff]
        );
    }

    /** Dashboard tile (R17) — same cutoff as dueForRenewal(), COUNT only. */
    public function countDueForRenewal(int $daysAhead): int
    {
        $cutoff = (new DateTimeImmutable("+{$daysAhead} days"))->format('Y-m-d');
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM domains WHERE status = 'active' AND auto_renew = 1 AND next_due_date <= ?",
            [$cutoff]
        );

        return (int) ($row['c'] ?? 0);
    }

    /** @param array<string, mixed> $fields */
    public function create(array $fields): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $domainName = strtolower((string) $fields['domain_name']);
        $tld = $fields['tld'] ?? substr($domainName, strpos($domainName, '.') + 1);

        return (int) $this->db->insert(
            'INSERT INTO domains (client_id, order_id, domain_name, tld, registrar_slug, status, expiry_date, next_due_date, auto_renew, amount, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['client_id'],
                $fields['order_id'] ?? null,
                $domainName,
                $tld,
                $fields['registrar_slug'],
                $fields['status'] ?? 'pending',
                $fields['expiry_date'] ?? null,
                $fields['next_due_date'],
                $fields['auto_renew'] ?? 1,
                $fields['amount'] ?? 0,
                $now,
                $now,
            ]
        );
    }

    public function activate(int $id, string $registrarDomainId, string $registrationDate, string $expiryDate): void
    {
        $this->db->update(
            'UPDATE domains SET status = ?, registrar_domain_id = ?, registration_date = ?, expiry_date = ?, updated_at = ? WHERE id = ?',
            ['active', $registrarDomainId, $registrationDate, $expiryDate, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->update(
            'UPDATE domains SET status = ?, updated_at = ? WHERE id = ?',
            [$status, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function advanceRenewal(int $id, string $newExpiryDate, string $newNextDueDate): void
    {
        $this->db->update(
            'UPDATE domains SET expiry_date = ?, next_due_date = ?, updated_at = ? WHERE id = ?',
            [$newExpiryDate, $newNextDueDate, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function updateContactId(int $id, string $registrarContactId): void
    {
        $this->db->update(
            'UPDATE domains SET registrar_contact_id = ?, updated_at = ? WHERE id = ?',
            [$registrarContactId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function updateNameservers(int $id, array $nameservers): void
    {
        $this->db->update(
            'UPDATE domains SET nameservers = ?, updated_at = ? WHERE id = ?',
            [json_encode(array_values($nameservers)), (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function setLock(int $id, bool $locked): void
    {
        $this->db->update(
            'UPDATE domains SET registrar_lock_enabled = ?, updated_at = ? WHERE id = ?',
            [$locked ? 1 : 0, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function setIdProtection(int $id, bool $enabled): void
    {
        $this->db->update(
            'UPDATE domains SET id_protection_enabled = ?, updated_at = ? WHERE id = ?',
            [$enabled ? 1 : 0, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /** @param string|null $message null clears a previously-recorded error */
    public function recordProvisioningError(int $id, ?string $message): void
    {
        $this->db->update(
            'UPDATE domains SET provisioning_error = ?, updated_at = ? WHERE id = ?',
            [$message, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }
}
