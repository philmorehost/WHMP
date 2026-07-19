<?php
/** @var array<string, mixed>|null $article */
/** @var array<int, array<string, mixed>> $categories */
/** @var string|null $error */
$isEdit = $article !== null;
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= $isEdit ? 'Edit Article' : 'Add Article' ?></h1>
    <p><a href="/admin/kb/articles">&larr; Back to articles</a></p>
    <?php if ($error !== null): ?>
        <div class="cv-field-error"><?= e($error) ?></div>
    <?php endif; ?>
</div>

<div class="cv-card">
    <form method="post" action="<?= $isEdit ? '/admin/kb/articles/' . (int) $article['id'] . '/edit' : '/admin/kb/articles' ?>"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Category</label>
            <select class="cv-input" name="category_id" required>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>" <?= $isEdit && (int) $article['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field">
            <label class="cv-label">Title</label>
            <input class="cv-input" name="title" value="<?= e((string) ($article['title'] ?? '')) ?>" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Body</label>
            <textarea class="cv-input" name="body" rows="12" required><?= e((string) ($article['body'] ?? '')) ?></textarea>
        </div>
        <button class="cv-btn" type="submit"><?= $isEdit ? 'Save Changes' : 'Create Article' ?></button>
    </form>
</div>
