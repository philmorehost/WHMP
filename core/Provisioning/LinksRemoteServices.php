<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

/**
 * A provisioning module whose services live on a provider account under the
 * provider's OWN ids. InterServer VPSes are one example: they are addressed by
 * `vps_uuid`, not by anything WHMP chose.
 *
 * Such a service is manageable once WHMP knows which remote machine it is. The id
 * can be linked explicitly (`services.remote_id`, set by an admin from the service
 * page). Otherwise the module matches the service's hostname or IPs against the
 * account's machines. So a VPS bought and set up by hand still gets client
 * self-service as soon as it is linked, and needs no WHMP username.
 */
interface LinksRemoteServices
{
    /**
     * Every machine on the provider account, for the admin's "link this service"
     * picker.
     *
     * @param array<string, mixed> $server the servers row (api_token etc.)
     * @return array{success: bool, message: string, services: array<int, array{ref: string, id: string, hostname: string, ip: string, status: string, label: string}>}
     */
    public function remoteServices(array $server): array;

    /**
     * Which remote machine this service is, and how that was decided.
     *
     * `via` is one of `linked` (explicit remote_id), `hostname`, `ip` or `username`
     * (matched), or `none`.
     *
     * @param array<string, mixed> $params the usual module params (server, hostname, remote_id, ...)
     * @return array{ref: ?string, via: string}
     */
    public function resolveRemote(array $params): array;
}
