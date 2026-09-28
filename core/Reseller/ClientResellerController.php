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
        private readonly ResellerSettings $settings,
        private readonly ResellerPricing $pricing,
        private readonly CurrencyService $currency,
        private readonly ActivityLogger $activity
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
        ]);
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
