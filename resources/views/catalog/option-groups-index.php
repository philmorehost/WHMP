<?php
/** @var array<int, array<string, mixed>> $groups */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Configurable Option Groups</h1>
    <p><a href="/admin/products">&larr; Back to products</a></p>
</div>

<div class="cv-card">
    <h3 id="option-group-form-title" style="margin-top:0;">Add Group</h3>
    <form id="option-group-form" method="post" action="/admin/configurable-options" style="display:flex;gap:var(--cv-space-2);align-items:end;flex-wrap:wrap;margin-bottom:var(--cv-space-4);"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;flex:1;min-width:200px;">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" id="option-group-name" required>
        </div>
        <button class="cv-btn" type="submit" data-edit-submit>Add Group</button>
        <button class="cv-btn cv-btn--secondary" type="button" style="display:none;"
            data-edit-cancel
            data-edit-reset-action="/admin/configurable-options"
            data-edit-reset-label="Add Group"
            data-edit-reset-title="Add Group"
            data-edit-title-target="#option-group-form-title">Cancel</button>
    </form>

    <table class="cv-table">
        <thead><tr><th>Name</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($groups as $group): ?>
            <tr>
                <td><a href="/admin/configurable-options/<?= (int) $group['id'] ?>"><?= e($group['name']) ?></a></td>
                <td style="display:flex;gap:var(--cv-space-2);">
                    <button type="button" class="cv-btn cv-btn--secondary"
                        data-edit-trigger
                        data-edit-form="#option-group-form"
                        data-edit-fields="<?= e(json_encode(['name' => $group['name']])) ?>"
                        data-edit-action="/admin/configurable-options/<?= (int) $group['id'] ?>/edit"
                        data-edit-submit-label="Update Group"
                        data-edit-title="Edit Group"
                        data-edit-title-target="#option-group-form-title">Edit</button>
                    <form method="post" action="/admin/configurable-options/<?= (int) $group['id'] ?>/delete" data-confirm="Delete this option group and all of its options?"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($groups === []): ?>
            <tr><td colspan="2" style="color:var(--cv-text-secondary);">No option groups yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
