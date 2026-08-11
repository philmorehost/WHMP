<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Billing\VatLookupService;
use CodeVault\Billing\VatNumberValidator;
use CodeVault\Config;
use CodeVault\Gdpr\GdprRequestRepository;
use CodeVault\Modules\SecurityQuestionModuleService;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Security\PhpassHasher;
use CodeVault\Security\QrCode;
use CodeVault\Security\RecoveryCodes;
use CodeVault\Security\Totp;
use CodeVault\View;

/**
 * Client-area self-service: contact-details/password update, 2FA
 * enable/disable, and GDPR data export/erasure requests — deliberately
 * separate from the admin-side ClientController (which manages *other
 * people's* client records and requires staff permissions), since editing
 * your own account never needs a permission grant.
 */
final class ClientAccountController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly ClientRepository $clients,
        private readonly Totp $totp,
        private readonly RecoveryCodes $recoveryCodes,
        private readonly Config $config,
        private readonly GdprRequestRepository $gdprRequests,
        private readonly SecurityQuestionModuleService $securityQuestions,
        private readonly VatNumberValidator $vatValidator,
        private readonly VatLookupService $vatLookup,
        private readonly PhpassHasher $phpass
    ) {
    }

    public function profile(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        return $this->page('client-account.profile', [
            'client' => $client,
            'error' => null,
            'success' => null,
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $fields = [
            'email' => trim((string) $request->input('email', '')),
            'first_name' => trim((string) $request->input('first_name', '')),
            'last_name' => trim((string) $request->input('last_name', '')),
            'company_name' => trim((string) $request->input('company_name', '')) ?: null,
            'address1' => trim((string) $request->input('address1', '')) ?: null,
            'address2' => trim((string) $request->input('address2', '')) ?: null,
            'city' => trim((string) $request->input('city', '')) ?: null,
            'state' => trim((string) $request->input('state', '')) ?: null,
            'postcode' => trim((string) $request->input('postcode', '')) ?: null,
            'country' => trim((string) $request->input('country', '')) ?: null,
            'vat_number' => trim((string) $request->input('vat_number', '')) ?: null,
            'phone' => trim((string) $request->input('phone', '')) ?: null,
        ];

        if ($fields['email'] === '' || $fields['first_name'] === '' || $fields['last_name'] === '' || $fields['address1'] === null || $fields['city'] === null || $fields['postcode'] === null || $fields['phone'] === null) {
            return $this->page('client-account.profile', [
                'client' => $client,
                'error' => 'Email, first name, last name, street address, city, postcode, and phone are required.',
                'success' => null,
            ]);
        }

        $existing = $this->clients->findByEmail($fields['email']);

        if ($existing !== null && (int) $existing['id'] !== (int) $client['id']) {
            return $this->page('client-account.profile', [
                'client' => $client,
                'error' => 'That email address is already in use by another account.',
                'success' => null,
            ]);
        }

        if ($fields['vat_number'] !== $client['vat_number']) {
            $this->clients->clearVatVerification((int) $client['id']);
        }

        $this->clients->updateContactDetails((int) $client['id'], $fields);

        return $this->page('client-account.profile', [
            'client' => $this->clients->find((int) $client['id']),
            'error' => null,
            'success' => 'Your details have been updated.',
        ]);
    }

    /**
     * Client-scoped equivalent of the admin `ClientController::verifyVat()`
     * (R22) — deliberately reads the client's own id from the authenticated
     * session rather than a route param, so a client can never trigger a
     * VIES check against someone else's account. Unlike the admin action,
     * this checks `VatNumberValidator::isValidFormat()` before spending a
     * live VIES call, and always surfaces a message either way rather than
     * silently redirecting — a self-service action needs feedback the
     * admin's own internal tooling doesn't.
     */
    public function verifyVat(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $country = trim((string) ($client['country'] ?? ''));
        $vatNumber = trim((string) ($client['vat_number'] ?? ''));

        if ($country === '' || $vatNumber === '') {
            return $this->page('client-account.profile', [
                'client' => $client,
                'error' => 'Set a country and VAT number first, then verify.',
                'success' => null,
            ]);
        }

        if (!$this->vatValidator->isValidFormat($country, $vatNumber)) {
            return $this->page('client-account.profile', [
                'client' => $client,
                'error' => "That doesn't look like a valid VAT number for {$country}.",
                'success' => null,
            ]);
        }

        $result = $this->vatLookup->lookup($country, $vatNumber);

        if (!$result['checked']) {
            return $this->page('client-account.profile', [
                'client' => $client,
                'error' => 'Could not reach the VAT verification service right now — try again shortly.',
                'success' => null,
            ]);
        }

        $this->clients->recordVatVerification((int) $client['id'], $result['valid'], $result['name']);

        return $this->page('client-account.profile', [
            'client' => $this->clients->find((int) $client['id']),
            'error' => null,
            'success' => $result['valid'] ? 'VAT number verified.' : 'VIES reports this VAT number is not valid.',
        ]);
    }

    public function updatePassword(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $currentPassword = (string) $request->input('current_password', '');
        $newPassword = (string) $request->input('new_password', '');

        if (!$this->passwordMatches((string) $client['password_hash'], $currentPassword)) {
            return $this->page('client-account.profile', [
                'client' => $client,
                'error' => 'Current password is incorrect — your password was not changed.',
                'success' => null,
            ]);
        }

        if (strlen($newPassword) < 8) {
            return $this->page('client-account.profile', [
                'client' => $client,
                'error' => 'New password must be at least 8 characters.',
                'success' => null,
            ]);
        }

        $this->clients->updatePassword((int) $client['id'], $newPassword);

        return $this->page('client-account.profile', [
            'client' => $this->clients->find((int) $client['id']),
            'error' => null,
            'success' => 'Your password has been changed.',
        ]);
    }

    public function updateSecurityPin(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $currentPassword = (string) $request->input('current_password', '');
        $newPin = (string) $request->input('new_security_pin', '');

        if (!$this->passwordMatches((string) $client['password_hash'], $currentPassword)) {
            return $this->page('client-account.profile', [
                'client' => $client,
                'error' => 'Current password is incorrect — your Security PIN was not changed.',
                'success' => null,
            ]);
        }

        if (strlen($newPin) < 4) {
            return $this->page('client-account.profile', [
                'client' => $client,
                'error' => 'Security PIN must be at least 4 characters.',
                'success' => null,
            ]);
        }

        $this->clients->updateSecurityPin((int) $client['id'], $newPin);

        return $this->page('client-account.profile', [
            'client' => $this->clients->find((int) $client['id']),
            'error' => null,
            'success' => 'Your Security PIN has been updated.',
        ]);
    }

    public function security(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        return $this->page('client-account.security', [
            'client' => $client,
            'error' => null,
            'securityQuestionOptions' => $this->securityQuestions->activeCatalog(),
            'currentSecurityQuestion' => $this->securityQuestions->promptFor((int) $client['id']),
        ]);
    }

    public function setupSecurityQuestion(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $slug = (string) $request->input('slug', '');
        $answer = (string) $request->input('answer', '');

        $result = $this->securityQuestions->setup((int) $client['id'], $slug, $answer);

        return $this->page('client-account.security', [
            'client' => $client,
            'error' => $result['success'] ? null : $result['message'],
            'securityQuestionOptions' => $this->securityQuestions->activeCatalog(),
            'currentSecurityQuestion' => $this->securityQuestions->promptFor((int) $client['id']),
        ]);
    }

    public function clearSecurityQuestion(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $this->securityQuestions->clear((int) $client['id']);

        return Response::redirect('/client/account/security');
    }

    /** Generates a new pending secret + recovery codes; not enabled until confirm() succeeds. */
    public function enable(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $secret = $this->totp->generateSecret();
        $plainCodes = $this->recoveryCodes->generate();
        $provisioningUri = $this->totp->provisioningUri($secret, (string) $client['email'], (string) $this->config->env('APP_NAME', 'CodeVault'));

        $this->clients->pendingTwoFactorSecret((int) $client['id'], $secret, $this->recoveryCodes->hashForStorage($plainCodes));

        return $this->page('client-account.security-setup', [
            'secret' => $secret,
            'provisioningUri' => $provisioningUri,
            // Rendered here (not in the template) so the encoder — which
            // auto-picks the smallest version and best mask — is exercised
            // once per request. The SVG is embedded as a data: URI, which
            // the app's CSP allows under img-src data:.
            'qrSvg' => QrCode::svg($provisioningUri),
            'recoveryCodes' => $plainCodes,
            'error' => null,
        ]);
    }

    public function confirm(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        if ($client['two_factor_secret'] === null) {
            return Response::redirect('/client/account/security');
        }

        $code = trim((string) $request->input('code', ''));

        if (!$this->totp->verify((string) $client['two_factor_secret'], $code)) {
            return $this->page('client-account.security-setup', [
                'secret' => $client['two_factor_secret'],
                'provisioningUri' => $this->totp->provisioningUri((string) $client['two_factor_secret'], (string) $client['email'], (string) $this->config->env('APP_NAME', 'CodeVault')),
                'qrSvg' => QrCode::svg($this->totp->provisioningUri((string) $client['two_factor_secret'], (string) $client['email'], (string) $this->config->env('APP_NAME', 'CodeVault'))),
                'recoveryCodes' => null,
                'error' => 'That code did not verify — check the time on your device and try again.',
            ]);
        }

        $this->clients->confirmTwoFactor((int) $client['id']);

        return Response::redirect('/client/account/security');
    }

    public function disable(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $password = (string) $request->input('password', '');

        if (!$this->passwordMatches((string) $client['password_hash'], $password)) {
            return $this->page('client-account.security', [
                'client' => $client,
                'error' => 'Incorrect password — 2FA was not disabled.',
            ]);
        }

        $this->clients->disableTwoFactor((int) $client['id']);

        return Response::redirect('/client/account/security');
    }

    public function privacy(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        return $this->page('client-account.privacy', [
            'requests' => $this->gdprRequests->forClient((int) $client['id']),
            'error' => null,
        ]);
    }

    /** One pending request per type at a time — repeated clicks must not pile up duplicate requests for the same thing. */
    public function requestExport(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        if (!$this->hasPendingRequest((int) $client['id'], 'export')) {
            $this->gdprRequests->create((int) $client['id'], 'export');
        }

        return Response::redirect('/client/account/privacy');
    }

    public function requestErasure(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        if (!$this->hasPendingRequest((int) $client['id'], 'erasure')) {
            $this->gdprRequests->create((int) $client['id'], 'erasure');
        }

        return Response::redirect('/client/account/privacy');
    }

    public function downloadExport(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $gdprRequest = $this->gdprRequests->find((int) $params['id']);

        if (
            $gdprRequest === null
            || (int) $gdprRequest['client_id'] !== (int) $client['id']
            || $gdprRequest['type'] !== 'export'
            || $gdprRequest['status'] !== 'completed'
            || $gdprRequest['export_data'] === null
        ) {
            return Response::html('404 Not Found', 404);
        }

        $bytes = (string) $gdprRequest['export_data'];

        return (new Response($bytes, 200))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', "attachment; filename=\"data-export-{$gdprRequest['id']}.json\"")
            ->withHeader('Content-Length', (string) strlen($bytes));
    }

    private function hasPendingRequest(int $clientId, string $type): bool
    {
        foreach ($this->gdprRequests->forClient($clientId) as $existing) {
            if ($existing['type'] === $type && $existing['status'] === 'pending') {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifies a client's current password whether the stored hash is a
     * modern bcrypt/Argon2 (password_verify) or a legacy phpass hash
     * carried over from a WHMCS import. The stored hash is upgraded to
     * Argon2 at login; if a session predates that upgrade, this fallback
     * keeps account self-service working too.
     */
    private function passwordMatches(string $storedHash, string $plainPassword): bool
    {
        if ($storedHash === '') {
            return false;
        }

        if (password_verify($plainPassword, $storedHash)) {
            return true;
        }

        return $this->phpass->isPhpassHash($storedHash) && $this->phpass->verify($plainPassword, $storedHash);
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'CodeVault — My Account',
            'content' => $content,
        ]));
    }
}
