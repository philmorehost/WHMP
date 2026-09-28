<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

/**
 * What a reseller has earned, shown to the reseller.
 *
 * The admin report answers "what do we owe, in total". This answers "what have
 * *I* earned", and the difference is not cosmetic: the admin page may show a base
 * total that means nothing to a reseller in Lagos, so this page leads with the
 * figure in THEIR currency and explains where it came from.
 *
 * THE HONESTY PROBLEM ON THIS PAGE. The account is held in the base currency
 * because the reseller carries the exchange movement (their election, not our
 * convenience). A consequence is that the number they see can fall — with no new
 * sales, no refund and no explanation other than the rate. A page that quietly
 * reports a shrinking balance is how you get a support ticket accusing us of
 * arithmetic errors, so the page says in plain words that the figure is a
 * conversion, and shows the base amount it was converted from.
 *
 * The second figure is the one that matters for money: only aged receipts are
 * withdrawable, and anything still inside the holding period is owed but can be
 * disputed by a customer. It is shown as its own number rather than folded into
 * "available", because "available" that includes disputable money is a promise we
 * cannot keep.
 *
 * A separate controller from ClientResellerController deliberately: that one is
 * hand-built in two test classes, so a new collaborator there is a test edit for
 * no benefit. Nothing here is reachable from the request — the client is always
 * re-derived from the guard, never taken from a parameter, or one reseller could
 * read another's account by changing an id.
 */
final class ClientResellerAccountController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly ResellerLedgerService $ledger,
        private readonly ResellerStoreRepository $stores,
        private readonly CurrencyService $currency
    ) {
    }

    public function index(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            // No store means no account, and the reseller area already explains
            // how to open one — sending them there beats an empty statement that
            // looks like money went missing.
            return Response::redirect('/client/reseller');
        }

        $account = $this->ledger->accountFor((int) $store['id']);

        if ($account === null) {
            return Response::redirect('/client/reseller');
        }

        return $this->page('reseller.client-account', [
            'account' => $account,
            'baseCode' => strtoupper(trim($this->currency->codeFor(null))),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Reseller account',
            'content' => $content,
        ]));
    }
}
