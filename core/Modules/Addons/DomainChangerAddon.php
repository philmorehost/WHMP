<?php

declare(strict_types=1);

namespace CodeVault\Modules\Addons;

use CodeVault\Database;
use CodeVault\Modules\AddonModule;

/**
 * Domain Name Changer — lets a client rename the primary domain on their own
 * shared cPanel hosting service, via a real WHM API call (modifyacct), no
 * ticket required.
 *
 * The addon itself is the admin-facing toggle + audit page, per the
 * AddonModule SDK's shape. The client self-service surface — the button and
 * form on My Services — lives in ClientServiceController::changeDomain(),
 * gated by checking AddonModuleRepository::isActive('domain-changer') before
 * showing the UI or accepting the request, since AddonModule::render() only
 * has a hook into the admin area, not the client one.
 *
 * The actual API call reuses the existing provisioning stack exactly —
 * CpanelProvisioningModule::changeDomain() (WHM modifyacct) dispatched
 * through ProvisioningService::changeDomain(), the same server-selection and
 * auth plumbing suspend/unsuspend/create already go through. This addon adds
 * no new server-communication code of its own.
 */
final class DomainChangerAddon implements AddonModule
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Domain Name Changer',
            'description' => 'Self-service primary domain changes for shared cPanel hosting, via the real cPanel/WHM API (modifyacct) across every connected server.',
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
        return ['success' => true, 'message' => 'Domain Name Changer activated — clients on shared cPanel hosting can now change their own primary domain from My Services.'];
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(): array
    {
        return ['success' => true, 'message' => 'Domain Name Changer deactivated — the "Change Domain" action is hidden from clients until re-activated.'];
    }

    public function hooks(): array
    {
        return [];
    }

    public function render(array $params): string
    {
        $cpanelServers = $this->cpanelServers();
        $recentChanges = $this->recentChanges();

        $serverRows = '';
        if ($cpanelServers === []) {
            $serverRows = '<tr><td colspan="2">No cPanel/WHM servers are configured yet — add one under Provisioning → Servers.</td></tr>';
        } else {
            foreach ($cpanelServers as $server) {
                $badge = ((int) $server['active']) === 1
                    ? '<span class="cv-badge cv-badge--success">Active</span>'
                    : '<span class="cv-badge cv-badge--neutral">Inactive</span>';
                $serverRows .= '<tr><td>' . e((string) $server['name']) . ' (' . e((string) $server['hostname']) . ')</td><td>' . $badge . '</td></tr>';
            }
        }

        $changeRows = '';
        if ($recentChanges === []) {
            $changeRows = '<tr><td colspan="4">No domain changes recorded yet.</td></tr>';
        } else {
            foreach ($recentChanges as $change) {
                $changeRows .= '<tr>'
                    . '<td>' . e((string) $change['created_at']) . '</td>'
                    . '<td>#' . (int) $change['subject_id'] . '</td>'
                    . '<td>' . e((string) $change['actor_type']) . ' #' . (int) $change['actor_id'] . '</td>'
                    . '<td>' . e((string) $change['description']) . '</td>'
                    . '</tr>';
            }
        }

        return <<<HTML
        <div class="cv-card" style="margin-bottom: var(--cv-space-4);">
            <h3 class="cv-card__title">What this does</h3>
            <p style="color:var(--cv-text-secondary);">
                Adds a "Change Domain" action to My Services for any active shared-hosting service provisioned on a
                cPanel/WHM server. The client enters the new domain, WHMP calls WHM's <code>modifyacct</code> function
                against that service's own server, and — only once WHM confirms the rename — the service's stored
                domain is updated to match. No support ticket, no manual WHM login required.
            </p>
        </div>
        <div class="cv-card" style="margin-bottom: var(--cv-space-4);">
            <h3 class="cv-card__title">cPanel/WHM servers this reaches</h3>
            <table class="cv-table">
                <thead><tr><th>Server</th><th>Status</th></tr></thead>
                <tbody>{$serverRows}</tbody>
            </table>
        </div>
        <div class="cv-card">
            <h3 class="cv-card__title">Recent domain changes</h3>
            <table class="cv-table">
                <thead><tr><th>When</th><th>Service</th><th>By</th><th>Detail</th></tr></thead>
                <tbody>{$changeRows}</tbody>
            </table>
        </div>
        HTML;
    }

    /** @return array<int, array<string, mixed>> */
    private function cpanelServers(): array
    {
        return $this->db->select("SELECT name, hostname, active FROM servers WHERE module_slug = 'cpanel' ORDER BY name ASC");
    }

    /** @return array<int, array<string, mixed>> */
    private function recentChanges(): array
    {
        return $this->db->select(
            "SELECT * FROM activity_log WHERE action = 'service.domain_changed' ORDER BY id DESC LIMIT 20"
        );
    }
}
