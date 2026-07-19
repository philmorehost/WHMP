<?php
/** @var array<string, mixed> $product */
/** @var array<string, array<string, mixed>> $pricing */
/** @var array<string, string> $cycles */
/** @var array<int, array<string, mixed>> $optionGroups */
/** @var array<string, mixed> $currency */
/** @var CodeVault\Localization\Translation $t */
$money = static fn (float $amount): string => $currency['symbol'] . number_format($amount * (float) $currency['exchange_rate'], 2);
?>
<div class="cv-card" style="max-width:36rem;margin:0 auto;">
    <h1 class="cv-card__title"><?= e($product['name']) ?></h1>
    <p style="color:var(--cv-text-secondary);"><?= e((string) ($product['description'] ?? '')) ?></p>
    <p><a href="/store">&larr; <?= e($t->get('product.back_to_store')) ?></a></p>

    <?php if ($pricing === []): ?>
        <p style="color:var(--cv-text-secondary);"><?= e($t->get('product.not_available')) ?></p>
    <?php else: ?>
        <form method="post" action="/cart/add"><?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

            <div class="cv-field">
                <label class="cv-label"><?= e($t->get('product.billing_cycle')) ?></label>
                <?php foreach ($pricing as $cycleKey => $row): ?>
                    <div>
                        <label>
                            <input type="radio" name="billing_cycle" value="<?= e($cycleKey) ?>" required>
                            <?= e($cycles[$cycleKey] ?? $cycleKey) ?> —
                            <?php if ((float) $row['setup_fee'] > 0): ?>
                                <?= $money((float) $row['setup_fee']) ?> <?= e($t->get('product.setup')) ?> +
                            <?php endif; ?>
                            <?= $money((float) $row['price']) ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php foreach ($optionGroups as $og): ?>
                <div class="cv-field">
                    <label class="cv-label"><?= e($og['name']) ?></label>
                    <select class="cv-select" name="option[<?= (int) $og['id'] ?>]">
                        <option value="">None</option>
                        <?php foreach ($og['options'] as $option): ?>
                            <option value="<?= (int) $option['id'] ?>"><?= e($option['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>

            <div class="cv-field">
                <label class="cv-label"><?= e($t->get('product.quantity')) ?></label>
                <input class="cv-input" type="number" name="quantity" value="1" min="1" style="width:6rem;">
            </div>

            <button class="cv-btn" type="submit"><?= e($t->get('product.add_to_cart')) ?></button>
        </form>
    <?php endif; ?>
</div>
