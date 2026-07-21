<?php
/** @var array<int, array<string, mixed>> $domains */
/** @var string $statusFilter */
/** @var array<int, string> $defaultNameservers */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Domains</h1>
    <p><a href="/admin">&larr; Back to dashboard</a> &middot; <a href="/admin/domains/create">Add Domain</a> &middot; <a href="/admin/registrars">Registrars</a> &middot; <a href="/admin/domain-pricing">TLD Pricing</a></p>
    <div style="margin-top:var(--cv-space-3);display:flex;gap:var(--cv-space-2);flex-wrap:wrap;align-items:center;">
        <a class="cv-btn <?= $statusFilter === '' ? '' : 'cv-btn--secondary' ?>" href="/admin/domains">
            All <span class="cv-badge cv-badge--neutral" style="margin-left:var(--cv-space-1);"><?= (int) ($statusCounts['all'] ?? 0) ?></span>
        </a>
        <a class="cv-btn <?= $statusFilter === 'active' ? '' : 'cv-btn--secondary' ?>" href="/admin/domains?status=active">
            Active <span class="cv-badge cv-badge--success" style="margin-left:var(--cv-space-1);"><?= (int) ($statusCounts['active'] ?? 0) ?></span>
        </a>
        <a class="cv-btn <?= $statusFilter === 'pending' ? '' : 'cv-btn--secondary' ?>" href="/admin/domains?status=pending">
            Pending <span class="cv-badge cv-badge--neutral" style="margin-left:var(--cv-space-1);"><?= (int) ($statusCounts['pending'] ?? 0) ?></span>
        </a>
        <a class="cv-btn <?= $statusFilter === 'expired' ? '' : 'cv-btn--secondary' ?>" href="/admin/domains?status=expired">
            Expired <span class="cv-badge cv-badge--danger" style="margin-left:var(--cv-space-1);"><?= (int) ($statusCounts['expired'] ?? 0) ?></span>
        </a>
    </div>

    <?php if (!empty($registrarCounts)): ?>
        <div style="margin-top:var(--cv-space-4);padding-top:var(--cv-space-3);border-top:1px solid var(--cv-border-color, #e0e0e0);">
            <h3 style="font-size:var(--cv-text-sm, 13px);color:var(--cv-text-secondary);text-transform:uppercase;letter-spacing:0.05em;margin:0 0 var(--cv-space-2) 0;">Active Domains by Registrar</h3>
            <div style="display:flex;gap:var(--cv-space-2);flex-wrap:wrap;">
                <?php foreach ($registrarCounts as $regSlug => $cnt): ?>
                    <span class="cv-badge cv-badge--neutral" style="padding:var(--cv-space-1) var(--cv-space-3);font-size:var(--cv-text-sm, 13px);">
                        <strong><?= e($regSlug) ?></strong>: <?= (int) $cnt ?> active
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Default Nameservers</h2>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Used to pre-fill new domain registrations when a client doesn't specify their own — same role as WHMCS's General Settings &rsaquo; Domains &rsaquo; Default Nameservers.</p>
    <form method="post" action="/admin/domains/nameservers" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(250px, 1fr));gap:var(--cv-space-3);align-items:end;"><?= csrf_field() ?>
        <?php for ($i = 0; $i < 6; $i++): ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Nameserver <?= $i + 1 ?><?= $i < 2 ? '' : ' (optional)' ?></label>
                <input class="cv-input" name="ns[]" value="<?= e($defaultNameservers[$i] ?? '') ?>" placeholder="ns<?= $i + 1 ?>.yourdomain.com">
            </div>
        <?php endfor; ?>
        <button class="cv-btn" type="submit" style="grid-column:1 / -1;margin-top:var(--cv-space-2);">Save Default Nameservers</button>
    </form>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#domains-table', 'placeholder' => 'Search domains...']) ?>
    </div>
    <table class="cv-table" id="domains-table">
        <thead><tr><th>Domain</th><th>Client</th><th>Registrar</th><th>Expiry</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($domains as $domain): ?>
            <tr>
                <td><?= e($domain['domain_name']) ?></td>
                <td><?= e($domain['first_name'] . ' ' . $domain['last_name']) ?> (<?= e($domain['client_email']) ?>)</td>
                <td><?= e($domain['registrar_slug']) ?></td>
                <td><?= e((string) ($domain['expiry_date'] ?? '-')) ?></td>
                <td>
                    <?php if ($domain['status'] === 'active'): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php elseif ($domain['status'] === 'expired'): ?>
                        <span class="cv-badge cv-badge--danger">Expired</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral"><?= e($domain['status']) ?></span>
                    <?php endif; ?>
                </td>
                <td><a class="cv-btn cv-btn--secondary" href="/admin/domains/<?= (int) $domain['id'] ?>">Manage</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($domains === []): ?>
            <tr><td colspan="6" style="color:var(--cv-text-secondary);">No domains yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
