<?php
/** @var array<int, array<string, mixed>> $services */
?>
<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h1 class="cv-card__title">My Services</h1>
    <p><a href="/client/dashboard">&larr; Back to dashboard</a></p>

    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#my-services-table', 'placeholder' => 'Search services...']) ?>
    </div>
    <table class="cv-table" id="my-services-table">
        <thead><tr><th>Product</th><th>Cycle</th><th>Next Due</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($services as $service): ?>
            <tr>
                <td><?= e($service['product_name']) ?></td>
                <td><?= e($service['billing_cycle']) ?></td>
                <td><?= e($service['next_due_date']) ?></td>
                <td>
                    <?php if ($service['status'] === 'active'): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php elseif ($service['status'] === 'suspended'): ?>
                        <span class="cv-badge cv-badge--danger">Suspended</span>
                    <?php elseif ($service['status'] === 'cancelled'): ?>
                        <span class="cv-badge cv-badge--neutral">Cancelled</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral"><?= e($service['status']) ?></span>
                    <?php endif; ?>
                </td>
                <td><a class="cv-btn cv-btn--secondary" href="/client/services/<?= (int) $service['id'] ?>">Manage</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($services === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No services yet. <a href="/store">Browse the store</a>.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
