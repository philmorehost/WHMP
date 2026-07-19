<?php
/** @var array<int, array<string, mixed>> $groups */
/** @var CodeVault\Localization\Translation $t */
?>
<div style="max-width:60rem;margin:0 auto;">
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h1 class="cv-card__title"><?= e($t->get('store.title')) ?></h1>
        <p><a href="/cart"><?= e($t->get('nav.cart')) ?></a></p>
    </div>

    <?php foreach ($groups as $group): ?>
        <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
            <h2 class="cv-card__title"><?= e($group['name']) ?></h2>
            <?php if (!empty($group['description'])): ?>
                <p style="color:var(--cv-text-secondary);"><?= e($group['description']) ?></p>
            <?php endif; ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(220px, 1fr));gap:var(--cv-space-3);">
                <?php foreach ($group['products'] as $product): ?>
                    <div class="cv-card">
                        <strong><?= e($product['name']) ?></strong>
                        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);"><?= e((string) ($product['description'] ?? '')) ?></p>
                        <a class="cv-btn" href="/store/<?= (int) $product['id'] ?>"><?= e($t->get('store.view')) ?></a>
                    </div>
                <?php endforeach; ?>
                <?php if ($group['products'] === []): ?>
                    <p style="color:var(--cv-text-secondary);"><?= e($t->get('store.no_products_in_group')) ?></p>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($groups === []): ?>
        <div class="cv-card"><p style="color:var(--cv-text-secondary);"><?= e($t->get('store.no_products')) ?></p></div>
    <?php endif; ?>
</div>
