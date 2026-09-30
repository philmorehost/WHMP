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
        private readonly SessionManager $session,
        private readonly ResellerLedgerService $ledger,
        private readonly ResellerPayoutService $payouts,
        private readonly ResellerStoreRepository $stores,
        private readonly CurrencyService $currency,
        private readonly ActivityLogger $activity,
        private readonly ResellerStatementService $documents
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
            'payout' => $this->withAmountCodes($this->payouts->summaryFor((int) $store['id'])),
            'labels' => ResellerPayoutService::STATUS_LABELS,
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);
    }

    /**
     * Attach the currency code each payout was denominated in.
     *
     * The row stores a currency ID, and the reseller's own currency may have
     * changed since the payout was asked for. Reading the code from the row rather
     * than from the client's current setting is what keeps an old payout labelled
     * in the unit it was actually sent in.
     *
     * @param array{open: array<string, mixed>|null, history: array<int, array<string, mixed>>} $summary
     * @return array{open: array<string, mixed>|null, history: array<int, array<string, mixed>>}
     */
    private function withAmountCodes(array $summary): array
    {
        $code = function (array $row): string {
            return strtoupper(trim($this->currency->codeFor(
                $row['currency_id'] === null ? null : (int) $row['currency_id']
            )));
        };

        if (is_array($summary['open'])) {
            $summary['open']['amount_code'] = $code($summary['open']);
        }

        foreach ($summary['history'] as $i => $row) {
            $summary['history'][$i]['amount_code'] = $code($row);
        }

        return $summary;
    }

    /**
     * Ask for the withdrawable balance.
     *
     * The amount is never taken from the form. The service decides it from the
     * account, so a reseller cannot request a figure of their choosing — including
     * one larger than they have — by editing a field. The form posts nothing but a
     * CSRF token.
     */
    public function requestPayout(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            return Response::redirect('/client/reseller');
        }

        $result = $this->payouts->request((int) $store['id']);

        if (!$result['ok']) {
            $this->session->flash('reseller_error', (string) $result['error']);

            return Response::redirect('/client/reseller/account');
        }

        $payoutId = (int) $result['payout']['id'];

        $this->activity->log(
            'client',
            (int) $client['id'],
            'reseller.payout.requested',
            null,
            null,
            'Requested reseller payout #' . $payoutId . ' for '
                . number_format((float) $result['payout']['amount'], 2) . ' '
                . $this->currency->codeFor(
                    $result['payout']['currency_id'] === null ? null : (int) $result['payout']['currency_id']
                ),
            $request->ip()
        );

        $this->session->flash(
            'reseller_notice',
            'Payout requested. The funds have been set aside from your available balance '
            . 'and will be sent by bank transfer once they are reviewed.'
        );

        return Response::redirect('/client/reseller/account');
    }

    /**
     * Withdraw a request that has not been paid yet.
     *
     * The service compares the request's owner against the signed-in client rather
     * than trusting the posted id, so one reseller cannot cancel another's request
     * by editing a field.
     */
    public function cancelPayout(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $payoutId = (int) ($params['payoutId'] ?? 0);
        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            return Response::redirect('/client/reseller');
        }

        $result = $this->payouts->cancel($payoutId, (int) $store['id']);

        if (!$result['ok']) {
            $this->session->flash('reseller_error', (string) $result['error']);

            return Response::redirect('/client/reseller/account');
        }

        $this->activity->log(
            'client',
            (int) $client['id'],
            'reseller.payout.cancelled',
            null,
            null,
            'Cancelled reseller payout request #' . $payoutId,
            $request->ip()
        );

        $this->session->flash(
            'reseller_notice',
            'Payout request cancelled and the funds returned to your available balance.'
        );

        return Response::redirect('/client/reseller/account');
    }

    /**
     * The numbered statements this reseller has been issued.
     *
     * Read-only, and the store is derived from the guard rather than from the URL,
     * so on THIS page there is no id to tamper with at all — the same rule the
     * account page follows. A reseller sees their own by construction, not by a
     * check somebody has to remember to write.
     */
    public function statements(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            return Response::redirect('/client/reseller');
        }

        return $this->page('reseller.client-statements', [
            'issued' => $this->documents->listing((int) $store['id'], 36),
            'baseCode' => strtoupper(trim($this->currency->codeFor(null))),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);
    }

    /**
     * One issued statement, as the reseller received it.
     *
     * THE OWNERSHIP CHECK IS WHY THIS IS A SEPARATE METHOD from statements(): the
     * id IS in the URL here, so it has to be verified against the store derived from
     * the session. Without it, any reseller could read another store's revenue by
     * counting upwards — and these documents carry our cost, their customer's
     * activity, and the store's whole trading history.
     */
    public function showStatement(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            return Response::redirect('/client/reseller');
        }

        $document = $this->documents->find((int) ($params['statementId'] ?? 0));

        if ($document === null || (int) $document['reseller_id'] !== (int) $store['id']) {
            // ONE message for "does not exist" and "is not yours", deliberately.
            // Distinguishing them would confirm which statement ids exist, which is
            // information about other stores.
            $this->session->flash('reseller_error', 'That statement is not available on your account.');

            return Response::redirect('/client/reseller/statements');
        }

        return $this->page('reseller.client-statement-document', [
            // Attached here rather than joined, so the document is always rendered
            // under the store that was authorised above.
            'document' => $document + ['store' => $store],
            'baseCode' => strtoupper(trim($this->currency->codeFor(null))),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
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
