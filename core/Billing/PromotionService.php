<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use DateTimeImmutable;

/**
 * Validates a promo code against the cart subtotal and computes the
 * discount — the one place that decides whether a code is currently
 * usable (active window, redemption cap, minimum order amount), so
 * CartService and the admin "test a code" path can't drift apart.
 */
final class PromotionService
{
    public function __construct(
        private readonly PromotionRepository $promotions
    ) {
    }

    /**
     * @return array{valid: bool, message: string, discount: float, promotion: ?array<string, mixed>}
     */
    public function validate(string $code, float $subtotal): array
    {
        $promotion = $this->promotions->findByCode($code);

        if ($promotion === null) {
            return ['valid' => false, 'message' => 'Promo code not found.', 'discount' => 0.0, 'promotion' => null];
        }

        if ($promotion['status'] !== 'active') {
            return ['valid' => false, 'message' => 'This promo code is no longer active.', 'discount' => 0.0, 'promotion' => null];
        }

        $today = (new DateTimeImmutable())->format('Y-m-d');

        if ($promotion['starts_at'] !== null && $today < $promotion['starts_at']) {
            return ['valid' => false, 'message' => 'This promo code is not active yet.', 'discount' => 0.0, 'promotion' => null];
        }

        if ($promotion['expires_at'] !== null && $today > $promotion['expires_at']) {
            return ['valid' => false, 'message' => 'This promo code has expired.', 'discount' => 0.0, 'promotion' => null];
        }

        if ($promotion['max_redemptions'] !== null && (int) $promotion['redemption_count'] >= (int) $promotion['max_redemptions']) {
            return ['valid' => false, 'message' => 'This promo code has reached its redemption limit.', 'discount' => 0.0, 'promotion' => null];
        }

        if ($subtotal < (float) $promotion['min_order_amount']) {
            $minAmount = number_format((float) $promotion['min_order_amount'], 2);

            return ['valid' => false, 'message' => "This promo code requires a minimum order of \${$minAmount}.", 'discount' => 0.0, 'promotion' => null];
        }

        return [
            'valid' => true,
            'message' => 'Promo code applied.',
            'discount' => $this->calculateDiscount($promotion, $subtotal),
            'promotion' => $promotion,
        ];
    }

    /** @param array<string, mixed> $promotion */
    private function calculateDiscount(array $promotion, float $subtotal): float
    {
        if ($subtotal <= 0.0) {
            return 0.0;
        }

        $discount = $promotion['type'] === 'percentage'
            ? $subtotal * ((float) $promotion['value'] / 100)
            : (float) $promotion['value'];

        return round(min($discount, $subtotal), 2);
    }
}
