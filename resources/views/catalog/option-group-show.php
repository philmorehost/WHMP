<?php
/** @var array<string, mixed> $group */
/** @var array<int, array<string, mixed>> $options */
/** @var array<string, string> $cycles */
$groupId = (int) $group['id'];
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= e($group['name']) ?></h1>
    <p><a href="/admin/configurable-options">&larr; Back to option groups</a></p>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Options</h2>
    <table class="cv-table">
        <thead>
        <tr>
            <th>Name</th>
            <?php foreach ($cycles as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($options as $option): ?>
            <tr>
                <td><?= e($option['name']) ?></td>
                <?php foreach (array_keys($cycles) as $cycleKey): ?>
                    <td><?= isset($option['pricing'][$cycleKey]) ? number_format((float) $option['pricing'][$cycleKey]['price'], 2) : '-' ?></td>
                <?php endforeach; ?>
                <td>
                    <form method="post" action="/admin/configurable-options/<?= $groupId ?>/options/<?= (int) $option['id'] ?>/delete"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($options === []): ?>
            <tr><td colspan="<?= count($cycles) + 2 ?>" style="color:var(--cv-text-secondary);">No options yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h3>Add Option</h3>
    <form method="post" action="/admin/configurable-options/<?= $groupId ?>/options"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" required>
        </div>
        <div style="display:flex;gap:var(--cv-space-3);flex-wrap:wrap;">
            <?php foreach ($cycles as $cycleKey => $label): ?>
                <div class="cv-field" style="margin-bottom:0;">
                    <label class="cv-label"><?= e($label) ?></label>
                    <input class="cv-input" type="number" step="0.01" name="price[<?= $cycleKey ?>]" placeholder="0.00" style="width:7rem;">
                </div>
            <?php endforeach; ?>
        </div>
        <button class="cv-btn" type="submit" style="margin-top:var(--cv-space-3);">Add Option</button>
    </form>
</div>
