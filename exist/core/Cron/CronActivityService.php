<?php

declare(strict_types=1);

namespace CodeVault\Cron;

use CodeVault\Database;
use DateTime;

final class CronActivityService
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function getActivityStats(DateTime $since): array
    {
        $sinceStr = $since->format('Y-m-d H:i:s');

        return [
            'invoices_generated' => $this->countInvoicesGenerated($sinceStr),
            'late_fees_added' => $this->countLateFees($sinceStr),
            'domain_renewals' => $this->countDomainRenewals($sinceStr),
            'tickets_created' => $this->countTicketsCreated($sinceStr),
            'tickets_resolved' => $this->countTicketsResolved($sinceStr),
            'services_created' => $this->countServicesCreated($sinceStr),
            'email_sent_count' => $this->countEmailsSent($sinceStr),
            'payment_captured_count' => $this->countPaymentsCaptured($sinceStr),
            'backups_completed' => $this->countBackupsCompleted($sinceStr),
            'cancellations_processed' => $this->countCancellationsProcessed($sinceStr),
            'period_start' => $since,
            'period_end' => new DateTime(),
            'date' => (new DateTime())->format('F j, Y'),
        ];
    }

    private function countInvoicesGenerated(string $since): int
    {
        $result = $this->db->selectOne(
            'SELECT COUNT(*) as count FROM invoices WHERE created_at >= ?',
            [$since]
        );
        return (int) ($result['count'] ?? 0);
    }

    private function countLateFees(string $since): int
    {
        $result = $this->db->selectOne(
            'SELECT COUNT(*) as count FROM transactions
             WHERE type = "late_fee" AND created_at >= ?',
            [$since]
        );
        return (int) ($result['count'] ?? 0);
    }

    private function countDomainRenewals(string $since): int
    {
        $result = $this->db->selectOne(
            'SELECT COUNT(*) as count FROM activity_log
             WHERE action = "domain_renewed" AND created_at >= ?',
            [$since]
        );
        return (int) ($result['count'] ?? 0);
    }

    private function countTicketsCreated(string $since): int
    {
        $result = $this->db->selectOne(
            'SELECT COUNT(*) as count FROM tickets WHERE created_at >= ?',
            [$since]
        );
        return (int) ($result['count'] ?? 0);
    }

    private function countTicketsResolved(string $since): int
    {
        $result = $this->db->selectOne(
            'SELECT COUNT(*) as count FROM tickets
             WHERE status = "closed" AND updated_at >= ?',
            [$since]
        );
        return (int) ($result['count'] ?? 0);
    }

    private function countServicesCreated(string $since): int
    {
        $result = $this->db->selectOne(
            'SELECT COUNT(*) as count FROM services WHERE created_at >= ?',
            [$since]
        );
        return (int) ($result['count'] ?? 0);
    }

    private function countEmailsSent(string $since): int
    {
        $result = $this->db->selectOne(
            'SELECT COUNT(*) as count FROM email_log
             WHERE status IN ("sent", "queued") AND created_at >= ?',
            [$since]
        );
        return (int) ($result['count'] ?? 0);
    }

    private function countPaymentsCaptured(string $since): int
    {
        $result = $this->db->selectOne(
            'SELECT COUNT(*) as count FROM transactions
             WHERE type = "payment" AND status = "completed" AND created_at >= ?',
            [$since]
        );
        return (int) ($result['count'] ?? 0);
    }

    private function countBackupsCompleted(string $since): int
    {
        $result = $this->db->selectOne(
            'SELECT COUNT(*) as count FROM backup_runs
             WHERE status = "completed" AND completed_at >= ?',
            [$since]
        );
        return (int) ($result['count'] ?? 0);
    }

    private function countCancellationsProcessed(string $since): int
    {
        $result = $this->db->selectOne(
            'SELECT COUNT(*) as count FROM cancellation_requests
             WHERE status = "approved" AND processed_at >= ?',
            [$since]
        );
        return (int) ($result['count'] ?? 0);
    }
}
