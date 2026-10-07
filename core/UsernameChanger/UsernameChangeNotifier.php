<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Auth\AdminRepository;
use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\PermissionRegistry;
use Throwable;

/**
 * Every email the feature sends.
 *
 * A store customer's mail goes out under the STORE's brand (EmailDispatcher brands
 * any mail addressed to a store's customer) and every link in it points at the
 * store's own address, so nothing names or links to the platform. Staff alerts go
 * to the platform's admins; a store owner hears only about its own customers.
 *
 * Mail is best-effort: a broken mailer never fails or rolls back a request.
 */
final class UsernameChangeNotifier
{
    public function __construct(
        private readonly EmailDispatcher $mail,
        private readonly SettingsRepository $settings,
        private readonly Config $config,
        private readonly UsernameChangerSettings $ucSettings,
        private readonly ?ResellerStoreRepository $stores = null,
        private readonly ?ResellerStoreLocator $locator = null,
        private readonly ?AdminRepository $admins = null,
        private readonly ?Database $db = null
    ) {
    }

    /** The site a client uses: the store's address for a store customer, else the platform's. */
    public function siteUrl(?int $storeId): string
    {
        if ($storeId !== null && $this->stores !== null && $this->locator !== null) {
            $store = $this->stores->find($storeId);

            if ($store !== null) {
                return rtrim($this->locator->baseUrlFor($store), '/');
            }
        }

        return $this->platformUrl();
    }

    public function platformUrl(): string
    {
        return rtrim((string) $this->config->env('APP_URL', 'http://localhost'), '/');
    }

    /** @param array<string, mixed> $r request (findDetailed) */
    public function confirm(array $r, string $token): void
    {
        $this->toClient(UsernameChangeTemplates::CONFIRM, $r, [
            'confirm_url' => $this->siteUrl(self::storeOf($r)) . '/username-change/confirm/' . $token,
            'expires_at' => (string) ($r['confirm_expires_at'] ?? ''),
        ]);
    }

    /** @param array<string, mixed> $r */
    public function submitted(array $r): void
    {
        $this->toClient(UsernameChangeTemplates::SUBMITTED, $r, ['status_label' => self::statusLabel((string) $r['status'])]);
    }

    /** @param array<string, mixed> $r */
    public function approved(array $r): void
    {
        $this->toClient(UsernameChangeTemplates::APPROVED, $r);
    }

    /** @param array<string, mixed> $r */
    public function declined(array $r): void
    {
        $this->toClient(UsernameChangeTemplates::DECLINED, $r, ['decline_reason' => (string) ($r['decline_reason'] ?? '')]);
    }

    /** @param array<string, mixed> $r */
    public function paymentDue(array $r, string $feeLabel): void
    {
        $this->toClient(UsernameChangeTemplates::PAYMENT_DUE, $r, [
            'fee' => $feeLabel,
            'invoice_id' => (string) ($r['invoice_id'] ?? ''),
            'invoice_url' => $this->siteUrl(self::storeOf($r)) . '/client/invoices/' . (int) ($r['invoice_id'] ?? 0),
        ]);
    }

    /** @param array<string, mixed> $r */
    public function completed(array $r): void
    {
        $this->toClient(UsernameChangeTemplates::COMPLETED, $r, [
            'database_note' => (int) ($r['rename_db_objects'] ?? 0) === 1
                ? 'Your databases and database users were renamed to the new prefix (' . $r['new_username'] . '_). Update the database name and user in wp-config.php and similar files.'
                : 'Your databases keep their existing names, so your websites keep working without changes.',
        ]);
    }

    /** @param array<string, mixed> $r */
    public function failed(array $r): void
    {
        $this->toClient(UsernameChangeTemplates::FAILED, $r);
    }

    /** @param array<string, mixed> $r */
    public function staffNew(array $r): void
    {
        $this->toStaff(UsernameChangeTemplates::STAFF_NEW, $r, ['status_label' => self::statusLabel((string) $r['status'])]);
    }

    /** @param array<string, mixed> $r */
    public function staffFailed(array $r): void
    {
        $this->toStaff(UsernameChangeTemplates::STAFF_FAILED, $r, [
            'attempts' => (string) ($r['attempts'] ?? 0),
            'error' => (string) ($r['last_error'] ?? ''),
        ]);
    }

    /** @param array<string, mixed> $r */
    public function staffMismatch(array $r): void
    {
        $this->toStaff(UsernameChangeTemplates::STAFF_MISMATCH, $r);
    }

    /** The store owner, for a request waiting on the store's approval. @param array<string, mixed> $r */
    public function storeApproval(array $r): void
    {
        $storeId = self::storeOf($r);

        if ($storeId === null || $this->stores === null) {
            return;
        }

        $store = $this->stores->find($storeId);

        if ($store === null) {
            return;
        }

        $owner = $this->ownerEmail((int) $store['client_id']);

        if ($owner === null) {
            return;
        }

        // The owner is a customer of the PLATFORM (or of its upline store), so this
        // mail is branded for the owner's own site, and links to it.
        $this->send(UsernameChangeTemplates::STORE_APPROVAL, $owner['email'], $this->vars($r, [
            'panel_url' => $this->siteUrl(($owner['reseller_id'] ?? null) === null ? null : (int) $owner['reseller_id']) . '/client/reseller/username-requests',
        ]), (int) $owner['id']);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'awaiting_confirmation' => 'awaiting confirmation',
            'pending_approval' => 'waiting for approval',
            'awaiting_payment' => 'waiting for payment',
            'queued' => 'queued',
            'processing' => 'in progress',
            'completed' => 'completed',
            'failed' => 'failed',
            'declined' => 'declined',
            'cancelled' => 'cancelled',
            'expired' => 'expired',
            default => $status,
        };
    }

    /** @param array<string, mixed> $r */
    private static function storeOf(array $r): ?int
    {
        return ($r['reseller_id'] ?? null) === null ? null : (int) $r['reseller_id'];
    }

    /**
     * @param array<string, mixed>  $r
     * @param array<string, string> $extra
     */
    private function toClient(string $template, array $r, array $extra = []): void
    {
        $email = (string) ($r['client_email'] ?? '');

        if ($email === '') {
            return;
        }

        $this->send($template, $email, $this->vars($r, $extra), (int) $r['client_id']);
    }

    /**
     * @param array<string, mixed>  $r
     * @param array<string, string> $extra
     */
    private function toStaff(string $template, array $r, array $extra = []): void
    {
        $vars = $this->vars($r, $extra + ['admin_url' => $this->platformUrl() . '/admin/username-changer/requests/' . (int) $r['id']]);
        $recipients = [];
        $alert = $this->ucSettings->staffAlertEmail();

        if ($alert !== '') {
            $recipients[] = $alert;
        } elseif ($this->admins !== null) {
            try {
                foreach ($this->admins->withPermission(PermissionRegistry::ADDONS_MANAGE) as $admin) {
                    if (!empty($admin['email'])) {
                        $recipients[] = (string) $admin['email'];
                    }
                }
            } catch (Throwable) {
                // No admin list — nothing to send.
            }
        }

        foreach (array_unique($recipients) as $to) {
            // Staff mail is always the platform's own, never a store's.
            try {
                $this->mail->onBehalfOfPlatform()->sendTemplate($template, $to, $vars);
            } catch (Throwable) {
            }
        }
    }

    /**
     * Every value HTML-escaped: the reason is typed by the client and the
     * template engine substitutes raw.
     *
     * @param array<string, mixed>  $r
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function vars(array $r, array $extra = []): array
    {
        $storeId = self::storeOf($r);
        $vars = [
            'request_id' => (string) $r['id'],
            'service_id' => (string) $r['service_id'],
            'client_name' => trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? '')) ?: 'there',
            'old_username' => (string) $r['old_username'],
            'new_username' => (string) $r['new_username'],
            'domain' => (string) ($r['domain'] ?? ''),
            'reason' => (string) (($r['reason'] ?? '') ?: '—'),
            'service_url' => $this->siteUrl($storeId) . '/client/services/' . (int) $r['service_id'],
            'company_name' => (string) ($this->settings->get('theme.brand_name', 'CodeVault') ?: 'CodeVault'),
        ];

        $out = [];

        foreach ($vars + $extra as $key => $value) {
            $out[$key] = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function ownerEmail(int $clientId): ?array
    {
        if ($this->db === null) {
            return null;
        }

        try {
            return $this->db->selectOne('SELECT id, email, reseller_id FROM clients WHERE id = ?', [$clientId]);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, string> $vars */
    private function send(string $template, string $to, array $vars, ?int $clientId): void
    {
        try {
            $this->mail->sendTemplate($template, $to, $vars, $clientId);
        } catch (Throwable) {
            // Best-effort: the request's own state is the record.
        }
    }
}
