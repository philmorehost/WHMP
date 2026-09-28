<?php
/** @var array{service: float, domain: float} $discounts */
/** @var array<int, array<string, mixed>> $resellers */
/** @var int $activeCount */
/** @var string|null $error */
/** @var string|null $notice */
/** @var string $docsUrl */

$servicePct = \CodeVault\Reseller\ResellerSettings::formatPercent($discounts['service']);
$domainPct = \CodeVault\Reseller\ResellerSettings::formatPercent($discounts['domain']);
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Resellers</h1>
    <p><a href="<?= e($docsUrl) ?>">Reseller API documentation &rarr;</a>
        &middot; <a href="/admin/resellers/billing">Store cost billing &rarr;</a>
        &middot; <a href="/admin/resellers/accounts">Reseller accounts &rarr;</a></p>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== null && $notice !== ''): ?>
        <div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div>
    <?php endif; ?>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Reseller discounts</h2>
    <p>What every reseller pays, applied to the catalogue price of each service and domain. Clients see these
        prices in their reseller area and through the API. Current: <strong><?= e($servicePct) ?>%</strong> on
        services, <strong><?= e($domainPct) ?>%</strong> on domains.</p>
    <form method="post" action="/admin/resellers/discounts">
        <?= csrf_field() ?>
        <p>
            <label for="discount_services">Discount on services (%)</label><br>
            <input class="cv-input" type="number" id="discount_services" name="discount_services"
                   min="0" max="100" step="0.01" value="<?= e($servicePct) ?>" required>
        </p>
        <p>
            <label for="discount_domains">Discount on domain names (%)</label><br>
            <input class="cv-input" type="number" id="discount_domains" name="discount_domains"
                   min="0" max="100" step="0.01" value="<?= e($domainPct) ?>" required>
        </p>
        <p style="color:var(--cv-text-secondary);">Enter a number between 0 and 100. Values outside that range
            are clamped — 100% makes the reseller price zero, which is almost never what you want.</p>
        <button class="cv-btn" type="submit">Save discounts</button>
    </form>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Reseller API keys</h2>
    <p><?= count($resellers) ?> key(s) issued, <strong><?= (int) $activeCount ?></strong> active.
        A key is created disabled and only becomes active when the client submits the domain they resell from —
        so an unused row here is normal, and a key that never activated has never been able to call the API.</p>
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
                        <button class="cv-btn" type="submit"><?= $active ? 'Disable' : 'Enable' ?></button>
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

<div class="cv-card">
    <h2 class="cv-card__title">White-label stores</h2>
    <p>Each store serves our catalogue under the reseller's own branding. A custom domain is only served once DNS
        proves the reseller controls it — a domain typed in but unverified is listed here and serves nothing.
        A suspended store returns 503 on its domain rather than showing our shop at our prices.</p>
    <table class="cv-table">
        <thead>
        <tr>
            <th>Store</th><th>Client</th><th>Platform address</th><th>Custom domain</th>
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
                </td>
                <td><code><?= e((string) $store['slug']) ?>.<?= e($platformHost) ?></code></td>
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
                    <a class="cv-btn" href="/admin/resellers/<?= (int) $store['client_id'] ?>/store">Manage</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($stores === []): ?>
            <tr><td colspan="7" style="color:var(--cv-text-secondary);">No reseller stores have been opened yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
