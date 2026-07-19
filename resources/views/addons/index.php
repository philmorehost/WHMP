<?php
/** @var array<int, array{slug: string, metadata: array{name: string, description: string, version: string, author: string}, active: bool}> $addons */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Addons</h1>
    <p style="color:var(--cv-text-secondary);">Installable admin-area apps (blueprint §3 <code>AddonModule</code> SDK). Activating an addon wires its hook listeners in; deactivating removes them again.</p>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Addon</th><th>Version</th><th>Author</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($addons as $addon): ?>
            <tr>
                <td>
                    <strong><?= e($addon['metadata']['name']) ?></strong>
                    <div style="color:var(--cv-text-secondary); font-size:var(--cv-text-sm);"><?= e($addon['metadata']['description']) ?></div>
                </td>
                <td><?= e($addon['metadata']['version']) ?></td>
                <td><?= e($addon['metadata']['author']) ?></td>
                <td>
                    <?php if ($addon['active']): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Inactive</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($addon['active']): ?>
                        <a class="cv-btn cv-btn--secondary" href="/admin/addons/<?= e($addon['slug']) ?>">Open</a>
                        <form method="post" action="/admin/addons/<?= e($addon['slug']) ?>/deactivate" style="display:inline;"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--secondary" type="submit">Deactivate</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/admin/addons/<?= e($addon['slug']) ?>/activate" style="display:inline;"><?= csrf_field() ?>
                            <button class="cv-btn" type="submit">Activate</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($addons === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No addons registered.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
