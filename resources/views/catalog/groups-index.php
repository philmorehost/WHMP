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
    <table class="cv-table">
        <thead><tr><th>Name</th><th>Description</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($groups as $group): ?>
            <tr>
                <td><?= e($group['name']) ?></td>
                <td style="color:var(--cv-text-secondary);"><?= e((string) ($group['description'] ?? '')) ?></td>
                <td>
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

    <form method="post" action="/admin/products/groups" style="margin-top:var(--cv-space-4);display:flex;gap:var(--cv-space-2);align-items:end;"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" required>
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Description</label>
            <input class="cv-input" name="description">
        </div>
        <button class="cv-btn" type="submit">Add Group</button>
    </form>
</div>
