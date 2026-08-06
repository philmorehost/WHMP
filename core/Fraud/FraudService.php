<?php

declare(strict_types=1);

namespace CodeVault\Fraud;

use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\OrderRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Modules\FraudModule;
use CodeVault\Modules\ModuleManager;
use DateTimeImmutable;

/**
 * Runs every registered FraudModule against a freshly placed order and
 * records the combined verdict (blueprint §4.4 fraud engine). Takes the
 * highest single score across modules rather than averaging — one
 * confident signal is enough to warrant a human look, and averaging
 * would let a strong rule-based flag get diluted by a quiet AI module.
 */
final class FraudService
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly OrderRepository $orders,
        private readonly ClientRepository $clients,
        private readonly HookDispatcher $hooks,
        private readonly CurrencyService $currency
    ) {
    }

    public function evaluate(int $orderId): void
    {
        $order = $this->orders->find($orderId);

        if ($order === null) {
            return;
        }

        $context = $this->buildContext($order);
        $score = 0.0;
        $hold = false;
        $reasons = [];

        foreach ($this->modules->allOfType(FraudModule::class) as $module) {
            /** @var FraudModule $module */
            $result = $module->score($context);
            $score = max($score, $result['score']);
            $hold = $hold || $result['hold'];
            $reasons = [...$reasons, ...$result['reasons']];
        }

        $this->orders->recordFraudReview($orderId, $score, $reasons, $hold);

        if ($hold) {
            $this->hooks->fire(HookPoints::ORDER_FRAUD_FLAGGED, ['orderId' => $orderId, 'score' => $score, 'reasons' => $reasons]);
        }
    }

    /** @param array<string, mixed> $order */
    private function buildContext(array $order): array
    {
        $client = $this->clients->find((int) $order['client_id']);
        $accountAgeMinutes = null;

        if ($client !== null) {
            $createdAt = new DateTimeImmutable((string) $client['created_at']);
            $accountAgeMinutes = (new DateTimeImmutable())->getTimestamp() - $createdAt->getTimestamp();
            $accountAgeMinutes = $accountAgeMinutes / 60;
        }

        $currencyId = $order['currency_id'] !== null ? (int) $order['currency_id'] : null;
        $lockedRate = (float) ($order['currency_rate'] ?? 1.0);

        // Order totals and order_item prices are stored in the client's
        // currency (denominateColumns() at checkout), so a "high-value"
        // threshold expressed in the base currency must be compared against
        // the base-currency equivalent — otherwise a ₦19,370 (=$13) domain
        // order reads as a $19,370 fraud signal. Items are normalized the
        // same way so the AI triage module doesn't reason over inflated
        // NGN-denominated figures either.
        $normalize = fn (float $amount) => $this->currency->toBase($amount, $currencyId, $lockedRate);

        $items = array_map(static function (array $item) use ($normalize): array {
            $item['unit_price'] = $normalize((float) $item['unit_price']);
            $item['setup_fee'] = $normalize((float) $item['setup_fee']);

            return $item;
        }, $this->orders->items((int) $order['id']));

        return [
            'total' => $normalize((float) $order['total']),
            'items' => $items,
            'clientName' => $client !== null ? $client['first_name'] . ' ' . $client['last_name'] : '',
            'clientEmail' => $client['email'] ?? '',
            'clientAccountAgeMinutes' => $accountAgeMinutes,
        ];
    }
}
