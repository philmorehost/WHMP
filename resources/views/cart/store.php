<?php
/** @var array<int, array<string, mixed>> $groups */
/** @var CodeVault\Localization\Translation $t */
?>
<style>
/* ====== Store Page Styles ====== */
.store-hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 45%, #0f3460 100%);
    padding: 56px 40px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 40px;
    position: relative;
    overflow: hidden;
    margin-bottom: 48px;
}
.store-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(245,158,11,.12) 0%, transparent 70%);
    pointer-events: none;
}
.store-hero__content {
    flex: 1;
    position: relative;
    z-index: 1;
}
.store-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2.5rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 16px 0;
    line-height: 1.2;
}
.store-hero__subtitle {
    font-size: 1.1rem;
    color: rgba(255,255,255,.75);
    margin: 0 0 24px 0;
    line-height: 1.6;
}
.store-hero__link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #f59e0b;
    text-decoration: none;
    font-weight: 600;
    font-size: .95rem;
    transition: all 0.2s;
}
.store-hero__link:hover {
    gap: 12px;
    color: #fbbf24;
}
.store-hero__icon {
    width: 120px;
    height: 120px;
    background: linear-gradient(135deg, rgba(245,158,11,.2), rgba(249,115,22,.15));
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    flex-shrink: 0;
    border: 2px solid rgba(245,158,11,.3);
    position: relative;
    z-index: 1;
    box-shadow: 0 20px 40px rgba(245,158,11,.1);
}

/* Product Group Container */
.product-group {
    margin-bottom: 64px;
}
.group-header {
    margin-bottom: 32px;
}
.group-header__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 12px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}
.group-header__badge {
    display: inline-block;
    background: linear-gradient(135deg, rgba(245,158,11,.15), rgba(249,115,22,.1));
    color: #f59e0b;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    border: 1px solid rgba(245,158,11,.3);
}
.group-header__desc {
    color: var(--cv-text-secondary);
    font-size: 1rem;
    line-height: 1.6;
    margin: 0;
    max-width: 700px;
}

/* Product Grid */
.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 24px;
}

/* Product Card - Standard */
.product-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 16px;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    height: 100%;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.product-card:hover {
    transform: translateY(-8px);
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 16px 32px rgba(0,0,0,0.12);
}
.product-card__header {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    padding: 24px;
    border-bottom: 1px solid var(--cv-border-default);
    position: relative;
}
.product-card__badge {
    display: inline-block;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 12px;
}
.product-card__name {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.2rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 12px 0 0 0;
    line-height: 1.3;
}
.product-card__body {
    padding: 24px;
    flex: 1;
}
.product-card__description {
    color: var(--cv-text-secondary);
    font-size: .95rem;
    line-height: 1.6;
    margin: 0;
}
.product-card__features {
    list-style: none;
    padding: 0;
    margin: 16px 0 0 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.product-card__feature {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: .9rem;
    color: var(--cv-text-secondary);
}
.product-card__feature-icon {
    color: var(--cv-color-success-600);
    font-weight: bold;
    flex-shrink: 0;
}
.product-card__footer {
    padding: 24px;
    border-top: 1px solid var(--cv-border-default);
    background: var(--cv-bg-surface-sunken);
    display: flex;
    gap: 12px;
    align-items: center;
    justify-content: space-between;
}
.product-card__cta {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 10px 20px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s;
    flex: 1;
    text-align: center;
}
.product-card__cta:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateX(2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}

/* Email Hosting Special Styling */
.product-card--email {
    border-left: 4px solid #10b981;
}
.product-card--email .product-card__badge {
    background: linear-gradient(135deg, #10b981, #059669);
}
.product-card--email .product-card__cta {
    background: linear-gradient(135deg, #10b981, #059669);
}
.product-card--email .product-card__cta:hover {
    background: linear-gradient(135deg, #059669, #047857);
    box-shadow: 0 8px 16px rgba(16,185,129,.3);
}

/* Premium Email Special Styling */
.product-card--premium .product-card__badge {
    background: linear-gradient(135deg, #6366f1, #4f46e5);
}
.product-card--premium .product-card__cta {
    background: linear-gradient(135deg, #6366f1, #4f46e5);
}
.product-card--premium .product-card__cta:hover {
    background: linear-gradient(135deg, #4f46e5, #4338ca);
    box-shadow: 0 8px 16px rgba(99,102,241,.3);
}

/* Google Workspace Styling */
.product-card--google .product-card__badge {
    background: linear-gradient(135deg, #4285f4, #3367d6);
}
.product-card--google .product-card__cta {
    background: linear-gradient(135deg, #4285f4, #3367d6);
}
.product-card--google .product-card__cta:hover {
    background: linear-gradient(135deg, #3367d6, #285bc2);
    box-shadow: 0 8px 16px rgba(66,133,244,.3);
}

/* Enterprise Styling */
.product-card--enterprise .product-card__badge {
    background: linear-gradient(135deg, #f59e0b, #d97706);
}
.product-card--enterprise .product-card__cta {
    background: linear-gradient(135deg, #f59e0b, #d97706);
}
.product-card--enterprise .product-card__cta:hover {
    background: linear-gradient(135deg, #d97706, #b45309);
    box-shadow: 0 8px 16px rgba(245,158,11,.3);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 80px 40px;
    background: var(--cv-bg-surface);
    border-radius: 16px;
    border: 1px dashed var(--cv-border-default);
}
.empty-state__icon {
    font-size: 3.5rem;
    margin-bottom: 24px;
}
.empty-state__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 12px 0;
}
.empty-state__text {
    color: var(--cv-text-secondary);
    font-size: 1rem;
    margin: 0;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .store-hero {
        flex-direction: column;
        padding: 40px 24px;
        gap: 24px;
    }
    .store-hero__title {
        font-size: 1.75rem;
    }
    .products-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div style="max-width: 1400px; margin: 0 auto; padding: 0;">
    <!-- Hero Section -->
    <div class="store-hero">
        <div class="store-hero__content">
            <h1 class="store-hero__title">Premium Hosting Solutions</h1>
            <p class="store-hero__subtitle">Discover powerful hosting, domains, and email services designed for your success</p>
            <a href="/client/services" class="store-hero__link">
                <span>View Your Services</span>
                <span>→</span>
            </a>
        </div>
        <div class="store-hero__icon">🚀</div>
    </div>

    <!-- Product Groups -->
    <?php if ($groups === []): ?>
        <div class="empty-state">
            <div class="empty-state__icon">📦</div>
            <h2 class="empty-state__title">No Products Available</h2>
            <p class="empty-state__text"><?= e($t->get('store.no_products')) ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($groups as $group): ?>
            <div class="product-group">
                <!-- Group Header -->
                <div class="group-header">
                    <h2 class="group-header__title">
                        <?php if ($group['name'] === 'ResellerClub Email Hosting'): ?>
                            <span>✉️</span>
                        <?php elseif (str_contains(strtolower($group['name']), 'domain')): ?>
                            <span>🌐</span>
                        <?php elseif (str_contains(strtolower($group['name']), 'hosting')): ?>
                            <span>🖥️</span>
                        <?php else: ?>
                            <span>⭐</span>
                        <?php endif; ?>
                        <?= e($group['name']) ?>
                    </h2>
                    <?php if (!empty($group['description'])): ?>
                        <p class="group-header__desc"><?= e($group['description']) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Products Grid -->
                <?php if ($group['products'] === []): ?>
                    <div class="empty-state" style="padding: 40px;">
                        <p class="empty-state__text"><?= e($t->get('store.no_products_in_group')) ?></p>
                    </div>
                <?php else: ?>
                    <div class="products-grid">
                        <?php foreach ($group['products'] as $product): ?>
                            <?php
                                $name = (string) $product['name'];
                                $isTitan = str_contains(strtolower($name), 'titan');
                                $isGoogle = str_contains(strtolower($name), 'google');
                                $isEnterprise = str_contains(strtolower($name), 'enterprise');

                                $badge = "Popular";
                                $cardClass = "product-card--email";
                                $features = ["Professional Webmail", "Spam Protection", "99.9% Uptime"];

                                if ($isTitan) {
                                    $badge = "Premium App";
                                    $cardClass = "product-card--premium";
                                    $features = ["Titan Mobile & Desktop App", "Read Receipts & Templates", "10GB Storage / Mailbox"];
                                } elseif ($isGoogle) {
                                    $badge = "Official Google";
                                    $cardClass = "product-card--google";
                                    $features = ["Gmail & Google Drive", "30GB Secure Cloud Storage", "Google Meet & Calendar"];
                                } elseif ($isEnterprise) {
                                    $badge = "For Teams";
                                    $cardClass = "product-card--enterprise";
                                    $features = ["30GB Email Storage", "Shared Calendar & Tasks", "Team Collaboration Tools"];
                                }
                            ?>
                            <div class="product-card <?= $cardClass ?>">
                                <div class="product-card__header">
                                    <span class="product-card__badge"><?= e($badge) ?></span>
                                    <h3 class="product-card__name"><?= e($name) ?></h3>
                                </div>
                                <div class="product-card__body">
                                    <p class="product-card__description"><?= e((string) ($product['description'] ?? '')) ?></p>
                                    
                                    <div style="margin: 16px 0; padding: 14px; background: var(--cv-bg-surface-sunken); border-radius: 10px; border: 1px solid var(--cv-border-default); text-align: center;">
                                        <div style="font-size: .8rem; color: var(--cv-text-secondary); text-transform: uppercase; font-weight: 700; letter-spacing: .05em;">Starting at</div>
                                        <div style="font-family: 'Hanken Grotesk', sans-serif; font-size: 1.65rem; font-weight: 900; color: var(--cv-color-brand-500); margin: 2px 0;">
                                            <?= e($currency['symbol'] ?? '$') ?><?= number_format((float) ($product['starting_price'] ?? 0), 2) ?>
                                        </div>
                                        <div style="font-size: .8rem; color: var(--cv-text-secondary); font-weight: 600;">
                                            <?= e(ucfirst(str_replace('_', ' ', (string) ($product['starting_cycle'] ?? 'monthly')))) ?>
                                        </div>
                                    </div>

                                    <ul class="product-card__features">
                                        <?php foreach ($features as $feat): ?>
                                            <li class="product-card__feature">
                                                <span class="product-card__feature-icon">✓</span>
                                                <span><?= e($feat) ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <div class="product-card__footer">
                                    <a href="/store/<?= (int) $product['id'] ?>" class="product-card__cta">
                                        Select Plan →
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
