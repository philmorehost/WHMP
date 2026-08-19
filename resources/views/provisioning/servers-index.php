<?php
/** @var array<int, array<string, mixed>> $servers */
/** @var array<int, array<string, mixed>> $groups */
/** @var array<int, string> $moduleSlugs */
/** @var CodeVault\View $view */
?>
<style>
.admin-srv-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-srv-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-srv-hero__back {
    position: relative;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    font-size: .9rem;
    margin-bottom: 12px;
}
.admin-srv-hero__title {
    position: relative;
    z-index: 1;
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
}
.admin-srv-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
}
.admin-srv-card__title {
    font-weight: 800;
    font-size: 1.25rem;
    color: var(--cv-text-primary);
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
    margin: 0;
}
.admin-srv-card__body {
    padding: 24px;
}
.admin-srv-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-srv-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-srv-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
}
.admin-srv-table td {
    padding: 12px 16px;
    color: var(--cv-text-primary);
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-srv-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-srv-badge--active {
    background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15));
    color: #10b981;
    border: 1px solid rgba(16,185,129,.3);
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-block;
    font-size: .7rem;
    font-weight: 700;
}
.admin-srv-badge--disabled {
    background: linear-gradient(135deg, rgba(107,114,128,.2), rgba(75,85,99,.15));
    color: #6b7280;
    border: 1px solid rgba(107,114,128,.3);
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-block;
    font-size: .7rem;
    font-weight: 700;
}
.admin-srv-field {
    margin-bottom: 16px;
}
.admin-srv-field label {
    display: block;
    font-size: .85rem;
    font-weight: 700;
    color: var(--cv-text-secondary);
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 6px;
}
.admin-srv-field input, .admin-srv-field select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .9rem;
    box-sizing: border-box;
}
.admin-srv-field input:focus, .admin-srv-field select:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.admin-srv-btn {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 6px;
    padding: 8px 16px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    transition: all 0.2s;
}
.admin-srv-btn:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
}
.admin-srv-btn--secondary {
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    border: 1px solid var(--cv-border-default);
}
.admin-srv-btn--secondary:hover {
    background: var(--cv-bg-surface-sunken);
    border-color: var(--cv-color-brand-500);
}
.admin-srv-btn--danger {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-srv-btn--danger:hover {
    background: rgba(239,68,68,.3);
}
.admin-srv-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
}
.admin-srv-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}
.admin-srv-toolbar > div {
    flex: 1;
}
@media (max-width: 768px) {
    .admin-srv-hero {
        padding: 32px 24px;
    }
    .admin-srv-hero__title {
        font-size: 1.5rem;
    }
}
</style>

<div class="admin-srv-hero">
    <a href="/admin" class="admin-srv-hero__back">← Back to Dashboard</a>
    <h1 class="admin-srv-hero__title">🖥️ Servers</h1>
</div>

<div class="admin-srv-card">
    <h2 class="admin-srv-card__title">📦 Server Groups</h2>
    <div class="admin-srv-card__body" style="padding:0;">
        <div style="overflow-x:auto;">
            <table class="admin-srv-table">
                <thead><tr><th>Name</th><th style="width:150px;">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($groups as $group): ?>
                    <tr>
                        <td><?= e($group['name']) ?></td>
                        <td style="display:flex;gap:8px;">
                            <button type="button" class="admin-srv-btn--secondary" style="padding:6px 12px;font-size:.75rem;border-radius:6px;border:1px solid var(--cv-border-default);"
                                data-edit-trigger
                                data-edit-form="#server-group-form"
                                data-edit-fields="<?= e(json_encode(['name' => $group['name']])) ?>"
                                data-edit-action="/admin/server-groups/<?= (int) $group['id'] ?>"
                                data-edit-submit-label="Update Group">Edit</button>
                            <form method="post" action="/admin/server-groups/<?= (int) $group['id'] ?>/delete" style="margin:0;">
                                <?= csrf_field() ?>
                                <button class="admin-srv-btn--danger" style="padding:6px 12px;font-size:.75rem;border-radius:6px;" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($groups === []): ?>
                    <tr><td colspan="2" style="color:var(--cv-text-secondary);text-align:center;padding:32px;">No server groups yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <form id="server-group-form" method="post" action="/admin/server-groups" style="padding:24px;border-top:1px solid var(--cv-border-default);display:flex;gap:12px;align-items:end;"><?= csrf_field() ?>
            <div class="admin-srv-field" style="margin-bottom:0;flex:1;min-width:200px;">
                <label>Name</label>
                <input name="name" id="server-group-name" required>
            </div>
            <button class="admin-srv-btn" type="submit" data-edit-submit>➕ Add Group</button>
            <button class="admin-srv-btn--secondary" type="button" style="display:none;padding:8px 16px;border-radius:6px;border:1px solid var(--cv-border-default);"
                data-edit-cancel
                data-edit-form="#server-group-form"
                data-edit-reset-action="/admin/server-groups"
                data-edit-reset-label="Add Group">Cancel</button>
        </form>
    </div>
</div>

<div class="admin-srv-card">
    <div class="admin-srv-toolbar" style="padding:24px 24px 0 24px;">
        <h2 class="admin-srv-card__title" style="padding:0;border:none;margin:0;">🖥️ Servers</h2>
        <div style="display:flex;align-items:center;gap:12px;">
            <?= $view->partial('partials.table-search', ['target' => '#servers-table', 'placeholder' => 'Search servers...']) ?>
            <?= $view->partial('partials.table-filter', [
                'formId' => 'servers-filter',
                'action' => '/admin/servers',
                'filters' => $filters ?? [],
                'preserve' => [],
                'activeCount' => count($filters ?? []),
            ]) ?>
        </div>
    </div>
    <div style="overflow-x:auto;">
        <table class="admin-srv-table" id="servers-table">
            <thead>
                <tr>
                    <th data-col-filter="servers-filter" data-col-filter-key="name">Name</th>
                    <th data-col-filter="servers-filter" data-col-filter-key="hostname">Hostname</th>
                    <th data-col-filter="servers-filter" data-col-filter-key="module">Module</th>
                    <th data-col-filter="servers-filter" data-col-filter-key="group">Group</th>
                    <th data-col-filter="servers-filter" data-col-filter-key="status">Status</th>
                    <th style="width:300px;">Actions</th>
                </tr>
                <?= $view->partial('partials.table-filter-row', [
                    'formId' => 'servers-filter',
                    'action' => '/admin/servers',
                    'columns' => $filterColumns ?? [],
                    'filters' => $filters ?? [],
                    'preserve' => [],
                ]) ?>
            </thead>
            <tbody>
            <?php foreach ($servers as $server): ?>
                <tr>
                    <td><strong><?= e($server['name']) ?></strong></td>
                    <td><code style="background:var(--cv-bg-surface-sunken);padding:2px 6px;border-radius:4px;font-size:.85rem;"><?= e($server['hostname']) ?></code></td>
                    <td><?= e($server['module_slug']) ?></td>
                    <td><?= e((string) ($server['group_name'] ?? 'None')) ?></td>
                    <td>
                        <?php if ($server['active']): ?>
                            <span class="admin-srv-badge--active">Active</span>
                        <?php else: ?>
                            <span class="admin-srv-badge--disabled">Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                        <a class="admin-srv-btn--secondary" style="padding:6px 12px;font-size:.75rem;border-radius:6px;border:1px solid var(--cv-border-default);text-decoration:none;display:inline-block;" href="/admin/servers/<?= (int) $server['id'] ?>/edit">✏️ Edit</a>
                        <button type="button" class="admin-srv-btn--secondary" style="padding:6px 12px;font-size:.75rem;border-radius:6px;border:1px solid var(--cv-border-default);" data-test-server="<?= (int) $server['id'] ?>" data-token="<?= e(csrf_token()) ?>">🔌 Test</button>
                        <form method="post" action="/admin/servers/<?= (int) $server['id'] ?>/toggle" style="margin:0;">
                            <?= csrf_field() ?>
                            <button class="admin-srv-btn--secondary" style="padding:6px 12px;font-size:.75rem;border-radius:6px;border:1px solid var(--cv-border-default);" type="submit"><?= $server['active'] ? '⊘ Disable' : '✓ Enable' ?></button>
                        </form>
                        <form method="post" action="/admin/servers/<?= (int) $server['id'] ?>/delete" style="margin:0;">
                            <?= csrf_field() ?>
                            <button class="admin-srv-btn--danger" style="padding:6px 12px;font-size:.75rem;border-radius:6px;" type="submit">🗑️ Delete</button>
                        </form>
                        <span class="server-test-result" style="font-size:.75rem;"></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($servers === []): ?>
                <tr><td colspan="6" style="color:var(--cv-text-secondary);text-align:center;padding:32px;">No servers yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (($results['data'] ?? []) !== []): ?>
        <?= $view->partial('partials.table-pagination', [
            'results' => $results,
            'action' => '/admin/servers',
            'filters' => $filters ?? [],
            'preserve' => [],
            'label' => 'servers',
        ]) ?>
    <?php endif; ?>
</div>

<div class="admin-srv-card">
    <h2 class="admin-srv-card__title">➕ Add Server</h2>
    <div class="admin-srv-card__body">
        <?= $view->partial('provisioning.server-form', [
            'server' => null,
            'groups' => $groups,
            'moduleSlugs' => $moduleSlugs,
            'action' => '/admin/servers',
            'submitLabel' => 'Add Server',
        ]) ?>
    </div>
</div>
