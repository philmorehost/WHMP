<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Catalog\BillingCycle;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Cart\CheckoutService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Domains\DomainService;
use CodeVault\Domains\DomainSettings;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * "Add New Order" for an admin acting on a client's behalf — a phone order,
 * a sale negotiated outside the storefront, a domain the client asked
 * support to register for them. Reuses CheckoutService::placeOrderForClient()
 * (see CartService::priceItems()) rather than re-deriving pricing/currency
 * logic here, so this can never drift from what a client placing the same
 * order through the storefront would actually be charged.
 *
 * The order lands exactly where a storefront order would — 'pending' in the
 * existing Orders queue — so acceptance/provisioning goes through
 * OrderController::accept() unchanged. There is no separate "admin order"
 * status or lifecycle.
 */
final class AdminOrderController
{
    private const DOMAIN_CARRIER_CYCLE = 'annually';

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ClientRepository $clients,
        private readonly ProductRepository $products,
        private readonly ProductPricingRepository $pricing,
        private readonly DomainPricingRepository $domainPricing,
        private readonly DomainService $domainService,
        private readonly DomainSettings $domainSettings,
        private readonly CheckoutService $checkoutService,
        private readonly ActivityLogger $activity,
        private readonly CurrencyService $currency,
        private readonly EmailDispatcher $mail,
        private readonly InvoiceRepository $invoices,
        private readonly Config $config
    ) {
    }

    public function createForm(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render([
            'clients' => $this->clients->all(),
            // false = active only — excludes the hidden "Domain Registration"
            // carrier product (see DomainService::carrierProductId()), which
            // would otherwise show up as a selectable, meaningless line item.
            'products' => $this->products->all(false),
            'cycles' => BillingCycle::labels(),
            'domainTlds' => array_column($this->domainPricing->all(), 'tld'),
            'defaultNameservers' => $this->domainSettings->defaultNameservers(),
            'error' => null,
            'old' => ['client_id' => '', 'lines' => []],
        ]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) $request->input('client_id', 0);
        $client = $clientId > 0 ? $this->clients->find($clientId) : null;

        if ($client === null) {
            return $this->createError($request, 'Select a client for this order.');
        }

        [$items, $error] = $this->buildItems($request);

        if ($error !== null) {
            return $this->createError($request, $error);
        }

        if ($items === []) {
            return $this->createError($request, 'Add at least one product, or a domain registration.');
        }

        $result = $this->checkoutService->placeOrderForClient($clientId, $items);

        if (!$result['success']) {
            return $this->createError($request, (string) ($result['error'] ?? 'Could not create the order.'));
        }

        $orderId = (int) $result['orderId'];
        $invoiceId = (int) $result['invoiceId'];
        $adminId = (int) $this->guard->currentAdmin()['id'];

        $this->activity->log(
            'admin',
            $adminId,
            'order.created_by_admin',
            'order',
            $orderId,
            "Created order #{$orderId} (invoice #{$invoiceId}) on behalf of client #{$clientId}",
            $request->ip()
        );

        $this->sendInvoiceEmail($client, $orderId, $invoiceId);

        return Response::redirect("/admin/orders/{$orderId}");
    }

    /**
     * Reads the repeatable product rows plus the single optional domain
     * section and turns them into the exact item shape
     * CheckoutService::placeOrderForClient() (via Cart::add()'s own shape)
     * expects. A 'register' domain is re-verified against the registrar
     * here — the same safety check CheckoutController::addToCart() and
     * DomainRegistrationController::addToCart() already apply — so an admin
     * can't accidentally queue an unavailable domain any more than a client
     * can.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: string|null}
     */
    private function buildItems(Request $request): array
    {
        $items = [];

        $productIds = (array) $request->input('product_id', []);
        $cycles = (array) $request->input('billing_cycle', []);
        $quantities = (array) $request->input('quantity', []);

        foreach ($productIds as $index => $rawProductId) {
            $productId = (int) $rawProductId;

            if ($productId <= 0) {
                continue;
            }

            $product = $this->products->find($productId);

            if ($product === null) {
                return [[], "Product #{$productId} no longer exists."];
            }

            $cycle = (string) ($cycles[$index] ?? '');
            $priceRow = $this->pricing->find($productId, $cycle);

            if ($priceRow === null && ($product['pay_type'] ?? 'paid') !== 'free') {
                return [[], "\"{$product['name']}\" has no pricing set for the \"{$cycle}\" billing cycle."];
            }

            $quantity = max(1, (int) ($quantities[$index] ?? 1));

            $items[] = [
                'product_id' => $productId,
                'billing_cycle' => $cycle,
                'quantity' => $quantity,
                'options' => [],
                'domain_options' => null,
                'server_options' => null,
                'custom_fields' => null,
            ];
        }

        $domainOption = (string) $request->input('domain_option', '');

        if ($domainOption !== '') {
            $domainName = strtolower(trim((string) $request->input('domain_name', '')));
            $domainTld = strtolower(trim((string) $request->input('domain_tld', '')));

            if ($domainOption === 'existing') {
                $fullDomain = $domainName;
            } else {
                // Same defensive rule as CheckoutController::addToCart() —
                // strip anything from the first dot onward before appending
                // the selected TLD.
                $dotPos = strpos($domainName, '.');
                $nameOnly = $dotPos !== false ? substr($domainName, 0, $dotPos) : $domainName;
                $fullDomain = $nameOnly . $domainTld;
            }

            if ($fullDomain === '' || !str_contains($fullDomain, '.')) {
                return [[], 'Enter a valid domain name.'];
            }

            $tld = '.' . substr($fullDomain, strpos($fullDomain, '.') + 1);
            $pricingRow = $this->domainPricing->findByTld($tld);

            if ($pricingRow === null) {
                return [[], "\"{$tld}\" isn't offered here."];
            }

            if ($domainOption === 'register') {
                $check = $this->domainService->checkAvailability($fullDomain, (string) $pricingRow['registrar_slug']);

                if (!$check['success'] || !$check['available']) {
                    return [[], "\"{$fullDomain}\" is not available to register."];
                }
            }

            $useDefaultNs = (string) $request->input('nameserver_choice', 'default') !== 'custom';
            $nameservers = $useDefaultNs ? $this->domainSettings->defaultNameservers() : $this->customNameserversFrom($request);

            $items[] = [
                'product_id' => $this->domainService->carrierProductId(),
                'billing_cycle' => self::DOMAIN_CARRIER_CYCLE,
                'quantity' => 1,
                'options' => [],
                'domain_options' => [
                    'name' => $fullDomain,
                    'option' => $domainOption,
                    'ns1' => $nameservers[0] ?? '',
                    'ns2' => $nameservers[1] ?? '',
                    'ns3' => $nameservers[2] ?? '',
                    'ns4' => $nameservers[3] ?? '',
                    'ns5' => $nameservers[4] ?? '',
                    'ns6' => $nameservers[5] ?? '',
                ],
                'server_options' => null,
                'custom_fields' => null,
            ];
        }

        return [$items, null];
    }

    /** @return array<int, string> */
    private function customNameserversFrom(Request $request): array
    {
        $nameservers = [];

        foreach (['ns1', 'ns2', 'ns3', 'ns4', 'ns5', 'ns6'] as $field) {
            $value = trim((string) $request->input($field, ''));

            if ($value !== '') {
                $nameservers[] = $value;
            }
        }

        return $nameservers;
    }

    /** @param array<string, mixed> $client */
    private function sendInvoiceEmail(array $client, int $orderId, int $invoiceId): void
    {
        $email = trim((string) ($client['email'] ?? ''));

        if ($email === '') {
            return;
        }

        try {
            $invoice = $this->invoices->find($invoiceId);

            if ($invoice === null) {
                return;
            }

            $clientCurrency = $this->currency->resolveForClient($client);
            $formattedTotal = $this->currency->formatDocument(
                (float) $invoice['total'],
                $invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null,
                (float) ($invoice['currency_rate'] ?? 1.0),
                $clientCurrency
            );

            $this->mail->sendTemplate('order_invoice_created', $email, [
                'first_name' => (string) ($client['first_name'] ?? ''),
                'order_id' => (string) $orderId,
                'invoice_id' => (string) $invoiceId,
                'invoice_total' => $formattedTotal,
                'due_date' => (string) ($invoice['due_date'] ?? ''),
                'invoice_url' => rtrim((string) $this->config->env('APP_URL', ''), '/') . "/client/invoices/{$invoiceId}",
                'company_name' => brand_name(),
            ], (int) $client['id']);
        } catch (\Throwable) {
            // A failed notification email must never undo an already-created
            // order — the admin can still see/share the invoice directly.
        }
    }

    private function createError(Request $request, string $message): Response
    {
        return $this->render([
            'clients' => $this->clients->all(),
            // false = active only — excludes the hidden "Domain Registration"
            // carrier product (see DomainService::carrierProductId()), which
            // would otherwise show up as a selectable, meaningless line item.
            'products' => $this->products->all(false),
            'cycles' => BillingCycle::labels(),
            'domainTlds' => array_column($this->domainPricing->all(), 'tld'),
            'defaultNameservers' => $this->domainSettings->defaultNameservers(),
            'error' => $message,
            'old' => ['client_id' => (string) $request->input('client_id', '')],
        ]);
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::ORDERS_MANAGE)) {
            return Response::html('403 Forbidden — missing orders.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(array $data): Response
    {
        $content = $this->view->render('billing.order-create', $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Create Order',
            'content' => $content,
        ]));
    }
}
