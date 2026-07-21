<?php
/** @var array<int, array<string, mixed>> $groups */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Product Groups</h1>
    <p><a href="/admin/products">&larr; Back to products</a></p>
</div>

<?php if (!empty($error)): ?>
    <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
<?php endif; ?>

<div class="cv-card">
    <h3 id="product-group-form-title" style="margin-top:0;">Add Group</h3>
    <form id="product-group-form" method="post" action="/admin/products/groups" style="display:flex;gap:var(--cv-space-2);align-items:end;flex-wrap:wrap;margin-bottom:var(--cv-space-4);"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;flex:1;min-width:200px;">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" id="product-group-name" required>
        </div>
        <div class="cv-field" style="margin-bottom:0;flex:1;min-width:200px;">
            <label class="cv-label">Description</label>
            <input class="cv-input" name="description" id="product-group-description">
        </div>
        <button class="cv-btn" type="submit" data-edit-submit>Add Group</button>
        <button class="cv-btn cv-btn--secondary" type="button" style="display:none;"
            data-edit-cancel
            data-edit-form="#product-group-form"
            data-edit-reset-action="/admin/products/groups"
            data-edit-reset-label="Add Group"
            data-edit-reset-title="Add Group"
            data-edit-title-target="#product-group-form-title">Cancel</button>
    </form>

    <table class="cv-table">
        <thead><tr><th>Name</th><th>Description</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($groups as $group): ?>
            <tr>
                <td><?= e($group['name']) ?></td>
                <td style="color:var(--cv-text-secondary);"><?= e((string) ($group['description'] ?? '')) ?></td>
                <td style="display:flex;gap:var(--cv-space-2);">
                    <button type="button" class="cv-btn cv-btn--secondary"
                        data-edit-trigger
                        data-edit-form="#product-group-form"
                        data-edit-fields="<?= e(json_encode(['name' => $group['name'], 'description' => (string) ($group['description'] ?? '')])) ?>"
                        data-edit-action="/admin/products/groups/<?= (int) $group['id'] ?>/edit"
                        data-edit-submit-label="Update Group"
                        data-edit-title="Edit Group"
                        data-edit-title-target="#product-group-form-title">Edit</button>
                    <form method="post" action="/admin/products/groups/<?= (int) $group['id'] ?>/delete"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($groups === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">No product groups yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
