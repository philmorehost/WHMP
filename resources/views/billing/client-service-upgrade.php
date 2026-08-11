<?php
/** @var array<string, mixed> $service */
/** @var array<int, array<string, mixed>> $candidates */
/** @var array<string, string> $modes */
/** @var array<string, mixed> $currency */
/** @var string $formattedAmount */
$id = (int) $service['id'];
$symbol = (string) ($currency['symbol'] ?? '$');
$money = static fn (float $amount): string => $symbol . number_format($amount, 2);
?>
<div class="cv-card" style="max-width:40rem;margin:var(--cv-space-6) auto;">
    <h1 class="cv-card__title">Upgrade / Downgrade Service</h1>
    <p><a href="/client/services/<?= $id ?>">&larr; Back to service</a></p>
</div>

<div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Current plan</h2>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:var(--cv-space-3);">
        <div>
            <strong><?= e($service['product_name']) ?></strong>
            <div style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);"><?= e(ucfirst((string) $service['billing_cycle'])) ?> billing &middot; renews <?= e(date('M j, Y', strtotime((string) $service['next_due_date']))) ?></div>
        </div>
        <div style="font-size:var(--cv-text-lg);font-weight:800;color:var(--cv-color-brand-500);"><?= $formattedAmount ?></div>
    </div>
</div>

<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Choose a new plan</h2>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
        Moving to a more expensive plan charges the difference; moving to a cheaper one credits your account. How that's calculated depends on the option you pick below.
    </p>

    <form method="post" action="/client/services/<?= $id ?>/upgrade"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:var(--cv-space-3);">
            <label class="cv-label">New plan</label>
            <?php foreach ($candidates as $product): ?>
                <label style="display:flex;align-items:center;gap:var(--cv-space-3);padding:var(--cv-space-3);border:1px solid var(--cv-border-default);border-radius:var(--cv-radius-md);margin-bottom:var(--cv-space-2);cursor:pointer;background:var(--cv-bg-surface-sunken, #f8fafc);">
                    <input type="radio" name="product_id" value="<?= (int) $product['id'] ?>" required>
                    <span style="flex:1;">
                        <strong><?= e($product['name']) ?></strong>
                    </span>
                    <span style="font-weight:800;color:var(--cv-color-brand-500);"><?= $money((float) $product['cycle_price']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="cv-field" style="margin-bottom:var(--cv-space-3);">
            <label class="cv-label">How to handle the change</label>
            <select class="cv-input" name="proration_mode" required>
                <?php foreach ($modes as $value => $label): ?>
                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="cv-btn" type="submit">Switch Plan</button>
    </form>
</div>
