<?php
/** @var array<int, array<string, mixed>> $affiliates */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Affiliates</h1>
    <p><a href="/admin">&larr; Back to dashboard</a> &middot; <a href="/admin/affiliates/payouts">Payout Requests</a></p>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#affiliates-table', 'placeholder' => 'Search affiliates...']) ?>
    </div>
    <table class="cv-table" id="affiliates-table">
        <thead><tr><th>Client</th><th>Code</th><th>Rate</th><th>Pending Balance</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($affiliates as $affiliate): ?>
            <tr>
                <td><?= e($affiliate['first_name'] . ' ' . $affiliate['last_name']) ?> (<?= e($affiliate['email']) ?>)</td>
                <td><code><?= e($affiliate['code']) ?></code></td>
                <td><?= number_format((float) $affiliate['commission_rate'], 2) ?>%</td>
                <td><?= e($affiliate['currency_symbol'] ?? '$') ?><?= number_format((float) $affiliate['pending_balance'], 2) ?></td>
                <td>
                    <?php if ($affiliate['status'] === 'active'): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Suspended</span>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" action="/admin/affiliates/<?= (int) $affiliate['id'] ?>/status"><?= csrf_field() ?>
                        <input type="hidden" name="status" value="<?= $affiliate['status'] === 'active' ? 'suspended' : 'active' ?>">
                        <button class="cv-btn cv-btn--secondary" type="submit"><?= $affiliate['status'] === 'active' ? 'Suspend' : 'Reactivate' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($affiliates === []): ?>
            <tr><td colspan="6" style="color:var(--cv-text-secondary);">No affiliates yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
