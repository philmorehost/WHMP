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
use CodeVault\Reseller\CurrentReseller;
use CodeVault\Reseller\ResellerRetailPricing;

/**
 * Resolves cart lines against live product/option pricing and stock —
 * the cart itself only stores IDs, this is what computes real numbers.
 *
 * On a reseller's storefront this runs in TWO currencies of meaning, not one:
 * the customer is charged the reseller's RETAIL price, while the reseller owes
 * us our COST for the same line. Both are computed here, from the same
 * catalogue lookup, so they can never drift apart — and both are returned, so
 * the order records what the customer paid and what the reseller owes without
 * either being re-derived later from prices that may have changed.
 *
 * With no store in play, nothing changes: the cost fields are null/zero and
 * every figure is the catalogue price exactly as before.
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
        private readonly Database $db,
        // The storefront this request is on, if any. Resolved from the Host, so
        // a cart on the platform's own site has none and prices at list.
        private readonly ?CurrentReseller $currentReseller = null,
        private readonly ?ResellerRetailPricing $retail = null
    ) {
    }

    /**
     * A promo code discounts the product subtotal only (never setup fees) —
     * validated fresh on every call rather than trusted from session state,
     * so a code that expires or hits its redemption cap between page loads
     * stops applying immediately instead of silently over-discounting.
     *
     * @return array{lines: array<int, array<string, mixed>>, subtotal: float, setupFees: float, domainTotal: float, discount: float, promoCode: ?string, promotionId: ?int, promoError: ?string, total: float, costTotal: float, store_id: ?int}
     */
    public function priced(): array
    {
        return $this->priceItems($this->cart->items(), $this->cart->promoCode(), $this->activeStore());
    }

    /**
     * The store being served, or null on the platform's own site.
     *
     * @return array<string, mixed>|null
     */
    public function activeStore(): ?array
    {
        $store = $this->currentReseller?->get();

        return $store !== null && $this->retail !== null ? $store : null;
    }

    /**
     * Same pricing logic as priced(), against an explicit item list instead
     * of the session cart — the seam that lets an admin price an order for a
     * client without needing that client's own session (see
     * AdminOrderController). $items is the exact shape Cart::add() produces.
     *
     * @param array<int, array{product_id: int, billing_cycle: string, quantity: int, options: array<int, int>, domain_options?: array<string, mixed>|null, server_options?: array<string, mixed>|null, custom_fields?: array<string, mixed>|null}> $items
     * @param array<string, mixed>|null $store the reseller storefront, or null for platform pricing
     * @return array{lines: array<int, array<string, mixed>>, subtotal: float, setupFees: float, domainTotal: float, discount: float, promoCode: ?string, promotionId: ?int, promoError: ?string, total: float, costTotal: float, store_id: ?int}
     */
    public function priceItems(array $items, ?string $promoCode = null, ?array $store = null): array
    {
        $lines = [];
        $subtotal = 0.0;
        $setupFees = 0.0;
        $costTotal = 0.0;

        // With a store, every catalogue figure below is a COST basis and the
        // customer's price is derived from it. Without one, list IS the price
        // and the cost fields stay null — so the platform's own checkout keeps
        // exactly the numbers it had before stores existed.
        $markup = $store === null || $this->retail === null ? 0.0 : $this->retail->markupFor($store);

        foreach ($items as $index => $item) {
            $product = $this->products->find($item['product_id']);

            if ($product === null) {
                continue;
            }

            $isFree = ($product['pay_type'] ?? 'paid') === 'free';
            $priceRow = $this->pricing->find($item['product_id'], $item['billing_cycle']);
            $unitPrice = ($isFree || $priceRow === null) ? 0.0 : (float) $priceRow['price'];
            $setupFee = ($isFree || $priceRow === null) ? 0.0 : (float) $priceRow['setup_fee'];

            $costUnit = null;
            $costSetup = null;

            if ($store !== null && $this->retail !== null) {
                $quote = $this->retail->quoteProduct($unitPrice, $store, (int) $product['id'], (string) $item['billing_cycle']);
                $costUnit = $quote['cost'];
                $costSetup = $this->costOf($setupFee, 'service');
                $unitPrice = $quote['retail'];
                $setupFee = $this->retail->priceFor($setupFee, $markup, null);
            }

            $selectedOptions = [];
            $optionsTotal = 0.0;
            $costOptionsTotal = 0.0;

            foreach ($item['options'] as $groupId => $optionId) {
                $option = $this->options->find((int) $optionId);

                if ($option === null) {
                    continue;
                }

                $optionPrice = $this->optionPricing->priceFor((int) $optionId, $item['billing_cycle']);

                // Options have no per-item override table, so they follow the
                // store's markup. A reseller who wants a hand-set price for an
                // option prices it into the product instead.
                if ($store !== null && $this->retail !== null) {
                    $costOptionsTotal += $this->costOf($optionPrice, 'service');
                    $optionPrice = $this->retail->priceFor($optionPrice, $markup, null);
                }

                $optionsTotal += $optionPrice;

                $selectedOptions[] = [
                    'option_id' => (int) $optionId,
                    'name' => $option['name'],
                    'price' => $optionPrice,
                ];
            }

            $domainPrice = 0.00;
            $costDomainPrice = null;
            $domainOptions = $item['domain_options'] ?? null;
            if ($domainOptions !== null && !empty($domainOptions['name'])) {
                // Admin existing-service/domain orders supply the price
                // explicitly (AdminOrderController resolves the TLD's
                // register price) rather than via the register/transfer
                // lookup below — the domain already exists, so there's no
                // new registration price to fetch from a registrar.
                if (($domainOptions['option'] ?? '') === 'existing' && isset($domainOptions['price'])) {
                    $domainPrice = (float) $domainOptions['price'];

                    // An admin-supplied figure is already a decision, not a
                    // catalogue price, so it is the customer's price as given
                    // and only the cost side is derived.
                    if ($store !== null && $this->retail !== null) {
                        $costDomainPrice = $this->costOf($domainPrice, 'domain');
                    }
                } elseif (in_array($domainOptions['option'], ['register', 'transfer'], true)) {
                    $tld = self::tldFromDomainName((string) $domainOptions['name']);
                    $priceRow = $this->db->selectOne("SELECT register_price, transfer_price FROM domain_pricing WHERE tld = ? LIMIT 1", [$tld]);
                    if ($priceRow !== null) {
                        $list = $domainOptions['option'] === 'register' ? (float) $priceRow['register_price'] : (float) $priceRow['transfer_price'];
                        $domainPrice = $list;

                        if ($store !== null && $this->retail !== null) {
                            $quote = $this->retail->quoteDomain($list, $store, $tld, (string) $domainOptions['option']);
                            $domainPrice = $quote['retail'];
                            $costDomainPrice = $quote['cost'];
                        }
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
                // What the reseller owes us for this line. Null on the
                // platform's own site, where there is no reseller.
                'cost_price' => $costUnit,
                'cost_setup_fee' => $costSetup,
                'cost_options_total' => $store === null ? null : round($costOptionsTotal, 2),
                'cost_domain_price' => $costDomainPrice,
            ];

            $subtotal += $lineTotal;
            $setupFees += $setupFee;

            if ($store !== null) {
                $costTotal += ($costUnit ?? 0.0) * $quantity
                    + ($costSetup ?? 0.0)
                    + $costOptionsTotal
                    + ($costDomainPrice ?? 0.0);
            }
        }

        $domainTotal = 0.0;
        foreach ($lines as $line) {
            $domainTotal += (float) ($line['domain_price'] ?? 0.0);
        }

        $discount = 0.0;
        $promotionId = null;
        $promoError = null;

        if ($promoCode !== null && $lines !== []) {
            // Scoped to the site being shopped on: a storefront honours only that
            // store's own codes, the platform only its own.
            $storeId = $store === null ? null : (int) ($store['id'] ?? 0);
            $validation = $this->promotions->validate(
                $promoCode,
                $subtotal,
                $storeId !== null && $storeId > 0 ? $storeId : null
            );

            if ($validation['valid']) {
                $discount = $validation['discount'];
                $promotionId = (int) $validation['promotion']['id'];
            } else {
                $promoError = $validation['message'];
            }
        }

        // The promo reduces what the CUSTOMER pays and nothing else. It is the
        // reseller's own concession, so our cost — what they owe us — is
        // unaffected; otherwise a reseller's campaign would spend our margin.
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
            'costTotal' => round($costTotal, 2),
            'store_id' => $store === null ? null : (int) ($store['id'] ?? 0),
        ];
    }

    /** What the reseller owes us for one catalogue figure, at the admin's discount. */
    private function costOf(float $listPrice, string $kind): float
    {
        return $this->retail === null
            ? round($listPrice, 2)
            : $this->retail->costPriceFor($listPrice, $kind);
    }

    /**
     * The TLD portion of a domain name, in domain_pricing's dotted-suffix
     * form (e.g. "foo.com.ng" -> ".com.ng").
     *
     * Everything after the FIRST label, not just the last one — domain_pricing
     * rows for a compound TLD like ".com.ng" or ".org.ng" are stored as the
     * whole suffix. Taking only the last dot segment (explode('.', $name);
     * end($parts)) resolved "foo.org.ng" to ".ng" instead of ".org.ng",
     * silently pricing every compound-TLD domain at whatever plain ".ng"
     * costs — both in the cart total here and, since CheckoutService used the
     * same shortcut, on the invoice actually raised at checkout. Matches the
     * TLD-normalisation already used for domains.tld lookups in
     * DomainService::renew() and DomainRenewalBillingService.
     */
    public static function tldFromDomainName(string $domainName): string
    {
        $parts = explode('.', strtolower(trim($domainName)));

        if (count($parts) > 1) {
            array_shift($parts);
        }

        return '.' . implode('.', $parts);
    }
}
