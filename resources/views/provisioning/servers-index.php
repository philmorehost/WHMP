<?php
/** @var array<int, array<string, mixed>> $servers */
/** @var array<int, array<string, mixed>> $groups */
/** @var array<int, string> $moduleSlugs */
/** @var CodeVault\View $view */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Servers</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Server Groups</h2>
    <table class="cv-table">
        <thead><tr><th>Name</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($groups as $group): ?>
            <tr>
                <td><?= e($group['name']) ?></td>
                <td>
                    <form method="post" action="/admin/server-groups/<?= (int) $group['id'] ?>/delete"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($groups === []): ?>
            <tr><td colspan="2" style="color:var(--cv-text-secondary);">No server groups yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <form method="post" action="/admin/server-groups" style="margin-top:var(--cv-space-4);display:flex;gap:var(--cv-space-2);align-items:end;"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" required>
        </div>
        <button class="cv-btn" type="submit">Add Group</button>
    </form>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <h2 class="cv-card__title" style="margin:0;">Servers</h2>
        <?= $view->partial('partials.table-search', ['target' => '#servers-table', 'placeholder' => 'Search servers...']) ?>
    </div>
    <table class="cv-table" id="servers-table">
        <thead><tr><th>Name</th><th>Hostname</th><th>Module</th><th>Group</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($servers as $server): ?>
            <tr>
                <td><?= e($server['name']) ?></td>
                <td><?= e($server['hostname']) ?></td>
                <td><?= e($server['module_slug']) ?></td>
                <td><?= e((string) ($server['group_name'] ?? 'None')) ?></td>
                <td>
                    <?php if ($server['active']): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Disabled</span>
                    <?php endif; ?>
                </td>
                <td style="display:flex;gap:var(--cv-space-2);">
                    <a class="cv-btn cv-btn--secondary" href="/admin/servers/<?= (int) $server['id'] ?>/edit">Edit</a>
                    <form method="post" action="/admin/servers/<?= (int) $server['id'] ?>/toggle"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--secondary" type="submit"><?= $server['active'] ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <form method="post" action="/admin/servers/<?= (int) $server['id'] ?>/delete"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($servers === []): ?>
            <tr><td colspan="6" style="color:var(--cv-text-secondary);">No servers yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h3>Add Server</h3>
    <?= $view->partial('provisioning.server-form', [
        'server' => null,
        'groups' => $groups,
        'moduleSlugs' => $moduleSlugs,
        'action' => '/admin/servers',
        'submitLabel' => 'Add Server',
    ]) ?>
</div>
