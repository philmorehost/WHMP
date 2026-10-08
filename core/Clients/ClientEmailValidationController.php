<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Auth\AuthGuard;
use CodeVault\Mail\EmailSuppression;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class ClientEmailValidationController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ClientEmailValidationRepository $results,
        private readonly ClientEmailValidationService $scanner,
        private readonly ?EmailSuppression $blocker = null
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $blocker = null;

        if ($this->blocker !== null) {
            try {
                $blocker = $this->blocker->settings() + ['stats' => $this->blocker->stats(), 'can_toggle' => $this->guard->can(PermissionRegistry::ADDONS_MANAGE)];
            } catch (\Throwable) {
                $blocker = null;
            }
        }

        return $this->render([
            'results' => $this->results->all(),
            'summary' => $this->results->summary(),
            'scanned' => $request->query('scanned'),
            'dnsDown' => $request->query('dns') === 'down',
            'notice' => $request->query('notice'),
            'blocker' => $blocker,
        ]);
    }

    public function scan(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $outcome = $this->scanner->scanAll();

        if (!empty($outcome['aborted'])) {
            return Response::redirect('/admin/email-validation?dns=down');
        }

        return Response::redirect('/admin/email-validation?scanned=' . $outcome['invalid'] . '-' . $outcome['total']);
    }

    /** ON/OFF switch for the Invalid Email Blocker addon. */
    public function blocking(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        if ($this->blocker === null || !$this->guard->can(PermissionRegistry::ADDONS_MANAGE)) {
            return Response::html('403 Forbidden — missing addons.manage permission', 403);
        }

        $on = (string) $request->input('block', '0') === '1';
        $this->blocker->setBlocking($on);

        return Response::redirect('/admin/email-validation?notice=' . ($on ? 'blocking_on' : 'blocking_off'));
    }

    /** "Always send" for one address, or back to normal. */
    public function allow(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $email = trim((string) $request->input('email', ''));

        if ($this->blocker !== null && $email !== '') {
            if ((string) $request->input('allow', '1') === '1') {
                $this->blocker->allow($email);
                $notice = 'allowed';
            } else {
                $this->blocker->disallow($email);
                $notice = 'disallowed';
            }

            return Response::redirect('/admin/email-validation?notice=' . $notice);
        }

        return Response::redirect('/admin/email-validation');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::CLIENTS_MANAGE)) {
            return Response::html('403 Forbidden — missing clients.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(array $data): Response
    {
        $content = $this->view->render('clients.email-validation', $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Email Validation',
            'content' => $content,
        ]));
    }
}
