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
        $this->hooks->fire(HookPoints::INVOICE_CREATED, ['invoiceId' => $invoiceId, 'clientId' => $clientId]);

        $this->cart->clear();

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
                        'company_name' => 'CodeVault',
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
    private function buildOrder(array $priced, int $clientId, array $tax, array $currencyLock): array
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $today = substr($now, 0, 10);
        
        $discount = (float) ($priced['discount'] ?? 0.0);
        $promoCode = $priced['promoCode'] ?? null;
        $promotionId = $priced['promotionId'] ?? null;

        $domainTotalAdditions = 0.0;
        $domainInvoiceItems = [];
        $orderLinesWithDomains = [];

        // Pre-parse domains to calculate total addition
        foreach ($priced['lines'] as $lineIndex => $line) {
            $domainName = null;
            $domainOptions = $line['domain_options'] ?? null;
            if ($domainOptions !== null && !empty($domainOptions['name'])) {
                $domainName = $domainOptions['name'];
                
                if (in_array($domainOptions['option'], ['register', 'transfer'], true)) {
                    $parts = explode('.', $domainName);
                    $tld = '.' . end($parts);
                    
                    $priceRow = $this->db->selectOne("SELECT register_price, transfer_price FROM domain_pricing WHERE tld = ? LIMIT 1", [$tld]);
                    $price = 0.00;
                    if ($priceRow !== null) {
                        $price = $domainOptions['option'] === 'register' ? (float) $priceRow['register_price'] : (float) $priceRow['transfer_price'];
                    }
                    
                    $domainTotalAdditions += $price;
                    $domainInvoiceItems[] = [
                        'description' => "Domain " . ($domainOptions['option'] === 'register' ? 'Registration' : 'Transfer') . ": {$domainName} (1 Year)",
                        'amount' => $price
                    ];
                }
            }
            $orderLinesWithDomains[$lineIndex] = $domainName;
        }

        $invoiceTotal = $priced['total'] + $tax['amount'];

        $orderId = (int) $this->db->insert(
            'INSERT INTO orders (client_id, status, total, discount_amount, promotion_code, currency_id, currency_rate, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, 'pending', $invoiceTotal, $discount, $promoCode, $currencyLock['currency_id'], $currencyLock['currency_rate'], $now, $now]
        );

        // Insert domains into DB now that we have orderId
        foreach ($priced['lines'] as $lineIndex => $line) {
            $domainName = $orderLinesWithDomains[$lineIndex];
            $domainOptions = $line['domain_options'] ?? null;
            if ($domainOptions !== null && !empty($domainOptions['name']) && in_array($domainOptions['option'], ['register', 'transfer'], true)) {
                $parts = explode('.', $domainOptions['name']);
                $tld = '.' . end($parts);
                
                $priceRow = $this->db->selectOne("SELECT register_price, transfer_price FROM domain_pricing WHERE tld = ? LIMIT 1", [$tld]);
                $price = 0.00;
                if ($priceRow !== null) {
                    $price = $domainOptions['option'] === 'register' ? (float) $priceRow['register_price'] : (float) $priceRow['transfer_price'];
                }
                
                $chosenNameservers = array_values(array_filter([
                    $domainOptions['ns1'] ?? '',
                    $domainOptions['ns2'] ?? '',
                    $domainOptions['ns3'] ?? '',
                    $domainOptions['ns4'] ?? '',
                    $domainOptions['ns5'] ?? '',
                    $domainOptions['ns6'] ?? '',
                ], static fn ($ns) => trim((string) $ns) !== ''));

                $nameservers = json_encode($chosenNameservers !== [] ? $chosenNameservers : $this->domainSettings->defaultNameservers());

                $this->db->insert(
                    'INSERT INTO domains (client_id, order_id, domain_name, tld, registrar_slug, status, amount, nameservers, auto_renew, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $clientId,
                        $orderId,
                        $domainOptions['name'],
                        $tld,
                        'local',
                        'pending',
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

        foreach ($priced['lines'] as $lineIndex => $line) {
            $domainName = $orderLinesWithDomains[$lineIndex];
            
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

            $product = $this->products->find((int) $line['product_id']);
            $hasLimitedStock = $product !== null && $product['stock_quantity'] !== null;
            $decremented = $this->products->decrementStock($line['product_id']);

            if ($hasLimitedStock && !$decremented) {
                throw new OutOfStockException("\"{$line['product_name']}\" just sold out — please remove it from your cart.");
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
                    'status' => 'pending',
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

        $settingsRepo = \CodeVault\Support\App::container()->make(\CodeVault\Settings\SettingsRepository::class);
        $dueDays = max(0, (int) $settingsRepo->get('billing.new_order_due_days', '7'));
        $dueDate = (new DateTimeImmutable())->modify("+{$dueDays} days")->format('Y-m-d');

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, discount_amount, promotion_code, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $orderId, 'unpaid', $priced['total'], $tax['amount'], $discount, $promoCode, $invoiceTotal, $currencyLock['currency_id'], $currencyLock['currency_rate'], $dueDate, $now, $now]
        );

        foreach ($priced['lines'] as $line) {
            $description = "{$line['product_name']} ({$line['cycle_label']}) x{$line['quantity']}";
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
