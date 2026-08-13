<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AdminRepository;
use CodeVault\Config;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Queue\Job;
use CodeVault\Reports\ExpiringReminderService;
use CodeVault\Support\App;
use Throwable;

/**
 * The deferred half of the admin "Email clients expiring in 7 days" action.
 *
 * Sending an expiry reminder to every affected client is a batch of emails
 * and must never block the admin's browser, so ExpiringReminderController
 * dispatches this job and returns immediately. The worker emails each client
 * whose services/domains renew within 7 days, personalizing every message
 * with their own service/domain names, due dates and amounts, plus the
 * admin's (optionally AI-generated) promotional message, and then reports a
 * per-client summary to every admin.
 */
final class ExpiringReminderJob implements Job
{
    public function __construct(
        public readonly string $message,
        public readonly int $adminId,
        public readonly string $adminIp
    ) {
    }

    public function queue(): string
    {
        return 'default';
    }

    public function handle(): void
    {
        $c = App::container();

        /** @var ExpiringReminderService $expiring */
        $expiring = $c->make(ExpiringReminderService::class);
        /** @var EmailDispatcher $mail */
        $mail = $c->make(EmailDispatcher::class);
        /** @var ActivityLogger $activity */
        $activity = $c->make(ActivityLogger::class);

        $sent = 0;
        $skipped = 0;
        $accounts = $expiring->accountsExpiringSoon();

        foreach ($accounts as $account) {
            $email = trim((string) ($account['email'] ?? ''));

            if ($email === '') {
                $skipped++;
                continue;
            }

            try {
                $mail->sendTemplate('expiring_reminder', $email, [
                    'first_name' => (string) ($account['first_name'] ?? ''),
                    'items_html' => $this->itemsHtml((array) ($account['items'] ?? [])),
                    'promo_message' => $this->message,
                    'billing_url' => $this->billingUrl(),
                    'company_name' => brand_name(),
                ], (int) ($account['client_id'] ?? 0));

                $sent++;
            } catch (Throwable) {
                $skipped++;
            }
        }

        $activity->log(
            'admin',
            $this->adminId,
            'expiring_reminder_sent',
            'system',
            null,
            "Sent expiry reminders to {$sent} client(s)" . ($skipped > 0 ? " ({$skipped} skipped)" : ''),
            $this->adminIp
        );

        $this->notifyAdmins('expiring_reminder_report', [
            'sent' => (string) $sent,
            'skipped' => (string) $skipped,
            'skipped_note' => $skipped > 0 ? " and {$skipped} skipped" : '',
            'total' => (string) count($accounts),
            'admin_url' => $this->adminUrl(),
            'company_name' => brand_name(),
        ]);
    }

    /**
     * @param array<int, array{kind: string, name: string, domain: string, due_date: string, amount: string}> $items
     */
    private function itemsHtml(array $items): string
    {
        $rows = '';

        foreach ($items as $item) {
            $kind = $item['kind'] === 'domain' ? 'Domain' : 'Service';
            $name = $item['name'] !== '' ? htmlspecialchars((string) $item['name'], ENT_QUOTES, 'UTF-8') : '—';
            $domain = $item['domain'] !== '' ? htmlspecialchars((string) $item['domain'], ENT_QUOTES, 'UTF-8') : '—';
            $due = $item['due_date'] !== '' ? htmlspecialchars((string) $item['due_date'], ENT_QUOTES, 'UTF-8') : '—';
            $amount = htmlspecialchars((string) $item['amount'], ENT_QUOTES, 'UTF-8');

            $rows .= '<tr>'
                . "<td>{$kind}</td><td>{$name}</td><td>{$domain}</td><td>{$due}</td><td>{$amount}</td>"
                . '</tr>';
        }

        return '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px;">'
            . '<tr style="text-align:left;border-bottom:1px solid #e2e8f0;"><th>Type</th><th>Name</th><th>Domain</th><th>Due date</th><th>Amount</th></tr>'
            . $rows
            . '</table>';
    }

    private function billingUrl(): string
    {
        return rtrim((string) App::container()->make(Config::class)->env('APP_URL', 'http://localhost'), '/') . '/client/invoices';
    }

    private function adminUrl(): string
    {
        return rtrim((string) App::container()->make(Config::class)->env('APP_URL', 'http://localhost'), '/') . '/admin/expiring-reminders';
    }

    /** @param array<string, string> $variables */
    private function notifyAdmins(string $templateKey, array $variables): void
    {
        try {
            $c = App::container();
            /** @var EmailDispatcher $mail */
            $mail = $c->make(EmailDispatcher::class);
            /** @var AdminRepository $adminRepo */
            $adminRepo = $c->make(AdminRepository::class);

            foreach ($adminRepo->all() as $admin) {
                if (!empty($admin['email'])) {
                    $mail->sendTemplate($templateKey, (string) $admin['email'], $variables);
                }
            }
        } catch (Throwable) {
            // A failed notification must never crash the worker over an email.
        }
    }
}
