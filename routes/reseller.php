<?php

declare(strict_types=1);

use CodeVault\Reseller\AdminResellerAccountsController;
use CodeVault\Reseller\AdminResellerBillingController;
use CodeVault\Reseller\AdminResellerPayoutsController;
use CodeVault\Reseller\AdminResellerController;
use CodeVault\Reseller\ClientResellerAccountController;
use CodeVault\Reseller\ClientResellerController;

/** @var CodeVault\Router $router */

// The client-facing Reseller Area. The whole programme hangs off this pair of
// steps: request a key (created DISABLED), then submit the domain you resell
// from — which is the only thing that switches the key on.
$router->get('/client/reseller', [ClientResellerController::class, 'index']);
$router->post('/client/reseller/key', [ClientResellerController::class, 'requestKey']);
$router->post('/client/reseller/activate', [ClientResellerController::class, 'activate']);
$router->post('/client/reseller/rotate', [ClientResellerController::class, 'rotate']);
$router->get('/client/reseller/docs', [ClientResellerController::class, 'docs']);

// What the reseller has earned. Registered as a literal path before the store
// routes below, and it takes no client id at all — the client is re-derived from
// the session guard, so one reseller cannot read another's account by editing a
// parameter.
$router->get('/client/reseller/account', [ClientResellerAccountController::class, 'index']);

// Asking for the withdrawable balance, and withdrawing the request. The amount is
// never posted -- ResellerPayoutService decides it from the account -- so the form
// carries nothing but a CSRF token and there is no figure to tamper with.
$router->post('/client/reseller/account/payouts', [ClientResellerAccountController::class, 'requestPayout']);
$router->post('/client/reseller/account/payouts/{payoutId}/cancel', [ClientResellerAccountController::class, 'cancelPayout']);

// The white-label store. Claiming a domain and proving control of it are
// separate steps on purpose: nothing is served on a claimant's domain until
// DNS shows they control it.
$router->get('/client/reseller/store', [ClientResellerController::class, 'store']);
$router->post('/client/reseller/store', [ClientResellerController::class, 'openStore']);
$router->post('/client/reseller/store/brand', [ClientResellerController::class, 'saveStoreBrand']);
$router->post('/client/reseller/store/domain', [ClientResellerController::class, 'claimStoreDomain']);
$router->post('/client/reseller/store/verify', [ClientResellerController::class, 'verifyStoreDomain']);

// What the reseller's own customers will pay: a store-wide markup plus optional
// per-cycle and per-TLD overrides. Preview only until checkout charges retail.
$router->get('/client/reseller/prices', [ClientResellerController::class, 'prices']);
$router->post('/client/reseller/prices', [ClientResellerController::class, 'savePrices']);

// Admin side: the discounts resellers get, and the keys issued to them.
$router->get('/admin/resellers', [AdminResellerController::class, 'index']);
$router->post('/admin/resellers/discounts', [AdminResellerController::class, 'saveDiscounts']);
$router->post('/admin/resellers/{clientId}/toggle', [AdminResellerController::class, 'toggle']);
$router->get('/admin/resellers/docs', [AdminResellerController::class, 'docs']);

// What the stores owe us, and how it is billed. Registered BEFORE the
// parameterised store routes below so these literal paths cannot be read as a
// client id. "Bill now" is safe to press twice — an order carries the id of the
// invoice that billed it, so idempotency is a property of the data.
$router->get('/admin/resellers/billing', [AdminResellerBillingController::class, 'index']);
$router->post('/admin/resellers/billing/settings', [AdminResellerBillingController::class, 'saveSettings']);
$router->post('/admin/resellers/billing/run', [AdminResellerBillingController::class, 'runNow']);

// What we OWE each store: the running account Phase A writes. Registered as a
// literal path before the parameterised routes below for the same reason as the
// billing pair — '/admin/resellers/accounts' must not be read as a client id.
$router->get('/admin/resellers/accounts', [AdminResellerAccountsController::class, 'index']);
$router->post('/admin/resellers/payouts/settings', [AdminResellerAccountsController::class, 'saveSettings']);

// The payout queue. Registered before the parameterised routes below for the same
// reason as the literal paths above, and because 'payouts' at this position is
// otherwise readable as a client id. Payment is a manual bank transfer, so these
// endpoints only RECORD a decision -- none of them moves money.
$router->get('/admin/resellers/payouts', [AdminResellerPayoutsController::class, 'index']);
$router->post('/admin/resellers/payouts/{payoutId}/paid', [AdminResellerPayoutsController::class, 'markPaid']);
$router->post('/admin/resellers/payouts/{payoutId}/reject', [AdminResellerPayoutsController::class, 'reject']);

// Store management, addressed by client id so a client who has no store yet is
// still reachable (that is exactly when an admin needs to look).
$router->get('/admin/resellers/{clientId}/store', [AdminResellerController::class, 'store']);
$router->post('/admin/resellers/{clientId}/store', [AdminResellerController::class, 'openStore']);
$router->post('/admin/resellers/{clientId}/store/brand', [AdminResellerController::class, 'saveStoreBrand']);
$router->post('/admin/resellers/{clientId}/store/domain', [AdminResellerController::class, 'claimStoreDomain']);
$router->post('/admin/resellers/{clientId}/store/verify', [AdminResellerController::class, 'verifyStoreDomain']);
// Forcing a domain verification (or taking it back) when DNS cannot be queried
// the way DomainVerifier needs. Recorded as 'manual' so an override never looks
// like a real proof.
$router->post('/admin/resellers/{clientId}/store/domain/override', [AdminResellerController::class, 'overrideStoreDomain']);
$router->post('/admin/resellers/{clientId}/store/status', [AdminResellerController::class, 'setStoreStatus']);

// The CSV of the same entries the account page shows (plan §10.3). Registered
// BEFORE the account route below so the literal `export` segment is matched as a
// path of its own rather than being read as part of the account path.
$router->get('/admin/resellers/{clientId}/account/export', [AdminResellerAccountsController::class, 'export']);

// The same account for a PERIOD — opening balance, entries, closing balance
// (plan §10.2). A view, not yet the numbered tax document that section asks for;
// see the controller method for why those are different things.
$router->get('/admin/resellers/{clientId}/statement', [AdminResellerAccountsController::class, 'statement']);

// One store's account and the entries behind it. Addressed by client id, like the
// store routes above, so a client with no store still lands somewhere that can
// explain why rather than on a 404.
$router->get('/admin/resellers/{clientId}/account', [AdminResellerAccountsController::class, 'show']);
