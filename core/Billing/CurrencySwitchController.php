<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;

/**
 * Public currency switcher (store/cart header widget). Stores the choice
 * in-session for everyone, and additionally saves it as the logged-in
 * client's profile preference so it persists across sessions/devices.
 *
 * $this->clients->setCurrencyPreference() is deliberately NOT called from
 * select(): `clients.currency_id` is the ACCOUNT currency, the same column
 * updateCurrency() reads to decide whether anything needs converting. Writing
 * it from a browse toggle used to leave the account half-changed — the column
 * said naira while the amounts it labels were still the base figures, because
 * nothing had been converted (and re-denominating on a browse toggle rounded
 * every amount twice per round trip, so a client who toggled USD/NGN twice came
 * back to slightly different invoices).
 *
 * applyToAccount() is the deliberate counterpart, and the two are kept apart on
 * purpose. Browsing prices in another currency is a display question, so it
 * lives in the session and touches nothing. Changing the account currency is a
 * money operation that re-denominates every live balance, so it takes its own
 * POST, requires an explicit confirmation, and answers with a warning rather
 * than silently re-pricing a client's account. That warning is not decoration:
 * the re-denomination re-rounds each amount, so switching A->B->A does not land
 * back on the exact original figures.
 */
final class CurrencySwitchController
{
    public function __construct(
        private readonly CurrencyRepository $currencies,
        private readonly CurrencySelection $selection,
        private readonly ClientAuthGuard $guard,
        private readonly ClientRepository $clients,
        private readonly SessionManager $session
    ) {
    }

    public function select(Request $request): Response
    {
        $currencyId = (int) $request->input('currency_id', 0);

        if ($this->currencies->find($currencyId) !== null) {
            // Session only — see the class docblock: `clients.currency_id` is
            // the ACCOUNT currency, and only applyToAccount() may write it,
            // because only applyToAccount() converts the amounts it labels.
            $this->selection->set($currencyId);
        }

        $redirectTo = (string) $request->input('redirect', '/store');

        return Response::redirect($this->safeRedirect($redirectTo === '' ? '/store' : $redirectTo));
    }

    /**
     * Change the account's currency, re-denominating every live amount.
     *
     * This is the "switch while ordering" action the checkout page offers, and
     * it is deliberately NOT what the header widget does. It refuses without the
     * confirmation the warning page asks for, because the conversion rewrites the
     * client's outstanding balances and each rewritten amount is re-rounded — so
     * it is not something a single click in a navigation bar should trigger.
     *
     * Only LIVE amounts move (updateCurrency()'s default): paid invoices, their
     * transactions, and cancelled/terminated items keep the currency they were
     * billed in, so a settled figure a gateway already took is never restated.
     */
    public function applyToAccount(Request $request): Response
    {
        $currencyId = (int) $request->input('currency_id', 0);
        $redirectTo = $this->safeRedirect((string) $request->input('redirect', '/cart'));

        $currency = $this->currencies->find($currencyId);

        if ($currency === null) {
            $this->session->flash('currency_error', 'That currency is not available.');

            return Response::redirect($redirectTo);
        }

        // Record the browsing choice either way, so the storefront prices the
        // rest of this session in the currency they just picked.
        $this->selection->set($currencyId);

        $client = $this->guard->currentClient();

        if ($client === null) {
            // A guest has no account to re-denominate; the session choice above
            // is the whole effect, and that is the correct one for a visitor.
            return Response::redirect($redirectTo);
        }

        if ((string) $request->input('confirm', '') !== '1') {
            $this->session->flash(
                'currency_error',
                'Tick the confirmation box before changing your account currency — it recalculates every amount you still owe or will be billed.'
            );

            return Response::redirect($redirectTo);
        }

        $clientId = (int) $client['id'];

        $default = $this->currencies->default();
        $currentRaw = $client['currency_id'] ?? null;
        // "Base currency" is stored as NULL, so equality has to compare the
        // resolved currency rather than the raw column, or switching TO the base
        // currency would look like a change every time it was requested. The
        // default's own id is accepted too: updateCurrency() writes the target
        // id verbatim, and an imported row may carry the default's id rather
        // than NULL, so both mean "already the base currency".
        $alreadyInTarget = (int) $default['id'] === $currencyId
            ? ($currentRaw === null || (int) $currentRaw === $currencyId)
            : ($currentRaw !== null && (int) $currentRaw === $currencyId);

        if ($alreadyInTarget) {
            $this->session->flash(
                'currency_notice',
                'Your account is already in ' . strtoupper((string) $currency['code']) . ' — nothing needed recalculating.'
            );

            return Response::redirect($redirectTo);
        }

        $from = $currentRaw === null
            ? $default
            : ($this->currencies->find((int) $currentRaw) ?? $default);

        // Live amounts only. Settled history keeps the currency it was billed in.
        $this->clients->updateCurrency($clientId, $currencyId, false);

        $this->session->flash(
            'currency_notice',
            'Your account currency is now ' . strtoupper((string) $currency['code']) . '. Outstanding invoices, orders and what '
            . 'you will be billed next have all been recalculated from ' . strtoupper((string) $from['code']) . '. Already-paid '
            . 'invoices keep the currency they were billed in. Amounts are rounded when converted, so switching back and forth '
            . 'may not land on exactly the original figure.'
        );

        return Response::redirect($redirectTo);
    }

    /**
     * Only a same-site absolute path may be redirected to, so a crafted
     * `redirect` value cannot turn this endpoint into an open redirect.
     */
    private function safeRedirect(string $target): string
    {
        return str_starts_with($target, '/') && !str_starts_with($target, '//') ? $target : '/cart';
    }
}
