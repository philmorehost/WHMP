<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

use CodeVault\Database;
use DateTimeImmutable;

final class MailCampaignRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT c.*, g.name AS group_name,
                cl.first_name AS client_first_name, cl.last_name AS client_last_name, cl.email AS client_email,
                (SELECT COUNT(*) FROM mail_campaign_recipients r WHERE r.campaign_id = c.id) AS recipient_count,
                (SELECT COUNT(*) FROM mail_campaign_recipients r WHERE r.campaign_id = c.id AND r.opened_at IS NOT NULL) AS opened_count
            FROM mail_campaigns c
            LEFT JOIN client_groups g ON g.id = c.client_group_id
            LEFT JOIN clients cl ON cl.id = c.client_id
            ORDER BY c.id DESC
            SQL
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM mail_campaigns WHERE id = ?', [$id]);
    }

    public function create(string $subject, string $body, ?int $clientGroupId, ?int $clientId = null): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        try {
            $this->db->statement('ALTER TABLE mail_campaigns ADD COLUMN client_id INT UNSIGNED NULL AFTER client_group_id');
        } catch (\Throwable) {}

        return (int) $this->db->insert(
            'INSERT INTO mail_campaigns (subject, body, client_group_id, client_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$subject, $body, $clientGroupId, $clientId, 'draft', $now, $now]
        );
    }

    public function markSending(int $id): void
    {
        $this->db->update('UPDATE mail_campaigns SET status = ?, updated_at = ? WHERE id = ?', ['sending', (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);
    }

    public function markSent(int $id): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update('UPDATE mail_campaigns SET status = ?, sent_at = ?, updated_at = ? WHERE id = ?', ['sent', $now, $now, $id]);
    }

    public function addRecipient(int $campaignId, int $clientId, string $openToken): void
    {
        $this->db->insert(
            'INSERT INTO mail_campaign_recipients (campaign_id, client_id, open_token, sent_at, created_at) VALUES (?, ?, ?, ?, ?)',
            [$campaignId, $clientId, $openToken, (new DateTimeImmutable())->format('Y-m-d H:i:s'), (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function recipients(int $campaignId): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT r.*, c.email, c.first_name, c.last_name
            FROM mail_campaign_recipients r
            JOIN clients c ON c.id = r.client_id
            WHERE r.campaign_id = ?
            ORDER BY r.id ASC
            SQL,
            [$campaignId]
        );
    }

    public function recordOpen(string $openToken): bool
    {
        return $this->db->update(
            'UPDATE mail_campaign_recipients SET opened_at = ? WHERE open_token = ? AND opened_at IS NULL',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $openToken]
        ) > 0;
    }
}
