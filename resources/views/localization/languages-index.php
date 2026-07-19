<?php
/** @var array<int, array<string, mixed>> $languages */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Languages</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Name</th><th>Code</th><th>RTL</th><th>Default</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($languages as $language): ?>
            <tr>
                <td><?= e($language['name']) ?></td>
                <td><?= e($language['code']) ?></td>
                <td><?= (int) $language['is_rtl'] === 1 ? 'Yes' : 'No' ?></td>
                <td>
                    <?php if ((int) $language['is_default'] === 1): ?>
                        <span class="cv-badge cv-badge--success">Default</span>
                    <?php else: ?>
                        <form method="post" action="/admin/languages/<?= (int) $language['id'] ?>/default"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--secondary" type="submit">Make Default</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((int) $language['is_active'] === 1): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Inactive</span>
                    <?php endif; ?>
                </td>
                <td style="display:flex;gap:var(--cv-space-2);">
                    <a class="cv-btn cv-btn--secondary" href="/admin/languages/<?= (int) $language['id'] ?>/translations">Edit Strings</a>
                    <?php if ((int) $language['is_default'] !== 1): ?>
                        <form method="post" action="/admin/languages/<?= (int) $language['id'] ?>/toggle-active"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--secondary" type="submit"><?= (int) $language['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);">
        Only active languages appear in the storefront language switcher. The default language cannot be deactivated.
    </p>
</div>
