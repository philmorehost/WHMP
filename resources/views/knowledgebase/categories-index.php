<?php
/** @var array<int, array<string, mixed>> $categories */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">KB Categories</h1>
    <p><a href="/admin/kb/articles">&larr; Back to articles</a></p>
    <?php if ($error !== null): ?>
        <div class="cv-field-error"><?= e($error) ?></div>
    <?php endif; ?>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Name</th><th>Description</th><th>Sort Order</th><th style="width:180px;"></th></tr></thead>
        <tbody>
        <?php foreach ($categories as $category): ?>
            <tr>
                <td><?= e($category['name']) ?></td>
                <td style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);"><?= e((string) ($category['description'] ?? '')) ?></td>
                <td><?= (int) $category['sort_order'] ?></td>
                <td style="display:flex;gap:6px;">
                    <button type="button" class="cv-btn cv-btn--secondary" style="padding:6px 12px;font-size:0.78rem;"
                        data-edit-trigger
                        data-edit-form="#kb-category-form"
                        data-edit-fields="<?= e(json_encode([
                            'name' => $category['name'],
                            'description' => (string) ($category['description'] ?? ''),
                            'sort_order' => (int) $category['sort_order'],
                        ])) ?>"
                        data-edit-action="/admin/kb/categories/<?= (int) $category['id'] ?>/update"
                        data-edit-submit-label="Update"
                        data-edit-title="Edit Category"
                        data-edit-title-target="#kb-category-form-title">Edit</button>
                    <form method="post" action="/admin/kb/categories/<?= (int) $category['id'] ?>/delete" style="margin:0;">
                        <?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" style="padding:6px 12px;font-size:0.78rem;" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($categories === []): ?>
            <tr><td colspan="4" style="color:var(--cv-text-secondary);">No categories yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <div style="margin-top:var(--cv-space-4);padding:var(--cv-space-3);background:var(--cv-bg-surface-sunken);border:1px dashed var(--cv-border-default);border-radius:8px;" data-kb-category-copilot>
        <h3 style="margin-top:0;">✨ Write with AI</h3>
        <div class="cv-field">
            <label class="cv-label">Brief — what's this category for?</label>
            <input class="cv-input" type="text" data-kb-category-copilot-brief placeholder="e.g. billing and invoice questions">
        </div>
        <button type="button" class="cv-btn cv-btn--secondary" data-kb-category-copilot-action>Generate Name + Description</button>
        <span data-kb-category-copilot-status style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);margin-left:var(--cv-space-2);"></span>
    </div>

    <h3 id="kb-category-form-title" style="margin-top:var(--cv-space-4);">Add Category</h3>
    <form id="kb-category-form" method="post" action="/admin/kb/categories" style="display:flex;gap:var(--cv-space-2);align-items:end;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;flex:1;min-width:160px;">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" data-kb-category-name required>
        </div>
        <div class="cv-field" style="margin-bottom:0;flex:2;min-width:220px;">
            <label class="cv-label">Description</label>
            <input class="cv-input" name="description" data-kb-category-description>
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Sort Order</label>
            <input class="cv-input" type="number" name="sort_order" value="0" style="width:6rem;">
        </div>
        <button class="cv-btn" type="submit" data-edit-submit>Add</button>
        <button class="cv-btn cv-btn--secondary" type="button" style="display:none;"
            data-edit-cancel
            data-edit-reset-action="/admin/kb/categories"
            data-edit-reset-label="Add"
            data-edit-reset-title="Add Category"
            data-edit-title-target="#kb-category-form-title">Cancel</button>
    </form>
</div>
