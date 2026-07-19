<?php
/** @var array<string, mixed>|null $field */
/** @var string|null $error */
$isEdit = $field !== null;
$action = $isEdit ? "/admin/custom-fields/{$field['id']}" : '/admin/custom-fields';
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= $isEdit ? 'Edit Field' : 'Add Field' ?></h1>
    <p><a href="/admin/custom-fields">&larr; Back to custom fields</a></p>
</div>

<div class="cv-card">
    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e($action) ?>"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Field Name</label>
            <input class="cv-input" name="name" value="<?= e((string) ($field['name'] ?? '')) ?>" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Type</label>
            <select class="cv-select" name="type">
                <?php foreach (['text', 'textarea', 'dropdown', 'checkbox', 'password'] as $type): ?>
                    <option value="<?= $type ?>" <?= ($field['type'] ?? 'text') === $type ? 'selected' : '' ?>><?= ucfirst($type) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field">
            <label class="cv-label">Options (for dropdown — one per line)</label>
            <textarea class="cv-textarea" name="options" rows="3"><?= e((string) ($field['options'] ?? '')) ?></textarea>
        </div>
        <div class="cv-field">
            <label class="cv-label"><input type="checkbox" name="required" value="1" <?= !empty($field['required']) ? 'checked' : '' ?>> Required</label>
        </div>
        <div class="cv-field">
            <label class="cv-label"><input type="checkbox" name="admin_only" value="1" <?= !empty($field['admin_only']) ? 'checked' : '' ?>> Admin only (hidden from client-facing forms)</label>
        </div>
        <button class="cv-btn" type="submit"><?= $isEdit ? 'Save Changes' : 'Create Field' ?></button>
    </form>
</div>
