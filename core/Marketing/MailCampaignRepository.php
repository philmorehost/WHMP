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

    public function create(string $subject, string $body, ?int $clientGroupId, ?int $clientId = null, ?string $externalEmails = null): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        // client_id is created by migration 0133. It used to be added by an
        // ALTER right here, wrapped in an empty catch — which could never have
        // worked: all() joins on the column and crashes the campaign list
        // before any code path can reach create(). Schema changes belong in a
        // migration, which the kernel applies automatically on boot.
        return (int) $this->db->insert(
            'INSERT INTO mail_campaigns (subject, body, client_group_id, client_id, external_emails, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$subject, $body, $clientGroupId, $clientId, $externalEmails, 'draft', $now, $now]
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

    /**
     * Stops a sending campaign without touching a single recipient row.
     *
     * pendingRecipients() and finaliseDrainedCampaigns() both filter on
     * `status = 'sending'`, so this is the entire mechanism — the cron simply
     * stops seeing this campaign's still-pending recipients the moment the
     * status changes. Nobody already sent gets a duplicate, nobody still
     * pending gets skipped; they just wait for resumeCampaign().
     *
     * Only from 'sending': pausing a draft is meaningless (nothing is
     * running), and pausing an already-sent campaign can't undo delivery.
     */
    public function pauseCampaign(int $id): bool
    {
        return $this->db->update(
            "UPDATE mail_campaigns SET status = 'paused', updated_at = ? WHERE id = ? AND status = 'sending'",
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        ) > 0;
    }

    /** Puts a paused campaign back in front of the cron. Only from 'paused'. */
    public function resumeCampaign(int $id): bool
    {
        return $this->db->update(
            "UPDATE mail_campaigns SET status = 'sending', updated_at = ? WHERE id = ? AND status = 'paused'",
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        ) > 0;
    }

    /**
     * Edits a campaign's subject/body — the "review, edit" half of pause →
     * review → edit → resume. Restricted to 'draft' and 'paused': a 'sending'
     * campaign has to be paused first, so there is no window where the cron
     * could read half-edited content mid-batch, and a 'sent' campaign's
     * content is history, not editable.
     */
    public function update(int $id, string $subject, string $body): bool
    {
        return $this->db->update(
            "UPDATE mail_campaigns SET subject = ?, body = ?, updated_at = ? WHERE id = ? AND status IN ('draft', 'paused')",
            [$subject, $body, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        ) > 0;
    }

    /**
     * Campaigns the cron is actively draining right now — what the auto-pause
     * reconciliation loop iterates.
     *
     * @return array<int, int>
     */
    public function sendingCampaignIds(): array
    {
        $rows = $this->db->select("SELECT id FROM mail_campaigns WHERE status = 'sending'");

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Copies the real outcome of any newly-completed send from `email_log`
     * onto its recipient row, then returns how many of the campaign's
     * recipients now have a recorded failure (enqueue failures written
     * directly by markRecipientFailed(), plus confirmed delivery failures
     * just synced from email_log).
     *
     * Why this can't just be checked at send time: `sent_at` being set has
     * never meant "delivered", only "handed to the queue" — SendEmailJob
     * catches and swallows the mailer's exception itself, recording the
     * outcome in email_log rather than letting it propagate, and under the
     * Redis queue driver that job may not even run until well after this
     * recipient's send loop iteration has already finished. The true outcome
     * only exists in email_log, whenever the job actually gets around to
     * running — this reads it from there, however many cron ticks that took.
     */
    public function syncConfirmedFailures(int $campaignId): int
    {
        $this->db->update(
            "UPDATE mail_campaign_recipients r
             JOIN email_log el ON el.id = r.email_log_id
             SET r.send_error = el.error
             WHERE r.campaign_id = ? AND el.status = 'failed' AND r.send_error IS NULL",
            [$campaignId]
        );

        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM mail_campaign_recipients WHERE campaign_id = ? AND send_error IS NOT NULL',
            [$campaignId]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * A recipient is either a client (client_id set, address read from the
     * clients table) or an external address (client_id null, address stored on
     * the row). Both get their own open-tracking token, so tracking works the
     * same for a prospect as for a client.
     */
    /**
     * @param bool $pending true queues the recipient for the cron to send
     *                      later (sent_at stays NULL); false stamps it as
     *                      already sent, for callers that send inline.
     */
    public function addRecipient(int $campaignId, ?int $clientId, string $openToken, ?string $email = null, bool $pending = false): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->insert(
            'INSERT INTO mail_campaign_recipients (campaign_id, client_id, email, open_token, sent_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$campaignId, $clientId, $email, $openToken, $pending ? null : $now, $now]
        );
    }

    /**
     * The next slice of queued recipients to send, oldest campaign first so a
     * big campaign can't starve one queued after it.
     *
     * Carries the campaign's subject/body so the dispatcher needs one query
     * per batch rather than one per recipient.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingRecipients(int $limit): array
    {
        $limit = max(1, min(500, $limit));

        return $this->db->select(
            "SELECT r.id, r.campaign_id, r.client_id, r.open_token,
                    COALESCE(c.email, r.email) AS email,
                    c.first_name, c.last_name,
                    mc.subject, mc.body
             FROM mail_campaign_recipients r
             JOIN mail_campaigns mc ON mc.id = r.campaign_id
             LEFT JOIN clients c ON c.id = r.client_id
             WHERE r.sent_at IS NULL AND mc.status = 'sending'
             ORDER BY r.campaign_id ASC, r.id ASC
             LIMIT {$limit}"
        );
    }

    /**
     * Marks a recipient as handed off to the mail queue and links it to the
     * email_log row that will hold its real outcome. Clears any earlier
     * enqueue failure — a recipient that has now been queued has nothing
     * left to explain, though syncConfirmedFailures() may set send_error
     * again later if this particular send turns out to have failed.
     */
    public function markRecipientSent(int $recipientId, ?int $emailLogId = null): void
    {
        $this->db->update(
            'UPDATE mail_campaign_recipients SET sent_at = ?, email_log_id = ?, send_error = NULL WHERE id = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $emailLogId, $recipientId]
        );
    }

    /**
     * Records that sendRaw() itself failed — the email couldn't even be
     * handed to the queue (DB write or Redis push failing), a real
     * synchronous failure unlike a delivery failure, which SendEmailJob
     * always catches internally. sent_at is left untouched (NULL) so the
     * cron retries this recipient once whatever is down comes back; see
     * syncConfirmedFailures() for the auto-pause threshold check.
     */
    public function markRecipientFailed(int $recipientId, string $error): void
    {
        $this->db->update(
            'UPDATE mail_campaign_recipients SET send_error = ? WHERE id = ?',
            [$error, $recipientId]
        );
    }

    public function countPending(int $campaignId): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM mail_campaign_recipients WHERE campaign_id = ? AND sent_at IS NULL',
            [$campaignId]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Flips any campaign that has finished draining to 'sent'.
     *
     * Also catches a "sending" campaign with no recipient rows at all — an
     * empty audience would otherwise sit in 'sending' forever.
     *
     * @return int campaigns finalised
     */
    public function finaliseDrainedCampaigns(): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->db->update(
            "UPDATE mail_campaigns SET status = 'sent', sent_at = ?, updated_at = ?
             WHERE status = 'sending'
               AND NOT EXISTS (
                   SELECT 1 FROM (SELECT * FROM mail_campaign_recipients) r
                   WHERE r.campaign_id = mail_campaigns.id AND r.sent_at IS NULL
               )",
            [$now, $now]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function recipients(int $campaignId): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT r.*,
                COALESCE(c.email, r.email) AS email,
                c.first_name, c.last_name,
                CASE WHEN r.client_id IS NULL THEN 1 ELSE 0 END AS is_external
            FROM mail_campaign_recipients r
            LEFT JOIN clients c ON c.id = r.client_id
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
