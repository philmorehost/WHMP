<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\CurrencyService;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * What we owe each store, and how much of it they could take today.
 *
 * This is the READ side of the reseller's running account: the ledger Phase A
 * writes and nothing has read until now. It answers two questions that look like
 * one and are not:
 *
 *   the balance       — what we owe them
 *   the withdrawable  — what a payout could draw on today, which is smaller by
 *                       exactly the receipts still inside the holding period
 *
 * A THIRD page that prints the balance and calls it "available" would be a lie
 * about 30 days of every receipt, and the lie would be the kind that leads to a
 * payout larger than the money we hold. So both numbers are on the page, always,
 * with the holding period that separates them stated in the same breath.
 *
 * THE FIGURES ARE IN BASE UNITS, and that is a deliberate choice with a visible
 * cost: a reseller in NGN reads a USD-denominated balance and has to convert it
 * in their head. The alternative is worse — crediting their own currency at
 * accrual would hand the exchange movement to US, and the user's decision was
 * that the reseller carries it. Their currency equivalents are shown alongside
 * so the page is not useless, but they are labelled as conversions of the balance
 * rather than as the balance, because they move from day to day with no new sales.
 *
 * ONE total across stores is legitimate here, unlike the cost report, precisely
 * because the account is kept in one unit. That is the whole payoff of the base-
 * currency decision and the page should say so.
 *
 * A separate controller from AdminResellerBillingController on purpose: that one
 * is hand-built in tests, so every new collaborator it takes is a test edit.
 * Reading the account needs a different set of collaborators anyway.
 */
final class AdminResellerAccountsController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly SettingsRepository $settings,
        private readonly ResellerLedgerService $ledger,
        private readonly ResellerStoreRepository $stores,
        private readonly CurrencyService $currency,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $accounts = $this->ledger->summaries();

        // A single total is meaningful because every amount in the ledger is a
        // base figure. Summing it is the point of keeping the account in one unit.
        $totalBalance = 0.0;
        $totalWithdrawable = 0.0;
        $claimable = 0;

        foreach ($accounts as $account) {
            $totalBalance += (float) $account['balance_base'];
            $totalWithdrawable += (float) $account['withdrawable_base'];

            if (($account['can_withdraw'] ?? false) === true) {
                $claimable++;
            }
        }

        return $this->render('reseller.admin-accounts', [
            'accounts' => $accounts,
            'totalBalance' => $totalBalance,
            'totalWithdrawable' => $totalWithdrawable,
            'claimable' => $claimable,
            'holdingDays' => $this->ledger->holdingDays(),
            'minimum' => $this->ledger->payoutMinimum(),
            'baseCode' => $this->baseCurrencyCode(),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);
    }

    /**
     * One store's account and the entries behind it.
     *
     * Addressed by CLIENT id rather than by store id, because that is what the
     * rest of the admin reseller surfaces use and because a client with no store
     * should still land somewhere explanatory rather than on a 404.
     */
    public function show(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) ($params['clientId'] ?? 0);
        $store = $this->stores->forClient($clientId);

        if ($store === null) {
            $this->session->flash(
                'reseller_error',
                'Client #' . $clientId . ' has no store, so there is no account to show.'
            );

            return Response::redirect('/admin/resellers/accounts');
        }

        $account = $this->ledger->accountFor((int) $store['id']);

        if ($account === null) {
            $this->session->flash('reseller_error', 'That store no longer exists.');

            return Response::redirect('/admin/resellers/accounts');
        }

        return $this->render('reseller.admin-account', [
            'clientId' => $clientId,
            'account' => $account,
            'baseCode' => $this->baseCurrencyCode(),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);
    }

    /**
     * The ledger behind one store's balance, as a CSV (payout plan §10.3).
     *
     * Read-only, and gated on the same permission as the page it exports rather
     * than a separate one: an export is a read with a different content type, and
     * a distinct permission would let a staff member who may READ the account be
     * unable to take a copy of what they just read. Nothing here is a mutation, so
     * there is nothing an export could do that the show() page could not.
     *
     * Two departures from the screens, both taken deliberately from the plan:
     *
     *   - The ids are written as PLAIN IDS, not links. The point of the file is to
     *     be joined against other records outside the system, where a URL is noise.
     *   - NO TOTALS. The ledger is the authority and a spreadsheet can sum its own
     *     column; a total in the export would invite someone to reconcile against
     *     the file rather than against the account, and a wrong total that looks
     *     authoritative is worse than no total at all.
     *
     * The amount is exported as `amount_base` and left unconverted. A store whose
     * own currency differs from the base sees the same figure the account page
     * shows, because there is no per-row rate at which each line was settled —
     * that absence is precisely the reseller-carries-the-FX decision.
     */
    public function export(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) ($params['clientId'] ?? 0);
        $store = $this->stores->forClient($clientId);

        if ($store === null) {
            $this->session->flash(
                'reseller_error',
                'Client #' . $clientId . ' has no store, so there is no account to export.'
            );

            return Response::redirect('/admin/resellers/accounts');
        }

        $stream = fopen('php://temp', 'r+');

        fputcsv($stream, [
            'created_at', 'kind', 'amount_base', 'withdrawable_at',
            'order_id', 'invoice_id', 'payout_id', 'description',
        ]);

        foreach ($this->ledger->entriesForExport((int) $store['id']) as $entry) {
            fputcsv($stream, [
                $entry['created_at'],
                $entry['kind'],
                $entry['amount'],
                $entry['withdrawable_at'] ?? '',
                $entry['order_id'] ?? '',
                $entry['invoice_id'] ?? '',
                $entry['payout_id'] ?? '',
                $entry['description'] ?? '',
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return (new Response($csv, 200))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="reseller-ledger-' . $clientId . '-' . date('Y-m-d') . '.csv"')
            ->withHeader('Content-Length', (string) strlen($csv));
    }

    /**
     * The two numbers a payout is measured against.
     *
     * Both are floored rather than trusted from the form, for the same reason the
     * billing page floors its own: a negative holding period would make the
     * withdrawable figure exceed the balance, and a negative minimum would make
     * every account claimable — including one that is overdrawn.
     */
    public function saveSettings(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $holdingDays = max(0, (int) $request->input('payout_holding_days', 30));
        $minimum = max(0.0, (float) $request->input('payout_minimum', 50));

        $this->settings->set('reseller.payout_holding_days', (string) $holdingDays);
        $this->settings->set('reseller.payout_minimum', number_format($minimum, 2, '.', ''));

        $this->session->flash(
            'reseller_notice',
            'Payout settings saved: receipts become withdrawable after ' . $holdingDays
            . ' day(s), and a payout needs at least ' . number_format($minimum, 2) . '.'
            . ($holdingDays === 0
                ? ' A zero-day holding period means a receipt is withdrawable the moment it is posted.'
                : '')
        );

        $this->activity->log(
            'admin',
            $this->adminId(),
            'reseller.payout.settings',
            null,
            null,
            'Set reseller payout holding period to ' . $holdingDays . ' day(s) and minimum to '
                . number_format($minimum, 2),
            $request->ip()
        );

        return Response::redirect('/admin/resellers/accounts');
    }

    /**
     * The code the base figures are denominated in.
     *
     * Read through CurrencyService rather than from a setting, because there is
     * no base-currency setting: the base is whichever row the `currencies` table
     * marks as default, and `codeFor(null)` is the one place that knows how to
     * resolve it. An empty string is returned rather than a guessed 'USD' — a
     * wrong three letters printed against a money figure is worse than none, and
     * this is the kind of label that gets copied into a payout instruction.
     */
    private function baseCurrencyCode(): string
    {
        return strtoupper(trim($this->currency->codeFor(null)));
    }

    private function adminId(): ?int
    {
        $admin = $this->guard->current();

        return $admin === null ? null : (int) $admin['id'];
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::RESELLERS_MANAGE)) {
            return Response::html('403 Forbidden — missing resellers.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Reseller accounts',
            'content' => $content,
        ]));
    }
}
