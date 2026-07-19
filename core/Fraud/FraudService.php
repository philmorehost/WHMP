<?php

declare(strict_types=1);

namespace CodeVault\Fraud;

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
        private readonly HookDispatcher $hooks
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

        return [
            'total' => (float) $order['total'],
            'items' => $this->orders->items((int) $order['id']),
            'clientName' => $client !== null ? $client['first_name'] . ' ' . $client['last_name'] : '',
            'clientEmail' => $client['email'] ?? '',
            'clientAccountAgeMinutes' => $accountAgeMinutes,
        ];
    }
}
