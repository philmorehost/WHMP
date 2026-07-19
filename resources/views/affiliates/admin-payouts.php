<?php
/** @var array<int, array<string, mixed>> $payoutRequests */
/** @var string $statusFilter */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Affiliate Payout Requests</h1>
    <p><a href="/admin/affiliates">&larr; Back to affiliates</a></p>
    <div style="margin-top:var(--cv-space-2);">
        <a class="cv-btn <?= $statusFilter === '' ? '' : 'cv-btn--secondary' ?>" href="/admin/affiliates/payouts">All</a>
        <a class="cv-btn <?= $statusFilter === 'requested' ? '' : 'cv-btn--secondary' ?>" href="/admin/affiliates/payouts?status=requested">Requested</a>
        <a class="cv-btn <?= $statusFilter === 'paid' ? '' : 'cv-btn--secondary' ?>" href="/admin/affiliates/payouts?status=paid">Paid</a>
        <a class="cv-btn <?= $statusFilter === 'rejected' ? '' : 'cv-btn--secondary' ?>" href="/admin/affiliates/payouts?status=rejected">Rejected</a>
    </div>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Affiliate</th><th>Amount</th><th>Status</th><th>Requested</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($payoutRequests as $payout): ?>
            <tr>
                <td><?= e($payout['first_name'] . ' ' . $payout['last_name']) ?> (<code><?= e($payout['code']) ?></code>)</td>
                <td>$<?= number_format((float) $payout['amount'], 2) ?></td>
                <td>
                    <?php if ($payout['status'] === 'paid'): ?>
                        <span class="cv-badge cv-badge--success">Paid</span>
                    <?php elseif ($payout['status'] === 'rejected'): ?>
                        <span class="cv-badge cv-badge--neutral">Rejected</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">Requested</span>
                    <?php endif; ?>
                </td>
                <td><?= e($payout['requested_at']) ?></td>
                <td>
                    <?php if ($payout['status'] === 'requested'): ?>
                        <form method="post" action="/admin/affiliates/payouts/<?= (int) $payout['id'] ?>/approve" style="display:inline;"><?= csrf_field() ?>
                            <button class="cv-btn" type="submit">Approve</button>
                        </form>
                        <form method="post" action="/admin/affiliates/payouts/<?= (int) $payout['id'] ?>/reject" style="display:inline;"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--danger" type="submit">Reject</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($payoutRequests === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No payout requests.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
