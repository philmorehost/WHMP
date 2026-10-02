<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

/**
 * Keeps the hosting panel in step with what a store is allowed to be served on.
 *
 * WHY THIS IS NOT JUST "CALL provision()"
 *
 * Because the interesting part is the DIFFERENCE, not the call. A store has two
 * independent pieces of state:
 *
 *   custom_domain + domain_status   what the store is allowed to be served on
 *   domain_provisioned_host         what is actually on the hosting panel
 *
 * and every operation below is a reconciliation of the two:
 *
 *   panel has nothing, claim approved        → add
 *   panel has XYZ, claim is now ABC          → remove XYZ, then add ABC
 *   panel has XYZ, claim released/refused    → remove XYZ
 *   panel has XYZ, claim is still XYZ        → nothing (this must NOT re-call)
 *
 * That last line is why the rule is written as a difference rather than as a
 * sequence of events. An approval that is clicked twice, a retry after a
 * timeout, and a nightly job all see "already in sync" and do nothing, so the
 * panel is never asked to add a domain it already has or remove one it does not.
 *
 * REMOVE BEFORE ADD, AND STOP IF THE REMOVE FAILS. Not the other way round: the
 * hostname being removed is already dead (the claim moved away from it, or was
 * refused, or the store is being deleted), so taking it off cannot lose anything.
 * Adding first and removing afterwards would leave the panel holding two
 * hostnames whenever the second step failed, and the stale one is the dangerous
 * one — it answers for a host no store claims, which resolves to the PLATFORM
 * shop at PLATFORM prices.
 *
 * EVERY OUTCOME IS PERSISTED, not just returned. `domain_provision_error` is the
 * admin's queue of "the panel did not do what an approval asked for", and it is
 * written here so a caller that forgets to look at the return value cannot lose
 * it. A caller that ignores a SKIPPED result loses nothing either — a skip
 * changes no state, which is exactly what it should mean.
 */
final class ResellerDomainSync
{
    public function __construct(
        private readonly ResellerStoreRepository $stores,
        private readonly ResellerDomainProvisioner $provisioner
    ) {
    }

    /** Whether the application is allowed to touch the hosting panel at all. */
    public function enabled(): bool
    {
        return $this->provisioner->enabled();
    }

    /**
     * The configured mode, passed straight through so the settings form reads it
     * from the same place the behaviour does. A form that re-derived "on/off"
     * from anything else is how a screen comes to say 'off' while the code is
     * calling the panel.
     */
    public function mode(): string
    {
        return $this->provisioner->mode();
    }

    /**
     * Bring the panel in line with this store's claimed domain.
     *
     * @param array<string, mixed> $store a `resellers` row, read AFTER any
     *                                    decision was recorded — the decision is
     *                                    the input to this
     * @return array{ok: bool, skipped: bool, changed: bool, removed: ?string, added: ?string, message: string}
     */
    public function sync(array $store): array
    {
        $id = (int) ($store['id'] ?? 0);
        $current = ResellerStoreLocator::normaliseHost((string) ($store['domain_provisioned_host'] ?? ''));

        // Only an APPROVED claim may be provisioned. Approval is the human gate
        // (migration 0198); a pending or refused domain must never reach the
        // panel, and anything left there from before must come off.
        $approved = (string) ($store['domain_status'] ?? 'none') === 'approved';
        $wanted = $approved ? ResellerStoreLocator::normaliseHost((string) ($store['custom_domain'] ?? '')) : '';

        if (!$this->provisioner->enabled()) {
            return $this->result(
                false,
                true,
                false,
                null,
                null,
                $current !== '' && $current !== $wanted
                    ? 'Automatic provisioning is off — ' . $current . ' is still on the hosting panel and has to be '
                        . 'removed there by hand.'
                    : 'Automatic provisioning is off — the hosting panel was not touched.'
            );
        }

        $notes = [];
        $removed = null;
        $added = null;

        if ($current !== '' && $current !== $wanted) {
            $outcome = $this->dropHost($id, $current);

            if (!$outcome['ok']) {
                return $this->result(false, (bool) ($outcome['skipped'] ?? false), false, null, null, $outcome['message']);
            }

            $removed = $current;
            $current = '';
            $notes[] = $outcome['message'];
        }

        if ($wanted !== '' && $wanted !== $current) {
            $outcome = $this->provisioner->provision($store);

            if (!$outcome['ok']) {
                if (!($outcome['skipped'] ?? false)) {
                    $this->stores->recordDomainProvisionError($id, $outcome['message']);
                }

                // `changed` is true when the old hostname did come off: the panel
                // is in a different state than it started, and the caller has to
                // report both halves rather than just the failure.
                return $this->result(
                    false,
                    (bool) ($outcome['skipped'] ?? false),
                    $removed !== null,
                    $removed,
                    null,
                    $outcome['message']
                );
            }

            $this->stores->markDomainProvisioned($id, $wanted);
            $added = $wanted;
            $notes[] = $outcome['message'];
        }

        if ($notes === []) {
            return $this->result(
                true,
                false,
                false,
                null,
                null,
                $wanted !== ''
                    ? $wanted . ' is already set up on the hosting panel.'
                    : 'Nothing to do on the hosting panel.'
            );
        }

        return $this->result(true, false, true, $removed, $added, implode(' ', $notes));
    }

    /**
     * Bring the panel in line with a store, looked up by id.
     *
     * Re-reads the row rather than taking it, because every caller that needs
     * this has just written a decision and the value it holds is the pre-decision
     * one — provisioning the version of the row from before the approval is the
     * bug this signature exists to prevent.
     *
     * @return array{ok: bool, skipped: bool, changed: bool, removed: ?string, added: ?string, message: string}
     */
    public function syncStore(int $storeId): array
    {
        $store = $this->stores->find($storeId);

        if ($store === null) {
            return $this->result(false, false, false, null, null, 'That store no longer exists.');
        }

        return $this->sync($store);
    }

    /**
     * Take whatever this client's store has on the panel back off it.
     *
     * Called BEFORE the client row is deleted. That ordering is not a
     * preference: `resellers.client_id` is `ON DELETE CASCADE`, so the moment the
     * account goes, the row holding the hostname goes with it and there is
     * nothing left to tell us what to remove from the panel. The information has
     * to be used before it is destroyed.
     *
     * Deliberately does NOT depend on `domain_status`. A store can be deleted
     * while its domain is pending, refused, or approved, and in all three cases
     * anything we put on the panel has to come back off.
     *
     * @return array{ok: bool, skipped: bool, changed: bool, removed: ?string, added: ?string, message: string}
     */
    public function removeForClient(int $clientId): array
    {
        $store = $this->stores->forClient($clientId);

        if ($store === null) {
            return $this->result(true, true, false, null, null, 'That account has no store.');
        }

        return $this->removeStoreDomain($store);
    }

    /**
     * The removal-only half of sync(), for a store row already in hand.
     *
     * @param array<string, mixed> $store
     * @return array{ok: bool, skipped: bool, changed: bool, removed: ?string, added: ?string, message: string}
     */
    public function removeStoreDomain(array $store): array
    {
        $id = (int) ($store['id'] ?? 0);
        $host = ResellerStoreLocator::normaliseHost((string) ($store['domain_provisioned_host'] ?? ''));

        if ($host === '') {
            return $this->result(true, true, false, null, null, 'No store domain was set up on the hosting panel.');
        }

        $outcome = $this->dropHost($id, $host);

        if (!$outcome['ok']) {
            return $this->result(false, (bool) ($outcome['skipped'] ?? false), false, null, null, $outcome['message']);
        }

        return $this->result(true, false, true, $host, null, $outcome['message']);
    }

    /**
     * Ask for the hostname to come off the panel and record what happened.
     *
     * Recording lives here rather than at each call site so a caller cannot
     * perform the removal and forget to write down that it failed — which would
     * leave the store looking in-sync while the domain is still answering.
     *
     * A SKIPPED outcome writes nothing on purpose: nothing was attempted, so
     * there is no panel state to describe, and the store keeps its record of the
     * hostname so a later attempt with provisioning switched on can still find it.
     *
     * @return array{ok: bool, skipped: bool, message: string}
     */
    private function dropHost(int $id, string $host): array
    {
        $outcome = $this->provisioner->remove($host);

        if ($outcome['ok']) {
            $this->stores->markDomainUnprovisioned($id);
        } elseif (!($outcome['skipped'] ?? false)) {
            $this->stores->recordDomainProvisionError($id, $outcome['message']);
        }

        return $outcome;
    }

    /**
     * @return array{ok: bool, skipped: bool, changed: bool, removed: ?string, added: ?string, message: string}
     */
    private function result(
        bool $ok,
        bool $skipped,
        bool $changed,
        ?string $removed,
        ?string $added,
        string $message
    ): array {
        return [
            'ok' => $ok,
            'skipped' => $skipped,
            'changed' => $changed,
            'removed' => $removed,
            'added' => $added,
            'message' => $message,
        ];
    }
}
