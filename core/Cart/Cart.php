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

    /**
     * A promo code (typed or applied from a banner) also gets a same-value
     * cookie, so it keeps applying for the rest of the day even if the PHP
     * session resets in between — e.g. a visitor who applies a banner code,
     * then registers/logs in, shouldn't have to retype it because signup
     * happened to start a fresh session.
     */
    private const PROMO_COOKIE_KEY = 'cv_promo_code';
    private const PROMO_COOKIE_TTL_SECONDS = 86400;

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
    public function add(int $productId, string $billingCycle, array $selectedOptions = [], int $quantity = 1, ?array $domainOptions = null, ?array $serverOptions = null, ?array $customFields = null): void
    {
        $items = $this->items();
        $items[] = [
            'product_id' => $productId,
            'billing_cycle' => $billingCycle,
            'quantity' => max(1, $quantity),
            'options' => $selectedOptions,
            'domain_options' => $domainOptions,
            'server_options' => $serverOptions,
            'custom_fields' => $customFields,
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
        $this->clearPromoCode();
    }

    public function count(): int
    {
        return count($this->items());
    }

    /**
     * A promo code the shopper has typed in or applied from a banner — kept
     * separate from cart_items so removing/re-adding lines doesn't clear it.
     * Falls back to the day-long cookie (see PROMO_COOKIE_KEY) and writes it
     * back into the session, so the rest of the app only ever needs to read
     * the session going forward this request.
     */
    public function promoCode(): ?string
    {
        $code = $this->session->get(self::PROMO_SESSION_KEY);

        if ($code !== null) {
            return $code;
        }

        $cookieCode = $_COOKIE[self::PROMO_COOKIE_KEY] ?? null;

        if (!is_string($cookieCode) || trim($cookieCode) === '') {
            return null;
        }

        $normalized = strtoupper(trim($cookieCode));
        $this->session->set(self::PROMO_SESSION_KEY, $normalized);

        return $normalized;
    }

    public function setPromoCode(string $code): void
    {
        $normalized = strtoupper(trim($code));
        $this->session->set(self::PROMO_SESSION_KEY, $normalized);

        if (!headers_sent()) {
            setcookie(self::PROMO_COOKIE_KEY, $normalized, [
                'expires' => time() + self::PROMO_COOKIE_TTL_SECONDS,
                'path' => '/',
                'samesite' => 'Lax',
            ]);
        }
    }

    public function clearPromoCode(): void
    {
        $this->session->remove(self::PROMO_SESSION_KEY);
        unset($_COOKIE[self::PROMO_COOKIE_KEY]);

        if (!headers_sent()) {
            setcookie(self::PROMO_COOKIE_KEY, '', ['expires' => time() - 3600, 'path' => '/']);
        }
    }
}
