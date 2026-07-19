<?php
/** @var array<int, array<string, mixed>> $groups */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Configurable Option Groups</h1>
    <p><a href="/admin/products">&larr; Back to products</a></p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Name</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($groups as $group): ?>
            <tr>
                <td><a href="/admin/configurable-options/<?= (int) $group['id'] ?>"><?= e($group['name']) ?></a></td>
                <td>
                    <form method="post" action="/admin/configurable-options/<?= (int) $group['id'] ?>/delete"><?= csrf_field() ?>
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

    <form method="post" action="/admin/configurable-options" style="margin-top:var(--cv-space-4);display:flex;gap:var(--cv-space-2);align-items:end;"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" required>
        </div>
        <button class="cv-btn" type="submit">Add Group</button>
    </form>
</div>
