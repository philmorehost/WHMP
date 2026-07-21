<?php
/** @var array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int} $results */
/** @var string $statusFilter */
$totalPages = (int) ceil(($results['total'] ?? 0) / ($results['perPage'] ?? 20));
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Services</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
    <div style="margin-top:var(--cv-space-2);display:flex;gap:var(--cv-space-2);flex-wrap:wrap;">
        <a class="cv-btn <?= $statusFilter === '' ? '' : 'cv-btn--secondary' ?>" href="/admin/services">All</a>
        <a class="cv-btn <?= $statusFilter === 'active' ? '' : 'cv-btn--secondary' ?>" href="/admin/services?status=active">Active</a>
        <a class="cv-btn <?= $statusFilter === 'suspended' ? '' : 'cv-btn--secondary' ?>" href="/admin/services?status=suspended">Suspended</a>
        <a class="cv-btn <?= $statusFilter === 'pending' ? '' : 'cv-btn--secondary' ?>" href="/admin/services?status=pending">Pending</a>
    </div>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#services-table', 'placeholder' => 'Search services...']) ?>
    </div>
    <div style="overflow-x:auto;">
        <table class="cv-table" id="services-table">
            <thead><tr><th>Client</th><th>Product</th><th>Cycle</th><th>Amount</th><th>Next Due</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($results['data'] as $service): ?>
                <tr>
                    <td><?= e($service['first_name'] . ' ' . $service['last_name']) ?> (<?= e($service['client_email']) ?>)</td>
                    <td><?= e($service['product_name']) ?></td>
                    <td><?= e($service['billing_cycle']) ?></td>
                    <td><?= e($service['currency_symbol'] ?? '$') ?><?= number_format((float) $service['amount'], 2) ?></td>
                    <td><?= e($service['next_due_date']) ?></td>
                    <td>
                        <?php if ($service['status'] === 'active'): ?>
                            <span class="cv-badge cv-badge--success">Active</span>
                        <?php elseif ($service['status'] === 'suspended'): ?>
                            <span class="cv-badge cv-badge--danger">Suspended</span>
                        <?php else: ?>
                            <span class="cv-badge cv-badge--neutral"><?= e($service['status']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><a class="cv-btn cv-btn--secondary" href="/admin/services/<?= (int) $service['id'] ?>">Manage</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($results['data'] === []): ?>
                <tr><td colspan="7" style="color:var(--cv-text-secondary);">No services found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="cv-datatable__pagination" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--cv-space-2);margin-top:var(--cv-space-4);">
        <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Page <?= $results['page'] ?> of <?= max(1, $totalPages) ?> (<?= $results['total'] ?> total services)</span>
        <div style="display:flex;gap:var(--cv-space-2);">
            <?php if ($results['page'] > 1): ?>
                <a class="cv-btn cv-btn--secondary" href="/admin/services?status=<?= urlencode($statusFilter) ?>&page=<?= $results['page'] - 1 ?>">Previous</a>
            <?php endif; ?>
            <?php if ($results['page'] < $totalPages): ?>
                <a class="cv-btn cv-btn--secondary" href="/admin/services?status=<?= urlencode($statusFilter) ?>&page=<?= $results['page'] + 1 ?>">Next</a>
            <?php endif; ?>
        </div>
    </div>
</div>
