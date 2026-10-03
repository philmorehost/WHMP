<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\CurrencyService;
use CodeVault\Reseller\ResellerStatementService;
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
        private readonly ActivityLogger $activity,
        private readonly ResellerStatementService $documents
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
            'minimums' => $this->ledger->payoutMinimums(),
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
     * One store's account for a period: the opening balance, the entries, the
     * closing balance and the withdrawable figure at the period end (§10.2).
     *
     * Defaults to the CURRENT CALENDAR MONTH as a convenient starting range. Cost
     * billing cadence is configurable separately (monthly or ISO-weekly).
     *
     * THIS IS THE LIVE ACCOUNT VIEW, not the issued document: its values are
     * recomputed on each read. A numbered statement is frozen separately so two
     * copies of the same number cannot disagree. That document currently uses a
     * generic account-statement layout; no jurisdiction-specific tax-document
     * format is claimed or implied.
     */
    public function statement(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) ($params['clientId'] ?? 0);
        $store = $this->stores->forClient($clientId);

        if ($store === null) {
            $this->session->flash(
                'reseller_error',
                'Client #' . $clientId . ' has no store, so there is no statement to show.'
            );

            return Response::redirect('/admin/resellers/accounts');
        }

        [$from, $to] = $this->period($request);
        $statement = $this->ledger->statementFor((int) $store['id'], $from, $to);

        if ($statement === null) {
            $this->session->flash('reseller_error', 'That store no longer exists.');

            return Response::redirect('/admin/resellers/accounts');
        }

        return $this->render('reseller.admin-statement', [
            'clientId' => $clientId,
            'statement' => $statement,
            'canIssueStatement' => $this->canIssueStatement(),
            'issued' => $this->documents->listing((int) $store['id'], 24),
            'missingIdentity' => $this->documents->missingIdentity(),
            'baseCode' => $this->baseCurrencyCode(),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);
    }

    /**
     * Issue the NUMBERED statement for the requested period (plan §10.2).
     *
     * The live view above and this are deliberately different pages. The view is
     * recomputed on every read and can be corrected; an issued statement is frozen
     * and can only be superseded. Publishing is therefore an explicit action with an
     * explicit click, never a side effect of looking at something — a document that
     * numbers itself when you open it is a document nobody decided to send.
     *
     * Issuing is IDEMPOTENT: a repeated request for a period already issued returns
     * the existing document and says so, because minting a second number for the same
     * period would make the sequence unauditable. The redirect goes to the document
     * either way, so pressing the button twice lands in the same place.
     */
    public function issueStatement(Request $request, array $params): Response
    {
        if ($denied = $this->requireStatementIssuePermission()) {
            return $denied;
        }

        $clientId = (int) ($params['clientId'] ?? 0);
        $store = $this->stores->forClient($clientId);

        if ($store === null) {
            $this->session->flash(
                'reseller_error',
                'Client #' . $clientId . ' has no store, so there is no statement to issue.'
            );

            return Response::redirect('/admin/resellers/accounts');
        }

        [$from, $to] = $this->period($request);
        $issued = $this->documents->issue((int) $store['id'], $from, $to, $this->adminId());

        if ($issued === null) {
            $this->session->flash('reseller_error', 'That store no longer exists.');

            return Response::redirect('/admin/resellers/accounts');
        }

        $already = ($issued['already_issued'] ?? false) === true;

        if ($already) {
            $this->session->flash(
                'reseller_notice',
                'Statement ' . $issued['number'] . ' was already issued for that period — showing the existing '
                . 'document rather than issuing a second number.'
            );
        } else {
            $this->session->flash('reseller_notice', 'Issued statement ' . $issued['number'] . '.');

            // Logged only when a number is actually minted. A repeat is not an event:
            // it issues nothing, changes nothing, and logging it would bury the real
            // issuances in the activity log.
            $this->activity->log(
                'admin',
                $this->adminId(),
                'reseller.statement.issue',
                null,
                null,
                'Issued statement ' . $issued['number'] . ' for store #' . (int) $store['id']
                    . ' covering ' . substr($from, 0, 10) . ' to ' . substr($to, 0, 10),
                $request->ip()
            );
        }

        return Response::redirect('/admin/resellers/' . $clientId . '/statements/' . (int) $issued['id']);
    }

    /**
     * One issued statement, rendered from what was frozen.
     *
     * THE OWNERSHIP CHECK IS THE POINT. The statement id arrives in the URL, so it
     * must be verified against the store in the URL as well — otherwise any statement
     * could be read by guessing an id, and these documents name another company's
     * revenue. The check is here rather than in the query so there is no code path
     * that reaches a document without passing it.
     */
    public function showStatement(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) ($params['clientId'] ?? 0);
        $store = $this->stores->forClient($clientId);

        if ($store === null) {
            $this->session->flash('reseller_error', 'Client #' . $clientId . ' has no store.');

            return Response::redirect('/admin/resellers/accounts');
        }

        $document = $this->documents->find((int) ($params['statementId'] ?? 0));

        if ($document === null || (int) $document['reseller_id'] !== (int) $store['id']) {
            $this->session->flash(
                'reseller_error',
                'That statement does not exist, or does not belong to this store.'
            );

            return Response::redirect('/admin/resellers/' . $clientId . '/statement');
        }

        return $this->render('reseller.admin-statement-document', [
            'clientId' => $clientId,
            // The store is attached here rather than joined in the query, so the
            // document always renders under the store that was AUTHORISED above. A
            // join would let a mismatched row supply its own store and quietly
            // defeat the ownership check.
            'document' => $document + ['store' => $store],
            'baseCode' => $this->baseCurrencyCode(),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);
    }

    /**
     * The statement period from `?from=` and `?to=`, as a pair of datetimes.
     *
     * DEFAULTS TO THE CURRENT CALENDAR MONTH. The TO end is normalised to the LAST
     * DAY at 23:59:59, not to midnight: a period ending at '2026-08-31 00:00:00'
     * would silently omit entries posted during that final day.
     *
     * Anything unparseable — or a period that runs backwards — falls back to the
     * default rather than erroring. A bad query string should give you this month,
     * not a broken page; and a reversed period can only be a typo, so guessing at
     * what was meant is worse than showing the obvious month.
     *
     * @return array{0: string, 1: string}
     */
    private function period(Request $request): array
    {
        $defaultFrom = date('Y-m-01 00:00:00');
        $defaultTo = date('Y-m-t 23:59:59');

        $from = $this->normaliseDate($this->periodValue($request, 'from'), false);
        $to = $this->normaliseDate($this->periodValue($request, 'to'), true);

        if ($from !== null && $to !== null && $from > $to) {
            return [$defaultFrom, $defaultTo];
        }

        return [$from ?? $defaultFrom, $to ?? $defaultTo];
    }

    /**
     * A period bound, from the POST body or the query string.
     *
     * BOTH, body first, because the two pages that use a period disagree about where
     * it comes from: viewing the statement is a GET form (query string) and issuing
     * one is a POST form (body). `query()` and `input()` are separate sources with no
     * fallback between them, so reading only one would silently ignore the other —
     * and the failure is the quiet kind: the issue button would have frozen the
     * CURRENT month whatever period the admin had chosen, which looks exactly like it
     * worked.
     */
    private function periodValue(Request $request, string $key): string
    {
        $value = $request->input($key);

        if ($value === null || $value === '') {
            $value = $request->query($key);
        }

        return trim((string) ($value ?? ''));
    }

    /**
     * An ISO date from a query string, as a full datetime, or null if it is not a
     * date at all. The round-trip comparison is what catches createFromFormat's
     * habit of accepting overflow ('2026-02-31' becomes 3 March) instead of
     * failing: only a value that formats back to itself was unambiguous.
     */
    private function normaliseDate(string $value, bool $endOfDay): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
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
        $this->settings->set('reseller.payout_holding_days', (string) $holdingDays);

        $postedMinimums = $request->input('payout_minimums');
        $minimumSummary = '';

        if (is_array($postedMinimums)) {
            $values = [];

            foreach ($this->currency->all() as $currency) {
                $code = strtoupper(trim((string) ($currency['code'] ?? '')));
                if ($code === '') {
                    continue;
                }

                // The form's default is the current effective threshold, including
                // the legacy base-currency fallback. Missing or malformed fields
                // therefore preserve the existing rule rather than zeroing a
                // threshold because of a partial POST.
                $effective = $this->ledger->payoutMinimumForCurrency($currency);
                $raw = $postedMinimums[$code] ?? $effective['minimum'];
                $amount = $this->normaliseMinimum($raw, (float) $effective['minimum']);
                $values[$code] = number_format($amount, 2, '.', '');
            }

            $this->settings->set(
                'reseller.payout_minimums',
                (string) json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            $minimumSummary = count($values) . ' currency-specific minimum(s)';
        } elseif ($request->input('payout_minimum') !== null) {
            // Backward-compatible path for older admin forms/automation. This is
            // the base-unit fallback used by currencies without a specific row.
            $minimum = $this->normaliseMinimum($request->input('payout_minimum'), $this->ledger->payoutMinimum());
            $this->settings->set('reseller.payout_minimum', number_format($minimum, 2, '.', ''));
            $minimumSummary = 'base-currency fallback ' . number_format($minimum, 2);
        }

        $this->session->flash(
            'reseller_notice',
            'Payout settings saved: receipts become withdrawable after ' . $holdingDays . ' day(s)'
            . ($minimumSummary === '' ? '' : ', with ' . $minimumSummary . '.')
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
            'Set reseller payout holding period to ' . $holdingDays . ' day(s)'
                . ($minimumSummary === '' ? '' : '; ' . $minimumSummary),
            $request->ip()
        );

        return Response::redirect('/admin/resellers/accounts');
    }

    private function normaliseMinimum(mixed $value, float $fallback): float
    {
        if (!is_scalar($value) || !is_numeric($value)) {
            return max(0.0, $fallback);
        }

        $amount = (float) $value;

        if (!is_finite($amount)) {
            return max(0.0, $fallback);
        }

        return round(max(0.0, $amount), 2);
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

    private function canIssueStatement(): bool
    {
        return $this->guard->can(PermissionRegistry::RESELLERS_MANAGE)
            && $this->guard->can(PermissionRegistry::RESELLER_STATEMENTS_ISSUE);
    }

    private function requireStatementIssuePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->canIssueStatement()) {
            return Response::html(
                '403 Forbidden — issuing reseller statements requires resellers.manage and resellers.statements.issue',
                403
            );
        }

        return null;
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
