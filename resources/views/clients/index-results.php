<?php
/** @var array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int} $results */
/** @var string $search */
/** @var array<string, string> $filters */
/** @var array{column: string, dir: string}|null $sort */
/** @var array<int, array<string, mixed>> $filterColumns */
$totalPages = max(1, (int) ceil($results['total'] / $results['perPage']));
?>

<?php if (($results['data'] ?? []) !== []): ?>
<div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
    <?= $view->partial('partials.table-filter', [
        'formId' => 'clients-filter',
        'action' => '/admin/clients',
        'filters' => $filters ?? [],
        'preserve' => ['q' => $search ?? ''],
        'sort' => $sort ?? null,
        'activeCount' => count($filters ?? []),
    ]) ?>
</div>
<?php endif; ?>

<form method="post" action="/admin/clients/bulk-delete" id="bulk-clients-form" data-confirm="Are you sure you want to delete the selected client account(s)? All associated services and data will be permanently removed.">
    <?= csrf_field() ?>

    <!-- Clients Table -->
    <div class="admin-table-card">
        <?php if ($results['data'] === []): ?>
            <div class="admin-empty-state">
                <div class="admin-empty-state__icon">👤</div>
                <h2 class="admin-empty-state__title">No Clients Found</h2>
                <p class="admin-empty-state__text">
                    <?= !empty($filters) ? 'No clients match the active column filters.' : (!empty($search) ? 'Try adjusting your search criteria.' : 'Start by creating your first client.') ?>
                </p>
            </div>
        <?php else: ?>
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;"><input type="checkbox" id="select-all-clients" aria-label="Select all clients"></th>
                            <?= $view->partial('partials.table-header-sort', ['key' => 'name', 'label' => 'Name / Email', 'action' => '/admin/clients', 'filters' => $filters ?? [], 'preserve' => ['q' => $search ?? ''], 'sort' => $sort ?? null]) ?>
                            <?= $view->partial('partials.table-header-sort', ['key' => 'company', 'label' => 'Company', 'action' => '/admin/clients', 'filters' => $filters ?? [], 'preserve' => ['q' => $search ?? ''], 'sort' => $sort ?? null]) ?>
                            <?= $view->partial('partials.table-header-sort', ['key' => 'group', 'label' => 'Group', 'action' => '/admin/clients', 'filters' => $filters ?? [], 'preserve' => ['q' => $search ?? ''], 'sort' => $sort ?? null]) ?>
                            <?= $view->partial('partials.table-header-sort', ['key' => 'status', 'label' => 'Status', 'action' => '/admin/clients', 'filters' => $filters ?? [], 'preserve' => ['q' => $search ?? ''], 'sort' => $sort ?? null]) ?>
                            <th>Services</th>
                            <?= $view->partial('partials.table-header-sort', ['key' => 'joined', 'label' => 'Joined', 'action' => '/admin/clients', 'filters' => $filters ?? [], 'preserve' => ['q' => $search ?? ''], 'sort' => $sort ?? null]) ?>
                            <th style="width: 140px; text-align: center;">Actions</th>
                        </tr>
                        <?= $view->partial('partials.table-filter-row', [
                            'formId' => 'clients-filter',
                            'action' => '/admin/clients',
                            'columns' => $filterColumns ?? [],
                            'filters' => $filters ?? [],
                            'preserve' => ['q' => $search ?? ''],
                        ]) ?>
                    </thead>
                    <tbody>
                    <?php foreach ($results['data'] as $client): ?>
                        <tr>
                            <td style="text-align: center;">
                                <input type="checkbox" name="client_ids[]" value="<?= (int) $client['id'] ?>" class="client-checkbox">
                            </td>
                            <td>
                                <div class="admin-table__name">
                                    <a href="/admin/clients/<?= (int) $client['id'] ?>" class="admin-table__name-main">
                                        <?= e($client['first_name'] . ' ' . $client['last_name']) ?>
                                    </a>
                                    <span class="admin-table__name-email"><?= e($client['email']) ?></span>
                                </div>
                            </td>
                            <td><?= e((string) ($client['company_name'] ?? '-')) ?></td>
                            <td><?= e((string) ($client['group_name'] ?? 'None')) ?></td>
                            <td>
                                <?php if ($client['status'] === 'active'): ?>
                                    <span class="admin-badge admin-badge--active">Active</span>
                                <?php elseif ($client['status'] === 'closed'): ?>
                                    <span class="admin-badge admin-badge--closed">Closed</span>
                                <?php else: ?>
                                    <span class="admin-badge admin-badge--inactive">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="admin-table__services">
                                    <span class="admin-table__services-active"><?= (int) $client['services_active'] ?></span>
                                    <span class="admin-table__services-total">/ <?= (int) $client['services_total'] ?></span>
                                </span>
                            </td>
                            <td style="font-size: .85rem; color: var(--cv-text-secondary);"><?= e((string) $client['created_at']) ?></td>
                            <td style="text-align: center; white-space: nowrap;">
                                <a href="/admin/clients/<?= (int) $client['id'] ?>" class="admin-btn admin-btn--secondary" style="padding: 6px 12px; font-size: .75rem; margin: 0 4px 0 0;">View</a>
                                <button type="submit" form="delete-client-form-<?= (int) $client['id'] ?>" class="admin-btn admin-btn--danger" style="padding: 6px 10px; font-size: .75rem; margin: 0; background:rgba(239,68,68,.2); border:1px solid rgba(239,68,68,.4); color:#ef4444;" title="Delete Client">🗑️</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?= $view->partial('partials.table-pagination', [
                'results' => $results,
                'action' => '/admin/clients',
                'filters' => $filters ?? [],
                'preserve' => ['q' => $search ?? ''],
                'sort' => $sort ?? null,
                'label' => 'clients',
            ]) ?>
        <?php endif; ?>
    </div>
</form>

<!-- Single Delete Forms per Client Row -->
<?php if ($results['data'] !== []): ?>
    <?php foreach ($results['data'] as $client): ?>
        <form id="delete-client-form-<?= (int) $client['id'] ?>" method="post" action="/admin/clients/<?= (int) $client['id'] ?>/delete" data-confirm="Are you sure you want to delete client account for <?= e($client['first_name'] . ' ' . $client['last_name']) ?> (<?= e($client['email']) ?>)? All associated services and data will be permanently removed.">
            <?= csrf_field() ?>
        </form>
    <?php endforeach; ?>
<?php endif; ?>
