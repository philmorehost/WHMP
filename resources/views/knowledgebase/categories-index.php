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
        <thead><tr><th>Name</th><th>Sort Order</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($categories as $category): ?>
            <tr>
                <td><?= e($category['name']) ?></td>
                <td><?= (int) $category['sort_order'] ?></td>
                <td>
                    <form method="post" action="/admin/kb/categories/<?= (int) $category['id'] ?>/delete"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($categories === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">No categories yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h3>Add Category</h3>
    <form method="post" action="/admin/kb/categories" style="display:flex;gap:var(--cv-space-2);align-items:end;flex-wrap:wrap;"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" required>
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Sort Order</label>
            <input class="cv-input" type="number" name="sort_order" value="0" style="width:6rem;">
        </div>
        <button class="cv-btn" type="submit">Add</button>
    </form>
</div>
