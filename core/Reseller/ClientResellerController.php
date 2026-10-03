<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\View;

/**
 * The client-facing Reseller Area.
 *
 * A client becomes a reseller in two steps, and the order matters:
 *
 *  1. Request a key. The key is created with active = 0 — it authenticates
 *     nothing at all yet, so a client who never finishes step 2 has no API
 *     access to anyone's data.
 *  2. Submit the domain they will resell from. Only then does the key switch
 *     on, and the domain is recorded against it.
 *
 * That second step is the whole point of the gate: a reseller key must be tied
 * to a real, identifiable site, so an abused key can be traced to a domain
 * instead of to "row 812 of api_credentials".
 *
 * Every action re-derives the client from ClientAuthGuard and passes that id
 * down. No action trusts a posted client id — a reseller surface that took the
 * client id from the request would let any client activate or rotate any other
 * client's key by editing a hidden field.
 */
final class ClientResellerController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly ResellerCredentialService $credentials,
        private readonly ResellerStoreService $stores,
        private readonly ResellerStoreLocator $locator,
        private readonly ResellerRetailPricing $retail,
        private readonly ResellerSettings $settings,
        private readonly ResellerPricing $pricing,
        private readonly CurrencyService $currency,
        private readonly ActivityLogger $activity,
        private readonly ResellerCostService $costs,
        // Appended last on purpose: this controller is constructed BY HAND in two
        // test files, and a new dependency in the middle would silently rebind
        // every argument after it.
        private readonly ResellerDomainSync $domainSync
    ) {
    }

    public function index(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $clientId = (int) $client['id'];
        $credential = $this->credentials->credentialFor($clientId);
        $currency = $this->currency->resolveForClient($client);

        // The secret exists in plaintext exactly once — at creation and at
        // rotation — and is never stored, only its hash. So it travels through
        // a flash and must be shown on the very next render; if the client
        // navigates away without reading it, rotation is the way back, and the
        // page says so rather than leaving them stuck with an unusable key.
        $issued = $this->session->pullFlash('reseller_issued', null);

        return $this->page('reseller.client-index', [
            'state' => $this->stateFor($credential),
            'credential' => $credential,
            'issued' => is_array($issued) ? $issued : null,
            'error' => $this->session->pullFlash('reseller_error'),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'discounts' => $this->settings->all(),
            'services' => $this->presentServices($this->pricing->serviceCatalogue(), $currency),
            'domains' => $this->presentDomains($this->pricing->domainCatalogue(), $currency),
            'currency' => $currency,
            'docsUrl' => '/client/reseller/docs',
            // Shown at the top: every reseller is known by its Reseller ID, every account by its User ID.
            'userId' => $clientId,
            'resellerId' => $this->storeIdFor($clientId),
        ]);
    }

    /** The client's store id (their Reseller ID), or null before they open a store. */
    private function storeIdFor(int $clientId): ?int
    {
        try {
            $store = $this->stores->forClient($clientId);
        } catch (\Throwable) {
            return null;
        }

        return $store === null ? null : (int) $store['id'];
    }

    /**
     * Step 1. Idempotent: asking twice does not mint a second key, it returns
     * the existing credential untouched (and, deliberately, does not re-reveal
     * the secret it no longer has).
     */
    public function requestKey(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $clientId = (int) $client['id'];
        $label = trim((string) $request->input('label', ''));
        $label = $label !== '' ? $label : 'Reseller key';

        $result = $this->credentials->requestKey($clientId, $label);

        if ($result['created']) {
            $this->session->flash('reseller_issued', [
                'key' => $result['key'],
                'secret' => $result['secret'],
                'rotated' => false,
            ]);
            $this->session->flash(
                'reseller_notice',
                'Your API key is created but DISABLED. Enter the domain you will resell from below to activate it.'
            );

            $this->activity->log(
                'client',
                $clientId,
                'reseller.key.requested',
                'api_credential',
                null,
                'Requested a reseller API key (inactive until a domain is submitted)',
                $request->ip()
            );
        } else {
            $this->session->flash(
                'reseller_notice',
                'You already have a reseller API key. If you have lost the secret, rotate the key to get a new one.'
            );
        }

        return Response::redirect('/client/reseller');
    }

    /** Step 2 — the gate. A valid domain here is what switches the key on. */
    public function activate(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $clientId = (int) $client['id'];
        $result = $this->credentials->activate($clientId, (string) $request->input('domain', ''));

        if (!$result['success']) {
            $this->session->flash('reseller_error', (string) $result['error']);

            return Response::redirect('/client/reseller');
        }

        $this->session->flash(
            'reseller_notice',
            'Your API key is now active for ' . $result['domain'] . '. Keep the secret safe — it is not shown again.'
        );

        $this->activity->log(
            'client',
            $clientId,
            'reseller.key.activated',
            'api_credential',
            null,
            'Activated reseller API key for domain ' . $result['domain'],
            $request->ip()
        );

        return Response::redirect('/client/reseller');
    }

    /** Issues a brand-new secret and returns the key to the inactive state. */
    public function rotate(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $clientId = (int) $client['id'];

        if (!$this->credentials->hasKey($clientId)) {
            $this->session->flash('reseller_error', 'You do not have a key to rotate yet.');

            return Response::redirect('/client/reseller');
        }

        $label = trim((string) $request->input('label', ''));
        $label = $label !== '' ? $label : 'Reseller key';
        $result = $this->credentials->rotateKey($clientId, $label);

        $this->session->flash('reseller_issued', [
            'key' => $result['key'],
            'secret' => $result['secret'],
            'rotated' => true,
        ]);
        // Rotation returns the key to inactive, so say why and what to do —
        // otherwise a working integration breaks and the reseller has no idea
        // the domain step is what re-armed it.
        $this->session->flash(
            'reseller_notice',
            'A new secret has been issued and the OLD ONE NO LONGER WORKS. The key is inactive again until you re-submit your reselling domain.'
        );

        $this->activity->log(
            'client',
            $clientId,
            'reseller.key.rotated',
            'api_credential',
            null,
            'Rotated the reseller API key (new credential, inactive until re-activated)',
            $request->ip()
        );

        return Response::redirect('/client/reseller');
    }

    /** The API reference, in the reseller area where a reseller will look for it. */
    public function docs(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        return $this->docsPage(
            $this->credentials->credentialFor((int) $client['id']),
            '/client/reseller',
            'Back to the reseller area'
        );
    }

    // --- the storefront ----------------------------------------------------

    /** The reseller's own white-label store: branding, address, custom domain. */
    public function store(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);
        $storeId = $store === null ? null : (int) $store['id'];

        // What to suggest for the store's web address, before they type anything:
        // their company name if we have one, otherwise their own name. Short — see
        // ResellerStoreService::suggestSlug().
        $companyName = trim((string) ($client['company_name'] ?? ''));
        $personName = trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));

        // What this store has cost its owner, in their own currency, and what is
        // still unpaid. Read-only: nothing here lets a reseller change what they
        // owe, because cost comes from our catalogue and the admin's discount.
        $summaries = $storeId === null ? [] : $this->costs->storeSummaries($storeId);

        return $this->page('reseller.store', [
            'store' => $store,
            'cost' => $summaries[0] ?? null,
            'arrears' => $storeId === null ? [] : $this->costs->arrears($storeId),
            'goLive' => $store === null ? [] : $this->stores->goLiveChecklist($store),
            'chat' => ResellerChat::formValues($store),
            // The reseller's own account phone, offered as the number their store's
            // chat button would use — so it works out of the box rather than waiting
            // for them to retype a number we already have.
            'phoneHint' => ResellerChat::normaliseWhatsapp((string) ($client['phone'] ?? '')) ?? '',
            // Whether the reseller has ever saved chat settings. Until they have, the
            // storefront ALREADY shows a WhatsApp button on the phone hint above, and
            // the page says so; afterwards, a blank field means "no chat".
            'chatConfigured' => $store !== null && ResellerChat::isConfigured($store),
            'platformHost' => $this->locator->platformHost(),
            'slugSuggestion' => $this->stores->suggestSlug($companyName !== '' ? $companyName : $personName),
            'platformUrl' => $store === null ? null : $this->stores->platformUrl($store),
            'recordName' => $store === null || ($store['custom_domain'] ?? null) === null
                ? null
                : '_codevault-verify.' . $store['custom_domain'],
            'error' => $this->session->pullFlash('reseller_error'),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'verification' => $this->session->pullFlash('reseller_verification'),
            'docsUrl' => '/client/reseller/docs',
        ]);
    }

    /**
     * The store's own support chat — WhatsApp by default, Tawk.To optionally.
     *
     * A reseller may change this whenever they like: it is their support channel,
     * and the only one their customers ever see. Ours must never appear there, which
     * is the whole reason the store has its own.
     */
    public function saveStoreChat(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            $this->session->flash('reseller_error', 'Open your store first.');

            return Response::redirect('/client/reseller/store');
        }

        $result = $this->stores->saveChat((int) $store['id'], [
            'support_whatsapp' => $request->input('support_whatsapp', ''),
            'tawk_property_id' => $request->input('tawk_property_id', ''),
            'tawk_widget_id' => $request->input('tawk_widget_id', ''),
        ]);

        $this->session->flash(
            $result['success'] ? 'reseller_notice' : 'reseller_error',
            $result['success']
                ? 'Support chat saved — this is what your customers see on your storefront.'
                : (string) $result['error']
        );

        return Response::redirect('/client/reseller/store');
    }

    /**
     * The headline and tagline at the top of the store's home page. Blank means
     * "use the default", so clearing a field is how a reseller undoes it.
     */
    public function saveStoreHomepage(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            $this->session->flash('reseller_error', 'Open your store first.');

            return Response::redirect('/client/reseller/store');
        }

        $result = $this->stores->saveHomepage(
            (int) $store['id'],
            (string) $request->input('home_headline', ''),
            (string) $request->input('home_tagline', '')
        );

        $this->session->flash(
            $result['success'] ? 'reseller_notice' : 'reseller_error',
            $result['success'] ? 'Home page text saved.' : (string) $result['error']
        );

        return Response::redirect('/client/reseller/store');
    }

    /** Opens the store. The address label is derived from the name, and can be changed after. */
    public function openStore(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $result = $this->stores->openForClient(
            (int) $client['id'],
            (string) $request->input('store_name', ''),
            (string) $request->input('slug', '')
        );

        if (!$result['success']) {
            $this->session->flash('reseller_error', (string) $result['error']);

            return Response::redirect('/client/reseller/store');
        }

        $this->activity->log(
            'client',
            (int) $client['id'],
            'reseller.store.opened',
            'reseller',
            (int) ($result['store']['id'] ?? 0),
            'Opened reseller store "' . (string) ($result['store']['slug'] ?? '') . '"',
            $request->ip()
        );

        $this->session->flash(
            'reseller_notice',
            'Your store is created. Set your logo and colours, then point your own domain at it if you have one.'
        );

        return Response::redirect('/client/reseller/store');
    }

    public function saveStoreBrand(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            $this->session->flash('reseller_error', 'Open your store first.');

            return Response::redirect('/client/reseller/store');
        }

        $storeId = (int) $store['id'];

        $result = $this->stores->saveBrand($storeId, [
            'brand_name' => $request->input('brand_name', ''),
            'logo_url' => $request->input('logo_url', ''),
            'favicon_url' => $request->input('favicon_url', ''),
            'primary_color' => $request->input('primary_color', ''),
        ]);

        if (!$result['success']) {
            $this->session->flash('reseller_error', (string) $result['error']);

            return Response::redirect('/client/reseller/store');
        }

        // The address is a separate submission because changing it breaks
        // links that already exist; it is only touched when actually provided.
        $slug = trim((string) $request->input('slug', ''));

        if ($slug !== '') {
            $renamed = $this->stores->rename($storeId, $slug);

            if (!$renamed['success']) {
                $this->session->flash('reseller_error', (string) $renamed['error']);

                return Response::redirect('/client/reseller/store');
            }
        }

        $this->session->flash('reseller_notice', 'Store branding saved.');

        return Response::redirect('/client/reseller/store');
    }

    /** Claims the domain the store will be served on. Nothing is served there until it verifies. */
    /**
     * The reseller's own domain: claim it, replace it, or take it away.
     *
     * REPLACING IS THE INTERESTING CASE, and it is a swap rather than an edit.
     * Both halves take effect as soon as the new name is saved, not when it is
     * approved:
     *
     *   - the old name stops being SERVED, because claiming a new domain clears
     *     the DNS proof — a proof belongs to a domain, so the new one earns its
     *     own;
     *   - the old name comes off the HOSTING PANEL, so it cannot keep answering
     *     for a hostname no store claims any more, which would resolve to OUR
     *     shop at OUR prices.
     *
     * The reseller is told about both: on the page before they save, and in the
     * notice afterwards naming the domain that came off. A store that silently
     * goes dark at its old address is a support ticket; one that silently keeps
     * answering is worse than that.
     */
    public function claimStoreDomain(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            $this->session->flash('reseller_error', 'Open your store first.');

            return Response::redirect('/client/reseller/store');
        }

        $domain = trim((string) $request->input('custom_domain', ''));

        if ($domain === '') {
            $this->stores->releaseDomain((int) $store['id']);
            $outcome = $this->domainSync->syncStore((int) $store['id']);

            $this->session->flash(
                'reseller_notice',
                'Custom domain removed. Your store is back on its platform address only. ' . $outcome['message']
            );

            return Response::redirect('/client/reseller/store');
        }

        $result = $this->stores->claimDomain((int) $store['id'], $domain);

        if (!$result['success']) {
            $this->session->flash('reseller_error', (string) $result['error']);

            return Response::redirect('/client/reseller/store');
        }

        // An unchanged domain is not a new request, so nothing is reconciled: the
        // claim is the same and, if it was approved, it is still on the panel.
        if (($result['unchanged'] ?? false) === true) {
            $this->session->flash(
                'reseller_notice',
                'That is already the domain your store uses — nothing was changed.'
            );

            return Response::redirect('/client/reseller/store');
        }

        $outcome = $this->domainSync->syncStore((int) $store['id']);
        $removed = (string) ($outcome['removed'] ?? '');

        $this->session->flash(
            'reseller_notice',
            ($removed !== ''
                ? 'Saved. ' . $removed . ' has been removed from our server and ' . $result['domain'] . ' replaces it. '
                : 'Domain saved. ')
            . 'Create the DNS record shown below and press Verify — an administrator also has to approve '
            . $result['domain'] . ' before we set it up, and your store is not served on it until the record is live.'
        );

        return Response::redirect('/client/reseller/store');
    }

    /** Asks DNS whether this reseller really controls the domain they claimed. */
    public function verifyStoreDomain(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            $this->session->flash('reseller_error', 'Open your store first.');

            return Response::redirect('/client/reseller/store');
        }

        $result = $this->stores->verifyDomain((int) $store['id']);

        if ($result['verified']) {
            $this->session->flash(
                'reseller_notice',
                'Domain verified — your store is live on ' . (string) $store['custom_domain'] . '.'
            );

            $this->activity->log(
                'client',
                (int) $client['id'],
                'reseller.store.domain_verified',
                'reseller',
                (int) $store['id'],
                'Verified store domain ' . (string) $store['custom_domain']
                    . ' via ' . (string) $result['method'],
                $request->ip()
            );

            return Response::redirect('/client/reseller/store');
        }

        // Show what DNS actually returned. "Not verified" alone leaves a
        // reseller guessing between a missing record, a typo, and propagation.
        $this->session->flash('reseller_verification', [
            'domain' => (string) ($store['custom_domain'] ?? ''),
            'record_name' => (string) ($result['record_name'] ?? ''),
            'expected' => (string) ($result['expected'] ?? ''),
            'found' => (array) ($result['found'] ?? []),
            'error' => $result['error'],
        ]);

        return Response::redirect('/client/reseller/store');
    }

    // --- prices ------------------------------------------------------------

    /**
     * What this reseller's customers will pay.
     *
     * A preview, not a live storefront: until checkout charges retail (Phase 3)
     * the public store still shows list prices, so showing these figures to a
     * customer now would be advertising a price we do not honour. The page says
     * so, and lists both the cost and the margin per line so the reseller can
     * see the two numbers that decide their profit.
     */
    public function prices(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            $this->session->flash('reseller_error', 'Open your store first.');

            return Response::redirect('/client/reseller/store');
        }

        $currency = $this->currency->resolveForClient($client);

        return $this->page('reseller.prices', [
            'store' => $store,
            'markup' => $this->retail->markupFor($store),
            'services' => $this->presentRetailServices($this->retail->previewServices($store), $currency),
            'domains' => $this->presentRetailDomains($this->retail->previewDomains($store), $currency),
            'error' => $this->session->pullFlash('reseller_error'),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'discounts' => $this->settings->all(),
            'currency' => $currency,
        ]);
    }

    /**
     * Saves the whole price list in one go: the store-wide markup, then any
     * individual overrides.
     *
     * A submission with a below-cost price is refused in full rather than
     * partly applied — a half-saved price list is worse than a rejected one,
     * because the reseller cannot tell which rows went through.
     */
    public function savePrices(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            $this->session->flash('reseller_error', 'Open your store first.');

            return Response::redirect('/client/reseller/store');
        }

        $storeId = (int) $store['id'];
        $markup = ResellerRetailPricing::clampMarkup((float) $request->input('markup_percent', 0));
        $problems = [];

        // Both loops are driven by the CATALOGUE, not by the posted keys, so a
        // product id or TLD that was not on the form is simply not looked at —
        // a posted key cannot introduce a price for something that does not
        // exist, and any key we do accept is one we already know the cost of.
        $productPrices = [];

        foreach ($this->retail->previewServices($store) as $product) {
            foreach ($product['cycles'] as $cycle) {
                $key = (int) $product['product_id'] . ':' . (string) $cycle['cycle'];
                $raw = trim((string) $this->postedPrice($request, 'price', $key));

                if ($raw === '') {
                    $productPrices[$key] = null;

                    continue;
                }

                $value = (float) $raw;
                $label = (string) $product['name'] . ' (' . (string) $cycle['label'] . ')';
                $problem = $this->retail->overrideProblem($value, (float) $cycle['retail']['cost'], $label);

                if ($problem !== null) {
                    $problems[] = $problem;

                    continue;
                }

                $productPrices[$key] = $value;
            }
        }

        $domainPrices = [];

        foreach ($this->retail->previewDomains($store) as $row) {
            $tld = (string) $row['tld'];
            $member = [];

            foreach (['register', 'transfer', 'renew'] as $which) {
                $raw = trim((string) $this->postedPrice($request, 'domain', $tld . '.' . $which));

                if ($raw === '') {
                    $member[$which] = null;

                    continue;
                }

                $value = (float) $raw;
                $problem = $this->retail->overrideProblem(
                    $value,
                    (float) $row[$which . '_retail']['cost'],
                    $tld . ' ' . $which
                );

                if ($problem !== null) {
                    $problems[] = $problem;

                    continue;
                }

                $member[$which] = $value;
            }

            $domainPrices[$tld] = $member + ['register' => null, 'transfer' => null, 'renew' => null];
        }

        if ($problems !== []) {
            $this->session->flash('reseller_error', implode(' ', array_slice($problems, 0, 3))
                . (count($problems) > 3 ? ' (and ' . (count($problems) - 3) . ' more)' : '')
                . ' Nothing was saved.');

            return Response::redirect('/client/reseller/prices');
        }

        $this->stores->setMarkup($storeId, $markup);
        $this->retail->saveOverrides($storeId, $productPrices, $domainPrices);

        $this->session->flash('reseller_notice', 'Your prices are saved. Nothing is charged at these prices yet — they go live when your store starts taking orders.');

        $this->activity->log(
            'client',
            (int) $client['id'],
            'reseller.store.prices_updated',
            'reseller',
            $storeId,
            'Set store markup to ' . number_format($markup, 2) . '% with '
                . count(array_filter($productPrices, static fn ($p): bool => $p !== null)) . ' product override(s)',
            $request->ip()
        );

        return Response::redirect('/client/reseller/prices');
    }

    /** A posted price field, or '' — the form nests them under `price` and `domain`. */
    private function postedPrice(Request $request, string $group, string $key): string
    {
        $values = $request->input($group, []);

        if (!is_array($values) || !array_key_exists($key, $values) || !is_scalar($values[$key])) {
            return '';
        }

        return (string) $values[$key];
    }

    /**
     * @param array<int, array<string, mixed>> $catalogue
     * @param array<string, mixed> $currency
     * @return array<int, array<string, mixed>>
     */
    private function presentRetailServices(array $catalogue, array $currency): array
    {
        foreach ($catalogue as $i => $product) {
            foreach ($product['cycles'] as $j => $cycle) {
                $catalogue[$i]['cycles'][$j]['retail_display'] = $this->presentRetailQuote($cycle['retail'], $currency);
            }
        }

        return $catalogue;
    }

    /**
     * @param array<int, array<string, mixed>> $catalogue
     * @param array<string, mixed> $currency
     * @return array<int, array<string, mixed>>
     */
    private function presentRetailDomains(array $catalogue, array $currency): array
    {
        foreach ($catalogue as $i => $row) {
            foreach (['register', 'transfer', 'renew'] as $which) {
                $catalogue[$i][$which . '_display'] = $this->presentRetailQuote($row[$which . '_retail'], $currency);
            }
        }

        return $catalogue;
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $currency
     * @return array<string, mixed>
     */
    private function presentRetailQuote(array $quote, array $currency): array
    {
        return $quote + [
            'list_display' => $this->currency->format((float) $quote['list'], $currency),
            'cost_display' => $this->currency->format((float) $quote['cost'], $currency),
            'retail_display' => $this->currency->format((float) $quote['retail'], $currency),
            'margin_display' => $this->currency->format((float) $quote['margin'], $currency),
            'below_cost' => $this->retail->belowCost((float) $quote['retail'], (float) $quote['cost']),
        ];
    }

    /**
     * Shared by the client and admin docs pages so the two can never disagree
     * about what the API does — and so the test that checks every documented
     * path against routes/api.php covers both.
     *
     * @param array<string, mixed>|null $credential
     */
    private function docsPage(?array $credential, string $backUrl, string $backLabel): Response
    {
        $content = $this->view->render('reseller.docs', [
            'credential' => $credential,
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
            'baseUrl' => ApiDocumentation::BASE_URL,
            'authHeader' => ApiDocumentation::AUTH_HEADER,
            'endpoints' => ApiDocumentation::endpoints(),
            'scopes' => ApiDocumentation::scopes(),
            'errorCodes' => ApiDocumentation::errorCodes(),
        ]);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Reseller API Documentation',
            'content' => $content,
        ]));
    }

    /** @param array<string, mixed>|null $credential */
    private function stateFor(?array $credential): string
    {
        if ($credential === null) {
            return 'none';
        }

        return (int) ($credential['active'] ?? 0) === 1 ? 'active' : 'pending';
    }

    /**
     * Attach display strings to a quote, in the client's own currency.
     *
     * The catalogue figures are CATALOG amounts (the currency an admin typed
     * prices in). ResellerPricing deliberately does not convert them — that is
     * CurrencyService::catalogRate()'s job, and mixing the two is how a ₦22,350
     * plan gets quoted to a dollar client as $22,350.
     *
     * @param array{list: float, discount_percent: float, reseller: float} $quote
     * @param array<string, mixed> $currency
     * @return array{list: string, reseller: string, discount_percent: float, discount_label: string, saving: string}
     */
    private function presentQuote(array $quote, array $currency): array
    {
        return [
            'list' => $this->currency->format($quote['list'], $currency),
            'reseller' => $this->currency->format($quote['reseller'], $currency),
            'discount_percent' => $quote['discount_percent'],
            'discount_label' => ResellerSettings::formatPercent($quote['discount_percent']) . '%',
            'saving' => $this->currency->format(round($quote['list'] - $quote['reseller'], 2), $currency),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $catalogue
     * @param array<string, mixed> $currency
     * @return array<int, array<string, mixed>>
     */
    private function presentServices(array $catalogue, array $currency): array
    {
        foreach ($catalogue as $i => $product) {
            foreach ($product['cycles'] as $j => $cycle) {
                $catalogue[$i]['cycles'][$j]['price'] = $this->presentQuote($cycle['price'], $currency);
                $catalogue[$i]['cycles'][$j]['setup_fee'] = $this->presentQuote($cycle['setup_fee'], $currency);
            }
        }

        return $catalogue;
    }

    /**
     * @param array<int, array<string, mixed>> $catalogue
     * @param array<string, mixed> $currency
     * @return array<int, array<string, mixed>>
     */
    private function presentDomains(array $catalogue, array $currency): array
    {
        foreach ($catalogue as $i => $row) {
            foreach (['register', 'transfer', 'renew'] as $kind) {
                $catalogue[$i][$kind] = $this->presentQuote($row[$kind], $currency);
            }
        }

        return $catalogue;
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Reseller Area',
            'content' => $content,
        ]));
    }
}
