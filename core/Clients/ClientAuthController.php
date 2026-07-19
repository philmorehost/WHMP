<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Affiliates\AffiliateService;
use CodeVault\Config;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Modules\SecurityQuestionModuleService;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Security\PasswordResetToken;
use CodeVault\Security\PasswordResetTokenRepository;
use CodeVault\Security\RecoveryCodes;
use CodeVault\Security\Totp;
use CodeVault\Session\SessionManager;
use CodeVault\View;
use Throwable;

final class ClientAuthController
{
    private const PENDING_2FA_SESSION_KEY = 'pending_2fa_client_id';
    private const RESET_ACCOUNT_TYPE = 'client';

    public function __construct(
        private readonly ClientAuthManager $auth,
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly AffiliateService $affiliateService,
        private readonly SessionManager $session,
        private readonly ClientRepository $clients,
        private readonly Totp $totp,
        private readonly RecoveryCodes $recoveryCodes,
        private readonly PasswordResetTokenRepository $resetTokens,
        private readonly PasswordResetToken $resetToken,
        private readonly EmailDispatcher $mail,
        private readonly Config $config,
        private readonly SecurityQuestionModuleService $securityQuestions
    ) {
    }

    public function loginForm(Request $request): Response
    {
        if ($this->guard->check()) {
            return Response::redirect('/client/dashboard');
        }

        return $this->page('client-auth.login', [
            'error' => null,
            'resetSuccess' => $request->query('reset') === 'success',
        ]);
    }

    public function login(Request $request): Response
    {
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        $result = $this->auth->attempt($email, $password, $request->ip());

        if ($result->requiresTwoFactor()) {
            $this->session->set(self::PENDING_2FA_SESSION_KEY, $result->client['id']);

            return Response::redirect('/client/login/2fa');
        }

        if ($result->isSuccess()) {
            $this->guard->login($result->client);

            return Response::redirect('/client/dashboard');
        }

        $message = $result->status === 'blocked' ? 'Access denied.' : 'Invalid email or password.';
        $status = $result->status === 'blocked' ? 403 : 200;

        return $this->page('client-auth.login', ['error' => $message], $status);
    }

    public function twoFactorForm(Request $request): Response
    {
        if ($this->pendingClient() === null) {
            return Response::redirect('/client/login');
        }

        return $this->page('client-auth.two-factor', ['error' => null]);
    }

    public function verifyTwoFactor(Request $request): Response
    {
        $client = $this->pendingClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $code = trim((string) $request->input('code', ''));

        if ($this->totp->verify((string) $client['two_factor_secret'], $code)) {
            return $this->completeTwoFactorLogin($client);
        }

        $remainingCodes = $this->recoveryCodes->verifyAndConsume($code, $client['two_factor_recovery_codes']);

        if ($remainingCodes !== null) {
            $this->clients->updateRecoveryCodes((int) $client['id'], $remainingCodes);

            return $this->completeTwoFactorLogin($client);
        }

        return $this->page('client-auth.two-factor', ['error' => 'Invalid code. Check your authenticator app or use a recovery code.']);
    }

    /** @param array<string, mixed> $client */
    private function completeTwoFactorLogin(array $client): Response
    {
        $this->session->remove(self::PENDING_2FA_SESSION_KEY);
        $this->guard->login($client);

        return Response::redirect('/client/dashboard');
    }

    /** @return array<string, mixed>|null */
    private function pendingClient(): ?array
    {
        $id = $this->session->get(self::PENDING_2FA_SESSION_KEY);

        return $id === null ? null : $this->clients->find((int) $id);
    }

    public function registerForm(Request $request): Response
    {
        if ($this->guard->check()) {
            return Response::redirect('/client/dashboard');
        }

        return $this->page('client-auth.register', ['error' => null, 'refCode' => (string) $request->query('ref', '')]);
    }

    public function register(Request $request): Response
    {
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $firstName = trim((string) $request->input('first_name', ''));
        $lastName = trim((string) $request->input('last_name', ''));
        $refCode = trim((string) $request->input('ref', ''));
        $country = strtoupper(trim((string) $request->input('country', '')));
        $vatNumber = trim((string) $request->input('vat_number', ''));

        if ($email === '' || $firstName === '' || $lastName === '') {
            return $this->page('client-auth.register', ['error' => 'Email, first name, and last name are required.', 'refCode' => $refCode]);
        }

        $result = $this->auth->register($email, $password, $firstName, $lastName, $request->ip(), $country, $vatNumber);

        if (!$result['success']) {
            return $this->page('client-auth.register', ['error' => $result['error'], 'refCode' => $refCode]);
        }

        $this->affiliateService->registerReferral($refCode, (int) $result['client']['id']);
        $this->guard->login($result['client']);

        return Response::redirect('/client/dashboard');
    }

    public function logout(Request $request): Response
    {
        $this->guard->logout();

        return Response::redirect('/client/login');
    }

    public function forgotPasswordForm(Request $request): Response
    {
        return $this->page('client-auth.forgot-password', ['sent' => false]);
    }

    /**
     * Always shows the same "if that email exists" confirmation whether or
     * not the account exists — telling a bad actor which emails are
     * registered is exactly the enumeration leak a reset-request endpoint
     * must not create.
     */
    public function sendResetLink(Request $request): Response
    {
        $email = trim((string) $request->input('email', ''));
        $client = $email !== '' ? $this->clients->findByEmail($email) : null;

        if ($client !== null) {
            $issued = $this->resetToken->generate();
            $this->resetTokens->issue(self::RESET_ACCOUNT_TYPE, (int) $client['id'], $issued['hash']);

            $baseUrl = rtrim((string) $this->config->env('APP_URL', ''), '/');
            $resetUrl = "{$baseUrl}/client/password/reset/{$issued['token']}";

            try {
                $this->mail->sendTemplate('client_password_reset', $client['email'], [
                    'first_name' => $client['first_name'],
                    'reset_url' => $resetUrl,
                    'company_name' => (string) $this->config->env('APP_NAME', 'CodeVault'),
                ], (int) $client['id']);
            } catch (Throwable) {
                // Template missing/misconfigured shouldn't leak via the response.
            }
        }

        return $this->page('client-auth.forgot-password', ['sent' => true]);
    }

    public function resetPasswordForm(Request $request, array $params): Response
    {
        $tokenRow = $this->resetTokens->findValid(self::RESET_ACCOUNT_TYPE, $this->resetToken->hash((string) $params['token']));

        if ($tokenRow === null) {
            return $this->page('client-auth.reset-password-invalid', []);
        }

        return $this->page('client-auth.reset-password', [
            'token' => $params['token'],
            'error' => null,
            'securityQuestion' => $this->securityQuestions->promptFor((int) $tokenRow['account_id']),
        ]);
    }

    /**
     * A valid reset token proves email possession ("something you have").
     * When a client has opted into a security question, this adds
     * "something you know" on top of that — real defense-in-depth for the
     * case where the reset link itself is what's compromised (a hijacked
     * inbox), not a replacement for the token. Clients who never set one up
     * see no change at all.
     */
    public function resetPassword(Request $request, array $params): Response
    {
        $token = (string) $params['token'];
        $tokenRow = $this->resetTokens->findValid(self::RESET_ACCOUNT_TYPE, $this->resetToken->hash($token));

        if ($tokenRow === null) {
            return $this->page('client-auth.reset-password-invalid', []);
        }

        $accountId = (int) $tokenRow['account_id'];
        $securityQuestion = $this->securityQuestions->promptFor($accountId);

        if ($securityQuestion !== null) {
            $answer = (string) $request->input('security_answer', '');

            if (!$this->securityQuestions->verify($accountId, $answer)) {
                return $this->page('client-auth.reset-password', [
                    'token' => $token,
                    'error' => 'That answer did not match — your password was not changed.',
                    'securityQuestion' => $securityQuestion,
                ]);
            }
        }

        $newPassword = (string) $request->input('new_password', '');

        if (strlen($newPassword) < 8) {
            return $this->page('client-auth.reset-password', [
                'token' => $token,
                'error' => 'Password must be at least 8 characters.',
                'securityQuestion' => $securityQuestion,
            ]);
        }

        $this->clients->updatePassword($accountId, $newPassword);
        $this->resetTokens->consume((int) $tokenRow['id']);

        return Response::redirect('/client/login?reset=success');
    }

    public function returnToAdmin(Request $request): Response
    {
        $originalAdminId = $this->session->get('original_admin_id');

        if ($originalAdminId !== null) {
            $this->session->set('admin_id', $originalAdminId);
            $this->session->remove('original_admin_id');
            $this->session->remove('client_id');
            return Response::redirect('/admin/clients');
        }

        return Response::redirect('/client/dashboard');
    }

    private function page(string $template, array $data, int $status = 200): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'CodeVault',
            'content' => $content,
        ]), $status);
    }
}
