<?php
/** @var array<string, mixed>|null $role */
/** @var array<int, string> $grantedPermissions */
/** @var array<string, array{label: string, group: string}> $permissions */
/** @var string|null $error */
$isEdit = $role !== null;
$action = $isEdit ? "/admin/roles/{$role['id']}" : '/admin/roles';

$grouped = [];
foreach ($permissions as $key => $meta) {
    $grouped[$meta['group']][$key] = $meta['label'];
}
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= $isEdit ? 'Edit Role' : 'Add Role' ?></h1>
    <p><a href="/admin/roles">&larr; Back to roles</a></p>
</div>

<div class="cv-card">
    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e($action) ?>"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Role Name</label>
            <input class="cv-input" name="name" value="<?= e((string) ($role['name'] ?? '')) ?>" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">
                <input type="checkbox" name="is_super_admin" value="1" <?= !empty($role['is_super_admin']) ? 'checked' : '' ?>>
                Super Admin (bypasses the permission matrix — has everything)
            </label>
        </div>

        <h3>Permission Matrix</h3>
        <?php foreach ($grouped as $group => $items): ?>
            <div class="cv-card" style="margin-bottom:var(--cv-space-3);">
                <strong><?= e($group) ?></strong>
                <?php foreach ($items as $key => $label): ?>
                    <div class="cv-field" style="margin-bottom:var(--cv-space-1);">
                        <label class="cv-label" style="font-weight:normal;">
                            <input type="checkbox" name="permissions[]" value="<?= e($key) ?>" <?= in_array($key, $grantedPermissions, true) ? 'checked' : '' ?>>
                            <?= e($label) ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <button class="cv-btn" type="submit"><?= $isEdit ? 'Save Changes' : 'Create Role' ?></button>
    </form>
</div>
