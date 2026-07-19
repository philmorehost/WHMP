<?php
/** @var array<int, array<string, mixed>> $categories */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Add Download</h1>
    <p><a href="/admin/downloads">&larr; Back to downloads</a></p>
    <?php if ($error !== null): ?>
        <div class="cv-field-error"><?= e($error) ?></div>
    <?php endif; ?>
</div>

<div class="cv-card">
    <form method="post" action="/admin/downloads" enctype="multipart/form-data"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Category</label>
            <select class="cv-input" name="category_id" required>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Description</label>
            <textarea class="cv-input" name="description" rows="4"></textarea>
        </div>
        <div class="cv-field">
            <label class="cv-label">File</label>
            <input class="cv-input" type="file" name="file" required>
        </div>
        <button class="cv-btn" type="submit">Upload</button>
    </form>
</div>
