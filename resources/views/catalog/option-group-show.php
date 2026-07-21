<?php
/** @var array<string, mixed> $group */
/** @var array<int, array<string, mixed>> $options */
/** @var array<string, string> $cycles */
$groupId = (int) $group['id'];
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= e($group['name']) ?></h1>
    <p><a href="/admin/configurable-options" style="color: var(--cv-color-brand-500); text-decoration: none; font-weight:600;">&larr; Back to option groups</a></p>
</div>

<!-- Forms for inline option editing -->
<?php foreach ($options as $option): ?>
    <form id="edit-form-<?= (int) $option['id'] ?>" method="post" action="/admin/configurable-options/<?= $groupId ?>/options/<?= (int) $option['id'] ?>/update">
        <?= csrf_field() ?>
    </form>
<?php endforeach; ?>

<!-- Form for bulk deletion -->
<form id="bulk-delete-form" method="post" action="/admin/configurable-options/<?= $groupId ?>/options/bulk-delete">
    <?= csrf_field() ?>
</form>

<div class="cv-card">
    <h2 class="cv-card__title" style="display:flex; justify-content:space-between; align-items:center;">
        <span>Options</span>
        <?php if ($options !== []): ?>
            <button class="cv-btn cv-btn--danger" type="submit" form="bulk-delete-form" style="font-size: var(--cv-text-xs); padding: var(--cv-space-1) var(--cv-space-2);">Delete Selected</button>
        <?php endif; ?>
    </h2>
    
    <table class="cv-table">
        <thead>
        <tr>
            <th style="width: 40px; text-align: center;">
                <input type="checkbox" id="select-all-options" data-select-all='input[name="selected_options[]"]'>
            </th>
            <th>Name</th>
            <?php foreach ($cycles as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
            <th style="text-align: right;">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($options as $option): ?>
            <?php $optId = (int) $option['id']; ?>
            <tr>
                <td style="text-align: center; vertical-align: middle;">
                    <input type="checkbox" name="selected_options[]" value="<?= $optId ?>" form="bulk-delete-form">
                </td>
                <td style="vertical-align: middle;">
                    <input class="cv-input" name="name" value="<?= e($option['name']) ?>" form="edit-form-<?= $optId ?>" required style="width: 100%; min-width: 140px;">
                </td>
                <?php foreach (array_keys($cycles) as $cycleKey): ?>
                    <td style="vertical-align: middle;">
                        <input class="cv-input" type="number" step="0.01" name="price[<?= $cycleKey ?>]" value="<?= isset($option['pricing'][$cycleKey]) ? number_format((float) $option['pricing'][$cycleKey]['price'], 2, '.', '') : '0.00' ?>" form="edit-form-<?= $optId ?>" style="width: 5.5rem;">
                    </td>
                <?php endforeach; ?>
                <td style="text-align: right; vertical-align: middle;">
                    <div style="display: flex; gap: var(--cv-space-1); justify-content: flex-end;">
                        <button class="cv-btn" type="submit" form="edit-form-<?= $optId ?>" style="font-size: var(--cv-text-xs); padding: var(--cv-space-1) var(--cv-space-2);">Save</button>
                        
                        <form method="post" action="/admin/configurable-options/<?= $groupId ?>/options/<?= $optId ?>/delete" style="margin:0; display:inline;" data-confirm="Are you sure you want to delete this option?">
                            <?= csrf_field() ?>
                            <button class="cv-btn cv-btn--danger" type="submit" style="font-size: var(--cv-text-xs); padding: var(--cv-space-1) var(--cv-space-2);">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($options === []): ?>
            <tr><td colspan="<?= count($cycles) + 3 ?>" style="color:var(--cv-text-secondary);">No options yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h3 style="margin-top: var(--cv-space-6); font-family: 'Hanken Grotesk', sans-serif;">Add New Option</h3>
    <form method="post" action="/admin/configurable-options/<?= $groupId ?>/options" style="border-top: 1px solid var(--cv-border-default); padding-top: var(--cv-space-4); margin-top: var(--cv-space-2);"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" required placeholder="e.g. 10 GB SSD Storage">
        </div>
        <div style="display:flex;gap:var(--cv-space-3);flex-wrap:wrap;">
            <?php foreach ($cycles as $cycleKey => $label): ?>
                <div class="cv-field" style="margin-bottom:0;">
                    <label class="cv-label"><?= e($label) ?></label>
                    <input class="cv-input" type="number" step="0.01" name="price[<?= $cycleKey ?>]" placeholder="0.00" style="width:7rem;">
                </div>
            <?php endforeach; ?>
        </div>
        <button class="cv-btn" type="submit" style="margin-top:var(--cv-space-4);">Add Option</button>
    </form>
</div>
