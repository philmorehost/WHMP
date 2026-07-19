<?php

declare(strict_types=1);

namespace CodeVault\Cart;

use CodeVault\Billing\CurrencySelection;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\PromotionRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Billing\TaxCalculator;
use CodeVault\Catalog\BillingCycle;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use DateTimeImmutable;

/**
 * Turns a priced cart into a real Order + Invoice (blueprint §4.2 "order
 * completion + invoice"), plus a recurring Service per non-one-time line
 * (blueprint §4.4 — the entity the R5 recurring billing engine rebills).
 * New orders land as `pending` — there's no fraud/auto-accept engine yet
 * (that's R9), so an admin accepts orders from the Pending queue today.
 */
final class CheckoutService
{
    public function __construct(
        private readonly Cart $cart,
        private readonly CartService $cartService,
        private readonly ProductRepository $products,
        private readonly ClientRepository $clients,
        private readonly ServiceRepository $services,
        private readonly TaxCalculator $tax,
        private readonly CurrencyService $currency,
        private readonly CurrencySelection $currencySelection,
        private readonly PromotionRepository $promotions,
        private readonly Database $db,
        private readonly HookDispatcher $hooks
    ) {
    }

    /**
     * @return array{success: bool, orderId?: int, invoiceId?: int, error?: string}
     */
    public function placeOrder(int $clientId): array
    {
        $priced = $this->cartService->priced();

        if ($priced['lines'] === []) {
            return ['success' => false, 'error' => 'Your cart is empty.'];
        }

        foreach ($priced['lines'] as $line) {
            if (!$line['in_stock']) {
                return ['success' => false, 'error' => "\"{$line['product_name']}\" is out of stock."];
            }
        }

        $client = $this->clients->find($clientId);
        $tax = $this->tax->calculate($client ?? [], $priced['total']);
        $effectiveCurrency = $this->currency->resolveEffective($client, $this->currencySelection->get());
        $currencyLock = $this->currency->lockColumns($effectiveCurrency);

        try {
            $result = $this->db->transaction(function () use ($priced, $clientId, $tax, $currencyLock) {
                return $this->buildOrder($priced, $clientId, $tax, $currencyLock);
            });
        } catch (OutOfStockException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        [$orderId, $invoiceId, $serviceIds] = $result;

        $this->hooks->fire(HookPoints::ORDER_PLACED, ['orderId' => $orderId, 'clientId' => $clientId]);
        $this->hooks->fire(HookPoints::INVOICE_CREATED, ['invoiceId' => $invoiceId, 'clientId' => $clientId]);

        $this->cart->clear();

        return ['success' => true, 'orderId' => $orderId, 'invoiceId' => $invoiceId, 'serviceIds' => $serviceIds];
    }

    /**
     * @param array{lines: array<int, array<string, mixed>>, subtotal: float, setupFees: float, discount: float, promoCode: ?string, promotionId: ?int, total: float} $priced
     * @param array{rate: float, name: string, amount: float} $tax
     * @param array{currency_id: int|null, currency_rate: float} $currencyLock
     * @return array{0: int, 1: int, 2: array<int, int>}
     */
    private function buildOrder(array $priced, int $clientId, array $tax, array $currencyLock): array
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $today = substr($now, 0, 10);
        $invoiceTotal = $priced['total'] + $tax['amount'];
        // Defensive fallbacks: a hand-built $priced fixture (e.g. in tests
        // exercising this method directly) may predate these keys.
        $discount = (float) ($priced['discount'] ?? 0.0);
        $promoCode = $priced['promoCode'] ?? null;
        $promotionId = $priced['promotionId'] ?? null;

        $orderId = (int) $this->db->insert(
            'INSERT INTO orders (client_id, status, total, discount_amount, promotion_code, currency_id, currency_rate, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, 'pending', $invoiceTotal, $discount, $promoCode, $currencyLock['currency_id'], $currencyLock['currency_rate'], $now, $now]
        );

        $serviceIds = [];

        foreach ($priced['lines'] as $line) {
            $this->db->insert(
                'INSERT INTO order_items (order_id, product_id, product_name, billing_cycle, quantity, unit_price, setup_fee, configurable_options, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $orderId,
                    $line['product_id'],
                    $line['product_name'],
                    $line['billing_cycle'],
                    $line['quantity'],
                    $line['unit_price'],
                    $line['setup_fee'],
                    json_encode($line['options']),
                    $now,
                ]
            );

            // The pre-transaction 'in_stock' check above reads stock at
            // pricing time — under concurrent checkouts for the last unit,
            // both requests can pass that check before either decrements.
            // This atomic UPDATE (product's decrementStock()) is the real
            // check: it only affects a row when stock_quantity is finite
            // AND still > 0, so a lost race returns false here and we
            // abort the whole order rather than silently overselling.
            $product = $this->products->find((int) $line['product_id']);
            $hasLimitedStock = $product !== null && $product['stock_quantity'] !== null;
            $decremented = $this->products->decrementStock($line['product_id']);

            if ($hasLimitedStock && !$decremented) {
                throw new OutOfStockException("\"{$line['product_name']}\" just sold out — please remove it from your cart.");
            }

            if ($line['billing_cycle'] !== BillingCycle::ONE_TIME) {
                $recurringAmount = ($line['unit_price'] + $line['options_total']) * $line['quantity'];

                $serviceIds[] = $this->services->create([
                    'client_id' => $clientId,
                    'order_id' => $orderId,
                    'product_id' => $line['product_id'],
                    'product_name' => $line['product_name'],
                    'billing_cycle' => $line['billing_cycle'],
                    'amount' => $recurringAmount,
                    'status' => 'pending',
                    'next_due_date' => ServiceRepository::nextCycleDate($today, $line['billing_cycle']),
                ]);
            }
        }

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, discount_amount, promotion_code, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $orderId, 'unpaid', $priced['total'], $tax['amount'], $discount, $promoCode, $invoiceTotal, $currencyLock['currency_id'], $currencyLock['currency_rate'], $today, $now, $now]
        );

        foreach ($priced['lines'] as $line) {
            $description = "{$line['product_name']} ({$line['cycle_label']}) x{$line['quantity']}";
            $this->db->insert(
                'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                [$invoiceId, $description, $line['line_total']]
            );
        }

        if ($discount > 0) {
            $this->db->insert(
                'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                [$invoiceId, "Promo: {$promoCode}", -$discount]
            );

            if ($promotionId !== null) {
                $this->promotions->incrementRedemptions($promotionId);
            }
        }

        if ($tax['amount'] > 0) {
            $this->db->insert(
                'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                [$invoiceId, "{$tax['name']} ({$tax['rate']}%)", $tax['amount']]
            );
        }

        return [$orderId, $invoiceId, $serviceIds];
    }
}
