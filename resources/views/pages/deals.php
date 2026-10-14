<?php
/** @var CodeVault\View $view */
/** @var array<int, array<string, mixed>> $promotions */
/** @var string $whatsappNumber admin-configured WhatsApp number (international, no +) */
/** @var bool|null $premium   injectable for tests; otherwise decided by the site being served */
/** @var callable|null $money injectable for tests */
use CodeVault\Reseller\StorefrontIcons as Icon;

$whatsappNumber ??= '';
$promotions ??= [];
$whatsappHref = $whatsappNumber !== '' ? 'https://wa.me/' . preg_replace('/\D/', '', $whatsappNumber) : '';

// A store's website and the main website in its premium design wear the
// storefront chrome, so they get the premium deals grid. The classic sidebar
// below links the PLATFORM's affiliate scheme, so it is only ever rendered on the
// main website's classic design — never on a store.
$premium ??= current_storefront() !== null || platform_premium_site();
$money ??= null;

if ($premium && $money === null) {
    try {
        $container = \CodeVault\Support\App::container();
        $currencyService = $container->make(\CodeVault\Billing\CurrencyService::class);
        $dealCurrency = $currencyService->resolveEffective(
            $container->make(\CodeVault\Clients\ClientAuthGuard::class)->currentClient(),
            $container->make(\CodeVault\Billing\CurrencySelection::class)->get()
        );
        $money = static fn (float $amount): string => $currencyService->format($amount, $dealCurrency);
    } catch (\Throwable) {
        $money = null;
    }
}

$money ??= static fn (float $amount): string => number_format($amount, 2);
?>
<?php if ($premium): ?>
<div class="sf-deals">
    <div class="sf-deals__intro">
        <div>
            <span class="sf-eyebrow"><?= Icon::svg('tag', 'sf-icon sf-icon--xs') ?> Limited-time offers</span>
            <h2>Save on your next order</h2>
            <p>Copy a code below and apply it in your cart at checkout. The discount is taken off before you pay.</p>
        </div>
        <div class="sf-deals__intro-actions">
            <a href="/store" class="sf-btn sf-btn--primary">Browse services <?= Icon::svg('arrow', 'sf-icon sf-icon--xs') ?></a>
            <?php if ($whatsappHref !== ''): ?>
                <a href="<?= e($whatsappHref) ?>" target="_blank" rel="noopener" class="sf-btn sf-btn--soft"><?= Icon::svg('phone', 'sf-icon sf-icon--xs') ?> Ask on WhatsApp</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($promotions === []): ?>
        <div class="sf-deals__empty">
            <span class="sf-chip sf-chip--lg"><?= Icon::svg('tag') ?></span>
            <h3>No active deals right now</h3>
            <p>New promotions appear here first — check back soon, or browse our plans in the meantime.</p>
            <a href="/store" class="sf-btn sf-btn--primary">View all plans</a>
        </div>
    <?php else: ?>
        <div class="sf-deals__grid">
            <?php foreach ($promotions as $promo): ?>
                <?php
                $isPercent = ($promo['type'] ?? '') === 'percentage';
                $amount = $isPercent
                    ? rtrim(rtrim(number_format((float) $promo['value'], 2), '0'), '.') . '%'
                    : $money((float) $promo['value']);
                $expires = trim((string) ($promo['expires_at'] ?? ''));
                $code = (string) ($promo['code'] ?? '');
                ?>
                <article class="sf-deal">
                    <div class="sf-deal__top">
                        <span class="sf-deal__badge">Promo</span>
                        <span class="sf-deal__expiry"><?= Icon::svg('bolt', 'sf-icon sf-icon--xs') ?> <?= $expires !== '' ? 'Ends ' . e(date('j M Y', strtotime($expires) ?: time())) : 'No expiry' ?></span>
                    </div>
                    <div class="sf-deal__amount"><strong><?= e($amount) ?></strong><span>off</span></div>
                    <p class="sf-deal__text"><?= !empty($promo['description']) ? e((string) $promo['description']) : 'Apply this code in your cart to claim the discount.' ?></p>
                    <div class="sf-deal__code">
                        <code><?= e($code) ?></code>
                        <button type="button" class="sf-deal__copy" data-sf-copy="<?= e($code) ?>" data-sf-copied="Copied!">Copy</button>
                    </div>
                    <a href="/store" class="sf-btn sf-btn--primary sf-btn--block">Claim deal <?= Icon::svg('arrow', 'sf-icon sf-icon--xs') ?></a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="home-layout-wrapper">
    <!-- Left Sidebar (Lagom2 style) -->
    <div class="cv-card home-sidebar">
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
            <?php if ($whatsappHref !== ''): ?>
                <li>
                    <a href="<?= e($whatsappHref) ?>" target="_blank" rel="noopener" style="display: flex; align-items: center; padding: var(--cv-space-3) var(--cv-space-4); color: var(--cv-text-primary); text-decoration: none; font-weight: 600; font-size: var(--cv-text-sm); transition: background var(--cv-transition-fast);" onmouseover="this.style.background='var(--cv-bg-surface-sunken)'" onmouseout="this.style.background='transparent'">
                        <span style="display: flex; align-items: center; gap: var(--cv-space-3);">
                            <span style="font-size: 1.1rem;">📞</span>
                            <span>WhatsApp</span>
                        </span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </div>

    <!-- Main Content Area -->
    <div class="home-main-content">
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
<?php endif; ?>
