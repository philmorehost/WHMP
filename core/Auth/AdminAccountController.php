<?php

declare(strict_types=1);

namespace CodeVault\Auth;

use CodeVault\Config;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Security\QrCode;
use CodeVault\Security\RecoveryCodes;
use CodeVault\Security\Totp;
use CodeVault\View;

/**
 * Self-service account settings for the *currently logged-in* admin —
 * deliberately separate from StaffController (which manages other
 * admins and requires staff.manage) since enabling your own 2FA
 * shouldn't require a permission grant.
 */
final class AdminAccountController
{
    private const RECOVERY_CODES_FLASH_KEY = 'new_recovery_codes';

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly AdminRepository $admins,
        private readonly Totp $totp,
        private readonly RecoveryCodes $recoveryCodes,
        private readonly Config $config
    ) {
    }

    public function security(Request $request): Response
    {
        $admin = $this->guard->currentAdmin();

        if ($admin === null) {
            return Response::redirect('/login');
        }

        return $this->page('auth.account-security', [
            'admin' => $admin,
            'error' => null,
        ]);
    }

    /** Generates a new pending secret + recovery codes; not enabled until confirm() succeeds. */
    public function enable(Request $request): Response
    {
        $admin = $this->guard->currentAdmin();

        if ($admin === null) {
            return Response::redirect('/login');
        }

        $secret = $this->totp->generateSecret();
        $plainCodes = $this->recoveryCodes->generate();
        $provisioningUri = $this->totp->provisioningUri($secret, (string) $admin['email'], (string) $this->config->env('APP_NAME', 'CodeVault'));

        $this->admins->pendingTwoFactorSecret((int) $admin['id'], $secret, $this->recoveryCodes->hashForStorage($plainCodes));

        return $this->page('auth.account-security-setup', [
            'secret' => $secret,
            'provisioningUri' => $provisioningUri,
            // QR rendered in the controller (not the template) so the encoder
            // runs once per request; embedded as a data: URI under the app's
            // img-src data: CSP rule.
            'qrSvg' => QrCode::svg($provisioningUri),
            'recoveryCodes' => $plainCodes,
            'error' => null,
        ]);
    }

    public function confirm(Request $request): Response
    {
        $admin = $this->guard->currentAdmin();

        if ($admin === null) {
            return Response::redirect('/login');
        }

        if ($admin['two_factor_secret'] === null) {
            return Response::redirect('/admin/account/security');
        }

        $code = trim((string) $request->input('code', ''));

        if (!$this->totp->verify((string) $admin['two_factor_secret'], $code)) {
            $provisioningUri = $this->totp->provisioningUri((string) $admin['two_factor_secret'], (string) $admin['email'], (string) $this->config->env('APP_NAME', 'CodeVault'));

            return $this->page('auth.account-security-setup', [
                'secret' => $admin['two_factor_secret'],
                'provisioningUri' => $provisioningUri,
                'qrSvg' => QrCode::svg($provisioningUri),
                'recoveryCodes' => null,
                'error' => 'That code did not verify — check the time on your device and try again.',
            ]);
        }

        $this->admins->confirmTwoFactor((int) $admin['id']);

        return Response::redirect('/admin/account/security');
    }

    public function disable(Request $request): Response
    {
        $admin = $this->guard->currentAdmin();

        if ($admin === null) {
            return Response::redirect('/login');
        }

        $password = (string) $request->input('password', '');

        if (!password_verify($password, (string) $admin['password_hash'])) {
            return $this->page('auth.account-security', [
                'admin' => $admin,
                'error' => 'Incorrect password — 2FA was not disabled.',
            ]);
        }

        $this->admins->disableTwoFactor((int) $admin['id']);

        return Response::redirect('/admin/account/security');
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Account Security',
            'content' => $content,
        ]));
    }
}
