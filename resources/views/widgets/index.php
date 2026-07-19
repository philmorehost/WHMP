<?php
/** @var array<int, array{slug: string, metadata: array{name: string, description: string, version: string, author: string}, active: bool}> $widgets */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Dashboard Widgets</h1>
    <p style="color:var(--cv-text-secondary);">Installable dashboard panels (blueprint §3/§4.3 <code>WidgetModule</code> SDK). An activated widget renders on the admin dashboard; deactivating removes it immediately.</p>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Widget</th><th>Version</th><th>Author</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($widgets as $widget): ?>
            <tr>
                <td>
                    <strong><?= e($widget['metadata']['name']) ?></strong>
                    <div style="color:var(--cv-text-secondary); font-size:var(--cv-text-sm);"><?= e($widget['metadata']['description']) ?></div>
                </td>
                <td><?= e($widget['metadata']['version']) ?></td>
                <td><?= e($widget['metadata']['author']) ?></td>
                <td>
                    <?php if ($widget['active']): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Inactive</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($widget['active']): ?>
                        <form method="post" action="/admin/widgets/<?= e($widget['slug']) ?>/deactivate" style="display:inline;"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--secondary" type="submit">Deactivate</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/admin/widgets/<?= e($widget['slug']) ?>/activate" style="display:inline;"><?= csrf_field() ?>
                            <button class="cv-btn" type="submit">Activate</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($widgets === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No widgets registered.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
