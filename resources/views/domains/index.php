<?php
/** @var array<int, array<string, mixed>> $domains */
/** @var string $statusFilter */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Domains</h1>
    <p><a href="/admin">&larr; Back to dashboard</a> &middot; <a href="/admin/domains/create">Add Domain</a> &middot; <a href="/admin/registrars">Registrars</a></p>
    <div style="margin-top:var(--cv-space-2);">
        <a class="cv-btn <?= $statusFilter === '' ? '' : 'cv-btn--secondary' ?>" href="/admin/domains">All</a>
        <a class="cv-btn <?= $statusFilter === 'active' ? '' : 'cv-btn--secondary' ?>" href="/admin/domains?status=active">Active</a>
        <a class="cv-btn <?= $statusFilter === 'pending' ? '' : 'cv-btn--secondary' ?>" href="/admin/domains?status=pending">Pending</a>
        <a class="cv-btn <?= $statusFilter === 'expired' ? '' : 'cv-btn--secondary' ?>" href="/admin/domains?status=expired">Expired</a>
    </div>
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
