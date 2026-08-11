<?php
/** @var array<string, mixed> $service */
/** @var array<int, array<string, mixed>> $current */
/** @var array<int, array<string, mixed>> $available */
/** @var array<string, mixed> $currency */
/** @var string $formattedAmount */
/** @var callable(float): string $money */
/** @var string|null $error */
$id = (int) $service['id'];
$symbol = (string) ($currency['symbol'] ?? '$');
?>
<div class="cv-card" style="max-width:46rem;margin:var(--cv-space-6) auto;">
    <h1 class="cv-card__title">Add-ons — <?= e($service['product_name']) ?></h1>
    <p><a href="/client/services/<?= $id ?>">&larr; Back to service</a></p>
</div>

<?php if (!empty($error)): ?>
    <div class="cv-card" style="max-width:46rem;margin:0 auto var(--cv-space-3);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<!-- Current add-ons -->
<div class="cv-card" style="max-width:46rem;margin:0 auto var(--cv-space-4);">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Your add-ons</h2>
    <?php if ($current === []): ?>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">No add-ons attached to this service yet.</p>
    <?php else: ?>
        <table class="cv-table">
            <thead>
                <tr><th>Add-on</th><th>Cycle</th><th>Price</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($current as $addon): ?>
                    <tr>
                        <td>
                            <strong><?= e($addon['product_name']) ?></strong>
                            <div style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">Renews <?= e(date('M j, Y', strtotime((string) $addon['next_due_date']))) ?></div>
                        </td>
                        <td><?= e(ucfirst((string) $addon['billing_cycle'])) ?></td>
                        <td><?= $symbol . number_format((float) ($addon['amount'] ?? 0), 2) ?></td>
                        <td>
                            <?php
                            $s = (string) ($addon['status'] ?? '');
                            $label = $s === 'active' ? 'Active' : ucfirst($s);
                            $color = match ($s) {
                                'active' => 'var(--cv-color-success-600)',
                                'suspended' => 'var(--cv-color-danger)',
                                default => 'var(--cv-text-secondary)',
                            };
                            ?>
                            <span style="color:<?= $color ?>;font-weight:600;font-size:var(--cv-text-sm);"><?= e($label) ?></span>
                        </td>
                        <td style="text-align:right;">
                            <?php if (in_array($addon['status'], ['active', 'suspended'], true)): ?>
                                <form method="post" action="/client/services/<?= (int) $addon['id'] ?>/addon-remove" style="margin:0;" data-confirm="Remove this add-on? It stops renewing immediately.">
                                    <?= csrf_field() ?>
                                    <button type="submit" style="background:none;border:none;color:var(--cv-color-danger);cursor:pointer;font-weight:600;font-size:var(--cv-text-sm);">Remove</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Available add-ons -->
<div class="cv-card" style="max-width:46rem;margin:0 auto;">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Add an add-on</h2>
    <?php if ($available === []): ?>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
            There are no add-ons available for this service's plan. Check back later or contact support.
        </p>
    <?php else: ?>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
            Add-ons bill on the same <?= e(ucfirst((string) $service['billing_cycle'])) ?> cycle as this service. The first period is invoiced immediately.
        </p>
        <?php foreach ($available as $addon): ?>
            <form method="post" action="/client/services/<?= $id ?>/addons" style="margin:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="addon_product_id" value="<?= (int) $addon['product_id'] ?>">
                <div style="display:flex;align-items:center;gap:var(--cv-space-3);padding:var(--cv-space-3);border:1px solid var(--cv-border-default);border-radius:var(--cv-radius-md);margin-bottom:var(--cv-space-2);background:var(--cv-bg-surface-sunken, #f8fafc);">
                    <div style="flex:1;">
                        <strong><?= e($addon['addon_name']) ?></strong>
                        <div style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
                            <?= $money((float) $addon['price']) ?>/<?= e(ucfirst((string) $service['billing_cycle'])) ?>
                            <?php if ((float) ($addon['setup_fee'] ?? 0) > 0): ?>
                                + <?= $money((float) $addon['setup_fee']) ?> setup
                            <?php endif; ?>
                        </div>
                    </div>
                    <button type="submit" style="padding:var(--cv-space-2) var(--cv-space-3);border:none;border-radius:var(--cv-radius-md);background:var(--cv-color-brand-500);color:#fff;font-weight:600;cursor:pointer;">Add</button>
                </div>
            </form>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
