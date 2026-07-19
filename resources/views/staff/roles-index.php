<?php
/** @var array<int, array<string, mixed>> $roles */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Roles</h1>
    <p><a href="/admin/staff">&larr; Back to staff</a></p>
</div>

<?php if (!empty($error)): ?>
    <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
<?php endif; ?>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <h2 class="cv-card__title" style="margin:0;">Roles &amp; Permission Matrix</h2>
        <a class="cv-btn" href="/admin/roles/create">Add Role</a>
    </div>
    <table class="cv-table">
        <thead><tr><th>Name</th><th>Type</th><th>Permissions</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($roles as $role): ?>
            <tr>
                <td><?= e($role['name']) ?></td>
                <td>
                    <?php if ($role['is_super_admin']): ?>
                        <span class="cv-badge cv-badge--king">Super Admin</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Scoped</span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--cv-text-secondary);">
                    <?= $role['is_super_admin'] ? 'All permissions' : e(implode(', ', $role['permissions']) ?: 'None') ?>
                </td>
                <td>
                    <a class="cv-btn cv-btn--secondary" href="/admin/roles/<?= (int) $role['id'] ?>/edit">Edit</a>
                    <form method="post" action="/admin/roles/<?= (int) $role['id'] ?>/delete" style="display:inline;"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
