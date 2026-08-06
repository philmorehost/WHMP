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
use CodeVault\Domains\DomainSettings;
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
        private readonly HookDispatcher $hooks,
        private readonly DomainSettings $domainSettings
    ) {
    }

    /**
     * Converts every monetary field in a priced() result from base currency
     * into $currency, exactly once — the point where a catalog price first
     * becomes a real, stored charge. Everything downstream that reads the
     * resulting services.amount/invoices.total back (renewals, proration,
     * the dashboard) must never convert it again — those rows lock rate 1.0
     * via denominateColumns()/denominateFor() specifically because this is
     * the one place the real conversion already happened.
     *
     * @param array{lines: array<int, array<string, mixed>>, subtotal: float, setupFees: float, domainTotal: float, discount: float, promoCode: ?string, promotionId: ?int, promoError: ?string, total: float} $priced
     * @param array<string, mixed> $currency
     * @return array{lines: array<int, array<string, mixed>>, subtotal: float, setupFees: float, domainTotal: float, discount: float, promoCode: ?string, promotionId: ?int, promoError: ?string, total: float}
     */
    private function convertPriced(array $priced, array $currency): array
    {
        $rate = $this->currency->rateFor($currency);
        $convert = fn (float $amount): float => $this->currency->convert($amount, $rate);

        foreach ($priced['lines'] as &$line) {
            $line['unit_price'] = $convert((float) $line['unit_price']);
            $line['setup_fee'] = $convert((float) $line['setup_fee']);
            $line['options_total'] = $convert((float) $line['options_total']);
            $line['line_total'] = $convert((float) $line['line_total']);
            $line['domain_price'] = $convert((float) ($line['domain_price'] ?? 0.0));

            foreach ($line['options'] as &$option) {
                $option['price'] = $convert((float) $option['price']);
            }
            unset($option);
        }
        unset($line);

        $priced['subtotal'] = $convert((float) $priced['subtotal']);
        $priced['setupFees'] = $convert((float) $priced['setupFees']);
        $priced['domainTotal'] = $convert((float) ($priced['domainTotal'] ?? 0.0));
        $priced['discount'] = $convert((float) $priced['discount']);
        $priced['total'] = $convert((float) $priced['total']);

        return $priced;
    }

    /**
     * @return array{success: bool, orderId?: int, invoiceId?: int, error?: string}
     */
    public function placeOrder(int $clientId): array
    {
        $client = $this->clients->find($clientId);
        $effectiveCurrency = $this->currency->resolveEffective($client, $this->currencySelection->get());

        $result = $this->executeOrder($clientId, $client, $this->cartService->priced(), $effectiveCurrency);

        if ($result['success']) {
            $this->cart->clear();
        }

        return $result;
    }

    /**
     * Admin-initiated order for a client, from an explicit item list rather
     * than the session cart — there is no "browsing session" for an admin
     * acting on someone else's behalf, so currency resolves purely from the
     * target client's own saved preference, not any in-session override.
     * See AdminOrderController.
     *
     * When $existing is true the order records a service/domain that already
     * exists (e.g. migrated from another system): it lands as `active`, no
     * invoice is raised, and the prices passed in on the items are still
     * written to the order/service/domain rows so the admin order page and
     * the client's records show what it is worth.
     *
     * @param array<int, array<string, mixed>> $items same shape Cart::add() produces
     * @return array{success: bool, orderId?: int, invoiceId?: int, error?: string}
     */
    public function placeOrderForClient(int $clientId, array $items, ?string $promoCode = null, bool $existing = false): array
    {
        $client = $this->clients->find($clientId);
        $effectiveCurrency = $this->currency->resolveForClient($client);

        return $this->executeOrder($clientId, $client, $this->cartService->priceItems($items, $promoCode), $effectiveCurrency, $existing);
    }

    /**
     * @param array<string, mixed>|null $client
     * @param array<string, mixed> $priced
     * @param array<string, mixed> $effectiveCurrency
     * @return array{success: bool, orderId?: int, invoiceId?: int, error?: string, serviceIds?: array<int, int>}
     */
    private function executeOrder(int $clientId, ?array $client, array $priced, array $effectiveCurrency, bool $existing = false): array
    {
        if ($priced['lines'] === []) {
            return ['success' => false, 'error' => 'Your cart is empty.'];
        }

        // An existing-service/domain order records what the client already
        // has — no stock is consumed, so stock level is irrelevant (and must
        // not block recording a product that happens to be sold out).
        if (!$existing) {
            foreach ($priced['lines'] as $line) {
                if (!$line['in_stock']) {
                    return ['success' => false, 'error' => "\"{$line['product_name']}\" is out of stock."];
                }
            }
        }

        // CartService::priced()/priceItems() is always base-currency — the
        // shopping cart page converts it for display via its own
        // $money/format() closure, but nothing before this converted the
        // amounts that actually get stored. Converting here, once, is what
        // makes the order/invoice match what the client saw while shopping
        // instead of charging the raw base-currency figure under the
        // client's currency symbol.
        $priced = $this->convertPriced($priced, $effectiveCurrency);

        $tax = $this->tax->calculate($client ?? [], $priced['total']);
        $currencyLock = $this->currency->denominateColumns($effectiveCurrency);

        try {
            $result = $this->db->transaction(function () use ($priced, $clientId, $tax, $currencyLock, $existing) {
                return $this->buildOrder($priced, $clientId, $tax, $currencyLock, $existing);
            });
        } catch (OutOfStockException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        [$orderId, $invoiceId, $serviceIds] = $result;

        // Auto-provision products and domains configured with autosetup === 'order'
        $hasPendingApproval = false;
        $container = \CodeVault\Support\App::container();
        $provisioning = $container->make(\CodeVault\Provisioning\ProvisioningService::class);
        $domainService = $container->make(\CodeVault\Domains\DomainService::class);

        foreach ($serviceIds as $sId) {
            $s = $this->services->find($sId);
            if ($s === null || $s['status'] !== 'pending') {
                continue;
            }

            $prod = $this->products->find((int) $s['product_id']);
            $autosetup = $prod['autosetup'] ?? 'payment';

            if ($autosetup === 'order') {
                $provisioning->provision((int) $s['id']);
            } elseif (in_array($autosetup, ['on_accept', 'off'], true)) {
                $hasPendingApproval = true;
            }
        }

        $domainRepo = $container->make(\CodeVault\Domains\DomainRepository::class);
        $domainPricingRepo = $container->make(\CodeVault\Domains\DomainPricingRepository::class);
        foreach ($domainRepo->forOrder($orderId) as $d) {
            if ($d['status'] !== 'pending') {
                continue;
            }

            $tldPricing = $domainPricingRepo->findByTld((string) $d['tld']);
            // Default to 'payment' if not set
            $autosetup = $tldPricing['autosetup_registration'] ?? 'payment';

            if ($autosetup === 'order') {
                $domainService->register((int) $d['id']);
            } elseif (in_array($autosetup, ['on_accept', 'off'], true)) {
                $hasPendingApproval = true;
            }
        }

        if ($hasPendingApproval) {
            $this->notifyAdminsOfPendingApproval($orderId, $client, $priced['total']);
        }

        $this->hooks->fire(HookPoints::ORDER_PLACED, ['orderId' => $orderId, 'clientId' => $clientId]);

        // An existing-service/domain order raises no invoice, so there is
        // nothing for the invoice hook to announce.
        if ($invoiceId > 0) {
            $this->hooks->fire(HookPoints::INVOICE_CREATED, ['invoiceId' => $invoiceId, 'clientId' => $clientId]);
        }

        return ['success' => true, 'orderId' => $orderId, 'invoiceId' => $invoiceId, 'serviceIds' => $serviceIds];
    }

    private function notifyAdminsOfPendingApproval(int $orderId, ?array $client, float $orderTotal): void
    {
        try {
            $container = \CodeVault\Support\App::container();
            /** @var \CodeVault\Mail\EmailDispatcher $dispatcher */
            $dispatcher = $container->make(\CodeVault\Mail\EmailDispatcher::class);
            /** @var \CodeVault\Auth\AdminRepository $adminRepo */
            $adminRepo = $container->make(\CodeVault\Auth\AdminRepository::class);

            $clientName = $client ? trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')) : 'Client';
            $clientEmail = $client['email'] ?? '';

            $admins = $adminRepo->all();
            foreach ($admins as $admin) {
                if (!empty($admin['email'])) {
                    $dispatcher->sendTemplate('admin_pending_order_approval', (string) $admin['email'], [
                        'order_id' => (string) $orderId,
                        'client_name' => $clientName,
                        'client_email' => $clientEmail,
                        'order_total' => number_format($orderTotal, 2),
                        'company_name' => brand_name(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Ignore email errors to not block checkout
        }
    }

    /**
     * @param array{lines: array<int, array<string, mixed>>, subtotal: float, setupFees: float, discount: float, promoCode: ?string, promotionId: ?int, total: float} $priced
     * @param array{rate: float, name: string, amount: float} $tax
     * @param array{currency_id: int|null, currency_rate: float} $currencyLock
     * @return array{0: int, 1: int, 2: array<int, int>}
     */
    private function buildOrder(array $priced, int $clientId, array $tax, array $currencyLock, bool $existing = false): array
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $today = substr($now, 0, 10);
        
        $discount = (float) ($priced['discount'] ?? 0.0);
        $promoCode = $priced['promoCode'] ?? null;
        $promotionId = $priced['promotionId'] ?? null;

        $domainInvoiceItems = [];
        $orderLinesWithDomains = [];

        // Pre-parse domains to build the invoice line description. The price
        // itself comes from $line['domain_price'] — already resolved (and, by
        // the time this runs, already currency-converted) by
        // CartService::priced() — rather than a second, independent
        // domain_pricing lookup here that used to disagree with it.
        foreach ($priced['lines'] as $lineIndex => $line) {
            $domainName = null;
            $domainOptions = $line['domain_options'] ?? null;
            if ($domainOptions !== null && !empty($domainOptions['name'])) {
                $domainName = $domainOptions['name'];

                if (in_array($domainOptions['option'], ['register', 'transfer'], true)) {
                    $price = (float) ($line['domain_price'] ?? 0.0);

                    $domainInvoiceItems[] = [
                        'description' => "Domain " . ($domainOptions['option'] === 'register' ? 'Registration' : 'Transfer') . ": {$domainName} (1 Year)",
                        'amount' => $price
                    ];
                }
            }
            $orderLinesWithDomains[$lineIndex] = $domainName;
        }

        $invoiceTotal = $priced['total'] + $tax['amount'];
        // An existing service/domain order never has an invoice to add tax to,
        // so its order total is exactly what the items are worth.
        $orderTotal = $existing ? $priced['total'] : $invoiceTotal;

        $orderId = (int) $this->db->insert(
            'INSERT INTO orders (client_id, status, total, discount_amount, promotion_code, currency_id, currency_rate, no_invoice, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $existing ? 'active' : 'pending', $orderTotal, $discount, $promoCode, $currencyLock['currency_id'], $currencyLock['currency_rate'], $existing ? 1 : 0, $now, $now]
        );

        // Insert domains into DB now that we have orderId
        $domainPricingRepo = \CodeVault\Support\App::container()->make(\CodeVault\Domains\DomainPricingRepository::class);
        foreach ($priced['lines'] as $lineIndex => $line) {
            $domainName = $orderLinesWithDomains[$lineIndex];
            $domainOptions = $line['domain_options'] ?? null;
            // A "register"/"transfer" domain is new to us and lands pending.
            // In existing-order mode a "existing" domain is recorded as
            // already-live instead — it must appear in the client's domain
            // list, but nothing is registered or transferred.
            $isNewDomain = $domainOptions !== null
                && !empty($domainOptions['name'])
                && in_array($domainOptions['option'], ['register', 'transfer'], true);
            $isExistingDomain = $existing
                && $domainOptions !== null
                && !empty($domainOptions['name'])
                && ($domainOptions['option'] ?? '') === 'existing';

            if ($isNewDomain || $isExistingDomain) {
                $tld = CartService::tldFromDomainName((string) $domainOptions['name']);
                $price = (float) ($line['domain_price'] ?? 0.0);

                $chosenNameservers = array_values(array_filter([
                    $domainOptions['ns1'] ?? '',
                    $domainOptions['ns2'] ?? '',
                    $domainOptions['ns3'] ?? '',
                    $domainOptions['ns4'] ?? '',
                    $domainOptions['ns5'] ?? '',
                    $domainOptions['ns6'] ?? '',
                ], static fn ($ns) => trim((string) $ns) !== ''));

                $nameservers = json_encode($chosenNameservers !== [] ? $chosenNameservers : $this->domainSettings->defaultNameservers());

                // The registrar is the one configured on the TLD's pricing
                // row, not a hardcoded dev module: hardcoding 'local' here
                // meant every self-service registration ran through the
                // disabled LocalRegistrarModule, which never contacted a real
                // registry and stamped fake "ns1.codevault.invalid"
                // nameservers on the domain instead of the configured default.
                $registrarSlug = (string) ($domainPricingRepo->findByTld($tld)['registrar_slug'] ?? 'local');

                $this->db->insert(
                    'INSERT INTO domains (client_id, order_id, domain_name, tld, registrar_slug, status, amount, nameservers, auto_renew, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $clientId,
                        $orderId,
                        $domainOptions['name'],
                        $tld,
                        $registrarSlug,
                        $isExistingDomain ? 'active' : 'pending',
                        $price,
                        $nameservers,
                        1,
                        $now,
                        $now
                    ]
                );
            }
        }

        $serviceIds = [];

        // A standalone domain rides on the $0 "Domain Registration" carrier
        // product (DomainService::carrierProductId()), so its line's
        // unit_price is 0 by design while the real charge lives in
        // domain_price. Record that price on the order item — otherwise the
        // admin order page shows a 0.00 line for a charged domain, and the
        // product revenue report silently omits every domain registration.
        $carrierProductId = (int) ($this->db->selectOne(
            "SELECT id FROM products WHERE name = 'Domain Registration' AND status = 'hidden' LIMIT 1"
        )['id'] ?? 0);

        foreach ($priced['lines'] as $lineIndex => $line) {
            $domainName = $orderLinesWithDomains[$lineIndex];
            
            $unitPrice = (float) $line['unit_price'];

            if ($domainName !== null && $carrierProductId > 0 && (int) $line['product_id'] === $carrierProductId) {
                $unitPrice = (float) ($line['domain_price'] ?? 0.0);
            }

            $this->db->insert(
                'INSERT INTO order_items (order_id, product_id, product_name, billing_cycle, quantity, unit_price, setup_fee, configurable_options, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $orderId,
                    $line['product_id'],
                    $line['product_name'],
                    $line['billing_cycle'],
                    $line['quantity'],
                    $unitPrice,
                    $line['setup_fee'],
                    json_encode($line['options']),
                    $now,
                ]
            );

            // An existing order records what the client already has — it must
            // not consume stock or provision anything new, and its services are
            // created active (already live) rather than pending.
            if (!$existing) {
                $product = $this->products->find((int) $line['product_id']);
                $hasLimitedStock = $product !== null && $product['stock_quantity'] !== null;
                $decremented = $this->products->decrementStock($line['product_id']);

                if ($hasLimitedStock && !$decremented) {
                    throw new OutOfStockException("\"{$line['product_name']}\" just sold out — please remove it from your cart.");
                }
            }

            if ($line['billing_cycle'] !== BillingCycle::ONE_TIME) {
                $recurringAmount = ($line['unit_price'] + $line['options_total']) * $line['quantity'];
                $hostname = $line['server_options']['hostname'] ?? null;
                $password = $line['server_options']['root_password'] ?? null;

                $serviceId = $this->services->create([
                    'client_id' => $clientId,
                    'order_id' => $orderId,
                    'product_id' => $line['product_id'],
                    'product_name' => $line['product_name'],
                    'billing_cycle' => $line['billing_cycle'],
                    'amount' => $recurringAmount,
                    'domain' => $domainName,
                    'hostname' => $hostname,
                    'password' => $password,
                    'status' => $existing ? 'active' : 'pending',
                    'next_due_date' => ServiceRepository::nextCycleDate($today, $line['billing_cycle']),
                ]);
                $serviceIds[] = $serviceId;

                $customFields = $line['custom_fields'] ?? [];
                foreach ($customFields as $fieldId => $val) {
                    $this->db->insert(
                        'INSERT INTO service_custom_field_values (service_id, custom_field_id, value) VALUES (?, ?, ?)',
                        [$serviceId, (int) $fieldId, $val]
                    );
                }
            }
        }

        // Existing-service/domain orders never generate an invoice — they are
        // records of things the client already has, not new charges.
        if ($existing) {
            return [$orderId, 0, $serviceIds];
        }

        $settingsRepo = \CodeVault\Support\App::container()->make(\CodeVault\Settings\SettingsRepository::class);
        $dueDays = max(0, (int) $settingsRepo->get('billing.new_order_due_days', '7'));
        $dueDate = (new DateTimeImmutable())->modify("+{$dueDays} days")->format('Y-m-d');

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, discount_amount, promotion_code, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $orderId, 'unpaid', $priced['total'], $tax['amount'], $discount, $promoCode, $invoiceTotal, $currencyLock['currency_id'], $currencyLock['currency_rate'], $dueDate, $now, $now]
        );

        foreach ($priced['lines'] as $line) {
            $identifier = ServiceRepository::invoiceIdentifierSuffix(
                $line['domain_options']['name'] ?? null,
                $line['server_options']['hostname'] ?? null
            );
            $description = "{$line['product_name']} ({$line['cycle_label']}) x{$line['quantity']}{$identifier}";
            $this->db->insert(
                'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                [$invoiceId, $description, $line['line_total']]
            );
        }

        // Insert domain invoice items
        foreach ($domainInvoiceItems as $div) {
            $this->db->insert(
                'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                [$invoiceId, $div['description'], $div['amount']]
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
