<?php
/** @var CodeVault\View $view */
/** @var array<int, array{group: array<string, mixed>, products: array<int, array<string, mixed>>}> $productGroups */
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
                        <span>New Deals</span>
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
        <!-- Terms Warning Banner -->
        <div style="background: rgba(255, 143, 40, 0.08); border: 1px solid var(--cv-color-brand-500); padding: var(--cv-space-4); border-radius: var(--cv-radius-md); display: flex; gap: var(--cv-space-3); margin-bottom: var(--cv-space-6); font-size: var(--cv-text-sm); color: var(--cv-text-primary); align-items: flex-start;">
            <span style="font-size: 1.25rem; line-height: 1;">⚠️</span>
            <div>
                <strong style="display: block; margin-bottom: var(--cv-space-1); font-family: 'Hanken Grotesk', sans-serif;">Read Our Terms of Service Before Purchase Licenses</strong>
                <span style="color: var(--cv-text-secondary); font-size: var(--cv-text-xs);">Please ensure you read <a href="/terms" style="color: var(--cv-color-brand-500); font-weight: 700; text-decoration: none;">terms of services</a> before ordering any license.</span>
            </div>
        </div>

        <?php foreach ($productGroups as $groupData): ?>
            <h2 style="font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-xl); font-weight: 800; margin-top: var(--cv-space-6); margin-bottom: var(--cv-space-4); color: var(--cv-text-primary); border-bottom: 2px solid var(--cv-border-default); padding-bottom: var(--cv-space-2);">
                <?= e($groupData['group']['name']) ?>
            </h2>

            <!-- Product Cards Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: var(--cv-space-4); margin-bottom: var(--cv-space-8);">
                <?php foreach ($groupData['products'] as $prod): ?>
                    <div class="cv-card" style="text-align: center; padding: var(--cv-space-5); display: flex; flex-direction: column; align-items: center; border: 1px solid var(--cv-border-default); background: var(--cv-bg-surface);">
                        <div style="font-size: 2.25rem; margin-bottom: var(--cv-space-3);">📦</div>
                        <h4 style="margin: 0; font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-sm); font-weight: 700; color: var(--cv-text-primary); min-height: 2.5rem; display: flex; align-items: center; justify-content: center;">
                            <?= e($prod['name']) ?>
                        </h4>
                        <p style="font-size: var(--cv-text-xs); color: var(--cv-text-secondary); height: 3.5rem; overflow: hidden; margin: var(--cv-space-2) 0; white-space: pre-wrap;">
                            <?= e($prod['description'] ?: 'High performance license key with instant auto-provisioning.') ?>
                        </p>
                        <div style="font-size: var(--cv-text-xs); color: var(--cv-text-secondary); margin-top: var(--cv-space-2);">Starting at</div>
                        <div style="font-size: var(--cv-text-xl); font-family: 'Hanken Grotesk', sans-serif; font-weight: 800; color: var(--cv-color-brand-500); margin: var(--cv-space-1) 0;">
                            $<?= number_format((float) $prod['price'], 2) ?>
                        </div>
                        <div style="font-size: var(--cv-text-xs); color: var(--cv-text-secondary); margin-bottom: var(--cv-space-5);">
                            <?= e(ucfirst(str_replace('_', ' ', (string) $prod['billing_cycle']))) ?>
                        </div>
                        <a href="/store" class="cv-btn" style="width: 100%; text-decoration: none; display: inline-block; font-weight: 700;">Get Started Now</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($productGroups === []): ?>
            <div class="cv-card" style="text-align: center; padding: var(--cv-space-8); color: var(--cv-text-secondary);">
                No products found. Please add products and product groups in the admin dashboard.
            </div>
        <?php endif; ?>

        <!-- Our Guarantees Section (Blue Block) -->
        <div style="background: #0d6efd; color: #ffffff; border-radius: var(--cv-radius-md); padding: var(--cv-space-6) var(--cv-space-8); margin-top: var(--cv-space-8);">
            <h3 style="text-align: center; margin: 0 0 var(--cv-space-1) 0; font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-xl); font-weight: 800; color: #ffffff;">Our Guarantees</h3>
            <p style="text-align: center; margin: 0 0 var(--cv-space-6) 0; font-size: var(--cv-text-xs); opacity: 0.85; color: #ffffff;">Learn why we are trusted by over 35,000 clients worldwides</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--cv-space-6);">
                <!-- G1 -->
                <div style="display: flex; gap: var(--cv-space-3); align-items: flex-start;">
                    <div style="font-size: 1.5rem; background: rgba(255,255,255,0.18); border-radius: 50%; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">🛠️</div>
                    <div>
                        <h4 style="margin: 0; font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-sm); font-weight: 700; color: #ffffff;">24/7 Expert Support</h4>
                        <p style="margin: var(--cv-space-1) 0 0 0; font-size: var(--cv-text-xs); opacity: 0.85; line-height: 1.4; color: #ffffff;">Proactively monitors for and alerts you about any malware or downtime.</p>
                    </div>
                </div>
                <!-- G2 -->
                <div style="display: flex; gap: var(--cv-space-3); align-items: flex-start;">
                    <div style="font-size: 1.5rem; background: rgba(255,255,255,0.18); border-radius: 50%; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">🚀</div>
                    <div>
                        <h4 style="margin: 0; font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-sm); font-weight: 700; color: #ffffff;">Fast & Reliable</h4>
                        <p style="margin: var(--cv-space-1) 0 0 0; font-size: var(--cv-text-xs); opacity: 0.85; line-height: 1.4; color: #ffffff;">If a scan finds anything, we will safely remove any malware.</p>
                    </div>
                </div>
                <!-- G3 -->
                <div style="display: flex; gap: var(--cv-space-3); align-items: flex-start;">
                    <div style="font-size: 1.5rem; background: rgba(255,255,255,0.18); border-radius: 50%; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">🎛️</div>
                    <div>
                        <h4 style="margin: 0; font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-sm); font-weight: 700; color: #ffffff;">Super Easy to Use</h4>
                        <p style="margin: var(--cv-space-1) 0 0 0; font-size: var(--cv-text-xs); opacity: 0.85; line-height: 1.4; color: #ffffff;">Automatically checks your applications to ensure they are up-to-date.</p>
                    </div>
                </div>
                <!-- G4 -->
                <div style="display: flex; gap: var(--cv-space-3); align-items: flex-start;">
                    <div style="font-size: 1.5rem; background: rgba(255,255,255,0.18); border-radius: 50%; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">🛡️</div>
                    <div>
                        <h4 style="margin: 0; font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-sm); font-weight: 700; color: #ffffff;">100% Uptime Guaranteed</h4>
                        <p style="margin: var(--cv-space-1) 0 0 0; font-size: var(--cv-text-xs); opacity: 0.85; line-height: 1.4; color: #ffffff;">Get protection against the top 10 web app security flaws.</p>
                    </div>
                </div>
                <!-- G5 -->
                <div style="display: flex; gap: var(--cv-space-3); align-items: flex-start;">
                    <div style="font-size: 1.5rem; background: rgba(255,255,255,0.18); border-radius: 50%; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">🔒</div>
                    <div>
                        <h4 style="margin: 0; font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-sm); font-weight: 700; color: #ffffff;">Secure Servers</h4>
                        <p style="margin: var(--cv-space-1) 0 0 0; font-size: var(--cv-text-xs); opacity: 0.85; line-height: 1.4; color: #ffffff;">Our application firewalls protect your website from threats.</p>
                    </div>
                </div>
                <!-- G6 -->
                <div style="display: flex; gap: var(--cv-space-3); align-items: flex-start;">
                    <div style="font-size: 1.5rem; background: rgba(255,255,255,0.18); border-radius: 50%; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">💰</div>
                    <div>
                        <h4 style="margin: 0; font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-sm); font-weight: 700; color: #ffffff;">Money-back Guarantee</h4>
                        <p style="margin: var(--cv-space-1) 0 0 0; font-size: var(--cv-text-xs); opacity: 0.85; line-height: 1.4; color: #ffffff;">Daily scans help detect malware early before search engines blacklist.</p>
                    </div>
                </div>
                <!-- G7 -->
                <div style="display: flex; gap: var(--cv-space-3); align-items: flex-start;">
                    <div style="font-size: 1.5rem; background: rgba(255,255,255,0.18); border-radius: 50%; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">⚡</div>
                    <div>
                        <h4 style="margin: 0; font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-sm); font-weight: 700; color: #ffffff;">High Performance</h4>
                        <p style="margin: var(--cv-space-1) 0 0 0; font-size: var(--cv-text-xs); opacity: 0.85; line-height: 1.4; color: #ffffff;">Instant and fully automated setup gives you protection immediately.</p>
                    </div>
                </div>
                <!-- G8 -->
                <div style="display: flex; gap: var(--cv-space-3); align-items: flex-start;">
                    <div style="font-size: 1.5rem; background: rgba(255,255,255,0.18); border-radius: 50%; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">📡</div>
                    <div>
                        <h4 style="margin: 0; font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-sm); font-weight: 700; color: #ffffff;">Content Delivery Network</h4>
                        <p style="margin: var(--cv-space-1) 0 0 0; font-size: var(--cv-text-xs); opacity: 0.85; line-height: 1.4; color: #ffffff;">Speed up your website by distributing it globally near your users.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
