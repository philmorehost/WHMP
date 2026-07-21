<?php
/** @var array<string, mixed> $product */
/** @var int $activeCount */
/** @var array<int, array<string, mixed>> $targetProducts */
?>
<div class="cv-card" style="max-width:32rem;margin:2rem auto; border: 1px solid var(--cv-color-danger, #ef4444); border-radius: var(--cv-radius-md);">
    <h1 class="cv-card__title" style="color: var(--cv-color-danger, #ef4444); font-family: 'Hanken Grotesk', sans-serif;">⚠️ Delete Product Warning</h1>
    
    <p style="font-weight: 600; margin-bottom: var(--cv-space-4);">
        The product <strong><?= e($product['name']) ?></strong> has <strong><?= $activeCount ?></strong> active/pending services (users) assigned to it.
    </p>
    
    <p style="color: var(--cv-text-secondary); font-size: var(--cv-text-sm); margin-bottom: var(--cv-space-5);">
        You cannot delete this product plan directly without migrating these users to an alternative package plan first. Please select a replacement plan below:
    </p>

    <form method="post" action="/admin/products/<?= (int) $product['id'] ?>/delete">
        <?= csrf_field() ?>
        
        <div class="cv-field" style="margin-bottom: var(--cv-space-4);">
            <label class="cv-label">Migrate Users To:</label>
            <select class="cv-select" name="migrate_to_product_id" required style="width: 100%;">
                <option value="">-- Select Replacement Package Plan --</option>
                <?php foreach ($targetProducts as $target): ?>
                    <option value="<?= (int) $target['id'] ?>"><?= e($target['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display: flex; gap: var(--cv-space-2); margin-top: var(--cv-space-6);">
            <a href="/admin/products" class="cv-btn cv-btn--secondary" style="flex: 1; text-align: center; text-decoration: none;">Cancel</a>
            <button type="submit" class="cv-btn cv-btn--danger" style="flex: 1;">Migrate & Delete</button>
        </div>
    </form>
</div>
