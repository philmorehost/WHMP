<?php
/** @var array<int, array{slug: string, metadata: array{name: string, description: string, version: string, author: string}, active: bool}> $customReports */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Reports</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
    <ul style="margin-top:var(--cv-space-3);">
        <li><a href="/admin/reports/income">Income Summary</a></li>
        <li><a href="/admin/reports/tax-liability">Tax Liability</a></li>
        <li><a href="/admin/reports/aged-debtors">Aged Debtors</a></li>
        <li><a href="/admin/reports/product-breakdown">Product Breakdown</a></li>
        <li><a href="/admin/reports/affiliate-payouts">Affiliate Payouts</a></li>
    </ul>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Custom Reports</h2>
    <p style="color:var(--cv-text-secondary);">Installable custom reports (blueprint §3/§4.3 <code>ReportModule</code> SDK). Activating one makes it runnable below.</p>
    <table class="cv-table" style="margin-top:var(--cv-space-3);">
        <thead><tr><th>Report</th><th>Version</th><th>Author</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($customReports as $report): ?>
            <tr>
                <td>
                    <strong><?= e($report['metadata']['name']) ?></strong>
                    <div style="color:var(--cv-text-secondary); font-size:var(--cv-text-sm);"><?= e($report['metadata']['description']) ?></div>
                </td>
                <td><?= e($report['metadata']['version']) ?></td>
                <td><?= e($report['metadata']['author']) ?></td>
                <td>
                    <?php if ($report['active']): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Inactive</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($report['active']): ?>
                        <a class="cv-btn" href="/admin/reports/modules/<?= e($report['slug']) ?>">Run</a>
                        <form method="post" action="/admin/reports/modules/<?= e($report['slug']) ?>/deactivate" style="display:inline;"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--secondary" type="submit">Deactivate</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/admin/reports/modules/<?= e($report['slug']) ?>/activate" style="display:inline;"><?= csrf_field() ?>
                            <button class="cv-btn" type="submit">Activate</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($customReports === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No custom reports registered.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
