<?php
/** @var array<int, array{code: string, client_name: string, paid_total: float, pending_total: float, currency_symbol: string, currency_code: string}> $affiliates */

// Commissions are denominated by the invoice that earned them, so an affiliate
// with referrals in two currencies gets one row per currency rather than a
// single blended figure under a hardcoded "$".
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Affiliate Payouts</h1>
    <p><a href="/admin/reports">&larr; Back to reports</a></p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Affiliate</th><th>Code</th><th>Currency</th><th>Paid to Date</th><th>Pending</th></tr></thead>
        <tbody>
        <?php foreach ($affiliates as $affiliate): ?>
            <tr>
                <td><?= e($affiliate['client_name']) ?></td>
                <td><code><?= e($affiliate['code']) ?></code></td>
                <td><?= e((string) ($affiliate['currency_code'] ?? '')) ?></td>
                <td><?= e($affiliate['currency_symbol'] . number_format((float) $affiliate['paid_total'], 2)) ?></td>
                <td><?= e($affiliate['currency_symbol'] . number_format((float) $affiliate['pending_total'], 2)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($affiliates === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No affiliates yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
