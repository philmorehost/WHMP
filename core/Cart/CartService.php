<?php

declare(strict_types=1);

namespace CodeVault\Cart;

use CodeVault\Billing\PromotionService;
use CodeVault\Catalog\BillingCycle;
use CodeVault\Catalog\ConfigurableOptionPricingRepository;
use CodeVault\Catalog\ConfigurableOptionRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Database;

/**
 * Resolves cart lines against live product/option pricing and stock —
 * the cart itself only stores IDs, this is what computes real numbers.
 */
final class CartService
{
    public function __construct(
        private readonly Cart $cart,
        private readonly ProductRepository $products,
        private readonly ProductPricingRepository $pricing,
        private readonly ConfigurableOptionRepository $options,
        private readonly ConfigurableOptionPricingRepository $optionPricing,
        private readonly PromotionService $promotions,
        private readonly Database $db
    ) {
    }

    /**
     * A promo code discounts the product subtotal only (never setup fees) —
     * validated fresh on every call rather than trusted from session state,
     * so a code that expires or hits its redemption cap between page loads
     * stops applying immediately instead of silently over-discounting.
     *
     * @return array{lines: array<int, array<string, mixed>>, subtotal: float, setupFees: float, discount: float, promoCode: ?string, promotionId: ?int, promoError: ?string, total: float}
     */
    public function priced(): array
    {
        $lines = [];
        $subtotal = 0.0;
        $setupFees = 0.0;

        foreach ($this->cart->items() as $index => $item) {
            $product = $this->products->find($item['product_id']);

            if ($product === null) {
                continue;
            }

            $isFree = ($product['pay_type'] ?? 'paid') === 'free';
            $priceRow = $this->pricing->find($item['product_id'], $item['billing_cycle']);
            $unitPrice = ($isFree || $priceRow === null) ? 0.0 : (float) $priceRow['price'];
            $setupFee = ($isFree || $priceRow === null) ? 0.0 : (float) $priceRow['setup_fee'];

            $selectedOptions = [];
            $optionsTotal = 0.0;

            foreach ($item['options'] as $groupId => $optionId) {
                $option = $this->options->find((int) $optionId);

                if ($option === null) {
                    continue;
                }

                $optionPrice = $this->optionPricing->priceFor((int) $optionId, $item['billing_cycle']);
                $optionsTotal += $optionPrice;

                $selectedOptions[] = [
                    'option_id' => (int) $optionId,
                    'name' => $option['name'],
                    'price' => $optionPrice,
                ];
            }

            $domainPrice = 0.00;
            $domainOptions = $item['domain_options'] ?? null;
            if ($domainOptions !== null && !empty($domainOptions['name'])) {
                if (in_array($domainOptions['option'], ['register', 'transfer'], true)) {
                    $parts = explode('.', $domainOptions['name']);
                    $tld = '.' . end($parts);
                    $priceRow = $this->db->selectOne("SELECT register_price, transfer_price FROM domain_pricing WHERE tld = ? LIMIT 1", [$tld]);
                    if ($priceRow !== null) {
                        $domainPrice = $domainOptions['option'] === 'register' ? (float) $priceRow['register_price'] : (float) $priceRow['transfer_price'];
                    }
                }
            }

            $quantity = $item['quantity'];
            $lineTotal = ($unitPrice + $optionsTotal) * $quantity;

            $lines[] = [
                'index' => $index,
                'product_id' => (int) $product['id'],
                'product_name' => $product['name'],
                'billing_cycle' => $item['billing_cycle'],
                'cycle_label' => BillingCycle::labels()[$item['billing_cycle']] ?? $item['billing_cycle'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'setup_fee' => $setupFee,
                'options' => $selectedOptions,
                'options_total' => $optionsTotal,
                'line_total' => $lineTotal + $setupFee,
                'in_stock' => $this->products->hasUnlimitedOrAvailableStock((int) $product['id']),
                'domain_options' => $item['domain_options'] ?? null,
                'domain_price' => $domainPrice,
                'server_options' => $item['server_options'] ?? null,
                'custom_fields' => $item['custom_fields'] ?? null,
            ];

            $subtotal += $lineTotal;
            $setupFees += $setupFee;
        }

        $domainTotal = 0.0;
        foreach ($lines as $line) {
            $domainTotal += (float) ($line['domain_price'] ?? 0.0);
        }

        $promoCode = $this->cart->promoCode();
        $discount = 0.0;
        $promotionId = null;
        $promoError = null;

        if ($promoCode !== null && $lines !== []) {
            $validation = $this->promotions->validate($promoCode, $subtotal);

            if ($validation['valid']) {
                $discount = $validation['discount'];
                $promotionId = (int) $validation['promotion']['id'];
            } else {
                $promoError = $validation['message'];
            }
        }

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'setupFees' => $setupFees,
            'domainTotal' => $domainTotal,
            'discount' => $discount,
            'promoCode' => $promoCode,
            'promotionId' => $promotionId,
            'promoError' => $promoError,
            'total' => max(0.0, $subtotal + $setupFees + $domainTotal - $discount),
        ];
    }
}
