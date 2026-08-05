<?php
/** @var array<int, array<string, mixed>> $services */
?>
<style>
/* ====== Services Page Styles ====== */
.services-hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 45%, #0f3460 100%);
    padding: 56px 40px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 40px;
    position: relative;
    overflow: hidden;
    margin-bottom: 48px;
    border-radius: 16px;
}
.services-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(16,185,129,.12) 0%, transparent 70%);
    pointer-events: none;
}
.services-hero__content {
    flex: 1;
    position: relative;
    z-index: 1;
}
.services-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2.5rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 16px 0;
    line-height: 1.2;
}
.services-hero__subtitle {
    font-size: 1.1rem;
    color: rgba(255,255,255,.75);
    margin: 0 0 24px 0;
    line-height: 1.6;
}
.services-hero__actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
    margin-top: 20px;
}
.services-hero__link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #10b981;
    text-decoration: none;
    font-weight: 600;
    font-size: .95rem;
    transition: all 0.2s;
}
.services-hero__link:hover {
    gap: 12px;
    color: #34d399;
}
.services-hero__icon {
    width: 120px;
    height: 120px;
    background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15));
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    flex-shrink: 0;
    border: 2px solid rgba(16,185,129,.3);
    position: relative;
    z-index: 1;
    box-shadow: 0 20px 40px rgba(16,185,129,.1);
}

/* Toolbar & Search */
.services-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 32px;
    flex-wrap: wrap;
}

/* Services Grid */
.services-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

/* Service Card */
.service-card {
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
.service-card:hover {
    transform: translateY(-8px);
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 16px 32px rgba(0,0,0,0.12);
}
.service-card__header {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    padding: 24px;
    border-bottom: 1px solid var(--cv-border-default);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}
.service-card__name {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0;
    line-height: 1.3;
    flex: 1;
}
.service-card__status {
    flex-shrink: 0;
}
.service-card__body {
    padding: 24px;
    flex: 1;
}
.service-card__info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--cv-border-default);
    font-size: .9rem;
}
.service-card__info-row:last-child {
    border-bottom: none;
}
.service-card__info-label {
    color: var(--cv-text-secondary);
    font-weight: 500;
}
.service-card__info-value {
    color: var(--cv-text-primary);
    font-weight: 600;
}
.service-card__footer {
    padding: 16px 24px;
    background: var(--cv-bg-surface-sunken);
    border-top: 1px solid var(--cv-border-default);
    display: flex;
    gap: 12px;
}
.service-card__cta {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 8px 16px;
    font-weight: 700;
    font-size: .85rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s;
    flex: 1;
    text-align: center;
}
.service-card__cta:hover {
    background: linear-gradient(135deg, #059669, #047857);
    transform: translateX(2px);
    box-shadow: 0 8px 16px rgba(16,185,129,.3);
}

/* Empty State */
.empty-state-services {
    text-align: center;
    padding: 80px 40px;
    background: var(--cv-bg-surface);
    border-radius: 16px;
    border: 1px dashed var(--cv-border-default);
}
.empty-state-services__icon {
    font-size: 3.5rem;
    margin-bottom: 24px;
}
.empty-state-services__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 12px 0;
}
.empty-state-services__text {
    color: var(--cv-text-secondary);
    font-size: 1rem;
    margin: 0 0 24px 0;
}
.empty-state-services__cta {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 10px 24px;
    font-weight: 700;
    font-size: .95rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s;
}
.empty-state-services__cta:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .services-hero {
        flex-direction: column;
        padding: 40px 24px;
        gap: 24px;
    }
    .services-hero__title {
        font-size: 1.75rem;
    }
    .services-grid {
        grid-template-columns: 1fr;
    }
    .services-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>

<div style="max-width: 1400px; margin: 0 auto; padding: 0;">
    <!-- Hero Section -->
    <div class="services-hero">
        <div class="services-hero__content">
            <h1 class="services-hero__title">My Services</h1>
            <p class="services-hero__subtitle">Manage and monitor all your active services in one place</p>
            <div class="services-hero__actions">
                <a href="/client/dashboard" class="services-hero__link">
                    <span>← Back to dashboard</span>
                </a>
                <a href="/store" class="services-hero__link" style="color: #f59e0b;">
                    <span>Browse More Services</span>
                    <span>→</span>
                </a>
            </div>
        </div>
        <div class="services-hero__icon">🚀</div>
    </div>

    <!-- Search Toolbar -->
    <div class="services-toolbar">
        <div style="flex: 1; min-width: 200px;">
            <?= $view->partial('partials.table-search', ['target' => '#services-list', 'placeholder' => 'Search services by name...']) ?>
        </div>
        <div style="color: var(--cv-text-secondary); font-size: .9rem;">
            <?= count($services) ?> service<?= count($services) !== 1 ? 's' : '' ?>
        </div>
    </div>

    <!-- Services Grid or Empty State -->
    <?php if ($services === []): ?>
        <div class="empty-state-services">
            <div class="empty-state-services__icon">📦</div>
            <h2 class="empty-state-services__title">No Services Yet</h2>
            <p class="empty-state-services__text">You don't have any active services. Browse our store to find the perfect solution for your needs.</p>
            <a href="/store" class="empty-state-services__cta">Browse Store →</a>
        </div>
    <?php else: ?>
        <div class="services-grid" id="services-list">
            <?php foreach ($services as $service): ?>
                <div class="service-card" style="<?= str_contains($service['product_name'] ?? '', 'Email') ? 'border-left: 4px solid #10b981;' : '' ?>">
                    <div class="service-card__header">
                        <h3 class="service-card__name"><?= e($service['product_name']) ?></h3>
                        <div class="service-card__status">
                            <?php if ($service['status'] === 'active'): ?>
                                <span class="cv-badge" style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 6px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; text-transform: uppercase;">Active</span>
                            <?php elseif ($service['status'] === 'suspended'): ?>
                                <span class="cv-badge" style="background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 6px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; text-transform: uppercase;">Suspended</span>
                            <?php elseif ($service['status'] === 'cancelled'): ?>
                                <span class="cv-badge" style="background: linear-gradient(135deg, #6b7280, #4b5563); color: white; padding: 6px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; text-transform: uppercase;">Cancelled</span>
                            <?php else: ?>
                                <span class="cv-badge" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white; padding: 6px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; text-transform: uppercase;"><?= e($service['status']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="service-card__body">
                        <div class="service-card__info-row">
                            <span class="service-card__info-label">Billing Cycle</span>
                            <span class="service-card__info-value"><?= e($service['billing_cycle']) ?></span>
                        </div>
                        <div class="service-card__info-row">
                            <span class="service-card__info-label">Next Due</span>
                            <span class="service-card__info-value"><?= e($service['next_due_date']) ?></span>
                        </div>
                        <?php if (!empty($service['domain']) || !empty($service['hostname'])): ?>
                            <div class="service-card__info-row">
                                <span class="service-card__info-label">Domain</span>
                                <span class="service-card__info-value" style="word-break: break-all; font-family: 'Monaco', 'Courier New', monospace; font-size: .85rem;"><?= e($service['domain'] ?: $service['hostname']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="service-card__footer">
                        <?php if (($service['domain_id'] ?? null) !== null): ?>
                            <a class="service-card__cta" href="/client/domains/<?= (int) $service['domain_id'] ?>">Manage Domain →</a>
                        <?php else: ?>
                            <a class="service-card__cta" href="/client/services/<?= (int) $service['id'] ?>">Manage Service →</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
