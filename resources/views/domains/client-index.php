<?php
/** @var array<int, array<string, mixed>> $domains */
?>
<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h1 class="cv-card__title">My Domains</h1>
    <p><a href="/client/dashboard">&larr; Back to dashboard</a></p>

    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#my-domains-table', 'placeholder' => 'Search domains...']) ?>
    </div>
    <table class="cv-table" id="my-domains-table">
        <thead><tr><th>Domain</th><th>Expiry</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($domains as $domain): ?>
            <tr>
                <td><?= e($domain['domain_name']) ?></td>
                <td><?= e((string) ($domain['expiry_date'] ?? '-')) ?></td>
                <td>
                    <?php if ($domain['status'] === 'active'): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral"><?= e($domain['status']) ?></span>
                    <?php endif; ?>
                </td>
                <td><a class="cv-btn cv-btn--secondary" href="/client/domains/<?= (int) $domain['id'] ?>">Manage</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($domains === []): ?>
            <tr><td colspan="4" style="color:var(--cv-text-secondary);">No domains yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
