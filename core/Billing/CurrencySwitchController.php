<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Request;
use CodeVault\Response;

/**
 * Public currency switcher (store/cart header widget). Stores the choice
 * in-session for everyone, and additionally saves it as the logged-in
 * client's profile preference so it persists across sessions/devices.
 *
 * It deliberately calls setCurrencyPreference(), NOT updateCurrency(): the
 * latter converts every balance on the account, and this endpoint is one click
 * away from the storefront header for any signed-in client. Re-denominating on
 * a browse-currency toggle also rounded every amount twice per round trip, so a
 * client who toggled USD/NGN twice came back to slightly different invoices.
 */
final class CurrencySwitchController
{
    public function __construct(
        private readonly CurrencyRepository $currencies,
        private readonly CurrencySelection $selection,
        private readonly ClientAuthGuard $guard,
        private readonly ClientRepository $clients
    ) {
    }

    public function select(Request $request): Response
    {
        $currencyId = (int) $request->input('currency_id', 0);

        if ($this->currencies->find($currencyId) !== null) {
            $this->selection->set($currencyId);

            $client = $this->guard->currentClient();

            if ($client !== null) {
                $this->clients->setCurrencyPreference((int) $client['id'], $currencyId);
            }
        }

        $redirectTo = (string) $request->input('redirect', '/store');

        return Response::redirect(str_starts_with($redirectTo, '/') ? $redirectTo : '/store');
    }
}
