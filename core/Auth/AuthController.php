<?php

declare(strict_types=1);

namespace CodeVault\Auth;

use CodeVault\Config;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Security\PasswordResetToken;
use CodeVault\Security\PasswordResetTokenRepository;
use CodeVault\Security\RecoveryCodes;
use CodeVault\Security\Totp;
use CodeVault\Session\SessionManager;
use CodeVault\View;
use Throwable;

final class AuthController
{
    private const PENDING_2FA_SESSION_KEY = 'pending_2fa_admin_id';
    private const RESET_ACCOUNT_TYPE = 'admin';

    public function __construct(
        private readonly AuthManager $auth,
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly AdminRepository $admins,
        private readonly Totp $totp,
        private readonly RecoveryCodes $recoveryCodes,
        private readonly HookDispatcher $hooks,
        private readonly PasswordResetTokenRepository $resetTokens,
        private readonly PasswordResetToken $resetToken,
        private readonly EmailDispatcher $mail,
        private readonly Config $config
    ) {
    }

    public function loginForm(Request $request): Response
    {
        if ($this->guard->check()) {
            return Response::redirect('/admin');
        }

        return $this->page('auth.login', [
            'error' => null,
            'resetSuccess' => $request->query('reset') === 'success',
        ]);
    }

    public function login(Request $request): Response
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        $result = $this->auth->attempt($username, $password, $request->ip());

        if ($result->requiresTwoFactor()) {
            $this->session->set(self::PENDING_2FA_SESSION_KEY, $result->admin['id']);

            return Response::redirect('/login/2fa');
        }

        if ($result->isSuccess()) {
            $this->guard->login($result->admin);

            return Response::redirect('/admin');
        }

        $message = match ($result->status) {
            'locked' => 'This account is temporarily locked due to repeated failed attempts. <a href="/login/recover-pin" style="color:var(--cv-color-brand-500);text-decoration:underline;">Recover with Security PIN</a>',
            'blocked' => 'Access denied. <a href="/login/recover-pin" style="color:var(--cv-color-brand-500);text-decoration:underline;">Recover with Security PIN</a>',
            default => 'Invalid username or password.',
        };

        $status = $result->status === 'blocked' ? 403 : 200;

        return $this->page('auth.login', ['error' => $message], $status);
    }

    public function twoFactorForm(Request $request): Response
    {
        if ($this->pendingAdmin() === null) {
            return Response::redirect('/login');
        }

        return $this->page('auth.two-factor', ['error' => null]);
    }

    public function verifyTwoFactor(Request $request): Response
    {
        $admin = $this->pendingAdmin();

        if ($admin === null) {
            return Response::redirect('/login');
        }

        $code = trim((string) $request->input('code', ''));

        if ($this->totp->verify((string) $admin['two_factor_secret'], $code)) {
            return $this->completeTwoFactorLogin($admin);
        }

        $remainingCodes = $this->recoveryCodes->verifyAndConsume($code, $admin['two_factor_recovery_codes']);

        if ($remainingCodes !== null) {
            $this->admins->updateRecoveryCodes((int) $admin['id'], $remainingCodes);

            return $this->completeTwoFactorLogin($admin);
        }

        return $this->page('auth.two-factor', ['error' => 'Invalid code. Check your authenticator app or use a recovery code.']);
    }

    /** @param array<string, mixed> $admin */
    private function completeTwoFactorLogin(array $admin): Response
    {
        $this->session->remove(self::PENDING_2FA_SESSION_KEY);
        $this->guard->login($admin);
        $this->hooks->fire(HookPoints::ADMIN_LOGIN, ['adminId' => $admin['id'], 'username' => $admin['username']]);

        return Response::redirect('/admin');
    }

    /** @return array<string, mixed>|null */
    private function pendingAdmin(): ?array
    {
        $id = $this->session->get(self::PENDING_2FA_SESSION_KEY);

        return $id === null ? null : $this->admins->findById((int) $id);
    }

    public function logout(Request $request): Response
    {
        $this->guard->logout();

        return Response::redirect('/login');
    }

    public function recoverPinForm(Request $request): Response
    {
        if ($this->guard->check()) {
            return Response::redirect('/admin');
        }

        return $this->page('auth.recover-pin', ['error' => null, 'success' => false]);
    }

    public function recoverPin(Request $request): Response
    {
        if ($this->guard->check()) {
            return Response::redirect('/admin');
        }

        $username = trim((string) $request->input('username', ''));
        $pin = trim((string) $request->input('security_pin', ''));

        if ($username === '' || $pin === '') {
            return $this->page('auth.recover-pin', ['error' => 'Username and Security PIN are required.', 'success' => false]);
        }

        $isValid = $this->auth->verifySecurityPin($username, $pin, $request->ip());

        if (!$isValid) {
            return $this->page('auth.recover-pin', ['error' => 'Invalid username or Security PIN.', 'success' => false], 403);
        }

        return $this->page('auth.recover-pin', ['error' => null, 'success' => true]);
    }

    public function forgotPasswordForm(Request $request): Response
    {
        return $this->page('auth.forgot-password', ['sent' => false]);
    }

    /**
     * Always shows the same "if that email exists" confirmation whether or
     * not the account exists — telling a bad actor which admin emails are
     * registered is exactly the enumeration leak a reset-request endpoint
     * must not create.
     */
    public function sendResetLink(Request $request): Response
    {
        $email = trim((string) $request->input('email', ''));
        $admin = $email !== '' ? $this->admins->findByEmail($email) : null;

        if ($admin !== null) {
            $issued = $this->resetToken->generate();
            $this->resetTokens->issue(self::RESET_ACCOUNT_TYPE, (int) $admin['id'], $issued['hash']);

            $baseUrl = rtrim((string) $this->config->env('APP_URL', ''), '/');
            $resetUrl = "{$baseUrl}/login/password/reset/{$issued['token']}";

            try {
                $this->mail->sendTemplate('admin_password_reset', $admin['email'], [
                    'display_name' => $admin['display_name'],
                    'reset_url' => $resetUrl,
                    'company_name' => brand_name(),
                ]);
            } catch (Throwable) {
                // Template missing/misconfigured shouldn't leak via the response.
            }
        }

        return $this->page('auth.forgot-password', ['sent' => true]);
    }

    public function resetPasswordForm(Request $request, array $params): Response
    {
        $tokenRow = $this->resetTokens->findValid(self::RESET_ACCOUNT_TYPE, $this->resetToken->hash((string) $params['token']));

        if ($tokenRow === null) {
            return $this->page('auth.reset-password-invalid', []);
        }

        return $this->page('auth.reset-password', ['token' => $params['token'], 'error' => null]);
    }

    public function resetPassword(Request $request, array $params): Response
    {
        $token = (string) $params['token'];
        $tokenRow = $this->resetTokens->findValid(self::RESET_ACCOUNT_TYPE, $this->resetToken->hash($token));

        if ($tokenRow === null) {
            return $this->page('auth.reset-password-invalid', []);
        }

        $newPassword = (string) $request->input('new_password', '');

        if (strlen($newPassword) < 8) {
            return $this->page('auth.reset-password', ['token' => $token, 'error' => 'Password must be at least 8 characters.']);
        }

        $this->admins->updatePassword((int) $tokenRow['account_id'], $newPassword);
        $this->resetTokens->consume((int) $tokenRow['id']);

        return Response::redirect('/login?reset=success');
    }

    private function page(string $template, array $data, int $status = 200): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.installer', [
            'title' => 'CodeVault — Login',
            'content' => $content,
        ]), $status);
    }
}
