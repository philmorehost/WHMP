<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * Admin management of standalone recurring invoices (the "make this invoice
 * recur" option on /admin/invoices/create). Lists every recurring template
 * with its next due date, and lets an admin pause / resume / cancel it —
 * a paused or cancelled template is simply skipped by the cron sweep.
 */
final class RecurringInvoiceController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly RecurringInvoiceRepository $recurring,
        private readonly RecurringInvoiceService $service,
        private readonly CurrencyService $currency,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $rows = $this->recurring->all();
        $currencies = [];

        foreach ($rows as $row) {
            // NULL currency_id means base currency; resolveLocked() handles it.
            $currencies[$row['id']] = $this->currency->resolveLocked($row['currency_id'] !== null ? (int) $row['currency_id'] : null);
        }

        return $this->render('billing.recurring-invoices-index', [
            'recurring' => $rows,
            'currencies' => $currencies,
            'created' => $request->query('created') !== null ? (int) $request->query('created') : null,
            'paused' => $request->query('paused') === '1',
            'resumed' => $request->query('resumed') === '1',
            'cancelled' => $request->query('cancelled') === '1',
            'error' => $request->query('error'),
        ]);
    }

    /** POST /admin/recurring-invoices/{id}/status — pause | resume | cancel */
    public function setStatus(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $row = $this->recurring->find($id);

        if ($row === null) {
            return Response::redirect('/admin/recurring-invoices?error=' . urlencode('Recurring invoice not found.'));
        }

        $action = (string) $request->input('action', '');
        $status = match ($action) {
            'pause' => 'paused',
            'cancel' => 'cancelled',
            'resume' => 'active',
            default => null,
        };

        if ($status === null) {
            return Response::redirect('/admin/recurring-invoices?error=' . urlencode('Invalid action.'));
        }

        $this->recurring->setStatus($id, $status);

        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            'recurring_invoice.status',
            'invoice',
            (int) ($row['last_invoice_id'] ?? null),
            "Set recurring invoice #{$id} (client #{$row['client_id']}) to {$status}",
            $request->ip()
        );

        $notice = match ($status) {
            'paused' => 'paused=1',
            'cancelled' => 'cancelled=1',
            default => 'resumed=1',
        };

        return Response::redirect('/admin/recurring-invoices?' . $notice);
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::INVOICES_MANAGE)) {
            return Response::html('403 Forbidden — missing invoices.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Recurring Invoices',
            'content' => $content,
        ]));
    }
}
