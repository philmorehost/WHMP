<?php

declare(strict_types=1);

namespace CodeVault\Cart;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Cron\CronJob;
use CodeVault\Cron\ReportsCronStats;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Settings\SettingsRepository;

/**
 * Cart abandonment recovery (blueprint §4.2): a sweep that finds carts
 * sitting idle past the configured threshold and emails the visitor a
 * recovery reminder with a direct link back to checkout.
 *
 * The cart itself stays in the session — this job only reads the persisted
 * snapshot (AbandonedCartRepository) that CheckoutController refreshes on
 * every cart mutation, so `updated_at` is a truthful "last touched" stamp
 * and carts that were actively being edited are never emailed.
 *
 * Defaults to emailing once per abandoned cart (reminder_sent_at stamp).
 * Set cart.abandoned_repeat_hours > 0 in Configuration to re-remind every
 * N hours instead — useful for a second-chance nudge on higher-value carts.
 */
final class AbandonedCartJob implements CronJob, ReportsCronStats
{
    /** @var array<string, int> counters for the daily activity report */
    private array $stats = [];

    public const DEFAULT_IDLE_MINUTES = 120;
    public const DEFAULT_REPEAT_HOURS = 0;

    public function __construct(
        private readonly AbandonedCartRepository $carts,
        private readonly EmailDispatcher $mail,
        private readonly ClientRepository $clients,
        private readonly CurrencyRepository $currencies,
        private readonly Config $config,
        private readonly SettingsRepository $settings
    ) {
    }

    public function name(): string
    {
        return 'abandoned-cart';
    }

    public function frequencyMinutes(): int
    {
        return 60;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats;
    }

    public function handle(): void
    {
        $this->stats = ['abandoned_cart_reminders' => 0];

        $idleMinutes = (int) $this->settings->get('cart.abandoned_idle_minutes', (string) self::DEFAULT_IDLE_MINUTES);
        $repeatHours = (int) $this->settings->get('cart.abandoned_repeat_hours', (string) self::DEFAULT_REPEAT_HOURS);

        $allowRepeat = $repeatHours > 0;
        $repeatEveryMinutes = $allowRepeat ? $repeatHours * 60 : 0;

        $baseUrl = rtrim((string) $this->config->env('APP_URL', 'http://localhost'), '/');

        foreach ($this->carts->stale($idleMinutes, $allowRepeat, $repeatEveryMinutes) as $cart) {
            $client = $cart['client_id'] !== null ? $this->clients->find((int) $cart['client_id']) : null;

            $email = $client !== null && !empty($client['email'])
                ? $client['email']
                : (string) ($cart['email'] ?? '');

            if ($email === '') {
                // Guest cart with no captured email — nothing to send to.
                // Marking it reminded would hide it from the repeat sweep,
                // so leave it for a later run in case the visitor logs in
                // and the snapshot picks up a client email.
                continue;
            }

            $firstName = $client['first_name'] ?? 'there';
            $total = $this->formatTotal((float) $cart['total'], (int) $cart['currency_id']);
            $itemCount = $this->countItems((string) $cart['items']);

            $this->mail->sendTemplate('abandoned_cart_reminder', $email, [
                'first_name' => $firstName,
                'item_count' => (string) $itemCount,
                'total' => $total,
                'checkout_url' => $baseUrl . '/cart',
                'company_name' => brand_name(),
            ], $client !== null ? (int) $client['id'] : null);

            $this->carts->markReminderSent((int) $cart['id']);
            $this->stats['abandoned_cart_reminders']++;
        }
    }

    private function formatTotal(float $total, int $currencyId): string
    {
        $currency = $this->currencies->find($currencyId);

        return ($currency['symbol'] ?? '$') . number_format($total, 2);
    }

    private function countItems(string $itemsJson): int
    {
        try {
            $decoded = json_decode($itemsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return 0;
        }

        $count = 0;
        foreach (($decoded['items'] ?? []) as $item) {
            $count += (int) ($item['quantity'] ?? 1);
        }

        return $count;
    }
}
