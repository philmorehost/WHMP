<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Billing\CurrencySelection;
use CodeVault\Billing\CurrencyService;
use CodeVault\Cart\Cart;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Domains\DomainRegistrationController;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\View;
use Throwable;

/**
 * The public side of the Free Reseller programme: the landing page that explains
 * it, the application form, and the "you're in" page after it.
 *
 * Applying does NOT invent a second way to become a reseller. It walks the same
 * steps the Reseller area does — open the store (ResellerStoreService), claim its
 * domain, hand the domain to the server sync — and, when the applicant wants a new
 * domain, puts it in the ordinary cart through the ordinary domain controller, so
 * availability, pricing and checkout are exactly what a domain order always is.
 *
 * MAIN HOST ONLY. On a reseller's store every route here is a 404: that site is
 * another business, and the platform recruiting resellers on it would break the
 * white-label promise (and poach the store's own customers).
 */
final class FreeResellerController
{
    private const WELCOME_SESSION_KEY = 'free_reseller_welcome';
    private const STORE_NAME_MAX = 80;

    public function __construct(
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly ClientAuthGuard $guard,
        private readonly FreeResellerProgramme $programme,
        private readonly ResellerStoreService $stores,
        private readonly ResellerStoreRepository $storeRows,
        private readonly ResellerStoreLocator $locator,
        private readonly ResellerDomainSync $domainSync,
        private readonly ResellerEligibility $eligibility,
        private readonly ResellerSettings $resellerSettings,
        private readonly ResellerLedgerService $ledger,
        private readonly SettingsRepository $settings,
        private readonly ActivityLogger $activity,
        private readonly CurrencyService $currency,
        private readonly CurrencySelection $currencySelection,
        private readonly CurrentReseller $tenant,
        private readonly Cart $cart,
        private readonly ?StorefrontCatalogue $catalogue = null,
        private readonly ?DomainVerifier $verifier = null
    ) {
    }

    // --- the landing page --------------------------------------------------

    public function landing(Request $request): Response
    {
        if ($this->tenant->get() !== null) {
            return $this->notFound();
        }

        $client = $this->guard->currentClient();
        $ownsStore = $client !== null && $this->stores->forClient((int) $client['id']) !== null;
        $currency = $this->displayCurrency($client);

        $categories = [];
        $tldCount = 0;
        $domainPrices = [];

        try {
            $categories = $this->catalogue?->categories() ?? [];
            $tldCount = $this->catalogue?->tldCount() ?? 0;
            $domainPrices = $this->catalogue?->domainPrices(6) ?? [];
        } catch (Throwable) {
            // A page that sells the programme must still render if the catalogue
            // query fails; it then simply shows no live prices.
        }

        $examplePrice = 10.0;

        foreach ($categories as $category) {
            if ((float) ($category['starting_price'] ?? 0) > 0) {
                $examplePrice = (float) $category['starting_price'];
                break;
            }
        }

        $content = $this->view->render('free-reseller.landing', [
            'enabled' => $this->programme->enabled(),
            // A closed programme shows visitors a "not open" notice, but a signed-in
            // admin still sees the real page (marked as a preview) to check it first.
            'adminPreview' => !$this->programme->enabled() && $this->adminSignedIn(),
            'headline' => $this->programme->headline(),
            'tagline' => $this->programme->tagline(),
            'demoLinks' => $this->programme->demoLinks(),
            'facts' => $this->facts(),
            'categories' => array_map(fn (array $c): array => $c + [
                'formatted_price' => (float) ($c['starting_price'] ?? 0) > 0 ? $this->currency->format((float) $c['starting_price'], $currency) : null,
                'icon' => StorefrontIcons::forCategory((string) $c['name']),
            ], $categories),
            'tldCount' => $tldCount,
            'domainPrices' => array_map(fn (array $d): array => $d + [
                'formatted_price' => $this->currency->format((float) $d['price'], $currency),
            ], $domainPrices),
            'example' => FreeResellerProgramme::earningsExample($examplePrice, $this->resellerSettings->serviceDiscount(), 30.0),
            'currencySymbol' => (string) ($currency['symbol'] ?? '$'),
            'currencyRate' => $this->currency->catalogRate($currency),
            'client' => $client,
            'ownsStore' => $ownsStore,
            'canApply' => $client === null || $this->eligibility->canResell($client),
        ]);

        return $this->page('Free Reseller Programme', $content, 'Get a free, fully branded hosting website. Resell VPS, dedicated servers, domains and hosting at your own prices — we handle the servers and support.');
    }

    // --- the application form ----------------------------------------------

    public function applyForm(Request $request): Response
    {
        if ($guard = $this->guardApplication()) {
            return $guard;
        }

        $client = $this->guard->currentClient();
        $draft = $this->session->get(FreeResellerProgramme::DRAFT_SESSION_KEY);
        $draft = is_array($draft) ? $draft : [];

        return $this->renderForm($client, $draft, [], $request->query('resume') === '1' && $draft !== [] && $client !== null);
    }

    public function apply(Request $request): Response
    {
        if ($guard = $this->guardApplication()) {
            return $guard;
        }

        $client = $this->guard->currentClient();

        $values = [
            'store_name' => trim((string) $request->input('store_name', '')),
            'slug' => trim((string) $request->input('slug', '')),
            'domain_mode' => (string) $request->input('domain_mode', 'register') === 'existing' ? 'existing' : 'register',
            'new_domain' => trim((string) $request->input('new_domain', '')),
            'existing_domain' => trim((string) $request->input('existing_domain', '')),
            'agree' => (string) $request->input('agree', '') === '1',
        ];

        [$errors, $clean] = $this->validate($values);

        if ($errors !== []) {
            return $this->renderForm($client, $values, $errors, false, 422);
        }

        // A guest has told us everything about the store; what is missing is WHO.
        // Their answers wait in the session while they register (with the usual email
        // code and security PIN) or sign in, and they come straight back here.
        if ($client === null) {
            $this->session->set(FreeResellerProgramme::DRAFT_SESSION_KEY, $values);

            return Response::redirect(
                (string) $request->input('account_action', 'register') === 'login'
                    ? '/client/login?free_reseller=1'
                    : '/client/register?free_reseller=1'
            );
        }

        $clientId = (int) $client['id'];

        // A new domain goes through the ordinary domain order: the domain controller
        // re-checks availability with the registrar, prices it and adds it to the
        // cart. Done BEFORE the store is opened, so "that domain is taken" leaves
        // nothing half-made behind.
        if ($clean['mode'] === 'register' && !$this->cartHasDomain($clean['domain'])) {
            $cartError = $this->addDomainToCart($clean['domain'], $request);

            if ($cartError !== null) {
                return $this->renderForm($client, $values, ['new_domain' => $cartError], false, 422);
            }
        }

        $opened = $this->stores->openForClient($clientId, $clean['store_name'], $clean['slug']);

        if (!$opened['success'] || !is_array($opened['store'] ?? null)) {
            return $this->renderForm($client, $values, ['slug' => (string) ($opened['error'] ?? 'Your store could not be created.')], false, 422);
        }

        $store = $opened['store'];
        $storeId = (int) $store['id'];
        $domainNote = null;

        $claim = $this->stores->claimDomain($storeId, $clean['domain']);

        if ($claim['success']) {
            try {
                $this->domainSync->syncStore($storeId);
            } catch (Throwable) {
                // The server sync runs again when the admin approves the domain; a
                // panel that is briefly unreachable must not fail the application.
            }
        } else {
            $domainNote = (string) $claim['error'];
        }

        $this->activity->log(
            'client',
            $clientId,
            'reseller.store.opened',
            'reseller',
            $storeId,
            'Joined the Free Reseller programme: opened store "' . (string) ($store['slug'] ?? '') . '" for ' . $clean['domain']
                . ($clean['mode'] === 'register' ? ' (new domain, added to cart)' : ' (existing domain)'),
            $request->ip()
        );

        $this->session->remove(FreeResellerProgramme::DRAFT_SESSION_KEY);
        $this->session->set(self::WELCOME_SESSION_KEY, [
            'domain' => $clean['domain'],
            'mode' => $clean['mode'],
            'domain_error' => $domainNote,
        ]);

        return Response::redirect('/free-reseller/welcome');
    }

    /**
     * Drop saved answers: "start again" for a guest, "not now" for someone who has
     * just signed in (who then goes to their dashboard, and is not sent back here
     * on every later sign-in).
     */
    public function discardDraft(Request $request): Response
    {
        $this->session->remove(FreeResellerProgramme::DRAFT_SESSION_KEY);

        return Response::redirect($this->guard->currentClient() !== null ? '/client/dashboard' : '/free-reseller/apply');
    }

    // --- after applying ----------------------------------------------------

    public function welcome(Request $request): Response
    {
        if ($this->tenant->get() !== null) {
            return $this->notFound();
        }

        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            return Response::redirect('/free-reseller/apply');
        }

        $welcome = $this->session->get(self::WELCOME_SESSION_KEY);
        $welcome = is_array($welcome) ? $welcome : [];
        $domain = (string) ($store['custom_domain'] ?? ($welcome['domain'] ?? ''));
        $token = (string) ($store['domain_verification_token'] ?? '');
        $verifier = $this->verifier ?? new DomainVerifier();

        $content = $this->view->render('free-reseller.welcome', [
            'client' => $client,
            'store' => $store,
            'domain' => $domain,
            'mode' => (string) ($welcome['mode'] ?? 'existing'),
            'domainError' => $welcome['domain_error'] ?? null,
            'domainInCart' => $domain !== '' && $this->cartHasDomain($domain),
            'recordName' => $domain !== '' ? $verifier->recordName($domain) : null,
            'recordValue' => $token !== '' ? $verifier->expectedValue($token) : null,
            'platformHost' => $this->locator->platformHost(),
            'platformUrl' => $this->stores->platformUrl($store),
            'domainStatus' => (string) ($store['domain_status'] ?? 'none'),
            'verified' => ($store['domain_verified_at'] ?? null) !== null,
            'facts' => $this->facts(),
        ]);

        return $this->page('Welcome to the Free Reseller Programme', $content);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * The programme rules exactly as the admin has set them, for the copy.
     *
     * @return array<string, mixed>
     */
    private function facts(): array
    {
        $billingPeriod = (string) $this->settings->get('reseller.billing_period', ResellerCostService::CADENCE_MONTHLY);

        return [
            'holdingDays' => $this->ledger->holdingDays(),
            'payoutMinimum' => number_format($this->ledger->payoutMinimum(), 2),
            'payoutCurrency' => $this->ledger->payoutMinimumCurrency(),
            'billingPeriod' => $billingPeriod === ResellerCostService::CADENCE_WEEKLY ? 'week' : 'month',
            'serviceDiscount' => $this->resellerSettings->serviceDiscount(),
            'domainDiscount' => $this->resellerSettings->domainDiscount(),
            'maxMarkup' => ResellerRetailPricing::MAX_MARKUP,
            'storeDomain' => $this->locator->storeDomain(),
            'platformHost' => $this->locator->platformHost(),
            'maxTier' => ResellerEligibility::MAX_TIER,
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array{0: array<string, string>, 1: array{store_name: string, slug: string, mode: string, domain: string}}
     */
    private function validate(array $values): array
    {
        $errors = [];
        $storeName = (string) $values['store_name'];

        if ($storeName === '') {
            $errors['store_name'] = 'Give your business a name — it is what your customers will see.';
        } elseif ((function_exists('mb_strlen') ? mb_strlen($storeName) : strlen($storeName)) > self::STORE_NAME_MAX) {
            $errors['store_name'] = 'Keep the name under ' . self::STORE_NAME_MAX . ' characters.';
        }

        $slug = ResellerStoreRepository::normaliseSlug((string) ($values['slug'] !== '' ? $values['slug'] : $storeName));

        if ($slug === null) {
            $errors['slug'] ??= 'Choose a store ID of at least 3 letters or numbers (hyphens allowed).';
        } elseif ($this->storeRows->slugTaken($slug)) {
            $errors['slug'] = 'The store ID "' . $slug . '" is already taken — try another.';
        }

        $mode = (string) $values['domain_mode'];
        $rawDomain = $mode === 'existing' ? (string) $values['existing_domain'] : (string) $values['new_domain'];
        $field = $mode === 'existing' ? 'existing_domain' : 'new_domain';
        $domain = ResellerCredentialService::normaliseDomain($rawDomain);

        if ($rawDomain === '') {
            $errors[$field] = $mode === 'existing'
                ? 'Enter the domain you already own, like mybrand.com.'
                : 'Search for a domain and pick one that is available.';
        } elseif ($domain === null) {
            $errors[$field] = 'That does not look like a domain name. Use something like mybrand.com.';
        } elseif ($this->storeRows->domainTaken($domain)) {
            $errors[$field] = $domain . ' is already used by another reseller store.';
        } else {
            $platformHost = $this->locator->platformHost();
            $storeDomain = $this->locator->storeDomain();

            if ($domain === $platformHost || str_ends_with($domain, '.' . $platformHost)
                || ($storeDomain !== null && ($domain === $storeDomain || str_ends_with($domain, '.' . $storeDomain)))) {
                $errors[$field] = 'That domain belongs to the platform. Use a domain of your own.';
            }
        }

        if (!$values['agree']) {
            $errors['agree'] = 'Please confirm you agree to the reseller terms.';
        }

        return [$errors, ['store_name' => $storeName, 'slug' => (string) $slug, 'mode' => $mode, 'domain' => (string) $domain]];
    }

    /**
     * Put a domain registration in the cart through the domain controller itself.
     * Returns null on success, otherwise the reason it could not be added.
     */
    private function addDomainToCart(string $domain, Request $request): ?string
    {
        try {
            /** @var DomainRegistrationController $controller */
            $controller = App::container()->make(DomainRegistrationController::class);
            $response = $controller->addToCart(new Request(
                [],
                ['domain' => $domain, 'nameserver_choice' => 'default'],
                ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/domains/register/add-to-cart', 'REMOTE_ADDR' => $request->ip()],
                []
            ));
        } catch (Throwable) {
            return 'We could not check that domain right now. Please try again in a moment, or choose "I already own a domain".';
        }

        $location = (string) ($response->headers()['Location'] ?? '');

        if ($location === '/cart') {
            return null;
        }

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $message = trim((string) ($query['error'] ?? ''));

        return $message !== '' ? $message : 'That domain cannot be registered. Try another name.';
    }

    private function cartHasDomain(string $domain): bool
    {
        foreach ($this->cart->items() as $item) {
            $options = $item['domain_options'] ?? null;

            if (is_array($options) && strtolower((string) ($options['name'] ?? '')) === strtolower($domain)
                && (string) ($options['option'] ?? 'register') === 'register') {
                return true;
            }
        }

        return false;
    }

    /** What stops an application before the form is even shown. */
    private function guardApplication(): ?Response
    {
        if ($this->tenant->get() !== null) {
            return $this->notFound();
        }

        if (!$this->programme->enabled()) {
            return Response::redirect('/free-reseller');
        }

        $client = $this->guard->currentClient();

        if ($client === null) {
            return null;
        }

        if ($this->stores->forClient((int) $client['id']) !== null) {
            $this->session->remove(FreeResellerProgramme::DRAFT_SESSION_KEY);
            $this->session->flash('reseller_notice', 'You already have a reseller store — this is where you manage it.');

            return Response::redirect('/client/reseller/store');
        }

        if (!$this->eligibility->canResell($client)) {
            return Response::redirect('/free-reseller');
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $client
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderForm(?array $client, array $values, array $errors, bool $resume, int $status = 200): Response
    {
        $currency = $this->displayCurrency($client);
        $domainPrices = [];

        try {
            $domainPrices = $this->catalogue?->domainPrices(6) ?? [];
        } catch (Throwable) {
        }

        $suggestionSource = trim((string) ($client['company_name'] ?? ''));

        if ($suggestionSource === '' && $client !== null) {
            $suggestionSource = trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));
        }

        $content = $this->view->render('free-reseller.apply', [
            'client' => $client,
            'values' => $values + [
                'store_name' => '',
                'slug' => '',
                'domain_mode' => 'register',
                'new_domain' => '',
                'existing_domain' => '',
                'agree' => false,
            ],
            'errors' => $errors,
            'resume' => $resume,
            'hasDraft' => is_array($this->session->get(FreeResellerProgramme::DRAFT_SESSION_KEY)),
            'slugSuggestion' => $suggestionSource !== '' ? $this->stores->suggestSlug($suggestionSource) : null,
            'storeDomain' => $this->locator->storeDomain(),
            'platformHost' => $this->locator->platformHost(),
            'domainPrices' => array_map(fn (array $d): array => $d + [
                'formatted_price' => $this->currency->format((float) $d['price'], $currency),
            ], $domainPrices),
            'facts' => $this->facts(),
        ]);

        return $this->page('Apply — Free Reseller Programme', $content, null, $status);
    }

    /**
     * @param array<string, mixed>|null $client
     * @return array<string, mixed>
     */
    private function displayCurrency(?array $client): array
    {
        try {
            return $this->currency->resolveEffective($client, $this->currencySelection->get());
        } catch (Throwable) {
            return ['symbol' => '$', 'code' => 'USD', 'exchange_rate' => 1];
        }
    }

    private function page(string $title, string $content, ?string $description = null, int $status = 200): Response
    {
        return Response::html($this->view->render('layouts.client', [
            'title' => $title,
            'content' => $content,
            'metaDescription' => $description,
        ]), $status);
    }

    private function adminSignedIn(): bool
    {
        try {
            return App::container()->make(\CodeVault\Auth\AuthGuard::class)->check();
        } catch (Throwable) {
            return false;
        }
    }

    private function notFound(): Response
    {
        return Response::html('<h1>404 Not Found</h1>', 404);
    }
}
