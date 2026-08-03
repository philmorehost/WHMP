<?php

declare(strict_types=1);

namespace CodeVault\Reports;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Modules\ReportModuleService;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;
use DateTimeImmutable;

final class ReportController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ReportRepository $reports,
        private readonly ReportModuleService $modules,
        private readonly ActivityLogger $activity,
        private readonly \CodeVault\Billing\CurrencyService $currency
    ) {
    }

    /**
     * Attaches the real symbol/code for each row's currency.
     *
     * A NULL currency_id means the base currency, resolved the same way every
     * other screen resolves it. Every report template used to print a
     * hardcoded "$" against these figures, which is simply wrong on an install
     * whose base currency is anything else — ₦7,501.50 read as "$7,501.50".
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function labelCurrencies(array $rows): array
    {
        return array_map(function (array $row): array {
            // Cast here rather than trusting the caller. Some rows are built in
            // PHP with a real int, others come straight off a `SELECT i.*`
            // where PDO hands back every column as a string — passing one of
            // those to resolveLocked(?int) is a fatal TypeError, which is
            // exactly what took the aged-debtors report down. '' and '0' both
            // mean "no currency locked", i.e. the base currency.
            $rawId = $row['currency_id'] ?? null;
            $currencyId = ($rawId === null || $rawId === '' || (int) $rawId === 0) ? null : (int) $rawId;

            $currency = $this->currency->resolveLocked($currencyId);

            // Prefixed keys, not 'symbol'/'code': the affiliate rows already
            // carry a 'code' of their own (the referral code), and PHP's array
            // union keeps the left-hand value — so an unprefixed key would be
            // silently dropped there and print an empty currency.
            return $row + [
                'currency_symbol' => (string) ($currency['symbol'] ?? '$'),
                'currency_code' => (string) ($currency['code'] ?? ''),
            ];
        }, $rows);
    }

    /**
     * Per-currency grand totals for a report's footer.
     *
     * Deliberately a list, not a single number: summing across currencies
     * produces a figure that means nothing, so a multi-currency report shows
     * one total per currency instead of one wrong one.
     *
     * @param array<int, array<string, mixed>> $rows already passed through labelCurrencies()
     * @return array<int, array{currency_symbol: string, currency_code: string, amount: float}>
     */
    private function totalsByCurrency(array $rows, string $amountKey): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $key = (string) ($row['currency_code'] ?? '');
            $totals[$key] ??= [
                'currency_symbol' => (string) $row['currency_symbol'],
                'currency_code' => $key,
                'amount' => 0.0,
            ];
            $totals[$key]['amount'] += (float) ($row[$amountKey] ?? 0);
        }

        return array_values($totals);
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('reports.index', ['customReports' => $this->modules->catalog()]);
    }

    public function activateModule(Request $request, array $params): Response
    {
        if ($denied = $this->requireManagePermission()) {
            return $denied;
        }

        $slug = (string) $params['slug'];
        $result = $this->modules->activate($slug);

        if ($result['success']) {
            $adminId = (int) $this->guard->currentAdmin()['id'];
            $this->activity->log('admin', $adminId, 'report_module.activated', 'report_module', 0, "Activated report [{$slug}]", $request->ip());
        }

        return Response::redirect('/admin/reports');
    }

    public function deactivateModule(Request $request, array $params): Response
    {
        if ($denied = $this->requireManagePermission()) {
            return $denied;
        }

        $slug = (string) $params['slug'];
        $result = $this->modules->deactivate($slug);

        if ($result['success']) {
            $adminId = (int) $this->guard->currentAdmin()['id'];
            $this->activity->log('admin', $adminId, 'report_module.deactivated', 'report_module', 0, "Deactivated report [{$slug}]", $request->ip());
        }

        return Response::redirect('/admin/reports');
    }

    public function runModule(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $slug = (string) $params['slug'];
        $module = $this->modules->find($slug);
        $filters = $module?->filters() ?? [];

        $values = [];
        foreach (array_keys($filters) as $key) {
            $values[$key] = $request->query($key, '');
        }

        $result = $this->modules->run($slug, $values);

        return $this->render('reports.module', [
            'slug' => $slug,
            'module' => $module,
            'filters' => $filters,
            'values' => $values,
            'result' => $result,
        ]);
    }

    public function income(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $year = $this->yearFromRequest($request);

        $byMonth = $this->labelCurrencies($this->reports->incomeByMonth($year));
        $byGateway = $this->labelCurrencies($this->reports->incomeByGateway($year));

        return $this->render('reports.income', [
            'year' => $year,
            'byMonth' => $byMonth,
            'byGateway' => $byGateway,
            'monthTotals' => $this->totalsByCurrency($byMonth, 'total'),
            'gatewayTotals' => $this->totalsByCurrency($byGateway, 'total'),
        ]);
    }

    public function taxLiability(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $year = $this->yearFromRequest($request);
        $byMonth = $this->labelCurrencies($this->reports->taxLiabilityByMonth($year));

        return $this->render('reports.tax-liability', [
            'year' => $year,
            'byMonth' => $byMonth,
            'totals' => $this->totalsByCurrency($byMonth, 'tax_amount'),
        ]);
    }

    public function agedDebtors(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $buckets = $this->reports->agedDebtors();

        foreach ($buckets as $key => $bucket) {
            $buckets[$key]['totals'] = $this->labelCurrencies($bucket['totals']);
            $buckets[$key]['invoices'] = $this->labelCurrencies($bucket['invoices']);
        }

        return $this->render('reports.aged-debtors', ['buckets' => $buckets]);
    }

    public function productBreakdown(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $products = $this->labelCurrencies($this->reports->productBreakdown());

        return $this->render('reports.product-breakdown', [
            'products' => $products,
            'totals' => $this->totalsByCurrency($products, 'revenue'),
        ]);
    }

    public function affiliatePayouts(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('reports.affiliate-payouts', [
            'affiliates' => $this->labelCurrencies($this->reports->affiliatePayouts()),
        ]);
    }

    private function yearFromRequest(Request $request): int
    {
        $year = (int) $request->query('year', 0);

        return $year > 0 ? $year : (int) (new DateTimeImmutable())->format('Y');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::REPORTS_VIEW)) {
            return Response::html('403 Forbidden — missing reports.view permission', 403);
        }

        return null;
    }

    private function requireManagePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::REPORTS_MANAGE)) {
            return Response::html('403 Forbidden — missing reports.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Reports',
            'content' => $content,
        ]));
    }
}
