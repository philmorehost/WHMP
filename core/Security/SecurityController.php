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
        $appUrl = ($configuredUrl !== '' && !str_contains($configuredUrl, 'localhost')) ? $configuredUrl : $request->baseUrl();

        $content = $this->view->render('security.index', [
            'recentAttempts' => $this->attempts->recent(50),
            'ipRules' => $this->ipRules->all(),
            'countryRules' => $this->countryRules->all(),
            'accountLocks' => $this->accountLocks->activeLocks(),
            'twoFactorEnabled' => $this->settings->get('security.2fa_enabled', '1') === '1',
            'googleClientId' => $this->settings->get('auth.google_client_id', ''),
            'googleClientSecret' => $this->settings->get('auth.google_client_secret', ''),
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
        $this->settings->set('auth.google_client_id', trim((string) $request->input('google_client_id', '')));
        $this->settings->set('auth.google_client_secret', trim((string) $request->input('google_client_secret', '')));

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
