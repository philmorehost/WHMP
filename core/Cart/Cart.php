<?php

declare(strict_types=1);

namespace CodeVault\Cart;

use CodeVault\Session\SessionManager;

/**
 * Session-backed cart — ephemeral by design (blueprint §4.2 "Order Form /
 * Cart"). Nothing here touches the database; checkout is what turns cart
 * lines into a real Order + Invoice.
 */
final class Cart
{
    private const SESSION_KEY = 'cart_items';
    private const PROMO_SESSION_KEY = 'cart_promo_code';

    public function __construct(
        private readonly SessionManager $session
    ) {
    }

    /** @return array<int, array{product_id: int, billing_cycle: string, quantity: int, options: array<int, int>}> */
    public function items(): array
    {
        return $this->session->get(self::SESSION_KEY, []);
    }

    /** @param array<int, int> $selectedOptions option_group_id => configurable_option_id */
    public function add(int $productId, string $billingCycle, array $selectedOptions = [], int $quantity = 1): void
    {
        $items = $this->items();
        $items[] = [
            'product_id' => $productId,
            'billing_cycle' => $billingCycle,
            'quantity' => max(1, $quantity),
            'options' => $selectedOptions,
        ];

        $this->session->set(self::SESSION_KEY, $items);
    }

    public function removeAt(int $index): void
    {
        $items = $this->items();
        unset($items[$index]);

        $this->session->set(self::SESSION_KEY, array_values($items));
    }

    public function clear(): void
    {
        $this->session->remove(self::SESSION_KEY);
        $this->session->remove(self::PROMO_SESSION_KEY);
    }

    public function count(): int
    {
        return count($this->items());
    }

    /** A promo code the shopper has typed in — kept separate from cart_items so removing/re-adding lines doesn't clear it. */
    public function promoCode(): ?string
    {
        return $this->session->get(self::PROMO_SESSION_KEY);
    }

    public function setPromoCode(string $code): void
    {
        $this->session->set(self::PROMO_SESSION_KEY, strtoupper(trim($code)));
    }

    public function clearPromoCode(): void
    {
        $this->session->remove(self::PROMO_SESSION_KEY);
    }
}
