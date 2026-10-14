<?php

declare(strict_types=1);

namespace CodeVault\Security;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;
use DateTimeImmutable;

/**
 * BruteGuard admin panel (blueprint §5): live log of recent attempts,
 * current IP/country rules, locked accounts, and manual override controls.
 */
final class SecurityController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly LoginAttemptRepository $attempts,
        private readonly IpRuleRepository $ipRules,
        private readonly CountryRuleRepository $countryRules,
        private readonly AccountLockRepository $accountLocks,
        private readonly \CodeVault\Settings\SettingsRepository $settings
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        $configuredUrl = (string) \CodeVault\Support\App::container()->make(\CodeVault\Config::class)->env('APP_URL', '');
        $appUrl = \CodeVault\Clients\GoogleSignIn::platformBaseUrl($configuredUrl, $request->baseUrl());

        $content = $this->view->render('security.index', [
            'recentAttempts' => $this->attempts->recent(50),
            'ipRules' => $this->ipRules->all(),
            'countryRules' => $this->countryRules->all(),
            'accountLocks' => $this->accountLocks->activeLocks(),
            'twoFactorEnabled' => $this->settings->get('security.2fa_enabled', '1') === '1',
            'googleClientId' => $this->settings->get(\CodeVault\Clients\GoogleSignIn::KEY_CLIENT_ID, ''),
            // Never the secret itself: a password field pre-filled with it would put it
            // in the page source of every admin screen load.
            'googleHasSecret' => trim((string) $this->settings->get(\CodeVault\Clients\GoogleSignIn::KEY_CLIENT_SECRET, '')) !== '',
            'googleResellersAllowed' => (string) $this->settings->get(\CodeVault\Clients\GoogleSignIn::KEY_RESELLERS_ALLOWED, '1') !== '0',
            'appUrl' => $appUrl,
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Security Settings',
            'content' => $content,
        ]));
    }

    public function updateAuthSettings(Request $request): Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        $this->settings->set('security.2fa_enabled', (string) $request->input('two_factor_enabled', '') === '1' ? '1' : '0');
        $this->settings->set(\CodeVault\Clients\GoogleSignIn::KEY_CLIENT_ID, trim((string) $request->input('google_client_id', '')));

        // The secret is never shown back, so a blank field means "keep the saved one";
        // the tick-box is how it is deleted.
        $secret = trim((string) $request->input('google_client_secret', ''));

        if ((string) $request->input('google_clear_secret', '') === '1') {
            $this->settings->set(\CodeVault\Clients\GoogleSignIn::KEY_CLIENT_SECRET, '');
        } elseif ($secret !== '') {
            $this->settings->set(\CodeVault\Clients\GoogleSignIn::KEY_CLIENT_SECRET, $secret);
        }

        $this->settings->set(
            \CodeVault\Clients\GoogleSignIn::KEY_RESELLERS_ALLOWED,
            (string) $request->input('google_resellers_allowed', '') === '1' ? '1' : '0'
        );

        return Response::redirect('/admin/security');
    }

    public function addIpRule(Request $request): Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        $ip = trim((string) $request->input('ip_address', ''));
        $action = (string) $request->input('action', 'block');
        $reason = trim((string) $request->input('reason', '')) ?: 'Manual rule.';
        $adminId = (int) $this->guard->currentAdmin()['id'];

        if ($ip !== '') {
            if ($action === 'whitelist') {
                $this->ipRules->whitelist($ip, $reason, 'manual', $adminId);
            } else {
                $this->ipRules->blacklist($ip, $reason, 'manual', $adminId);
            }
        }

        return Response::redirect('/admin/security');
    }

    public function removeIpRule(Request $request): Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        $ip = trim((string) $request->input('ip_address', ''));

        if ($ip !== '') {
            $this->ipRules->clear($ip);
        }

        return Response::redirect('/admin/security');
    }

    public function setCountryRule(Request $request): Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        $country = trim((string) $request->input('country_code', ''));
        $policy = (string) $request->input('policy', 'not_specified');

        if ($country !== '' && strlen($country) === 2) {
            $this->countryRules->setPolicy($country, $policy);
        }

        return Response::redirect('/admin/security');
    }

    public function unlockAccount(Request $request): Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        $adminId = (int) $request->input('admin_id', 0);

        if ($adminId > 0) {
            $this->accountLocks->unlock($adminId);
        }

        return Response::redirect('/admin/security');
    }
}
