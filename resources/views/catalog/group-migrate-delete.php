<?php
/** @var array<string, mixed> $group */
/** @var int $productCount */
/** @var array<int, array<string, mixed>> $targetGroups */
?>
<div class="cv-card" style="max-width:32rem;margin:2rem auto; border: 1px solid var(--cv-color-danger, #ef4444); border-radius: var(--cv-radius-md);">
    <h1 class="cv-card__title" style="color: var(--cv-color-danger, #ef4444); font-family: 'Hanken Grotesk', sans-serif;">⚠️ Delete Product Group Warning</h1>
    
    <p style="font-weight: 600; margin-bottom: var(--cv-space-4);">
        The group <strong><?= e($group['name']) ?></strong> has <strong><?= $productCount ?></strong> products in it.
    </p>
    
    <p style="color: var(--cv-text-secondary); font-size: var(--cv-text-sm); margin-bottom: var(--cv-space-5);">
        Choose an action below to proceed with deleting the group:
    </p>

    <form method="post" action="/admin/products/groups/<?= (int) $group['id'] ?>/delete">
        <?= csrf_field() ?>
        
        <div class="cv-field" style="margin-bottom: var(--cv-space-4);">
            <label style="display:flex; align-items:center; gap:var(--cv-space-2); cursor:pointer; font-weight:600; margin-bottom:var(--cv-space-2);">
                <input type="radio" name="group_action" value="migrate" checked>
                Migrate products to another group
            </label>
            <label style="display:flex; align-items:center; gap:var(--cv-space-2); cursor:pointer; font-weight:600; color:var(--cv-color-danger, #ef4444);">
                <input type="radio" name="group_action" value="delete_all">
                Bulk delete all products in this group (Data loss warning!)
            </label>
        </div>

        <div id="migrate-group-wrapper" class="cv-field" style="margin-bottom: var(--cv-space-4);">
            <label class="cv-label">Migrate Products To Group:</label>
            <select class="cv-select" name="migrate_to_group_id" style="width: 100%;" required>
                <option value="">-- Select Target Product Group --</option>
                <?php foreach ($targetGroups as $target): ?>
                    <option value="<?= (int) $target['id'] ?>"><?= e($target['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display: flex; gap: var(--cv-space-2); margin-top: var(--cv-space-6);">
            <a href="/admin/products/groups" class="cv-btn cv-btn--secondary" style="flex: 1; text-align: center; text-decoration: none;">Cancel</a>
            <button type="submit" class="cv-btn cv-btn--danger" style="flex: 1;">Proceed</button>
        </div>
    </form>
</div>
