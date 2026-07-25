<?php

declare(strict_types=1);

namespace CodeVault\Configuration;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class GeneralSettingsController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly SettingsRepository $settings
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $content = $this->view->render('configuration.general', [
            'companyName' => $this->settings->get('company.name', ''),
            'companyEmail' => $this->settings->get('company.email', ''),
            'companyDept' => $this->settings->get('company.billing_dept', ''),
            'lateFeePercentage' => $this->settings->get('billing.late_fee_percentage', '5.00'),
            'newOrderDueDays' => $this->settings->get('billing.new_order_due_days', '7'),
            'allowCheckoutNotes' => $this->settings->get('checkout.allow_notes', '1') === '1',
            'maintenanceMode' => $this->settings->get('system.maintenance_mode', '0') === '1',
            'minCreditBalance' => $this->settings->get('billing.min_credit_balance', '0.00'),
            'minDeposit' => $this->settings->get('billing.min_deposit', '10.00'),
            'maxDeposit' => $this->settings->get('billing.max_deposit', '10000.00'),
            'minAffiliatePayout' => $this->settings->get('affiliates.min_payout', '50.00'),
            'ticketRatingEnabled' => $this->settings->get('support.ticket_rating_enabled', '1') === '1',
            'randomCpanelUsernames' => $this->settings->get('cpanel.random_usernames', '1') === '1',
            'smtpHost' => $this->settings->get('smtp.host', ''),
            'smtpPort' => $this->settings->get('smtp.port', '587'),
            'smtpUser' => $this->settings->get('smtp.user', ''),
            'smtpPass' => $this->settings->get('smtp.pass', ''),
            'smtpEncryption' => $this->settings->get('smtp.encryption', 'tls'),
            'smtpFromEmail' => $this->settings->get('smtp.from_email', ''),
            'smtpFromName' => $this->settings->get('smtp.from_name', ''),
            'saved' => $request->query('saved') === '1',
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — General Settings',
            'content' => $content,
        ]));
    }

    public function update(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->settings->set('company.name', trim((string) $request->input('company_name', '')));
        $this->settings->set('company.email', trim((string) $request->input('company_email', '')));
        $this->settings->set('company.billing_dept', trim((string) $request->input('company_billing_dept', '')));

        $this->settings->set('billing.late_fee_percentage', (string) max(0.0, (float) $request->input('late_fee_percentage', 5.0)));
        $this->settings->set('billing.new_order_due_days', (string) max(0, (int) $request->input('new_order_due_days', 7)));
        $this->settings->set('checkout.allow_notes', $request->input('allow_checkout_notes') ? '1' : '0');
        $this->settings->set('system.maintenance_mode', $request->input('maintenance_mode') ? '1' : '0');
        $this->settings->set('billing.min_credit_balance', (string) max(0.0, (float) $request->input('min_credit_balance', 0.0)));

        // Deposit bounds, in base currency like every other stored amount.
        // The maximum is floored at the minimum so a typo cannot save a range
        // that rejects every possible deposit; 0 means "no upper limit".
        $minDeposit = max(0.0, (float) $request->input('min_deposit', 10.0));
        $maxDeposit = (float) $request->input('max_deposit', 10000.0);

        if ($maxDeposit > 0 && $maxDeposit < $minDeposit) {
            $maxDeposit = $minDeposit;
        }

        $this->settings->set('billing.min_deposit', (string) $minDeposit);
        $this->settings->set('billing.max_deposit', (string) max(0.0, $maxDeposit));
        $this->settings->set('affiliates.min_payout', (string) max(0.0, (float) $request->input('min_affiliate_payout', 50.0)));
        $this->settings->set('support.ticket_rating_enabled', $request->input('ticket_rating_enabled') ? '1' : '0');
        $this->settings->set('cpanel.random_usernames', $request->input('random_cpanel_usernames') ? '1' : '0');

        $this->settings->set('smtp.host', trim((string) $request->input('smtp_host', '')));
        $this->settings->set('smtp.port', (string) max(1, (int) $request->input('smtp_port', 587)));
        $this->settings->set('smtp.user', trim((string) $request->input('smtp_user', '')));
        $this->settings->set('smtp.pass', (string) $request->input('smtp_pass', ''));
        $this->settings->set('smtp.encryption', trim((string) $request->input('smtp_encryption', 'tls')));
        $this->settings->set('smtp.from_email', trim((string) $request->input('smtp_from_email', '')));
        $this->settings->set('smtp.from_name', trim((string) $request->input('smtp_from_name', '')));

        return Response::redirect('/admin/settings/general?saved=1');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::SETTINGS_MANAGE)) {
            return Response::html('403 Forbidden — missing settings.manage permission', 403);
        }

        return null;
    }
}