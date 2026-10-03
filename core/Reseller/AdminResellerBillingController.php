<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * What the stores owe us, and how that gets billed.
 *
 * This is the admin half of the reseller money path. Phase 3 charged the
 * customer retail and recorded what the order cost us; this page answers "so
 * what are we owed, and when do we ask for it" — accrued per store, split into
 * billed and unbilled, with the arrears list of cost invoices nobody has paid.
 *
 * It is a separate controller from AdminResellerController because it needs a
 * different set of collaborators (the billing job and the cost engine rather
 * than the credential service), and because adding to a controller that is
 * hand-built in tests is a blast radius with no upside here.
 *
 * The report is a READ. Writes are limited to the billing controls and terms,
 * plus an explicit "bill now" — and that button is safe to press twice, because idempotency is a
 * property of the data (the stamp on each order), not of the run.
 */
final class AdminResellerBillingController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly SettingsRepository $settings,
        private readonly ResellerCostService $costs,
        private readonly ResellerCostRepository $repository,
        private readonly ResellerCostBillingJob $billing,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $summaries = $this->costs->storeSummaries();
        $arrears = $this->costs->arrears();

        // Totals are grouped by currency, never summed across them: a store
        // billed in NGN and a store billed in USD have no common total, and a
        // single figure would be a number nobody could act on.
        $byCurrency = [];

        foreach ($summaries as $summary) {
            $code = (string) $summary['currency_code'];
            $byCurrency[$code] ??= ['accrued' => 0.0, 'unbilled' => 0.0, 'arrears' => 0.0];
            $byCurrency[$code]['accrued'] += (float) $summary['accrued'];
            $byCurrency[$code]['unbilled'] += (float) $summary['unbilled'];
        }

        foreach ($arrears as $row) {
            $code = (string) ($row['currency']['code'] ?? '');
            $byCurrency[$code] ??= ['accrued' => 0.0, 'unbilled' => 0.0, 'arrears' => 0.0];
            $byCurrency[$code]['arrears'] += (float) $row['total'];
        }

        ksort($byCurrency);

        return $this->render('reseller.admin-billing', [
            'summaries' => $summaries,
            'arrears' => $arrears,
            'byCurrency' => $byCurrency,
            'auto' => $this->settings->get('reseller.billing_auto', '1') === '1',
            'minimum' => (float) $this->settings->get('reseller.billing_minimum', '0.00'),
            'dueDays' => (int) $this->settings->get('reseller.billing_due_days', '7'),
            'billingPeriod' => $this->settings->get('reseller.billing_period', ResellerCostService::CADENCE_MONTHLY)
                === ResellerCostService::CADENCE_WEEKLY
                    ? ResellerCostService::CADENCE_WEEKLY
                    : ResellerCostService::CADENCE_MONTHLY,
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        // A negative minimum would make every period billable and a negative
        // term would date the invoice in the past, so both are floored here
        // rather than trusted from the form.
        $auto = (string) $request->input('billing_auto', '0') === '1';
        $minimum = max(0.0, (float) $request->input('billing_minimum', 0));
        $dueDays = max(0, (int) $request->input('billing_due_days', 7));
        $requestedPeriod = (string) $request->input(
            'billing_period',
            $this->settings->get('reseller.billing_period', ResellerCostService::CADENCE_MONTHLY)
        );
        $billingPeriod = $requestedPeriod === ResellerCostService::CADENCE_WEEKLY
            ? ResellerCostService::CADENCE_WEEKLY
            : ResellerCostService::CADENCE_MONTHLY;

        $this->settings->set('reseller.billing_auto', $auto ? '1' : '0');
        $this->settings->set('reseller.billing_minimum', number_format($minimum, 2, '.', ''));
        $this->settings->set('reseller.billing_due_days', (string) $dueDays);
        $this->settings->set('reseller.billing_period', $billingPeriod);

        $cadenceLabel = $billingPeriod === ResellerCostService::CADENCE_WEEKLY
            ? 'weekly (Monday–Sunday)'
            : 'monthly (calendar month)';
        $this->session->flash(
            'reseller_notice',
            'Cost billing saved: ' . ($auto ? $cadenceLabel . ', automatically' : 'manual only; next period ' . $cadenceLabel)
            . ', ' . number_format($minimum, 2) . ' minimum, ' . $dueDays . '-day terms.'
        );

        $this->activity->log(
            'admin',
            $this->adminId(),
            'reseller.billing.settings',
            null,
            null,
            'Set store cost billing to ' . ($auto ? 'auto' : 'manual')
                . ', ' . $billingPeriod . ', minimum ' . number_format($minimum, 2)
                . ', terms ' . $dueDays . ' days',
            $request->ip()
        );

        return Response::redirect('/admin/resellers/billing');
    }

    /**
     * Raise the cost invoices now rather than waiting for the nightly sweep.
     *
     * Reports the count it actually raised, computed from the data before and
     * after, so pressing it a second time answers "0 raised" honestly instead of
     * claiming to have billed again.
     */
    public function runNow(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $before = $this->repository->distinctCostInvoiceCount();
        $this->billing->billNow();
        $raised = $this->repository->distinctCostInvoiceCount() - $before;

        $this->session->flash(
            'reseller_notice',
            $raised === 0
                ? 'Cost billing ran — nothing new to invoice. Every eligible closed period is already billed.'
                : 'Cost billing ran — ' . $raised . ' invoice(s) raised. Pressing it again will not raise them twice.'
        );

        $this->activity->log(
            'admin',
            $this->adminId(),
            'reseller.billing.run',
            null,
            null,
            'Ran store cost billing manually: ' . $raised . ' invoice(s) raised',
            $request->ip()
        );

        return Response::redirect('/admin/resellers/billing');
    }

    /** The signed-in admin's id for the activity log, or null when there isn't one. */
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
            'title' => 'CodeVault Admin — Reseller billing',
            'content' => $content,
        ]));
    }
}
