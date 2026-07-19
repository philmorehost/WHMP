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
        private readonly ActivityLogger $activity
    ) {
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

        return $this->render('reports.income', [
            'year' => $year,
            'byMonth' => $this->reports->incomeByMonth($year),
            'byGateway' => $this->reports->incomeByGateway($year),
        ]);
    }

    public function taxLiability(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $year = $this->yearFromRequest($request);

        return $this->render('reports.tax-liability', [
            'year' => $year,
            'byMonth' => $this->reports->taxLiabilityByMonth($year),
        ]);
    }

    public function agedDebtors(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('reports.aged-debtors', ['buckets' => $this->reports->agedDebtors()]);
    }

    public function productBreakdown(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('reports.product-breakdown', ['products' => $this->reports->productBreakdown()]);
    }

    public function affiliatePayouts(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('reports.affiliate-payouts', ['affiliates' => $this->reports->affiliatePayouts()]);
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
