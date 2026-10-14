<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * The effective rules for ONE service (plan §6):
 *
 *   global settings ⊕ product override ⊕ store override(s) ⊕ client override
 *
 * plus the price the client pays when payment is switched on, and what each
 * reseller in the chain earns from it.
 *
 * Store overrides can only make things STRICTER, and that is enforced here, not
 * in the form: a store can switch the feature off, lower the allowance, lengthen
 * the cooldown and add its own approval step, never the reverse.
 */
final class PolicyResolver
{
    public function __construct(
        private readonly Database $db,
        private readonly UsernameChangeRepository $requests,
        private readonly UsernameChangerSettings $settings
    ) {
    }

    /**
     * Everything the screens and the state machine need to know about a
     * service, in one array. `eligible` false + `reason` when the feature does
     * not apply at all (the banner is then not shown).
     *
     * @return array<string, mixed>
     */
    public function forService(int $serviceId): array
    {
        $ctx = $this->db->selectOne(
            <<<'SQL'
            SELECT s.id, s.client_id, s.product_id, s.server_id, s.username, s.domain, s.status,
                   p.type AS product_type, p.name AS product_name, sv.module_slug,
                   c.reseller_id AS store_id, c.first_name, c.last_name, c.email, c.security_pin_hash
              FROM services s
              JOIN clients c ON c.id = s.client_id
              LEFT JOIN products p ON p.id = s.product_id
              LEFT JOIN servers sv ON sv.id = s.server_id
             WHERE s.id = ?
            SQL,
            [$serviceId]
        );

        if ($ctx === null) {
            return ['eligible' => false, 'reason' => 'not_found'];
        }

        return $this->resolve($ctx);
    }

    /**
     * @param array<string, mixed> $ctx the row forService() selects
     * @return array<string, mixed>
     */
    public function resolve(array $ctx): array
    {
        $s = $this->settings;
        $storeId = ($ctx['store_id'] ?? null) === null ? null : (int) $ctx['store_id'];
        $store = $storeId === null ? null : $this->db->selectOne('SELECT id, client_id, brand_name, slug FROM resellers WHERE id = ?', [$storeId]);
        $upline = $store === null ? null : $this->db->selectOne(
            'SELECT u.id, u.client_id FROM clients c JOIN resellers u ON u.id = c.reseller_id WHERE c.id = ? AND u.id <> ? LIMIT 1',
            [(int) $store['client_id'], (int) $store['id']]
        );

        $product = ($ctx['product_id'] ?? null) === null ? null : $this->requests->policy('product', (int) $ctx['product_id']);
        $storePolicy = $store === null ? null : $this->requests->policy('store', (int) $store['id']);
        $uplinePolicy = $upline === null ? null : $this->requests->policy('store', (int) $upline['id']);
        $client = $this->requests->policy('client', (int) $ctx['client_id']);

        $p = [
            'service_id' => (int) $ctx['id'],
            'client_id' => (int) $ctx['client_id'],
            'server_id' => ($ctx['server_id'] ?? null) === null ? null : (int) $ctx['server_id'],
            'store_id' => $store === null ? null : (int) $store['id'],
            'upline_store_id' => $upline === null ? null : (int) $upline['id'],
            'store_owner_client_id' => $store === null ? null : (int) $store['client_id'],
            'username' => (string) ($ctx['username'] ?? ''),
            'domain' => (string) ($ctx['domain'] ?? ''),
            'first_name' => (string) ($ctx['first_name'] ?? ''),
            'last_name' => (string) ($ctx['last_name'] ?? ''),
            'has_pin' => !empty($ctx['security_pin_hash']),
            'enabled' => true,
            'max_changes' => $s->maxChanges(),
            'cooldown_days' => $s->cooldownDays(),
            'approval' => $s->approval(),
            'allow_db_rename' => $s->allowDbRename(),
            'client_mode' => null,
        ];

        // Product override (super admin).
        if ($product !== null) {
            $p = self::overlay($p, $product);
        }

        // Store overrides — tightening only. The customer's own store first, then
        // (for a sub-reseller's customer) the upline's, which also binds its tree.
        foreach ([$storePolicy, $uplinePolicy] as $i => $sp) {
            if ($sp === null) {
                continue;
            }

            if (($sp['enabled'] ?? null) !== null && (int) $sp['enabled'] === 0) {
                $p['enabled'] = false;
            }

            if (($sp['max_changes'] ?? null) !== null && (int) $sp['max_changes'] > 0) {
                $p['max_changes'] = $p['max_changes'] === 0 ? (int) $sp['max_changes'] : min($p['max_changes'], (int) $sp['max_changes']);
            }

            if (($sp['cooldown_days'] ?? null) !== null) {
                $p['cooldown_days'] = max($p['cooldown_days'], (int) $sp['cooldown_days']);
            }

            if ($i === 0 && ($sp['approval'] ?? null) === 'reseller' && $p['approval'] === 'none' && $s->storeApprovalAllowed()) {
                $p['approval'] = 'reseller';
            }

            if (($sp['allow_db_rename'] ?? null) !== null && (int) $sp['allow_db_rename'] === 0) {
                $p['allow_db_rename'] = false;
            }
        }

        // Client override (super admin): waive | block | extra changes.
        if ($client !== null) {
            $mode = $client['client_mode'] ?? null;
            $p['client_mode'] = in_array($mode, ['waive', 'block'], true) ? $mode : null;

            if (($client['extra_changes'] ?? null) !== null && $p['max_changes'] > 0) {
                $p['max_changes'] += (int) $client['extra_changes'];
            }

            foreach (['enabled', 'approval', 'allow_db_rename'] as $key) {
                if (($client[$key] ?? null) !== null) {
                    $p = self::overlay($p, [$key => $client[$key]]);
                }
            }
        }

        // Allowance and cooldown, from this service's completed history.
        $stats = $this->requests->completedStats((int) $ctx['id']);
        $waived = $p['client_mode'] === 'waive';
        $p['completed_count'] = $stats['count'];
        $p['last_completed_at'] = $stats['last'];
        $p['remaining'] = $p['max_changes'] === 0 || $waived ? null : max(0, $p['max_changes'] - $stats['count']);
        $p['cooldown_until'] = null;

        if (!$waived && $stats['last'] !== null && $p['cooldown_days'] > 0) {
            $until = (new DateTimeImmutable($stats['last']))->modify('+' . $p['cooldown_days'] . ' days');

            if ($until > new DateTimeImmutable()) {
                $p['cooldown_until'] = $until->format('Y-m-d H:i:s');
            }
        }

        $p['pricing'] = $this->pricing($ctx, $product, $storePolicy, $uplinePolicy, $store, $upline, $waived);

        // Eligibility: does the feature apply to this service at all?
        $p['eligible'] = true;
        $p['reason'] = null;
        $p['can_request'] = true;
        $p['blocked_reason'] = null;

        $fail = static function (array $p, string $reason): array {
            $p['eligible'] = false;
            $p['reason'] = $reason;
            $p['can_request'] = false;

            return $p;
        };

        if ((string) ($ctx['module_slug'] ?? '') !== 'cpanel') {
            return $fail($p, 'not_cpanel');
        }

        if (trim((string) ($ctx['username'] ?? '')) === '') {
            return $fail($p, 'no_username');
        }

        if (!in_array((string) ($ctx['product_type'] ?? 'other'), $s->productTypes(), true)) {
            return $fail($p, 'product_type');
        }

        if ($store !== null && !$s->storesAllowed()) {
            return $fail($p, 'stores_off');
        }

        if (!$p['enabled'] || $p['client_mode'] === 'block') {
            return $fail($p, 'disabled');
        }

        // Eligible — but maybe not right now.
        if (!in_array((string) ($ctx['status'] ?? ''), $s->statuses(), true)) {
            $p['can_request'] = false;
            $p['blocked_reason'] = 'Username changes are not available while this service is ' . (string) $ctx['status'] . '.';
        } elseif ($p['remaining'] !== null && $p['remaining'] <= 0) {
            $p['can_request'] = false;
            $p['blocked_reason'] = 'You have used all username changes allowed for this service.';
        } elseif ($p['cooldown_until'] !== null) {
            $p['can_request'] = false;
            $p['blocked_reason'] = 'You can change the username again after ' . (new DateTimeImmutable($p['cooldown_until']))->format('j M Y') . '.';
        }

        return $p;
    }

    /**
     * The price this client pays and how it splits, all in the CATALOG
     * currency (the currency product prices are typed in).
     *
     *   platform customer        pays  F                         (F = admin fee)
     *   tier-1 store customer    pays  P1 ≥ F    store earns P1 − F
     *   tier-2 store customer    pays  P2 ≥ C2   store earns P2 − C2, where
     *                                  C2 = max(P1 ?? F, F) is the upline's price,
     *                                  and the upline earns C2 − F
     *
     * A store that has not set a price charges its cost, so it earns nothing
     * but its customers are never charged less than the platform is owed.
     *
     * @return array{charge: bool, price: float, cost: ?float, upline_cost: ?float, admin_fee: float, store_margin: float, upline_margin: float}
     */
    private function pricing(array $ctx, ?array $product, ?array $storePolicy, ?array $uplinePolicy, ?array $store, ?array $upline, bool $waived): array
    {
        $none = ['charge' => false, 'price' => 0.0, 'cost' => null, 'upline_cost' => null, 'admin_fee' => 0.0, 'store_margin' => 0.0, 'upline_margin' => 0.0];

        if (!$this->settings->feeEnabled() || $waived) {
            return $none;
        }

        $fee = ($product['fee'] ?? null) !== null ? max(0.0, (float) $product['fee']) : $this->settings->fee();
        $resale = $this->settings->storePricing();
        $storePrice = static fn (?array $policy): ?float => $resale && ($policy['fee'] ?? null) !== null ? max(0.0, (float) $policy['fee']) : null;

        if ($store === null) {
            $price = round($fee, 2);

            return ['charge' => $price > 0, 'price' => $price, 'cost' => null, 'upline_cost' => null, 'admin_fee' => $price, 'store_margin' => 0.0, 'upline_margin' => 0.0];
        }

        if ($upline === null) {
            $cost = $fee;
            $price = max($storePrice($storePolicy) ?? $cost, $cost);

            return [
                'charge' => $price > 0,
                'price' => round($price, 2),
                'cost' => round($cost, 2),
                'upline_cost' => null,
                'admin_fee' => round($fee, 2),
                'store_margin' => round($price - $cost, 2),
                'upline_margin' => 0.0,
            ];
        }

        $cost = max($storePrice($uplinePolicy) ?? $fee, $fee);
        $price = max($storePrice($storePolicy) ?? $cost, $cost);

        return [
            'charge' => $price > 0,
            'price' => round($price, 2),
            'cost' => round($cost, 2),
            'upline_cost' => round($fee, 2),
            'admin_fee' => round($fee, 2),
            'store_margin' => round($price - $cost, 2),
            'upline_margin' => round($cost - $fee, 2),
        ];
    }

    /**
     * What a store pays per change (its cost) — shown on the reseller page next
     * to the price it sets.
     *
     * @param array<string, mixed> $store resellers row
     */
    public function storeCost(array $store): float
    {
        $fee = $this->settings->fee();
        $upline = $this->db->selectOne(
            'SELECT u.id FROM clients c JOIN resellers u ON u.id = c.reseller_id WHERE c.id = ? AND u.id <> ? LIMIT 1',
            [(int) $store['client_id'], (int) $store['id']]
        );

        if ($upline === null) {
            return round($fee, 2);
        }

        $up = $this->requests->policy('store', (int) $upline['id']);
        $upPrice = $this->settings->storePricing() && ($up['fee'] ?? null) !== null ? (float) $up['fee'] : $fee;

        return round(max($upPrice, $fee), 2);
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    private static function overlay(array $p, array $o): array
    {
        if (($o['enabled'] ?? null) !== null) {
            $p['enabled'] = (int) $o['enabled'] === 1;
        }

        if (($o['max_changes'] ?? null) !== null) {
            $p['max_changes'] = max(0, (int) $o['max_changes']);
        }

        if (($o['cooldown_days'] ?? null) !== null) {
            $p['cooldown_days'] = max(0, (int) $o['cooldown_days']);
        }

        if (in_array($o['approval'] ?? null, ['none', 'admin'], true)) {
            $p['approval'] = (string) $o['approval'];
        }

        if (($o['allow_db_rename'] ?? null) !== null) {
            $p['allow_db_rename'] = (int) $o['allow_db_rename'] === 1;
        }

        return $p;
    }
}
