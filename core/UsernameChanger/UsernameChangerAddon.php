<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Modules\AddonModule;
use Throwable;

/**
 * cPanel Username Changer — the on/off switch (Addons → cPanel Username Changer).
 *
 * Off until the super admin activates it. While off, no client, reseller or admin
 * screen shows it and every endpoint answers 404. The working screens live on
 * AdminUsernameController (/admin/username-changer); this page summarises and
 * links there, the same split DomainChangerAddon uses.
 *
 * Payment listeners are registered by the Kernel, not by hooks(), on purpose: an
 * invoice raised while the add-on was on must still advance its request and pay
 * the resellers their margin if it is paid after the add-on is switched off.
 */
final class UsernameChangerAddon implements AddonModule
{
    public function __construct(
        private readonly ?UsernameChangeRepository $requests = null,
        private readonly ?UsernameChangerSettings $settings = null
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'cPanel Username Changer',
            'description' => 'Self-service cPanel username changes for every customer — including reseller stores and their customers — with live availability, email/PIN confirmation, optional approval, optional paid changes with reseller resale margins, and a full audit trail.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [];
    }

    /** @return array{success: bool, message: string} */
    public function activate(): array
    {
        return ['success' => true, 'message' => 'cPanel Username Changer activated. Review the rules under Addons → cPanel Username Changer → Settings — changes are free unless you switch payment on.'];
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(): array
    {
        return ['success' => true, 'message' => 'cPanel Username Changer deactivated — the option is hidden everywhere. Open requests are kept and resume if you re-activate.'];
    }

    public function hooks(): array
    {
        return [];
    }

    public function render(array $params): string
    {
        $counts = [];
        $feeLine = 'Changes are free.';

        try {
            $counts = $this->requests?->countsByStatus() ?? [];

            if ($this->settings?->feeEnabled()) {
                $feeLine = 'Payment is ON — fee ' . number_format($this->settings->fee(), 2) . ($this->settings->storePricing() ? ' (resellers may resell at their own price).' : '.');
            }
        } catch (Throwable) {
        }

        $pending = (int) ($counts['pending_approval'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $done = (int) ($counts['completed'] ?? 0);

        return '<div class="cv-card" style="padding:1.25rem">'
            . '<p>' . e($feeLine) . '</p>'
            . '<p><strong>' . $pending . '</strong> awaiting approval · <strong>' . $failed . '</strong> failed · <strong>' . $done . '</strong> completed</p>'
            . '<p style="display:flex;gap:.5rem;flex-wrap:wrap">'
            . '<a class="cv-btn" href="/admin/username-changer">Open dashboard</a>'
            . '<a class="cv-btn cv-btn--secondary" href="/admin/username-changer/settings">Settings</a>'
            . '<a class="cv-btn cv-btn--secondary" href="/admin/username-changer/manual">Manual change</a>'
            . '</p></div>';
    }
}
