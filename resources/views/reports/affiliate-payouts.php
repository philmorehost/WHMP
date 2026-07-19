<?php
/** @var array<int, array{code: string, client_name: string, paid_total: mixed, pending_total: mixed}> $affiliates */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Affiliate Payouts</h1>
    <p><a href="/admin/reports">&larr; Back to reports</a></p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Affiliate</th><th>Code</th><th>Paid to Date</th><th>Pending</th></tr></thead>
        <tbody>
        <?php foreach ($affiliates as $affiliate): ?>
            <tr>
                <td><?= e($affiliate['client_name']) ?></td>
                <td><code><?= e($affiliate['code']) ?></code></td>
                <td>$<?= number_format((float) $affiliate['paid_total'], 2) ?></td>
                <td>$<?= number_format((float) $affiliate['pending_total'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($affiliates === []): ?>
            <tr><td colspan="4" style="color:var(--cv-text-secondary);">No affiliates yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
