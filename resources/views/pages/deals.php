<?php
/** @var CodeVault\View $view */
/** @var array<int, array<string, mixed>> $promotions */
?>
<div style="display: flex; gap: var(--cv-space-6); align-items: flex-start; max-width: 1400px; margin: 0 auto; padding-top: var(--cv-space-4);">
    <!-- Left Sidebar (Lagom2 style) -->
    <div class="cv-card" style="width: 260px; padding: var(--cv-space-3) 0; flex-shrink: 0; border: 1px solid var(--cv-border-default); background: var(--cv-bg-surface); position: sticky; top: var(--cv-space-4);">
        <ul style="list-style: none; padding: 0; margin: 0;">
            <li style="margin-bottom: var(--cv-space-1);">
                <a href="/store" style="display: flex; align-items: center; justify-content: space-between; padding: var(--cv-space-3) var(--cv-space-4); color: var(--cv-text-primary); text-decoration: none; font-weight: 600; font-size: var(--cv-text-sm); transition: background var(--cv-transition-fast);" onmouseover="this.style.background='var(--cv-bg-surface-sunken)'" onmouseout="this.style.background='transparent'">
                    <span style="display: flex; align-items: center; gap: var(--cv-space-3);">
                        <span style="font-size: 1.1rem;">📦</span>
                        <span>Products</span>
                    </span>
                    <span style="font-size: 0.8em; color: var(--cv-text-secondary);">&gt;</span>
                </a>
            </li>
            <li style="margin-bottom: var(--cv-space-1);">
                <a href="/deals" style="display: flex; align-items: center; padding: var(--cv-space-3) var(--cv-space-4); color: var(--cv-text-primary); text-decoration: none; font-weight: 600; font-size: var(--cv-text-sm); transition: background var(--cv-transition-fast);" onmouseover="this.style.background='var(--cv-bg-surface-sunken)'" onmouseout="this.style.background='transparent'">
                    <span style="display: flex; align-items: center; gap: var(--cv-space-3);">
                        <span style="font-size: 1.1rem;">🏷️</span>
                        <span style="color:var(--cv-color-brand-500);">New Deals</span>
                    </span>
                </a>
            </li>
            <li style="margin-bottom: var(--cv-space-1);">
                <a href="/client/affiliate" style="display: flex; align-items: center; padding: var(--cv-space-3) var(--cv-space-4); color: var(--cv-text-primary); text-decoration: none; font-weight: 600; font-size: var(--cv-text-sm); transition: background var(--cv-transition-fast);" onmouseover="this.style.background='var(--cv-bg-surface-sunken)'" onmouseout="this.style.background='transparent'">
                    <span style="display: flex; align-items: center; gap: var(--cv-space-3);">
                        <span style="font-size: 1.1rem;">🤝</span>
                        <span>Affiliates</span>
                    </span>
                </a>
            </li>
            <li style="margin-bottom: var(--cv-space-1);">
                <a href="/client/tickets" style="display: flex; align-items: center; justify-content: space-between; padding: var(--cv-space-3) var(--cv-space-4); color: var(--cv-text-primary); text-decoration: none; font-weight: 600; font-size: var(--cv-text-sm); transition: background var(--cv-transition-fast);" onmouseover="this.style.background='var(--cv-bg-surface-sunken)'" onmouseout="this.style.background='transparent'">
                    <span style="display: flex; align-items: center; gap: var(--cv-space-3);">
                        <span style="font-size: 1.1rem;">💬</span>
                        <span>Support</span>
                    </span>
                    <span style="font-size: 0.8em; color: var(--cv-text-secondary);">&gt;</span>
                </a>
            </li>
            <li>
                <a href="https://wa.me/xxx" target="_blank" style="display: flex; align-items: center; padding: var(--cv-space-3) var(--cv-space-4); color: var(--cv-text-primary); text-decoration: none; font-weight: 600; font-size: var(--cv-text-sm); transition: background var(--cv-transition-fast);" onmouseover="this.style.background='var(--cv-bg-surface-sunken)'" onmouseout="this.style.background='transparent'">
                    <span style="display: flex; align-items: center; gap: var(--cv-space-3);">
                        <span style="font-size: 1.1rem;">📞</span>
                        <span>WhatsApp</span>
                    </span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Main Content Area -->
    <div style="flex: 1; min-width: 0;">
        <h2 style="font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-2xl); font-weight: 800; margin-bottom: var(--cv-space-6); color: var(--cv-text-primary);">New Deals & Promotions</h2>

        <!-- Promotions Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--cv-space-4); margin-bottom: var(--cv-space-8);">
            <?php foreach ($promotions as $promo): ?>
                <div class="cv-card" style="border: 1px solid var(--cv-border-default); background: var(--cv-bg-surface); padding: var(--cv-space-5); display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: var(--cv-space-3);">
                            <span class="cv-badge cv-badge--success" style="font-size:var(--cv-text-xs); padding: var(--cv-space-1) var(--cv-space-2);">PROMO</span>
                            <span style="font-size: var(--cv-text-xs); color: var(--cv-text-secondary);">Expires: <?= e($promo['expires_at'] ?: 'Never') ?></span>
                        </div>
                        <h3 style="margin: 0 0 var(--cv-space-2) 0; font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-lg); color: var(--cv-color-brand-500); font-weight:800;">
                            <?php if ($promo['type'] === 'percentage'): ?>
                                Save <?= number_format((float) $promo['value'], 0) ?>% Off!
                            <?php else: ?>
                                Save $<?= number_format((float) $promo['value'], 2) ?> Off!
                            <?php endif; ?>
                        </h3>
                        <p style="font-size: var(--cv-text-sm); color: var(--cv-text-secondary); margin: 0 0 var(--cv-space-4) 0;">
                            Apply coupon code <code style="background:var(--cv-bg-surface-sunken); padding: 2px var(--cv-space-1); border-radius: 4px; font-weight:700; color:var(--cv-text-primary);"><?= e($promo['code']) ?></code> at checkout to redeem this discount.
                        </p>
                    </div>
                    <a href="/store" class="cv-btn" style="width: 100%; text-decoration: none; text-align:center; font-weight:700;">Claim Deal Now</a>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($promotions === []): ?>
            <div class="cv-card" style="text-align: center; padding: var(--cv-space-8); color: var(--cv-text-secondary);">
                🎉 No active deals at the moment. Check back soon for hot promos!
            </div>
        <?php endif; ?>
    </div>
</div>
