<?php
/** @var array<string, mixed>|null $admin */
/** @var array<int, array<string, mixed>> $roles */
/** @var string|null $error */
$isEdit = $admin !== null;
$action = $isEdit ? "/admin/staff/{$admin['id']}" : '/admin/staff';
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= $isEdit ? 'Edit Staff' : 'Add Staff' ?></h1>
    <p><a href="/admin/staff">&larr; Back to staff list</a></p>
</div>

<div class="cv-card">
    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e($action) ?>"><?= csrf_field() ?>
        <?php if (!$isEdit): ?>
        <div class="cv-field">
            <label class="cv-label">Username</label>
            <input class="cv-input" name="username" required>
        </div>
        <?php endif; ?>
        <div class="cv-field">
            <label class="cv-label">Display Name</label>
            <input class="cv-input" name="display_name" value="<?= e((string) ($admin['display_name'] ?? '')) ?>" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Email</label>
            <input class="cv-input" type="email" name="email" value="<?= e((string) ($admin['email'] ?? '')) ?>" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Role</label>
            <select class="cv-select" name="role_id">
                <option value="">None</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= (int) $role['id'] ?>" <?= ($admin['role_id'] ?? null) == $role['id'] ? 'selected' : '' ?>><?= e($role['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field">
            <label class="cv-label">Password <?= $isEdit ? '(leave blank to keep current)' : '' ?></label>
            <input class="cv-input" type="password" name="password" <?= $isEdit ? '' : 'required' ?>>
        </div>
        <button class="cv-btn" type="submit"><?= $isEdit ? 'Save Changes' : 'Create Staff Account' ?></button>
    </form>
</div>
