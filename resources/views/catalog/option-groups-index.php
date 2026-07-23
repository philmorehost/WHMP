<?php
/** @var array<int, array<string, mixed>> $groups */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Configurable Option Groups</h1>
    <p><a href="/admin/products">&larr; Back to products</a></p>
</div>

<!-- Form for bulk deletion of groups -->
<form id="bulk-delete-groups-form" method="post" action="/admin/configurable-options/bulk-delete" data-confirm="Are you sure you want to delete all selected option groups? This will delete all of their options too.">
    <?= csrf_field() ?>
</form>

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

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:var(--cv-space-3);">
        <h3 style="margin:0;">Groups</h3>
        <?php if ($groups !== []): ?>
            <button class="cv-btn cv-btn--danger" type="submit" form="bulk-delete-groups-form" style="font-size: var(--cv-text-xs); padding: var(--cv-space-1) var(--cv-space-2);">Delete Selected</button>
        <?php endif; ?>
    </div>

    <table class="cv-table">
        <thead>
            <tr>
                <th style="width: 40px; text-align: center;">
                    <input type="checkbox" id="select-all-groups" data-select-all='input[name="selected_groups[]"]'>
                </th>
                <th>Name</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $group): ?>
            <?php $groupId = (int) $group['id']; ?>
            <tr>
                <td style="text-align: center; vertical-align: middle;">
                    <input type="checkbox" name="selected_groups[]" value="<?= $groupId ?>" form="bulk-delete-groups-form">
                </td>
                <td style="vertical-align: middle;"><a href="/admin/configurable-options/<?= $groupId ?>"><?= e($group['name']) ?></a></td>
                <td style="display:flex; gap:var(--cv-space-2); justify-content: flex-end; align-items: middle;">
                    <button type="button" class="cv-btn cv-btn--secondary"
                        data-edit-trigger
                        data-edit-form="#option-group-form"
                        data-edit-fields="<?= e(json_encode(['name' => $group['name']])) ?>"
                        data-edit-action="/admin/configurable-options/<?= $groupId ?>/edit"
                        data-edit-submit-label="Update Group"
                        data-edit-title="Edit Group"
                        data-edit-title-target="#option-group-form-title">Edit</button>
                    <form method="post" action="/admin/configurable-options/<?= $groupId ?>/delete" data-confirm="Delete this option group and all of its options?" style="margin:0;">
                        <?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($groups === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary); text-align: center;">No option groups yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
