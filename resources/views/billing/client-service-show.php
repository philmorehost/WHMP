<?php
/** @var array<string, mixed> $service */
/** @var array<string, mixed>|null $usage */
/** @var string|null $error */
$id = (int) $service['id'];
?>
<div class="cv-card" style="max-width:32rem;margin:0 auto;">
    <h1 class="cv-card__title"><?= e($service['product_name']) ?></h1>
    <p><a href="/client/services">&larr; Back to my services</a></p>

    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <p><strong>Status:</strong>
        <?php if ($service['status'] === 'active'): ?>
            <span class="cv-badge cv-badge--success">Active</span>
        <?php elseif ($service['status'] === 'suspended'): ?>
            <span class="cv-badge cv-badge--danger">Suspended</span>
        <?php else: ?>
            <span class="cv-badge cv-badge--neutral"><?= e($service['status']) ?></span>
        <?php endif; ?>
    </p>
    <p><strong>Billing Cycle:</strong> <?= e($service['billing_cycle']) ?> &middot; <strong>Amount:</strong> $<?= number_format((float) $service['amount'], 2) ?></p>
    <p><strong>Next Due:</strong> <?= e($service['next_due_date']) ?></p>

    <?php if ($usage !== null && ($usage['success'] ?? false)): ?>
        <div class="cv-card" style="margin-top:var(--cv-space-3);">
            <strong>Usage</strong>
            <p>Disk: <?= number_format((float) $usage['diskUsedMb'], 0) ?> / <?= number_format((float) $usage['diskLimitMb'], 0) ?> MB</p>
            <p>Bandwidth: <?= number_format((float) $usage['bandwidthUsedMb'], 0) ?> / <?= number_format((float) $usage['bandwidthLimitMb'], 0) ?> MB</p>
        </div>
    <?php endif; ?>

    <div style="display:flex;gap:var(--cv-space-2);margin-top:var(--cv-space-4);">
        <?php if ($service['status'] === 'active'): ?>
            <form method="post" action="/client/services/<?= $id ?>/sso"><?= csrf_field() ?>
                <button class="cv-btn" type="submit">Log In to Control Panel</button>
            </form>
        <?php endif; ?>
        <?php if (!in_array($service['status'], ['cancelled', 'terminated'], true)): ?>
            <form method="post" action="/client/services/<?= $id ?>/cancel" data-confirm="Request cancellation of this service?"><?= csrf_field() ?>
                <button class="cv-btn cv-btn--danger" type="submit">Cancel Service</button>
            </form>
        <?php endif; ?>
    </div>
</div>
