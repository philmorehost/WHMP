<?php
/** @var array<string, mixed> $affiliate */
/** @var array<int, array<string, mixed>> $referrals */
/** @var float $pendingBalance */
/** @var float $lifetimeTotal */
/** @var array<int, array<string, mixed>> $payoutRequests */
?>
<div class="cv-card" style="max-width:40rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Affiliate Area</h1>
    <p><a href="/client/dashboard">&larr; Back to dashboard</a></p>
    <p><strong>Your referral link:</strong> <code>/client/register?ref=<?= e($affiliate['code']) ?></code></p>
    <p><strong>Commission rate:</strong> <?= number_format((float) $affiliate['commission_rate'], 2) ?>%</p>
    <p><strong>Status:</strong> <?= e($affiliate['status']) ?></p>
</div>

<div class="cv-card" style="max-width:40rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Balance</h2>
    <p><strong>Pending commission:</strong> $<?= number_format($pendingBalance, 2) ?></p>
    <p><strong>Lifetime earned:</strong> $<?= number_format($lifetimeTotal, 2) ?></p>
    <?php if ($pendingBalance > 0): ?>
        <form method="post" action="/client/affiliate/payout"><?= csrf_field() ?>
            <button class="cv-btn" type="submit">Request Payout</button>
        </form>
    <?php endif; ?>
</div>

<div class="cv-card" style="max-width:40rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Referrals</h2>
    <table class="cv-table">
        <thead><tr><th>Client</th><th>Joined</th></tr></thead>
        <tbody>
        <?php foreach ($referrals as $referral): ?>
            <tr>
                <td><?= e($referral['first_name'] . ' ' . $referral['last_name']) ?></td>
                <td><?= e($referral['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($referrals === []): ?>
            <tr><td colspan="2" style="color:var(--cv-text-secondary);">No referrals yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h2 class="cv-card__title">Payout Requests</h2>
    <table class="cv-table">
        <thead><tr><th>Amount</th><th>Status</th><th>Requested</th></tr></thead>
        <tbody>
        <?php foreach ($payoutRequests as $payout): ?>
            <tr>
                <td>$<?= number_format((float) $payout['amount'], 2) ?></td>
                <td><?= e($payout['status']) ?></td>
                <td><?= e($payout['requested_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($payoutRequests === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">No payout requests yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
