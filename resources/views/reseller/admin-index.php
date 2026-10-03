<?php
/** @var array{service: float, domain: float} $discounts */
/** @var array<int, array<string, mixed>> $resellers */
/** @var array<int, array<string, mixed>> $stores */
/** @var int $activeCount */
/** @var string|null $error */
/** @var string|null $notice */
/** @var string $docsUrl */
/** @var array<string, int|null>|null $stats from AdminResellerController::overviewStats() */
/** @var string $platformHost the host this install runs on (APP_URL) */
/** @var string|null $storeDomain the domain store subdomains are served under; null = none */
/** @var string|null $storeDomainSetting what the super admin saved (null when not set) */
/** @var array<string, mixed>|null $dnsCheck the result of the last "Check DNS" */

$servicePct = \CodeVault\Reseller\ResellerSettings::formatPercent($discounts['service']);
$domainPct = \CodeVault\Reseller\ResellerSettings::formatPercent($discounts['domain']);
$stats = $stats ?? \CodeVault\Reseller\AdminResellerController::overviewStats($stores, ['domains' => null, 'payouts' => null, 'migrations' => null]);
$icon = static fn (string $paths): string => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
$storeDomain = $storeDomain ?? null;
$storeDomainSetting = $storeDomainSetting ?? null;
$dnsCheck = $dnsCheck ?? null;
$plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
?>
<link rel="stylesheet" href="/assets/css/reseller.css">
<section class="rs-welcome rs-welcome--admin" aria-labelledby="rs-admin-title">
    <div class="rs-welcome__body">
        <p class="rs-eyebrow">Reseller programme</p>
        <header class="rs-head">
            <h1 class="rs-head__title" id="rs-admin-title">Resellers</h1>
            <p class="rs-head__lede">Every white-label store, its customers and the money between you, in one place.
                Resale is capped at two tiers: a reseller's customer may resell at that reseller's prices, but their
                own customers cannot.</p>
        </header>
        <div class="rs-welcome__actions">
            <a class="cv-btn" href="#rs-stores"><?= $icon('<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12v8h14v-8"/>') ?> View stores</a>
            <a class="cv-btn cv-btn--secondary" href="#rs-platform-address">Platform address</a>
            <a class="cv-btn cv-btn--secondary" href="#rs-discounts">Reseller discounts</a>
            <a class="cv-btn cv-btn--secondary" href="<?= e($docsUrl) ?>">API documentation</a>
        </div>
    </div>
    <div class="rs-welcome__art" aria-hidden="true">
        <span class="rs-welcome__orb rs-welcome__orb--a"></span>
        <span class="rs-welcome__orb rs-welcome__orb--b"></span>
        <span class="rs-welcome__glyph"><?= $icon('<path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/>') ?></span>
    </div>
</section>

<?= $view->render('partials.reseller-admin-nav') ?>

<?php if ($error !== null && $error !== ''): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e((string) $error) ?></div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e((string) $notice) ?></div>
<?php endif; ?>

<div class="rs-stats rs-stats--kpi" style="margin-bottom:var(--cv-space-4);">
    <a class="rs-stat rs-stat--link" href="#rs-stores">
        <span class="rs-stat__icon"><?= $icon('<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12v8h14v-8"/><path d="M10 20v-5h4v5"/>') ?></span>
        <div class="rs-stat__label">Reseller stores</div>
        <div class="rs-stat__value"><?= (int) $stats['stores'] ?></div>
        <div class="rs-stat__note"><?= (int) $stats['active'] ?> active &middot; <?= (int) $stats['stores'] - (int) $stats['active'] ?> suspended</div>
    </a>
    <div class="rs-stat rs-stat--teal">
        <span class="rs-stat__icon"><?= $icon('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>') ?></span>
        <div class="rs-stat__label">Store customers</div>
        <div class="rs-stat__value"><?= (int) $stats['customers'] ?></div>
        <div class="rs-stat__note">across every store</div>
    </div>
    <div class="rs-stat rs-stat--violet">
        <span class="rs-stat__icon"><?= $icon('<path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/>') ?></span>
        <div class="rs-stat__label">Sub-resellers</div>
        <div class="rs-stat__value"><?= (int) $stats['sub_resellers'] ?></div>
        <div class="rs-stat__note"><?= (int) $stats['third_tier'] > 0 ? $plural((int) $stats['third_tier'], 'legacy third-tier store', 'legacy third-tier stores') : 'buying at another store\'s prices' ?></div>
    </div>
    <div class="rs-stat<?= (int) $stats['pending_orders'] > 0 ? ' rs-stat--alert' : ' rs-stat--amber' ?>">
        <span class="rs-stat__icon"><?= $icon('<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>') ?></span>
        <div class="rs-stat__label">Pending orders</div>
        <div class="rs-stat__value"><?= (int) $stats['pending_orders'] ?></div>
        <div class="rs-stat__note">from store customers</div>
    </div>
    <div class="rs-stat">
        <span class="rs-stat__icon"><?= $icon('<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L21 2M17 6l3 3M15 8l2 2"/>') ?></span>
        <div class="rs-stat__label">API keys</div>
        <div class="rs-stat__value"><?= (int) $activeCount ?><small class="rs-stat__of">/ <?= count($resellers) ?></small></div>
        <div class="rs-stat__note">active of issued</div>
    </div>
    <?php if (($stats['domain_requests'] ?? null) !== null): ?>
        <a class="rs-stat rs-stat--link<?= (int) $stats['domain_requests'] > 0 ? ' rs-stat--alert' : ' rs-stat--teal' ?>" href="/admin/resellers/domains">
            <span class="rs-stat__icon"><?= $icon('<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>') ?></span>
            <div class="rs-stat__label">Domain requests</div>
            <div class="rs-stat__value"><?= (int) $stats['domain_requests'] ?></div>
            <div class="rs-stat__note">awaiting your decision &middot; <?= (int) $stats['custom_domains'] ?> verified domains</div>
        </a>
    <?php endif; ?>
    <?php if (($stats['payout_requests'] ?? null) !== null): ?>
        <a class="rs-stat rs-stat--link<?= (int) $stats['payout_requests'] > 0 ? ' rs-stat--alert' : ' rs-stat--violet' ?>" href="/admin/resellers/payouts">
            <span class="rs-stat__icon"><?= $icon('<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>') ?></span>
            <div class="rs-stat__label">Payout requests</div>
            <div class="rs-stat__value"><?= (int) $stats['payout_requests'] ?></div>
            <div class="rs-stat__note">waiting to be paid</div>
        </a>
    <?php endif; ?>
    <?php if (($stats['migrations'] ?? null) !== null): ?>
        <a class="rs-stat rs-stat--link<?= (int) $stats['migrations'] > 0 ? ' rs-stat--alert' : '' ?>" href="/admin/resellers/migrations">
            <span class="rs-stat__icon"><?= $icon('<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>') ?></span>
            <div class="rs-stat__label">Account moves</div>
            <div class="rs-stat__value"><?= (int) $stats['migrations'] ?></div>
            <div class="rs-stat__note">pending review</div>
        </a>
    <?php endif; ?>
</div>

<div class="cv-card" id="rs-platform-address" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Platform address</h2>
    <p>The domain every reseller store gets a free address under. Use a short, neutral domain so your resellers'
        customers see a white-label address and not your hosting brand. With <code>resellerhub.com</code> set here,
        the store named <strong>acme</strong> is served at <code>acme.resellerhub.com</code>.</p>

    <?php if ($storeDomain !== null): ?>
        <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);">
            <strong>Live.</strong> Store addresses are <code>{store name}.<?= e($storeDomain) ?></code>.
            Resellers can still connect their own domain and verify it; that becomes their main address.
        </div>
    <?php else: ?>
        <div class="cv-alert cv-alert--warning" style="margin-bottom:var(--cv-space-4);">
            <strong>Not set.</strong> Stores have no free address: a store's name is just its name, and the store is
            served only once the reseller connects and verifies a domain of their own. Set a domain below to give
            every store <code>{store name}.yourdomain.com</code>.
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/resellers/platform-domain" class="rs-form-grid">
        <?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label" for="platform_domain">Platform address domain</label>
            <input class="cv-input" type="text" id="platform_domain" name="platform_domain" maxlength="253"
                   placeholder="resellerhub.com" autocomplete="off" spellcheck="false"
                   value="<?= e((string) ($storeDomainSetting ?? '')) ?>">
        </div>
        <div class="rs-form-grid__actions">
            <button class="cv-btn" type="submit">Save platform address</button>
        </div>
        <p class="rs-form-grid__wide" style="color:var(--cv-text-secondary);margin:0;">
            A domain only, without <code>https://</code>. Leave it empty and save to switch free addresses off.
            Avoid a subdomain of your client area (such as <code>client.example.com</code>): store addresses would
            become sub-subdomains like <code>acme.client.example.com</code>, which are long and not covered by a
            normal wildcard certificate.
        </p>
    </form>

    <?php if (is_array($dnsCheck)): ?>
        <div class="cv-alert <?= !empty($dnsCheck['ok']) ? 'cv-alert--success' : 'cv-alert--error' ?>" style="margin-top:var(--cv-space-4);">
            <strong>DNS check:</strong> <?= e((string) ($dnsCheck['message'] ?? '')) ?>
        </div>
    <?php endif; ?>

    <?php if ($storeDomainSetting !== null): ?>
        <h3 style="margin-top:var(--cv-space-5);">Make <?= e($storeDomainSetting) ?> work</h3>
        <ol class="rs-steps">
            <li><strong>Wildcard DNS.</strong> At the domain's DNS host, add an <code>A</code> record for
                <code>*.<?= e($storeDomainSetting) ?></code> pointing at this server's IP. You can also use a
                <code>CNAME</code> to <code><?= e($platformHost) ?></code>.
                <form method="post" action="/admin/resellers/platform-domain/check" style="display:inline;">
                    <?= csrf_field() ?>
                    <button class="cv-btn cv-btn--secondary rs-btn-sm" type="submit">Check DNS</button>
                </form>
            </li>
            <li><strong>Web server.</strong> Make the server answer for every subdomain with this platform. On cPanel,
                add <code><?= e($storeDomainSetting) ?></code> as an addon or alias domain, then create the subdomain
                <code>*</code> (a wildcard subdomain). Both must use the platform's document root.</li>
            <li><strong>Wildcard SSL.</strong> Install a certificate for <code>*.<?= e($storeDomainSetting) ?></code>.
                cPanel AutoSSL with Let's Encrypt or Sectigo covers wildcard subdomains when the domain's DNS is on the
                same server. Otherwise issue one with DNS validation (for example <code>certbot --manual
                --preferred-challenges dns -d "*.<?= e($storeDomainSetting) ?>"</code>) or buy one.</li>
        </ol>
    <?php endif; ?>
</div>

<div class="cv-card" id="rs-discounts" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Reseller discounts</h2>
    <p>What every <strong>partner</strong> reseller pays, applied to the catalogue price of each service and domain.
        Clients see these prices in their reseller area and through the API. Current:
        <span class="cv-badge cv-badge--success"><?= e($servicePct) ?>% services</span>
        <span class="cv-badge cv-badge--success"><?= e($domainPct) ?>% domains</span>.
        Sub-resellers do not get this discount — they buy at the prices of the store they registered on.</p>
    <form method="post" action="/admin/resellers/discounts" class="rs-form-grid">
        <?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label" for="discount_services">Discount on services (%)</label>
            <input class="cv-input" type="number" id="discount_services" name="discount_services"
                   min="0" max="100" step="0.01" value="<?= e($servicePct) ?>" required>
        </div>
        <div class="cv-field">
            <label class="cv-label" for="discount_domains">Discount on domain names (%)</label>
            <input class="cv-input" type="number" id="discount_domains" name="discount_domains"
                   min="0" max="100" step="0.01" value="<?= e($domainPct) ?>" required>
        </div>
        <div class="rs-form-grid__actions">
            <button class="cv-btn" type="submit">Save discounts</button>
        </div>
        <p class="rs-form-grid__wide" style="color:var(--cv-text-secondary);margin:0;">Enter a number between 0 and 100. Values outside that range
            are clamped — 100% makes the reseller price zero, which is almost never what you want.</p>
    </form>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Reseller API keys</h2>
    <p><?= count($resellers) ?> key(s) issued, <strong><?= (int) $activeCount ?></strong> active.
        A key is created disabled and only becomes active when the client submits the domain they resell from —
        so an unused row here is normal, and a key that never activated has never been able to call the API.</p>
    <div class="rs-table-scroll">
    <table class="cv-table">
        <thead>
        <tr>
            <th>Client</th><th>Label</th><th>API key</th><th>Reselling domain</th>
            <th>Status</th><th>Issued</th><th>Last used</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($resellers as $reseller): ?>
            <?php $active = (int) ($reseller['active'] ?? 0) === 1; ?>
            <tr>
                <td>
                    <a href="/admin/clients/<?= (int) $reseller['client_id'] ?>">
                        <?= e(trim((string) ($reseller['first_name'] ?? '') . ' ' . (string) ($reseller['last_name'] ?? ''))) ?>
                    </a>
                    <br><span style="color:var(--cv-text-secondary);"><?= e((string) ($reseller['email'] ?? '')) ?></span>
                </td>
                <td><?= e((string) ($reseller['label'] ?? '')) ?></td>
                <td><code><?= e((string) ($reseller['api_key'] ?? '')) ?></code></td>
                <td><?= ($reseller['reseller_domain'] ?? null) !== null
                    ? e((string) $reseller['reseller_domain'])
                    : '<em>not submitted</em>' ?></td>
                <td>
                    <?php if ($active): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">Disabled</span>
                    <?php endif; ?>
                </td>
                <td><?= e((string) ($reseller['created_at'] ?? '')) ?></td>
                <td><?= ($reseller['last_used_at'] ?? null) !== null
                    ? e((string) $reseller['last_used_at'])
                    : '<span style="color:var(--cv-text-secondary);">never</span>' ?></td>
                <td>
                    <form method="post" action="/admin/resellers/<?= (int) $reseller['client_id'] ?>/toggle">
                        <?= csrf_field() ?>
                        <input type="hidden" name="enabled" value="<?= $active ? '0' : '1' ?>">
                        <button class="cv-btn <?= $active ? 'cv-btn--danger' : '' ?> rs-btn-sm" type="submit"><?= $active ? 'Disable' : 'Enable' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($resellers === []): ?>
            <tr><td colspan="8" style="color:var(--cv-text-secondary);">No reseller API keys have been issued yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="cv-card" id="rs-stores">
    <h2 class="cv-card__title">White-label stores</h2>
    <p>Each store serves our catalogue under the reseller's own branding. A custom domain is only served once DNS
        proves the reseller controls it — a domain typed in but unverified is listed here and serves nothing.
        A suspended store returns 503 on its domain rather than showing our shop at our prices.</p>
    <div class="rs-table-scroll">
    <table class="cv-table">
        <thead>
        <tr>
            <th>Reseller ID</th><th>Store</th><th>Reseller (user)</th><th>Tier</th><th>Customers</th><th>Platform address</th><th>Custom domain</th>
            <th>Status</th><th>Opened</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($stores as $store): ?>
            <?php
            $storeVerified = ($store['domain_verified_at'] ?? null) !== null;
            $storeActive = ($store['status'] ?? 'active') === 'active';
            ?>
            <tr>
                <td><span class="rs-id-badge" title="This reseller's unique ID">#<?= (int) $store['id'] ?></span></td>
                <td>
                    <a href="/admin/resellers/<?= (int) $store['client_id'] ?>/store">
                        <?= e((string) ($store['brand_name'] ?? $store['slug'])) ?>
                    </a>
                    <br><code><?= e((string) $store['slug']) ?></code>
                </td>
                <td>
                    <a href="/admin/clients/<?= (int) $store['client_id'] ?>">
                        <?= e(trim((string) ($store['first_name'] ?? '') . ' ' . (string) ($store['last_name'] ?? ''))) ?>
                    </a>
                    <br><span style="color:var(--cv-text-secondary);"><?= e((string) ($store['email'] ?? '')) ?></span>
                    <br><span class="rs-id-badge rs-id-badge--user">User ID <?= (int) $store['client_id'] ?></span>
                </td>
                <td>
                    <?php if ((int) ($store['upline_id'] ?? 0) > 0): ?>
                        <span class="rs-chip rs-chip--tier" title="Registered on another store, so buys at its prices">Sub-reseller</span>
                        <br><span class="rs-cust-sub">of <?= e((string) (($store['upline_brand'] ?? '') !== '' && $store['upline_brand'] !== null ? $store['upline_brand'] : $store['upline_slug'])) ?> (#<?= (int) $store['upline_id'] ?>)</span>
                        <?php if ((int) ($store['upline_upline_id'] ?? 0) > 0): ?>
                            <br><span class="cv-badge cv-badge--warning" title="Its upline is itself a sub-reseller: opened before the two-tier cap">Legacy 3rd tier</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="rs-chip">Partner</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="/admin/resellers/<?= (int) $store['client_id'] ?>/customers"><?= (int) ($store['customer_count'] ?? 0) ?></a>
                    <?php if ((int) ($store['pending_orders'] ?? 0) > 0): ?>
                        <br><a class="cv-badge cv-badge--warning" href="/admin/resellers/<?= (int) $store['client_id'] ?>/customers#orders"><?= (int) $store['pending_orders'] ?> pending orders</a>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($storeDomain !== null): ?>
                        <code><?= e((string) $store['slug']) ?>.<?= e($storeDomain) ?></code>
                    <?php else: ?>
                        <code><?= e((string) $store['slug']) ?></code>
                        <br><span class="rs-cust-sub">not served (no platform address)</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (($store['custom_domain'] ?? null) === null): ?>
                        <em>not set</em>
                    <?php elseif ($storeVerified): ?>
                        <span class="cv-badge cv-badge--success">Verified</span>
                        <code><?= e((string) $store['custom_domain']) ?></code>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">Not verified</span>
                        <code><?= e((string) $store['custom_domain']) ?></code>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($storeActive): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">Suspended</span>
                    <?php endif; ?>
                </td>
                <td><?= e((string) ($store['created_at'] ?? '')) ?></td>
                <td>
                    <div class="rs-row-actions">
                        <a class="cv-btn rs-btn-sm" href="/admin/resellers/<?= (int) $store['client_id'] ?>/store">Manage</a>
                        <a class="cv-btn cv-btn--secondary rs-btn-sm" href="/admin/resellers/<?= (int) $store['client_id'] ?>/customers">Customers</a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($stores === []): ?>
            <tr><td colspan="10" style="color:var(--cv-text-secondary);">No reseller stores have been opened yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
