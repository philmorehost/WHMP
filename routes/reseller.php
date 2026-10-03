<?php

declare(strict_types=1);

use CodeVault\Reseller\AdminClientMigrationController;
use CodeVault\Reseller\AdminResellerAccountsController;
use CodeVault\Reseller\AdminResellerBillingController;
use CodeVault\Reseller\AdminResellerDomainController;
use CodeVault\Reseller\AdminResellerPayoutsController;
use CodeVault\Reseller\AdminResellerTicketController;
use CodeVault\Reseller\AdminResellerController;
use CodeVault\Reseller\ClientResellerAccountController;
use CodeVault\Reseller\ClientResellerController;
use CodeVault\Reseller\ClientResellerMailController;
use CodeVault\Reseller\ClientResellerMigrationController;
use CodeVault\Reseller\ClientResellerPromotionsController;
use CodeVault\Reseller\ClientResellerTicketController;

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

// Where the money is sent. Recorded BEFORE a payout can be requested, and
// frozen onto the payout row when it is, so a later change of bank cannot
// restate where an earlier payout went (payout plan §8 Phase D).
$router->post('/client/reseller/account/payout-method', [ClientResellerAccountController::class, 'savePayoutMethod']);
$router->post('/client/reseller/account/payout-method/remove', [ClientResellerAccountController::class, 'removePayoutMethod']);

// The numbered statements issued to this reseller (plan §10.2). Read-only: a
// reseller cannot issue one to themselves, because issuing freezes our tax
// identity and mints a number, and both are our decision rather than theirs.
// Neither route carries a store id — the store comes from the session guard.
$router->get('/client/reseller/statements', [ClientResellerAccountController::class, 'statements']);
$router->get('/client/reseller/statements/{statementId}', [ClientResellerAccountController::class, 'showStatement']);

// The store's own support address: what its customers are written to FROM, and whether
// the domain actually authorises us to send as it. No store id in any path — the store is
// resolved from the session guard.
$router->get('/client/reseller/mail', [ClientResellerMailController::class, 'index']);
$router->post('/client/reseller/mail', [ClientResellerMailController::class, 'save']);
// Longer paths before the bare POST above, for the same first-match reason as the other
// literal groups: '/client/reseller/mail/create' is more specific than '/client/reseller/mail'.
$router->post('/client/reseller/mail/create', [ClientResellerMailController::class, 'create']);
$router->post('/client/reseller/mail/check', [ClientResellerMailController::class, 'check']);
$router->post('/client/reseller/mail/clear', [ClientResellerMailController::class, 'clear']);

// The store's own support desk: its customers' tickets, and a way to hand one up to
// us. No store id is carried in any of these paths — the store is resolved from the
// session guard, and each ticket is then matched against it INSIDE the query
// (TicketRepository::forResellerTicket), so a ticket id in the URL is one that has
// already been checked rather than a claim about ownership.
$router->get('/client/reseller/tickets', [ClientResellerTicketController::class, 'index']);
$router->get('/client/reseller/tickets/{ticketId}', [ClientResellerTicketController::class, 'show']);
$router->post('/client/reseller/tickets/{ticketId}/reply', [ClientResellerTicketController::class, 'reply']);
$router->post('/client/reseller/tickets/{ticketId}/escalate', [ClientResellerTicketController::class, 'escalate']);
// Taking a request back before we answer it — the store resolved it itself after all.
$router->post('/client/reseller/tickets/{ticketId}/withdraw', [ClientResellerTicketController::class, 'withdraw']);

// Asking us to move a customer INTO this store, or one of its customers OUT to another
// provider. A request only — a super admin decides (ClientMigrationService). No store
// id in either path: the store is the signed-in reseller's own.
$router->get('/client/reseller/migrations', [ClientResellerMigrationController::class, 'index']);
$router->post('/client/reseller/migrations', [ClientResellerMigrationController::class, 'submit']);

// The store's OWN promo codes and discount banner. Strictly isolated: no store id in
// any path (the store comes from the session guard) and every query is scoped to it,
// so one store can never see or change another's — or the platform's — offers, and
// the platform's banner never appears on a store's website (migration 0204).
$router->get('/client/reseller/promotions', [ClientResellerPromotionsController::class, 'index']);
$router->post('/client/reseller/promotions/codes', [ClientResellerPromotionsController::class, 'saveCode']);
$router->post('/client/reseller/promotions/codes/{id}/toggle', [ClientResellerPromotionsController::class, 'toggleCode']);
$router->post('/client/reseller/promotions/codes/{id}/delete', [ClientResellerPromotionsController::class, 'deleteCode']);
$router->post('/client/reseller/promotions/banners', [ClientResellerPromotionsController::class, 'createBanner']);
$router->post('/client/reseller/promotions/banners/{id}/update', [ClientResellerPromotionsController::class, 'updateBanner']);
$router->post('/client/reseller/promotions/banners/{id}/pause', [ClientResellerPromotionsController::class, 'pauseBanner']);
$router->post('/client/reseller/promotions/banners/{id}/resume', [ClientResellerPromotionsController::class, 'resumeBanner']);
$router->post('/client/reseller/promotions/banners/{id}/delete', [ClientResellerPromotionsController::class, 'deleteBanner']);

// The white-label store. Claiming a domain and proving control of it are
// separate steps on purpose: nothing is served on a claimant's domain until
// DNS shows they control it.
$router->get('/client/reseller/store', [ClientResellerController::class, 'store']);
$router->post('/client/reseller/store', [ClientResellerController::class, 'openStore']);
$router->post('/client/reseller/store/brand', [ClientResellerController::class, 'saveStoreBrand']);
$router->post('/client/reseller/store/chat', [ClientResellerController::class, 'saveStoreChat']);
$router->post('/client/reseller/store/homepage', [ClientResellerController::class, 'saveStoreHomepage']);
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

// Custom-domain REQUESTS: the reseller asks, an admin decides. Registered as a
// literal path before the parameterised store routes below, like the billing and
// account pairs, so 'domains' is never read as a client id.
$router->get('/admin/resellers/domains', [AdminResellerDomainController::class, 'index']);
$router->post('/admin/resellers/domains/settings', [AdminResellerDomainController::class, 'saveSettings']);
// Two segments like /settings, so it cannot be read as a store id.
$router->post('/admin/resellers/domains/diagnose', [AdminResellerDomainController::class, 'runDiagnostic']);
$router->post('/admin/resellers/domains/{storeId}/approve', [AdminResellerDomainController::class, 'approve']);
$router->post('/admin/resellers/domains/{storeId}/reject', [AdminResellerDomainController::class, 'reject']);
// Take a hostname back off the hosting panel. Registered for the same reason the
// approve/reject pair is: it acts on a store, but it belongs to the domain queue
// that is where an outstanding removal is visible.
$router->post('/admin/resellers/domains/{storeId}/unprovision', [AdminResellerDomainController::class, 'unprovision']);
// Create it on the panel again — for an approval the panel refused, or one that
// could not reach it. Same page, because that is where the failure is visible.
$router->post('/admin/resellers/domains/{storeId}/provision', [AdminResellerDomainController::class, 'provisionNow']);

// Tickets the stores have handed up to us. A literal path, registered before the
// parameterised store routes below so 'escalations' is never read as a client id.
$router->get('/admin/resellers/escalations', [AdminResellerTicketController::class, 'index']);

// Moving clients between providers (store -> store, store <-> platform). Literal paths,
// registered before the parameterised store routes below for the same reason as the
// groups above. The review page changes nothing; only `execute` moves a client, and
// only a super admin may press it (AdminClientMigrationController).
$router->get('/admin/resellers/migrations', [AdminClientMigrationController::class, 'index']);
$router->get('/admin/resellers/migrations/review', [AdminClientMigrationController::class, 'review']);
$router->post('/admin/resellers/migrations/execute', [AdminClientMigrationController::class, 'execute']);
$router->post('/admin/resellers/migrations/{id}/reject', [AdminClientMigrationController::class, 'reject']);

// Store management, addressed by client id so a client who has no store yet is
// still reachable (that is exactly when an admin needs to look).
$router->get('/admin/resellers/{clientId}/store', [AdminResellerController::class, 'store']);
$router->post('/admin/resellers/{clientId}/store', [AdminResellerController::class, 'openStore']);
$router->post('/admin/resellers/{clientId}/store/brand', [AdminResellerController::class, 'saveStoreBrand']);
$router->post('/admin/resellers/{clientId}/store/chat', [AdminResellerController::class, 'saveStoreChat']);
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

// ISSUE the numbered, frozen document for a period. A POST because it mints a
// number and writes a record: opening a page must never do either.
$router->post('/admin/resellers/{clientId}/statement/issue', [AdminResellerAccountsController::class, 'issueStatement']);

// One issued statement, rendered from its frozen snapshot. `statements` (plural)
// rather than `statement` so the issued documents are addressable separately from
// the live view, which has no id at all.
$router->get('/admin/resellers/{clientId}/statements/{statementId}', [AdminResellerAccountsController::class, 'showStatement']);

// One store's account and the entries behind it. Addressed by client id, like the
// store routes above, so a client with no store still lands somewhere that can
// explain why rather than on a 404.
$router->get('/admin/resellers/{clientId}/account', [AdminResellerAccountsController::class, 'show']);
