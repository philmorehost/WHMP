<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use CodeVault\Database;
use RuntimeException;
use Throwable;

/**
 * Free Cloudflare Email Routing for an explicitly opted-in, client-owned zone.
 *
 * Cloudflare destination addresses are account-wide. WHMP therefore keeps a
 * private owner mapping (client + reseller + destination) and never renders
 * Cloudflare's global address list. Rules are zone-scoped and are only changed
 * when their local mapping belongs to that exact zone and owner.
 */
final class CloudflareEmailRouting
{
    private const MAX_DESTINATIONS = 200;
    private const MAX_RULES_PER_ZONE = 200;
    private const SAFE_CATCH_ALL_DEFAULT = 'drop';
    private const REQUIRED_MX_TARGETS = [
        'route1.mx.cloudflare.net',
        'route2.mx.cloudflare.net',
        'route3.mx.cloudflare.net',
    ];

    public function __construct(
        private readonly CloudflareService $cloudflare,
        private readonly CloudflareZoneRepository $zones,
        private readonly CloudflareSettings $settings,
        private readonly Database $db
    ) {
    }

    /**
     * Read-only page data. The only destinations returned are mapped to this
     * zone's client and reseller; raw account-wide Cloudflare data never escapes.
     *
     * @param array<string,mixed> $zone
     * @return array<string,mixed>
     */
    public function overview(array $zone, array $actor): array
    {
        if (!$this->actorOwnsZone($zone, $actor)) {
            return self::failedOverview('Email Routing is available only to the client and reseller that own this Cloudflare service.');
        }

        try {
            $state = $this->dnsState($zone);
            $api = $this->cloudflare->apiClient();
            $remoteAddresses = $api->emailRoutingAddresses($this->settings->accountId());
            $remoteRules = $api->emailRoutingRules((string) $zone['cf_zone_id']);
            $remoteCatchAll = $api->emailRoutingCatchAll((string) $zone['cf_zone_id']);
            $owner = self::owner($zone);
            $destinations = $this->syncOwnedDestinations($owner, $remoteAddresses);
            $routeRows = $this->routeRows($zone, $owner);
            $rulesById = [];

            foreach ($remoteRules as $remoteRule) {
                $id = (string) ($remoteRule['id'] ?? '');
                if ($id !== '') {
                    $rulesById[$id] = $remoteRule;
                }
            }

            $ownedIds = [];
            $routes = [];
            $catchRow = null;

            foreach ($routeRows as $row) {
                $ruleId = (string) ($row['cf_rule_id'] ?? '');
                $remoteRule = $rulesById[$ruleId] ?? null;
                $localPart = (string) ($row['local_part'] ?? '');

                if ($localPart === '*') {
                    $catchRow = $row;
                    continue;
                }

                $matches = is_array($remoteRule)
                    && self::remoteRuleMatches($remoteRule, self::address($localPart, (string) $zone['name']), (string) ($row['destination_email'] ?? ''));

                $routes[] = [
                    'id' => (int) $row['id'],
                    'address' => self::address($localPart, (string) $zone['name']),
                    'destination_email' => (string) ($row['destination_email'] ?? ''),
                    'enabled' => is_array($remoteRule) ? self::truthy($remoteRule['enabled'] ?? false) : false,
                    'missing' => !is_array($remoteRule),
                    'changed' => is_array($remoteRule) && !$matches,
                    'manageable' => $matches,
                ];

                if ($matches) {
                    $ownedIds[$ruleId] = true;
                }
            }

            $catchAll = $this->catchAllView($remoteCatchAll, $catchRow, (string) $zone['name']);
            $unmanaged = 0;

            foreach ($remoteRules as $remoteRule) {
                $ruleId = (string) ($remoteRule['id'] ?? '');
                if ($ruleId !== '' && !isset($ownedIds[$ruleId])) {
                    $unmanaged++;
                }
            }

            $canManage = $this->zoneCanChange($zone) && $state['ready'];
            $canEnable = $this->zoneCanChange($zone)
                && !$state['enabled']
                && $state['status'] === 'unconfigured'
                && $state['mx_records'] === []
                && $state['spf_records'] === []
                && $state['dkim_records'] === []
                && $state['required_dns_complete'];

            return [
                'ok' => true,
                'message' => 'OK',
                'enabled' => $state['enabled'],
                'ready' => $state['ready'],
                'status' => $state['status'],
                'can_enable' => $canEnable,
                'can_manage' => $canManage,
                'required_records' => $state['required_records'],
                'required_dns_complete' => $state['required_dns_complete'],
                'mx_records' => $state['mx_records'],
                'spf_records' => $state['spf_records'],
                'dkim_records' => $state['dkim_records'],
                'mx_conflicts' => $state['mx_conflicts'],
                'spf_conflicts' => $state['spf_records'],
                'destinations' => $destinations,
                'routes' => $routes,
                'catch_all' => $catchAll,
                'unmanaged_rule_count' => $unmanaged,
                'can_disable' => $this->zoneCanChange($zone) && $state['enabled'],
            ];
        } catch (CloudflareApiException $e) {
            return self::failedOverview($e->getMessage());
        } catch (Throwable) {
            return self::failedOverview('Email Routing could not be read safely. No DNS or routing changes were made.');
        }
    }

    /** Enable via Cloudflare's supported DNS endpoint after a read-only conflict check. */
    public function enable(array $zone, bool $confirmed, array $actor): array
    {
        if (!$this->actorOwnsZone($zone, $actor)) {
            return self::fail('Email Routing is available only to the client and reseller that own this Cloudflare service.');
        }

        if (!$confirmed) {
            return self::fail('Confirm that this domain has no existing email provider or mailbox that must keep receiving mail.');
        }

        if (!$this->zoneCanChange($zone)) {
            return self::fail('Email Routing can be set up only after Cloudflare is active for this domain and the service is not paused or scheduled for removal.');
        }

        try {
            $state = $this->dnsState($zone);

            if ($state['enabled'] && $state['ready']) {
                return ['ok' => true, 'message' => 'Cloudflare Email Routing is already enabled.'];
            }

            if ($state['enabled']) {
                return self::fail('Cloudflare reports Email Routing is enabled but its DNS is not ready. No records were changed; review the Email Routing DNS status in Cloudflare first.');
            }

            if ($state['status'] !== 'unconfigured') {
                return self::fail('Email Routing has existing or incomplete Cloudflare DNS state. WHMP will not overwrite it; review the Email Routing settings in Cloudflare first.');
            }

            if ($state['mx_conflicts'] !== []) {
                return self::fail('Existing MX records point to another mail provider. Email Routing takes over inbound mail, so WHMP left every record untouched. Migrate mailboxes and change those MX records yourself before retrying.');
            }

            if ($state['mx_records'] !== []) {
                return self::fail('Root-domain MX records already exist, even if they appear to match Cloudflare. WHMP will not replace or duplicate them; review the current Email Routing DNS state in Cloudflare first.');
            }

            if ($state['spf_records'] !== []) {
                return self::fail('A root-domain SPF record already exists. WHMP will not replace or duplicate it. Keep one SPF record and plan a safe merge that preserves every existing sender before setting up Email Routing.');
            }

            if ($state['dkim_records'] !== []) {
                return self::fail('Existing DKIM records were found for this domain. WHMP will not modify them or risk disrupting outbound mail; review and migrate mail DNS manually before retrying.');
            }

            if (!$state['required_dns_complete']) {
                return self::fail('Cloudflare did not return a complete Email Routing MX, SPF and DKIM DNS checklist. No changes were made.');
            }

            $this->cloudflare->apiClient()->enableEmailRouting((string) $zone['cf_zone_id'], (string) $zone['name']);
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        } catch (Throwable) {
            return self::fail('Email Routing could not be enabled. No nameserver change was attempted.');
        }

        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], 'email_routing_enabled', 'Client explicitly enabled Cloudflare Email Routing; Cloudflare added its managed DNS records.');

        return ['ok' => true, 'message' => 'Email Routing is enabled. Cloudflare added and locked its required DNS records; allow time for DNS to propagate before testing delivery.'];
    }

    /** Disable only after a second explicit confirmation; nameservers are never touched. */
    public function disable(array $zone, bool $confirmed, array $actor): array
    {
        if (!$this->actorOwnsZone($zone, $actor)) {
            return self::fail('Email Routing is available only to the client and reseller that own this Cloudflare service.');
        }

        if (!$confirmed) {
            return self::fail('Confirm that you understand disabling Email Routing stops forwarding and removes its Cloudflare-managed DNS records.');
        }

        if (!$this->zoneCanChange($zone)) {
            return self::fail('Email Routing cannot be changed while Cloudflare is paused, inactive, or scheduled for removal.');
        }

        try {
            $this->assertRemoteFreeZone($zone);
            $settings = $this->cloudflare->apiClient()->emailRoutingSettings((string) $zone['cf_zone_id']);

            if (!self::truthy($settings['enabled'] ?? false)) {
                return ['ok' => true, 'message' => 'Cloudflare Email Routing is already off.'];
            }

            $this->cloudflare->apiClient()->disableEmailRouting((string) $zone['cf_zone_id']);
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        } catch (Throwable) {
            return self::fail('Email Routing could not be disabled. No nameserver change was attempted.');
        }

        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], 'email_routing_disabled', 'Client explicitly disabled Cloudflare Email Routing.');

        return ['ok' => true, 'message' => 'Email Routing is off. Cloudflare removed only the DNS records it manages for Email Routing; check that your replacement mail provider and SPF records are in place. Nameservers were not changed.'];
    }

    /** Create an account-level Cloudflare destination, held privately for this owner. */
    public function addDestination(array $zone, string $email, array $actor): array
    {
        if (!$this->actorOwnsZone($zone, $actor)) {
            return self::fail('Email Routing is available only to the client and reseller that own this Cloudflare service.');
        }

        $email = self::validateDestination($email);

        if ($email === null) {
            return self::fail('Enter a valid destination email address up to 90 characters.');
        }

        $gate = $this->routingGate($zone);
        if ($gate !== null) {
            return self::fail($gate);
        }

        $owner = self::owner($zone);
        $existing = $this->destinationByEmail($email);

        if ($existing !== null) {
            if (self::sameOwner($existing, $owner)) {
                return self::fail('That destination is already on your list. Refresh the page to check its verification status.');
            }

            return self::fail('That destination is already associated with another Cloudflare setup. For privacy, use a different address.');
        }

        $api = $this->cloudflare->apiClient();
        $reservationId = 0;
        $remoteCreatedId = '';

        try {
            // Reserve the normalised address locally first. The unique email
            // index also closes a race between two clients claiming the same
            // account-wide Cloudflare destination at once.
            $reservationId = (int) $this->db->insert(
                'INSERT INTO cloudflare_email_destinations (client_id, reseller_id, cf_destination_id, email, verified_at, created_at, updated_at)
                 VALUES (?, ?, NULL, ?, NULL, ?, ?)',
                [$owner['client_id'], $owner['reseller_id'], $email, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
            );
        } catch (Throwable) {
            return self::fail('This destination is already being added or could not be reserved safely. Refresh and try again.');
        }

        try {
            $remoteAddresses = $api->emailRoutingAddresses($this->settings->accountId());
            $remoteMatch = self::findRemoteAddressByEmail($remoteAddresses, $email);

            if ($remoteMatch !== null) {
                $this->db->delete('DELETE FROM cloudflare_email_destinations WHERE id = ?', [$reservationId]);

                return self::fail('Cloudflare already has this destination in the shared account. WHMP will not attach an address it cannot verify as yours; use a different destination or ask the account owner for help.');
            }

            if (count($remoteAddresses) >= self::MAX_DESTINATIONS) {
                $this->db->delete('DELETE FROM cloudflare_email_destinations WHERE id = ?', [$reservationId]);

                return self::fail('The Cloudflare Free account has reached its 200 destination-address limit. Remove an unused address in Cloudflare after checking every zone that may use it.');
            }

            $created = $api->createEmailRoutingAddress($this->settings->accountId(), $email);
            $remoteId = (string) ($created['id'] ?? '');
            $remoteCreatedId = $remoteId;

            if ($remoteId === '') {
                $this->db->delete('DELETE FROM cloudflare_email_destinations WHERE id = ?', [$reservationId]);

                return self::fail('Cloudflare did not return a destination identifier. No forwarding rule was created.');
            }

            $updatedRows = $this->db->update(
                'UPDATE cloudflare_email_destinations SET cf_destination_id = ?, verified_at = ?, updated_at = ? WHERE id = ? AND client_id = ? AND ' . self::resellerWhere($owner['reseller_id']),
                array_merge([$remoteId, !empty($created['verified']) ? (string) $created['verified'] : null, date('Y-m-d H:i:s'), $reservationId, $owner['client_id']], self::resellerBindings($owner['reseller_id']))
            );
            if ($updatedRows !== 1) {
                throw new RuntimeException('Destination ownership reservation was lost.');
            }
        } catch (CloudflareApiException $e) {
            if ($remoteCreatedId !== '') {
                try {
                    $api->deleteEmailRoutingAddress($this->settings->accountId(), $remoteCreatedId);
                } catch (Throwable) {
                }
            }
            $this->db->delete('DELETE FROM cloudflare_email_destinations WHERE id = ?', [$reservationId]);

            return self::fail($e->getMessage());
        } catch (Throwable) {
            if ($remoteCreatedId !== '') {
                try {
                    $api->deleteEmailRoutingAddress($this->settings->accountId(), $remoteCreatedId);
                } catch (Throwable) {
                }
            }
            $this->db->delete('DELETE FROM cloudflare_email_destinations WHERE id = ?', [$reservationId]);

            return self::fail('Cloudflare could not add that destination. No routing rule was created.');
        }

        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], 'email_destination_added', 'Added an Email Routing destination; Cloudflare verification is required before it can receive forwarded mail.');

        return ['ok' => true, 'message' => 'Cloudflare sent a verification email to that destination. Verify it, then refresh this page before creating a forwarding rule.'];
    }

    /** Add one exact alias -> one verified destination rule. */
    public function addRoute(array $zone, string $localPart, int $destinationId, array $actor): array
    {
        if (!$this->actorOwnsZone($zone, $actor)) {
            return self::fail('Email Routing is available only to the client and reseller that own this Cloudflare service.');
        }

        $localPart = strtolower(trim($localPart));
        $address = self::validateLocalPart($localPart, (string) $zone['name']);

        if ($address === null) {
            return self::fail('Enter a valid email alias for this domain (letters, numbers, dots, plus, hyphen or underscore).');
        }

        $gate = $this->routingGate($zone);
        if ($gate !== null) {
            return self::fail($gate);
        }

        $owner = self::owner($zone);
        $destination = $this->ownedDestination($destinationId, $owner);

        if ($destination === null || empty($destination['cf_destination_id'])) {
            return self::fail('Choose a destination address from your own verified list.');
        }

        try {
            $remoteAddresses = $this->cloudflare->apiClient()->emailRoutingAddresses($this->settings->accountId());
            $remoteDestination = self::findRemoteAddressById($remoteAddresses, (string) $destination['cf_destination_id']);

            if ($remoteDestination === null || strcasecmp((string) ($remoteDestination['email'] ?? ''), (string) $destination['email']) !== 0) {
                return self::fail('Cloudflare no longer has this destination. Add it again and verify it before creating a route.');
            }

            if (empty($remoteDestination['verified'])) {
                return self::fail('Verify the destination email using Cloudflare’s verification message before creating a route.');
            }

            if ($this->routeByLocalPart((int) $zone['id'], $localPart, $owner) !== null) {
                return self::fail('A forwarding rule for that alias already exists.');
            }

            $api = $this->cloudflare->apiClient();
            $remoteRules = $api->emailRoutingRules((string) $zone['cf_zone_id']);

            if (count($remoteRules) >= self::MAX_RULES_PER_ZONE) {
                return self::fail('Cloudflare Email Routing allows up to 200 rules per domain. Remove an unused rule in Cloudflare first.');
            }

            foreach ($remoteRules as $remoteRule) {
                if (self::remoteHasAddress($remoteRule, $address)) {
                    return self::fail('A Cloudflare routing rule already matches that email address. WHMP did not replace it.');
                }
            }

            $created = $api->createEmailRoutingRule((string) $zone['cf_zone_id'], [
                'actions' => [['type' => 'forward', 'value' => [(string) $destination['email']]]],
                'matchers' => [['type' => 'literal', 'field' => 'to', 'value' => $address]],
                'enabled' => true,
                'name' => 'WHMP forwarding ' . $address,
            ]);
            $remoteId = (string) ($created['id'] ?? '');

            if ($remoteId === '') {
                return self::fail('Cloudflare did not return a rule identifier. Check Cloudflare before retrying to avoid a duplicate rule.');
            }

            try {
                $this->db->insert(
                    'INSERT INTO cloudflare_email_routes (zone_id, client_id, reseller_id, cf_rule_id, local_part, destination_id, enabled, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
                    [(int) $zone['id'], $owner['client_id'], $owner['reseller_id'], $remoteId, strtolower(trim($localPart)), $destinationId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
                );
            } catch (Throwable) {
                // The remote rule is zone-scoped and was just created by this
                // request, so it is safe to compensate if local persistence fails.
                try {
                    $api->deleteEmailRoutingRule((string) $zone['cf_zone_id'], $remoteId);
                } catch (Throwable) {
                }

                return self::fail('The Cloudflare rule could not be linked to this customer account. The new rule was rolled back.');
            }
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        } catch (Throwable) {
            return self::fail('The forwarding rule could not be created. No nameserver changes were attempted.');
        }

        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], 'email_route_added', 'Added forwarding rule for ' . $address . '.');

        return ['ok' => true, 'message' => 'Forwarding rule added. Email Routing forwards inbound mail only; it does not create a mailbox or send outgoing mail.'];
    }

    /** Enable or pause one locally owned rule. */
    public function setRouteEnabled(array $zone, int $routeId, bool $enabled, array $actor): array
    {
        if (!$this->actorOwnsZone($zone, $actor)) {
            return self::fail('Email Routing is available only to the client and reseller that own this Cloudflare service.');
        }

        $gate = $this->routingGate($zone);
        if ($gate !== null) {
            return self::fail($gate);
        }

        $owner = self::owner($zone);
        $row = $this->routeById((int) $zone['id'], $routeId, $owner);

        if ($row === null || (string) ($row['local_part'] ?? '') === '*') {
            return self::fail('That forwarding rule does not belong to this service.');
        }

        try {
            $api = $this->cloudflare->apiClient();
            $remote = $api->emailRoutingRule((string) $zone['cf_zone_id'], (string) $row['cf_rule_id']);

            if (!self::remoteRuleMatches($remote, self::address((string) $row['local_part'], (string) $zone['name']), (string) $row['destination_email'])) {
                return self::fail('This rule was changed outside WHMP. It was left untouched; review it in Cloudflare before trying again.');
            }

            if ($enabled) {
                $addresses = $api->emailRoutingAddresses($this->settings->accountId());
                $remoteDestination = self::findRemoteAddressById($addresses, (string) ($row['cf_destination_id'] ?? ''));

                if ($remoteDestination === null || strcasecmp((string) ($remoteDestination['email'] ?? ''), (string) $row['destination_email']) !== 0 || empty($remoteDestination['verified'])) {
                    return self::fail('The forwarding destination is no longer verified in Cloudflare. Verify or replace it before enabling this rule.');
                }
            }

            if (self::truthy($remote['enabled'] ?? false) === $enabled) {
                return ['ok' => true, 'message' => $enabled ? 'That forwarding rule is already active.' : 'That forwarding rule is already paused.'];
            }

            $api->updateEmailRoutingRule((string) $zone['cf_zone_id'], (string) $row['cf_rule_id'], [
                'actions' => (array) ($remote['actions'] ?? []),
                'matchers' => (array) ($remote['matchers'] ?? []),
                'enabled' => $enabled,
                'name' => (string) ($remote['name'] ?? ('WHMP forwarding ' . self::address((string) $row['local_part'], (string) $zone['name']))),
            ]);
            $this->db->update(
                'UPDATE cloudflare_email_routes SET enabled = ?, updated_at = ? WHERE id = ? AND zone_id = ? AND client_id = ? AND ' . self::resellerWhere($owner['reseller_id']),
                array_merge([$enabled ? 1 : 0, date('Y-m-d H:i:s'), $routeId, (int) $zone['id'], $owner['client_id']], self::resellerBindings($owner['reseller_id']))
            );
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        } catch (Throwable) {
            return self::fail('The forwarding rule could not be changed.');
        }

        $address = self::address((string) $row['local_part'], (string) $zone['name']);
        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], $enabled ? 'email_route_enabled' : 'email_route_paused', ($enabled ? 'Enabled ' : 'Paused ') . $address . '.');

        return ['ok' => true, 'message' => $enabled ? 'Forwarding rule enabled.' : 'Forwarding rule paused.'];
    }

    /** Delete only a rule this exact tenant created and whose remote match still agrees. */
    public function deleteRoute(array $zone, int $routeId, array $actor): array
    {
        if (!$this->actorOwnsZone($zone, $actor)) {
            return self::fail('Email Routing is available only to the client and reseller that own this Cloudflare service.');
        }

        $gate = $this->routingGate($zone);
        if ($gate !== null) {
            return self::fail($gate);
        }

        $owner = self::owner($zone);
        $row = $this->routeById((int) $zone['id'], $routeId, $owner);

        if ($row === null || (string) ($row['local_part'] ?? '') === '*') {
            return self::fail('That forwarding rule does not belong to this service.');
        }

        $address = self::address((string) $row['local_part'], (string) $zone['name']);

        try {
            $api = $this->cloudflare->apiClient();
            $remoteRules = $api->emailRoutingRules((string) $zone['cf_zone_id']);
            $remote = null;

            foreach ($remoteRules as $candidate) {
                if ((string) ($candidate['id'] ?? '') === (string) $row['cf_rule_id']) {
                    $remote = $candidate;
                    break;
                }
            }

            if ($remote !== null && !self::remoteRuleMatches($remote, $address, (string) $row['destination_email'])) {
                return self::fail('This rule was changed outside WHMP. It was left untouched; review it in Cloudflare before trying again.');
            }

            if ($remote !== null) {
                $api->deleteEmailRoutingRule((string) $zone['cf_zone_id'], (string) $row['cf_rule_id']);
            }

            $this->db->delete(
                'DELETE FROM cloudflare_email_routes WHERE id = ? AND zone_id = ? AND client_id = ? AND ' . self::resellerWhere($owner['reseller_id']),
                array_merge([$routeId, (int) $zone['id'], $owner['client_id']], self::resellerBindings($owner['reseller_id']))
            );
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        } catch (Throwable) {
            return self::fail('The forwarding rule could not be removed.');
        }

        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], 'email_route_deleted', 'Removed forwarding rule for ' . $address . '.');

        return ['ok' => true, 'message' => 'Forwarding rule removed. Its destination address remains on your private list.'];
    }

    /** Configure the separate, opt-in catch-all rule. It is never enabled by default. */
    public function setCatchAll(array $zone, bool $enabled, ?int $destinationId, array $actor, bool $confirmed = false): array
    {
        if (!$this->actorOwnsZone($zone, $actor)) {
            return self::fail('Email Routing is available only to the client and reseller that own this Cloudflare service.');
        }

        if (!$enabled && !$confirmed) {
            return self::fail('Confirm that disabling the catch-all stops forwarding for unmatched addresses.');
        }

        $gate = $this->routingGate($zone);
        if ($gate !== null) {
            return self::fail($gate);
        }

        $owner = self::owner($zone);
        $local = $this->routeByLocalPart((int) $zone['id'], '*', $owner);
        $destination = null;

        if ($enabled) {
            $destination = $destinationId !== null ? $this->ownedDestination($destinationId, $owner) : null;

            if ($destination === null || empty($destination['cf_destination_id'])) {
                return self::fail('Choose one of your own verified destination addresses for the catch-all.');
            }

            try {
                $addresses = $this->cloudflare->apiClient()->emailRoutingAddresses($this->settings->accountId());
                $remoteDestination = self::findRemoteAddressById($addresses, (string) $destination['cf_destination_id']);

                if ($remoteDestination === null || strcasecmp((string) ($remoteDestination['email'] ?? ''), (string) $destination['email']) !== 0 || empty($remoteDestination['verified'])) {
                    return self::fail('Verify that destination with Cloudflare before enabling the catch-all.');
                }
            } catch (CloudflareApiException $e) {
                return self::fail($e->getMessage());
            }
        }

        try {
            $api = $this->cloudflare->apiClient();
            $remote = $api->emailRoutingCatchAll((string) $zone['cf_zone_id']);
            $remoteId = (string) ($remote['id'] ?? '');

            if ($local === null) {
                if (self::truthy($remote['enabled'] ?? false)) {
                    return self::fail('A catch-all rule is already active outside WHMP. WHMP will not replace or expose its destination.');
                }

                if (!self::isSafeDefaultCatchAll($remote)) {
                    return self::fail('A catch-all rule is configured outside WHMP. Review it in Cloudflare before changing it here.');
                }
            } elseif ($remoteId !== (string) $local['cf_rule_id'] || !self::remoteCatchAllMatches($remote, (string) $local['destination_email'])) {
                return self::fail('This catch-all rule was changed outside WHMP. It was left untouched; review it in Cloudflare before trying again.');
            }

            if (!$enabled && $local === null) {
                return ['ok' => true, 'message' => 'The catch-all is already off.'];
            }

            $actions = $enabled
                ? [['type' => 'forward', 'value' => [(string) $destination['email']]]]
                : (array) ($remote['actions'] ?? [['type' => self::SAFE_CATCH_ALL_DEFAULT, 'value' => []]]);
            $payload = [
                'actions' => $actions,
                'matchers' => [['type' => 'all']],
                'enabled' => $enabled,
                'name' => 'WHMP catch-all for ' . (string) $zone['name'],
            ];
            $updated = $api->updateEmailRoutingCatchAll((string) $zone['cf_zone_id'], $payload);
            $remoteId = (string) ($updated['id'] ?? $remoteId);

            if ($remoteId === '') {
                $fresh = $api->emailRoutingCatchAll((string) $zone['cf_zone_id']);
                $remoteId = (string) ($fresh['id'] ?? '');
            }

            if ($remoteId === '') {
                return self::fail('Cloudflare did not return a catch-all identifier. Check Cloudflare before retrying.');
            }

            if ($local === null && $enabled) {
                $this->db->insert(
                    'INSERT INTO cloudflare_email_routes (zone_id, client_id, reseller_id, cf_rule_id, local_part, destination_id, enabled, created_at, updated_at)
                     VALUES (?, ?, ?, ?, \'*\', ?, 1, ?, ?)',
                    [(int) $zone['id'], $owner['client_id'], $owner['reseller_id'], $remoteId, (int) $destination['id'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
                );
            } elseif ($local !== null && $enabled && (int) $local['destination_id'] !== (int) $destination['id']) {
                $this->db->update(
                    'UPDATE cloudflare_email_routes SET destination_id = ?, enabled = 1, updated_at = ? WHERE id = ? AND zone_id = ? AND client_id = ? AND ' . self::resellerWhere($owner['reseller_id']),
                    array_merge([(int) $destination['id'], date('Y-m-d H:i:s'), (int) $local['id'], (int) $zone['id'], $owner['client_id']], self::resellerBindings($owner['reseller_id']))
                );
            } elseif ($local !== null) {
                $this->db->update(
                    'UPDATE cloudflare_email_routes SET enabled = ?, updated_at = ? WHERE id = ? AND zone_id = ? AND client_id = ? AND ' . self::resellerWhere($owner['reseller_id']),
                    array_merge([$enabled ? 1 : 0, date('Y-m-d H:i:s'), (int) $local['id'], (int) $zone['id'], $owner['client_id']], self::resellerBindings($owner['reseller_id']))
                );
            }
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        } catch (Throwable) {
            return self::fail('The catch-all rule could not be changed.');
        }

        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], $enabled ? 'email_catch_all_enabled' : 'email_catch_all_disabled', $enabled ? 'Enabled the catch-all forwarding rule.' : 'Disabled the catch-all forwarding rule.');

        return ['ok' => true, 'message' => $enabled ? 'Catch-all forwarding is on. It forwards every address at this domain to the selected destination.' : 'Catch-all forwarding is off.'];
    }

    /** @return string|null normalised lower-case email */
    public static function validateDestination(string $email): ?string
    {
        $email = strtolower(trim($email));

        return strlen($email) <= 90 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /** @return string|null full alias address, or null when invalid */
    public static function validateLocalPart(string $localPart, string $zoneName): ?string
    {
        $localPart = strtolower(trim($localPart));
        $zoneName = strtolower(rtrim(trim($zoneName), '.'));

        if ($localPart === '' || strlen($localPart) > 64
            || preg_match('/^[a-z0-9](?:[a-z0-9._+\-]*[a-z0-9])?$/D', $localPart) !== 1) {
            return null;
        }

        $address = $localPart . '@' . $zoneName;

        return strlen($address) <= 90 && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }

    /** @param array<string,mixed> $zone @return array<string,mixed> */
    private function dnsState(array $zone): array
    {
        $this->assertRemoteFreeZone($zone);
        $api = $this->cloudflare->apiClient();
        $settings = $api->emailRoutingSettings((string) $zone['cf_zone_id']);
        $required = $api->emailRoutingDns((string) $zone['cf_zone_id']);
        $records = $api->dnsRecords((string) $zone['cf_zone_id']);
        $zoneName = strtolower(rtrim((string) $zone['name'], '.'));
        $rootMx = [];
        $rootSpf = [];
        $dkimRecords = [];

        foreach ($records as $record) {
            $recordName = (string) ($record['name'] ?? '');
            $recordType = strtoupper((string) ($record['type'] ?? ''));

            if (self::isDkimRecord($recordName, $recordType, $zoneName)) {
                $dkimRecords[] = $record;
            }

            if (!self::isApex($recordName, $zoneName)) {
                continue;
            }

            if ($recordType === 'MX') {
                $rootMx[] = $record;
            } elseif ($recordType === 'TXT' && self::isSpfContent((string) ($record['content'] ?? ''))) {
                $rootSpf[] = $record;
            }
        }

        $requiredMx = [];
        $requiredSpf = false;
        $requiredDkim = false;
        foreach ($required as $record) {
            $recordType = strtoupper((string) ($record['type'] ?? ''));
            $recordName = (string) ($record['name'] ?? '');
            $content = (string) ($record['content'] ?? '');

            if ($recordType === 'MX' && self::isApex($recordName, $zoneName)
                && isset($record['priority']) && is_numeric($record['priority'])) {
                $target = self::normaliseHost($content);
                if ($target !== '') {
                    $requiredMx[$target] = true;
                }
            }
            if ($recordType === 'TXT' && self::isApex($recordName, $zoneName)
                && self::isCloudflareSpfContent($content)) {
                $requiredSpf = true;
            }
            if ($recordType === 'TXT' && self::isDkimRecord($recordName, $recordType, $zoneName)
                && self::isDkimContent($content)) {
                $requiredDkim = true;
            }
        }
        $expectedMx = array_fill_keys(self::REQUIRED_MX_TARGETS, true);
        $requiredMxComplete = count($requiredMx) === count($expectedMx)
            && array_diff_key($expectedMx, $requiredMx) === [];
        $requiredDnsComplete = $requiredMxComplete && $requiredSpf && $requiredDkim;
        if ($requiredMx === []) {
            $requiredMx = $expectedMx;
        }

        $mxConflicts = [];
        foreach ($rootMx as $record) {
            $target = self::normaliseHost((string) ($record['content'] ?? ''));
            if ($target === '' || !isset($requiredMx[$target])) {
                $mxConflicts[] = $record;
            }
        }

        $enabled = self::truthy($settings['enabled'] ?? false);
        $status = strtolower(trim((string) ($settings['status'] ?? 'unknown')));
        $ready = $enabled && in_array($status, ['ready', 'unlocked'], true);

        return [
            'settings' => $settings,
            'required_records' => $required,
            'required_dns_complete' => $requiredDnsComplete,
            'mx_records' => $rootMx,
            'spf_records' => $rootSpf,
            'dkim_records' => $dkimRecords,
            'mx_conflicts' => $mxConflicts,
            'enabled' => $enabled,
            'status' => $status,
            'ready' => $ready,
        ];
    }

    /** @param array<string,mixed> $zone */
    private function assertRemoteFreeZone(array $zone): void
    {
        if (($zone['status'] ?? '') === 'deleted') {
            throw new CloudflareApiException('This Cloudflare zone has already been removed.');
        }

        if (!$this->settings->connected()) {
            throw new CloudflareApiException('Cloudflare is not connected yet.');
        }

        $remote = $this->cloudflare->apiClient()->zone((string) $zone['cf_zone_id']);
        $name = strtolower(rtrim(trim((string) ($remote['name'] ?? '')), '.'));
        $remoteAccount = (string) ($remote['account']['id'] ?? '');

        if ($name !== strtolower(rtrim((string) $zone['name'], '.')) || $remoteAccount !== $this->settings->accountId()) {
            throw new CloudflareApiException('This zone does not match the connected Cloudflare account and saved service domain.');
        }

        if (!CloudflareService::isFreePlanZone($remote)) {
            throw new CloudflareApiException('Only Cloudflare Free-plan zones can use this add-on. Paid plans and upgrades are not supported.');
        }
    }

    /** @param array<string,mixed> $zone */
    private function routingGate(array $zone): ?string
    {
        if (!$this->zoneCanChange($zone)) {
            return 'Email Routing can be changed only while Cloudflare is active and the service is not paused or scheduled for removal.';
        }

        try {
            $state = $this->dnsState($zone);
        } catch (CloudflareApiException $e) {
            return $e->getMessage();
        } catch (Throwable) {
            return 'Cloudflare Email Routing could not be verified. No changes were made.';
        }

        if (!$state['ready']) {
            return 'Enable Email Routing and wait until Cloudflare reports its DNS status as ready before managing destinations or rules.';
        }

        return null;
    }

    /** @param array<string,mixed> $zone */
    private function zoneCanChange(array $zone): bool
    {
        return (string) ($zone['status'] ?? '') === 'active'
            && (int) ($zone['paused'] ?? 0) !== 1
            && ($zone['delete_after'] ?? null) === null
            && !empty($zone['service_id']);
    }

    /** @param array<string,mixed> $zone @param array<int,array<string,mixed>> $remoteAddresses @return array<int,array<string,mixed>> */
    private function syncOwnedDestinations(array $owner, array $remoteAddresses): array
    {
        $remoteById = [];
        foreach ($remoteAddresses as $remote) {
            $id = (string) ($remote['id'] ?? '');
            if ($id !== '') {
                $remoteById[$id] = $remote;
            }
        }

        $owned = $this->ownerRows('cloudflare_email_destinations', $owner);
        $out = [];
        foreach ($owned as $row) {
            $id = (string) ($row['cf_destination_id'] ?? '');
            $remote = $id !== '' ? ($remoteById[$id] ?? null) : null;
            $status = 'missing';
            $verifiedAt = null;

            if (is_array($remote)) {
                if (strcasecmp((string) ($remote['email'] ?? ''), (string) $row['email']) !== 0) {
                    $status = 'changed';
                } else {
                    $verifiedAt = !empty($remote['verified']) ? (string) $remote['verified'] : null;
                    $status = $verifiedAt !== null ? 'verified' : 'pending';
                    if (($row['verified_at'] ?? null) !== $verifiedAt) {
                        $this->db->update(
                            'UPDATE cloudflare_email_destinations SET verified_at = ?, updated_at = ? WHERE id = ? AND client_id = ? AND ' . self::resellerWhere($owner['reseller_id']),
                            array_merge([$verifiedAt, date('Y-m-d H:i:s'), (int) $row['id'], $owner['client_id']], self::resellerBindings($owner['reseller_id']))
                        );
                    }
                }
            }

            $out[] = [
                'id' => (int) $row['id'],
                'email' => (string) $row['email'],
                'status' => $status,
                'verified_at' => $verifiedAt,
            ];
        }

        return $out;
    }

    /** @param array<string,mixed> $zone @param array<string,mixed> $owner @return array<int,array<string,mixed>> */
    private function routeRows(array $zone, array $owner): array
    {
        $whereReseller = self::resellerWhere($owner['reseller_id']);
        $bindings = [(int) $zone['id'], $owner['client_id']];
        $bindings = array_merge($bindings, self::resellerBindings($owner['reseller_id']));

        return $this->db->select(
            'SELECT r.*, d.email AS destination_email, d.cf_destination_id AS cf_destination_id
             FROM cloudflare_email_routes r
             JOIN cloudflare_email_destinations d ON d.id = r.destination_id
             WHERE r.zone_id = ? AND r.client_id = ? AND ' . str_replace('reseller_id', 'r.reseller_id', $whereReseller) . '
               AND d.client_id = r.client_id AND ((d.reseller_id = r.reseller_id) OR (d.reseller_id IS NULL AND r.reseller_id IS NULL))
             ORDER BY r.id ASC',
            $bindings
        );
    }

    /** @param array<string,mixed> $owner @return array<string,mixed>|null */
    private function ownedDestination(int $id, array $owner): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $where = self::resellerWhere($owner['reseller_id']);
        $bindings = [$id, $owner['client_id']];
        $bindings = array_merge($bindings, self::resellerBindings($owner['reseller_id']));

        return $this->db->selectOne('SELECT * FROM cloudflare_email_destinations WHERE id = ? AND client_id = ? AND ' . $where . ' LIMIT 1', $bindings);
    }

    /** @param array<string,mixed> $owner @return array<string,mixed>|null */
    private function destinationByEmail(string $email): ?array
    {
        return $this->db->selectOne('SELECT * FROM cloudflare_email_destinations WHERE email = ? LIMIT 1', [$email]);
    }

    /** @param array<string,mixed> $owner @return array<int,array<string,mixed>> */
    private function ownerRows(string $table, array $owner): array
    {
        if (!in_array($table, ['cloudflare_email_destinations'], true)) {
            return [];
        }

        $where = self::resellerWhere($owner['reseller_id']);
        $bindings = [$owner['client_id']];
        $bindings = array_merge($bindings, self::resellerBindings($owner['reseller_id']));

        return $this->db->select('SELECT * FROM ' . $table . ' WHERE client_id = ? AND ' . $where . ' ORDER BY id ASC', $bindings);
    }

    /** @param array<string,mixed> $owner @return array<string,mixed>|null */
    private function routeById(int $zoneId, int $routeId, array $owner): ?array
    {
        $where = self::resellerWhere($owner['reseller_id']);
        $bindings = [$routeId, $zoneId, $owner['client_id']];
        $bindings = array_merge($bindings, self::resellerBindings($owner['reseller_id']));
        $row = $this->db->selectOne(
            'SELECT r.*, d.email AS destination_email, d.cf_destination_id AS cf_destination_id FROM cloudflare_email_routes r
             JOIN cloudflare_email_destinations d ON d.id = r.destination_id
             WHERE r.id = ? AND r.zone_id = ? AND r.client_id = ? AND ' . str_replace('reseller_id', 'r.reseller_id', $where) . '
               AND d.client_id = r.client_id AND ((d.reseller_id = r.reseller_id) OR (d.reseller_id IS NULL AND r.reseller_id IS NULL))
             LIMIT 1',
            $bindings
        );

        return $row;
    }

    /** @param array<string,mixed> $owner @return array<string,mixed>|null */
    private function routeByLocalPart(int $zoneId, string $localPart, array $owner): ?array
    {
        $where = self::resellerWhere($owner['reseller_id']);
        $bindings = [$zoneId, $localPart, $owner['client_id']];
        $bindings = array_merge($bindings, self::resellerBindings($owner['reseller_id']));

        return $this->db->selectOne(
            'SELECT * FROM cloudflare_email_routes WHERE zone_id = ? AND local_part = ? AND client_id = ? AND ' . $where . ' LIMIT 1',
            $bindings
        );
    }

    /** @param array<string,mixed> $zone @param array<string,mixed>|null $local @return array<string,mixed> */
    private function catchAllView(array $remote, ?array $local, string $zoneName): array
    {
        $enabled = self::truthy($remote['enabled'] ?? false);
        $destinationEmail = '';
        $changed = false;
        $external = false;
        $managed = false;
        $destinationId = null;

        if ($local !== null) {
            $managed = true;
            $destinationEmail = (string) ($local['destination_email'] ?? '');
            $destinationId = (int) ($local['destination_id'] ?? 0);
            $changed = (string) ($remote['id'] ?? '') !== (string) ($local['cf_rule_id'] ?? '')
                || !self::remoteCatchAllMatches($remote, $destinationEmail);
        } else {
            $external = $enabled || !self::isSafeDefaultCatchAll($remote);
            // Never return the destination of an account-global/external rule.
        }

        return [
            'enabled' => $enabled,
            'managed' => $managed,
            'external' => $external,
            'changed' => $changed,
            'manageable' => $managed ? !$changed : !$external,
            'destination_id' => $destinationId,
            'destination_email' => $destinationEmail,
            'address' => '*@' . $zoneName,
        ];
    }

    /** @param array<string,mixed> $remote */
    private static function isSafeDefaultCatchAll(array $remote): bool
    {
        if (self::truthy($remote['enabled'] ?? false)) {
            return false;
        }

        $action = (array) (((array) ($remote['actions'] ?? []))[0] ?? []);
        $matcher = (array) (((array) ($remote['matchers'] ?? []))[0] ?? []);
        $name = strtolower(trim((string) ($remote['name'] ?? '')));

        return strtolower((string) ($action['type'] ?? '')) === self::SAFE_CATCH_ALL_DEFAULT
            && strtolower((string) ($matcher['type'] ?? 'all')) === 'all'
            && in_array($name, ['', 'catch-all', 'catch-all rule', 'default catch-all'], true)
            && strtolower((string) ($remote['source'] ?? 'api')) !== 'wrangler';
    }

    /** @param array<string,mixed> $remote */
    private static function remoteCatchAllMatches(array $remote, string $destination): bool
    {
        $actions = (array) ($remote['actions'] ?? []);
        $action = (array) ($actions[0] ?? []);
        $matchers = (array) ($remote['matchers'] ?? []);
        $matcher = (array) ($matchers[0] ?? []);
        $values = array_values(array_filter((array) ($action['value'] ?? []), 'is_string'));

        return strtolower((string) ($action['type'] ?? '')) === 'forward'
            && count($values) === 1
            && strcasecmp($values[0], $destination) === 0
            && strtolower((string) ($matcher['type'] ?? '')) === 'all'
            && strtolower((string) ($remote['source'] ?? 'api')) !== 'wrangler';
    }

    /** @param array<string,mixed> $remote */
    private static function remoteRuleMatches(array $remote, string $address, string $destination): bool
    {
        $actions = (array) ($remote['actions'] ?? []);
        $action = (array) ($actions[0] ?? []);
        $matchers = (array) ($remote['matchers'] ?? []);
        $matcher = (array) ($matchers[0] ?? []);
        $values = array_values(array_filter((array) ($action['value'] ?? []), 'is_string'));

        return strtolower((string) ($remote['source'] ?? 'api')) !== 'wrangler'
            && strtolower((string) ($action['type'] ?? '')) === 'forward'
            && count($values) === 1
            && strcasecmp($values[0], $destination) === 0
            && strtolower((string) ($matcher['type'] ?? '')) === 'literal'
            && strtolower((string) ($matcher['field'] ?? 'to')) === 'to'
            && strcasecmp((string) ($matcher['value'] ?? ''), $address) === 0;
    }

    /** @param array<string,mixed> $remote */
    private static function remoteHasAddress(array $remote, string $address): bool
    {
        foreach ((array) ($remote['matchers'] ?? []) as $matcher) {
            if (is_array($matcher) && strtolower((string) ($matcher['type'] ?? '')) === 'literal'
                && strcasecmp((string) ($matcher['value'] ?? ''), $address) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,mixed>|null */
    private static function findRemoteAddressById(array $rows, string $id): ?array
    {
        foreach ($rows as $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,mixed>|null */
    private static function findRemoteAddressByEmail(array $rows, string $email): ?array
    {
        foreach ($rows as $row) {
            if (strcasecmp((string) ($row['email'] ?? ''), $email) === 0) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $zone @param array<string,mixed> $actor */
    private function actorOwnsZone(array $zone, array $actor): bool
    {
        if ((string) ($actor['type'] ?? '') !== 'client' || !array_key_exists('reseller_id', $actor)) {
            return false;
        }

        $owner = self::owner($zone);
        $actorResellerId = $actor['reseller_id'] === null ? null : (int) $actor['reseller_id'];

        return (int) ($actor['id'] ?? 0) === $owner['client_id']
            && $actorResellerId === $owner['reseller_id'];
    }

    /** @param array<string,mixed> $zone @return array{client_id:int,reseller_id:int|null} */
    private static function owner(array $zone): array
    {
        return [
            'client_id' => (int) ($zone['client_id'] ?? 0),
            'reseller_id' => ($zone['reseller_id'] ?? null) === null ? null : (int) $zone['reseller_id'],
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $owner */
    private static function sameOwner(array $row, array $owner): bool
    {
        return (int) ($row['client_id'] ?? 0) === $owner['client_id']
            && (($row['reseller_id'] ?? null) === null ? null : (int) $row['reseller_id']) === $owner['reseller_id'];
    }

    private static function isApex(string $name, string $zoneName): bool
    {
        $name = strtolower(trim($name, " \t\n\r\0\x0B."));

        return $name === '@' || $name === strtolower(rtrim($zoneName, '.'));
    }

    private static function isSpfContent(string $content): bool
    {
        $content = strtolower(trim(trim($content), "\"'"));

        return str_starts_with($content, 'v=spf1')
            && (strlen($content) === 6 || (strlen($content) > 6 && ctype_space($content[6])));
    }

    private static function isCloudflareSpfContent(string $content): bool
    {
        $content = strtolower(trim(trim($content), "\"'"));

        return self::isSpfContent($content)
            && preg_match('/(?:^|\\s)include:_spf\\.mx\\.cloudflare\\.net(?:\\s|$)/', $content) === 1;
    }

    private static function isDkimContent(string $content): bool
    {
        $content = strtolower(trim(trim($content), "\"'"));

        return str_starts_with($content, 'v=dkim1;')
            && preg_match('/(?:^|;)\\s*p=[^;\\s]+/', $content) === 1;
    }

    private static function isDkimRecord(string $name, string $type, string $zoneName): bool
    {
        if (!in_array($type, ['TXT', 'CNAME'], true)) {
            return false;
        }

        $name = strtolower(trim($name, " \t\n\r\0\x0B."));
        $zoneName = strtolower(rtrim($zoneName, '.'));

        return str_ends_with($name, '._domainkey.' . $zoneName)
            || $name === '_domainkey.' . $zoneName
            || str_ends_with($name, '._domainkey')
            || $name === '_domainkey';
    }

    private static function normaliseHost(string $host): string
    {
        return strtolower(trim($host, " \t\n\r\0\x0B."));
    }

    private static function address(string $localPart, string $zoneName): string
    {
        return strtolower($localPart . '@' . rtrim($zoneName, '.'));
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private static function resellerWhere(?int $resellerId): string
    {
        return $resellerId === null ? 'reseller_id IS NULL' : 'reseller_id = ?';
    }

    /** @return array<int,mixed> */
    private static function resellerBindings(?int $resellerId): array
    {
        return $resellerId === null ? [] : [$resellerId];
    }

    /** @return array{ok:false,message:string} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

    /** @return array<string,mixed> */
    private static function failedOverview(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'enabled' => false,
            'ready' => false,
            'status' => 'unknown',
            'can_enable' => false,
            'can_manage' => false,
            'required_records' => [],
            'required_dns_complete' => false,
            'mx_records' => [],
            'spf_records' => [],
            'dkim_records' => [],
            'mx_conflicts' => [],
            'spf_conflicts' => [],
            'destinations' => [],
            'routes' => [],
            'catch_all' => ['enabled' => false, 'managed' => false, 'external' => false, 'changed' => false, 'manageable' => false, 'destination_id' => null, 'destination_email' => '', 'address' => ''],
            'unmanaged_rule_count' => 0,
            'can_disable' => false,
        ];
    }
}
