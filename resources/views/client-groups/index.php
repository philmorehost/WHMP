<?php
/** @var array<int, array<string, mixed>> $groups */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Client Groups</h1>
    <p><a href="/admin/clients">&larr; Back to clients</a></p>
</div>

<?php if (!empty($error)): ?>
    <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
<?php endif; ?>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Name</th><th>Discount %</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($groups as $group): ?>
            <tr>
                <td><?= e($group['name']) ?></td>
                <td><?= e((string) $group['discount_percent']) ?>%</td>
                <td>
                    <form method="post" action="/admin/client-groups/<?= (int) $group['id'] ?>/delete"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($groups === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">No client groups yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <form method="post" action="/admin/client-groups" style="margin-top:var(--cv-space-4);display:flex;gap:var(--cv-space-2);align-items:end;flex-wrap:wrap;"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;flex:1;min-width:200px;">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" required>
        </div>
        <div class="cv-field" style="margin-bottom:0;flex:1;min-width:200px;">
            <label class="cv-label">Discount %</label>
            <input class="cv-input" type="number" step="0.01" name="discount_percent" value="0">
        </div>
        <button class="cv-btn" type="submit">Add Group</button>
    </form>
</div>
