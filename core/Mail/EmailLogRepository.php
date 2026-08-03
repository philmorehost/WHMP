<?php

declare(strict_types=1);

namespace CodeVault\Mail;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Every outbound email lands a row here regardless of outcome — the source
 * for "My Emails" (client area, future) and the admin Email Log.
 */
final class EmailLogRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function create(string $toEmail, string $subject, ?string $templateKey, ?int $clientId): int
    {
        return (int) $this->db->insert(
            'INSERT INTO email_log (to_email, subject, template_key, client_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$toEmail, $subject, $templateKey, $clientId, 'queued', (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    public function markSent(int $id): void
    {
        $this->db->update(
            'UPDATE email_log SET status = ?, sent_at = ? WHERE id = ?',
            ['sent', (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function markFailed(int $id, string $error): void
    {
        $this->db->update('UPDATE email_log SET status = ?, error = ? WHERE id = ?', ['failed', $error, $id]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM email_log WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        return $this->db->select('SELECT * FROM email_log ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
    }

    /** @return array<int, array<string, mixed>> */
    public function forClient(int $clientId, int $limit = 50): array
    {
        return $this->db->select(
            'SELECT * FROM email_log WHERE client_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)),
            [$clientId]
        );
    }

    /** Used by DataPruningJob (blueprint §4.4 "pruning automation") — deletes rows older than the given timestamp, returns how many were removed. */
    public function deleteOlderThan(string $beforeDateTime): int
    {
        return $this->db->delete('DELETE FROM email_log WHERE created_at < ?', [$beforeDateTime]);
    }

    /**
     * The admin Email History feed: every one-off/transactional email as its
     * own row, but a campaign blast collapses into a single row for the
     * whole send instead of one row per recipient — a 500-recipient
     * campaign would otherwise push everything else off the page.
     *
     * The split is exact, not heuristic: mail_campaign_recipients.email_log_id
     * links a recipient straight back to the email_log row its send wrote,
     * so "was this part of a campaign" is a plain LEFT JOIN, not a guess
     * based on similar subjects/timing.
     *
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function history(int $page = 1, int $perPage = 25, string $search = ''): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $singleSearchSql = '';
        $campaignSearchSql = '';
        $singleBindings = [];
        $campaignBindings = [];

        if ($search !== '') {
            $needle = "%{$search}%";
            $singleSearchSql = ' AND (el.subject LIKE ? OR el.to_email LIKE ?)';
            $singleBindings = [$needle, $needle];
            $campaignSearchSql = ' AND mc.subject LIKE ?';
            $campaignBindings = [$needle];
        }

        // Column shapes must match exactly for the UNION ALL — the campaign
        // branch fills placeholders where a single send has nothing (e.g. a
        // single row's audience is always just the one email address, so
        // group_name/campaign client/external list are always NULL there).
        $unionSql = <<<SQL
            SELECT
                'single' AS kind, el.id AS row_id, el.subject AS subject, el.to_email AS to_email,
                el.client_id AS client_id, 1 AS recipient_count,
                (el.status = 'sent') AS sent_count, (el.status = 'failed') AS failed_count, (el.status = 'queued') AS queued_count,
                el.status AS status, el.template_key AS template_key, el.created_at AS created_at,
                NULL AS campaign_id, NULL AS group_name,
                NULL AS campaign_client_first_name, NULL AS campaign_client_last_name, NULL AS campaign_client_email
            FROM email_log el
            LEFT JOIN mail_campaign_recipients r ON r.email_log_id = el.id
            WHERE r.id IS NULL{$singleSearchSql}

            UNION ALL

            SELECT
                'campaign' AS kind, mc.id AS row_id, mc.subject AS subject, NULL AS to_email,
                NULL AS client_id, COUNT(*) AS recipient_count,
                SUM(el.status = 'sent') AS sent_count, SUM(el.status = 'failed') AS failed_count, SUM(el.status = 'queued') AS queued_count,
                NULL AS status, NULL AS template_key, MAX(el.created_at) AS created_at,
                mc.id AS campaign_id, MAX(g.name) AS group_name,
                MAX(cl.first_name) AS campaign_client_first_name, MAX(cl.last_name) AS campaign_client_last_name, MAX(cl.email) AS campaign_client_email
            FROM mail_campaign_recipients r
            JOIN email_log el ON el.id = r.email_log_id
            JOIN mail_campaigns mc ON mc.id = r.campaign_id
            LEFT JOIN client_groups g ON g.id = mc.client_group_id
            LEFT JOIN clients cl ON cl.id = mc.client_id
            WHERE 1 = 1{$campaignSearchSql}
            GROUP BY mc.id
            SQL;

        $total = (int) ($this->db->selectOne(
            "SELECT COUNT(*) AS c FROM ({$unionSql}) history",
            array_merge($singleBindings, $campaignBindings)
        )['c'] ?? 0);

        $data = $this->db->select(
            "SELECT * FROM ({$unionSql}) history ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}",
            array_merge($singleBindings, $campaignBindings)
        );

        foreach ($data as &$row) {
            $row['audience_label'] = $row['kind'] === 'campaign' ? self::campaignAudienceLabel($row) : null;
        }
        unset($row);

        return ['data' => $data, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /** @param array<string, mixed> $row */
    private static function campaignAudienceLabel(array $row): string
    {
        if (!empty($row['campaign_client_email'])) {
            $name = trim(($row['campaign_client_first_name'] ?? '') . ' ' . ($row['campaign_client_last_name'] ?? ''));

            return '👤 ' . ($name !== '' ? $name : $row['campaign_client_email']) . ' (' . $row['campaign_client_email'] . ')';
        }

        if (!empty($row['group_name'])) {
            return '📁 Group: ' . $row['group_name'];
        }

        return '🌐 All active clients';
    }
}
