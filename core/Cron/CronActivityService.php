<?php

declare(strict_types=1);

namespace CodeVault\Cron;

use CodeVault\Database;
use DateTime;

/**
 * Collects statistics about cron job activity over a given period (typically
 * the last 24 hours) by querying created_at/updated_at timestamps in various
 * tables. Used by CronActivityReportJob to populate the daily report.
 */
final class CronActivityService
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * @return array<string, int|string> of activity stats
     */
    public function getActivityStats(DateTime $since): array
    {
        $sinceStr = $since->format('Y-m-d H:i:s');
        $now = new DateTime();
        $nowStr = $now->format('Y-m-d H:i:s');

        return [
            'invoices_generated' => $this->countInvoices($sinceStr),
            'late_fees_added' => $this->countLateFees($sinceStr),
            'credit_card_charges' => $this->countCreditCardCharges($sinceStr),
            'auto_charge_success' => $this->countSuccessfulAutoCharges($sinceStr),
            'domain_renewals' => $this->countDomainRenewals($sinceStr),
            'renewal_notices' => $this->countRenewalNotices($sinceStr),
            'cancellations' => $this->countServiceCancellations($sinceStr),
            'renewal_reminders' => $this->countRenewalReminders($sinceStr),
            'overdue_reminders' => $this->countOverdueReminders($sinceStr),
            'tickets_escalated' => $this->countTicketEscalations($sinceStr),
            'tickets_auto_closed' => $this->countTicketsAutoClosed($sinceStr),
            'email_campaigns' => $this->countEmailCampaigns($sinceStr),
            'backups_created' => $this->countBackups($sinceStr),
            'integrity_checks_passed' => $this->countIntegrityChecks($sinceStr),
            'data_pruned' => $this->countDataPruned($sinceStr),
            'quotes_expired' => $this->countQuotesExpired($sinceStr),
            'report_date' => date('F d, Y'),
            'generated_at' => date('g:i A'),
        ];
    }

    private function countInvoices(string $since): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) as count FROM invoices WHERE created_at >= ?',
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }

    private function countLateFees(string $since): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM invoice_items WHERE created_at >= ? AND description LIKE '%Late Fee%'",
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }

    private function countCreditCardCharges(string $since): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM transactions WHERE created_at >= ? AND type = 'charge' AND status = 'completed'",
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }

    private function countSuccessfulAutoCharges(string $since): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM transactions WHERE created_at >= ? AND source LIKE '%auto%' AND status = 'completed'",
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }

    private function countDomainRenewals(string $since): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM domains WHERE updated_at >= ? AND status = 'active'",
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }

    private function countRenewalNotices(string $since): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM email_log WHERE created_at >= ? AND email_template_key = 'service_renewal_reminder'",
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }

    private function countServiceCancellations(string $since): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM services WHERE updated_at >= ? AND status = 'cancelled'",
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }

    private function countRenewalReminders(string $since): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM email_log WHERE created_at >= ? AND email_template_key IN ('service_renewal_reminder', 'renewal_reminder')",
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }

    private function countOverdueReminders(string $since): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM email_log WHERE created_at >= ? AND email_template_key = 'invoice_overdue'",
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }

    private function countTicketEscalations(string $since): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM activity_log WHERE created_at >= ? AND description LIKE '%ticket%escalat%'",
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }

    private function countTicketsAutoClosed(string $since): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM activity_log WHERE created_at >= ? AND description LIKE '%ticket%closed%'",
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }

    private function countEmailCampaigns(string $since): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM mail_campaign_recipients WHERE created_at >= ? AND status = 'queued'",
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }

    private function countBackups(string $since): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM backup_runs WHERE created_at >= ? AND status = 'completed'",
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }

    private function countIntegrityChecks(string $since): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM activity_log WHERE created_at >= ? AND type = 'system' AND description LIKE '%integrity%'",
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }

    private function countDataPruned(string $since): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM activity_log WHERE created_at >= ? AND description LIKE '%pruned%' OR description LIKE '%deleted%'",
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }

    private function countQuotesExpired(string $since): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM quotes WHERE updated_at >= ? AND status = 'expired'",
            [$since]
        );
        return (int) ($row['count'] ?? 0);
    }
}
