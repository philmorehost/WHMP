<?php

declare(strict_types=1);

namespace CodeVault\Modules;

/**
 * Server modules — cPanel, Plesk, DirectAdmin, CyberPanel, Virtualizor,
 * SolusVM, Proxmox, etc. (blueprint §3/§4.4). One implementation per
 * control-panel API; the provisioning engine calls these lifecycle methods
 * and never talks to a panel API directly.
 */
interface ProvisioningModule extends Module
{
    /**
     * @param array<string, mixed> $params service + server + package params
     * @return array{success: bool, message: string}
     */
    public function create(array $params): array;

    public function suspend(array $params): array;

    public function unsuspend(array $params): array;

    public function terminate(array $params): array;

    public function changePassword(array $params): array;

    public function changePackage(array $params): array;

    /**
     * URL/token for one-click SSO into the underlying control panel.
     */
    public function singleSignOn(array $params): array;

    /**
     * @return array<string, mixed> usage stats (disk, bandwidth, etc.) for
     *   the "My Services" usage bar and overage handling
     */
    public function usage(array $params): array;

    public function testConnection(array $params): array;
}
