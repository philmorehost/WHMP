<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Auth\AdminRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Staff\PermissionRegistry;
use Throwable;

/**
 * Tells the people who can release a payout that there is one waiting.
 *
 * WHO IS TOLD, AND WHY THAT IS DERIVED RATHER THAN LISTED
 *
 * The recipients are resolved from the permission that actually gates the payout
 * queue (`resellers.manage`), plus every super admin, because `is_super_admin`
 * bypasses the permission matrix. Hard-coding "email the super admin" would be
 * wrong the moment somebody grants the queue to a finance role: they would be
 * able to release the funds and would never be told a request had arrived. The
 * rule is "tell whoever can act", and the permission registry is the only place
 * that answer is written down.
 *
 * BEST-EFFORT, LIKE EVERY OTHER NOTIFICATION HERE
 *
 * A payout request must succeed whether or not anybody can be emailed — the
 * request itself is the thing the reseller cares about, and failing it because
 * SMTP is down would take their money out of reach and give them nothing. So
 * every send is wrapped and failures are swallowed, and a site with no mail
 * configured still has a working queue page. The cost is that a broken mail
 * setup is silent, which is the same trade the rest of this codebase makes.
 */
final class ResellerPayoutNotifier
{
    private const TEMPLATE = 'reseller_payout_requested';

    public function __construct(
        private readonly EmailDispatcher $mail,
        private readonly AdminRepository $admins,
        private readonly ClientRepository $clients,
        private readonly ResellerStoreRepository $stores,
        private readonly CurrencyService $currency,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed> $payout a row as ResellerPayoutRepository returns it
     */
    public function requested(array $payout): void
    {
        try {
            $recipients = $this->admins->withPermission(PermissionRegistry::RESELLERS_MANAGE);

            if ($recipients === []) {
                return;
            }

            $variables = $this->variables($payout);

            foreach ($recipients as $admin) {
                $email = trim((string) ($admin['email'] ?? ''));

                if ($email === '') {
                    continue;
                }

                // Individually wrapped: one malformed address must not stop the
                // rest of the queue's recipients being told.
                try {
                    $this->mail->sendTemplate(self::TEMPLATE, $email, $variables);
                } catch (Throwable) {
                    // Swallowed on purpose — see the class docblock.
                }
            }
        } catch (Throwable) {
            // Resolving recipients failed; the payout still stands.
        }
    }

    /**
     * @param array<string, mixed> $payout
     * @return array<string, string>
     */
    private function variables(array $payout): array
    {
        $store = $this->stores->find((int) ($payout['reseller_id'] ?? 0));

        $client = null;

        if ($store !== null && ($store['client_id'] ?? null) !== null) {
            $client = $this->clients->find((int) $store['client_id']);
        }

        $currencyId = $payout['currency_id'] ?? null;

        return [
            'store_name' => $this->storeName($store),
            'reseller_name' => $this->clientName($client),
            'reseller_email' => trim((string) ($client['email'] ?? '')),
            'amount' => number_format((float) ($payout['amount'] ?? 0.0), 2),
            'currency' => strtoupper($this->currency->codeFor($currencyId === null ? null : (int) $currencyId)),
            'payout_id' => (string) ($payout['id'] ?? ''),
            'requested_at' => (string) ($payout['requested_at'] ?? ''),
            'queue_url' => $this->baseUrl() . '/admin/resellers/payouts',
            'company_name' => brand_name(),
        ];
    }

    /** @param array<string, mixed>|null $store */
    private function storeName(?array $store): string
    {
        if ($store === null) {
            return 'Unknown store';
        }

        $brand = trim((string) ($store['brand_name'] ?? ''));

        if ($brand !== '') {
            return $brand;
        }

        $slug = trim((string) ($store['slug'] ?? ''));

        return $slug !== '' ? $slug : 'Store #' . ($store['id'] ?? '');
    }

    /** @param array<string, mixed>|null $client */
    private function clientName(?array $client): string
    {
        if ($client === null) {
            return 'Unknown reseller';
        }

        $first = trim((string) ($client['first_name'] ?? ''));
        $last = trim((string) ($client['last_name'] ?? ''));
        $name = trim($first . ' ' . $last);

        return $name !== '' ? $name : 'Client #' . ($client['id'] ?? '');
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->config->env('APP_URL', 'http://localhost'), '/');
    }
}
