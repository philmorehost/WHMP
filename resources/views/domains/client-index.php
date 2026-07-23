<?php
/** @var array<int, array<string, mixed>> $domains */
?>
<style>
/* ====== Domains Page Styles ====== */
.domains-hero {
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
.domains-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(245,158,11,.12) 0%, transparent 70%);
    pointer-events: none;
}
.domains-hero__content {
    flex: 1;
    position: relative;
    z-index: 1;
}
.domains-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2.5rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 16px 0;
    line-height: 1.2;
}
.domains-hero__subtitle {
    font-size: 1.1rem;
    color: rgba(255,255,255,.75);
    margin: 0 0 24px 0;
    line-height: 1.6;
}
.domains-hero__actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
    margin-top: 20px;
}
.domains-hero__link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #f59e0b;
    text-decoration: none;
    font-weight: 600;
    font-size: .95rem;
    transition: all 0.2s;
}
.domains-hero__link:hover {
    gap: 12px;
    color: #fbbf24;
}
.domains-hero__icon {
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

/* Tabs */
.domains-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 32px;
    border-bottom: 2px solid var(--cv-border-default);
    overflow-x: auto;
    padding-bottom: 12px;
}
.domains-tab {
    padding: 8px 16px;
    border: none;
    background: transparent;
    color: var(--cv-text-secondary);
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-size: .95rem;
    white-space: nowrap;
}
.domains-tab:hover {
    color: var(--cv-text-primary);
}
.domains-tab.active {
    color: var(--cv-color-brand-500);
    border-bottom: 3px solid var(--cv-color-brand-500);
    margin-bottom: -12px;
    padding-bottom: 9px;
}

/* Toolbar & Search */
.domains-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 32px;
    flex-wrap: wrap;
}

/* Domains Grid */
.domains-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

/* Domain Card */
.domain-card {
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
.domain-card:hover {
    transform: translateY(-8px);
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 16px 32px rgba(0,0,0,0.12);
}
.domain-card__header {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    padding: 24px;
    border-bottom: 1px solid var(--cv-border-default);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}
.domain-card__name {
    font-family: 'Monaco', 'Courier New', monospace;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--cv-text-primary);
    margin: 0;
    line-height: 1.3;
    flex: 1;
    word-break: break-word;
}
.domain-card__status {
    flex-shrink: 0;
}
.domain-card__body {
    padding: 24px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.domain-card__info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: .85rem;
    padding: 8px 0;
}
.domain-card__info-label {
    color: var(--cv-text-secondary);
    font-weight: 500;
}
.domain-card__info-value {
    color: var(--cv-text-primary);
    font-weight: 600;
}
.domain-card__expiry-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: .75rem;
    font-weight: 700;
    background: rgba(239,68,68,0.1);
    color: #dc2626;
}
.domain-card__footer {
    padding: 16px 24px;
    background: var(--cv-bg-surface-sunken);
    border-top: 1px solid var(--cv-border-default);
}
.domain-card__cta {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
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
    width: 100%;
    text-align: center;
}
.domain-card__cta:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateX(2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}

/* Empty State */
.empty-state-domains {
    text-align: center;
    padding: 80px 40px;
    background: var(--cv-bg-surface);
    border-radius: 16px;
    border: 1px dashed var(--cv-border-default);
}
.empty-state-domains__icon {
    font-size: 3.5rem;
    margin-bottom: 24px;
}
.empty-state-domains__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 12px 0;
}
.empty-state-domains__text {
    color: var(--cv-text-secondary);
    font-size: 1rem;
    margin: 0 0 24px 0;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .domains-hero {
        flex-direction: column;
        padding: 40px 24px;
        gap: 24px;
    }
    .domains-hero__title {
        font-size: 1.75rem;
    }
    .domains-grid {
        grid-template-columns: 1fr;
    }
    .domains-tabs {
        margin-bottom: 16px;
    }
}
</style>

<div style="max-width: 1400px; margin: 0 auto; padding: 0;">
    <!-- Hero Section -->
    <div class="domains-hero">
        <div class="domains-hero__content">
            <h1 class="domains-hero__title">My Domains</h1>
            <p class="domains-hero__subtitle">Manage all your domain registrations in one place</p>
            <div class="domains-hero__actions">
                <a href="/client/dashboard" class="domains-hero__link">
                    <span>← Back to dashboard</span>
                </a>
                <a href="/store" class="domains-hero__link" style="color: #10b981;">
                    <span>Register New Domain</span>
                    <span>→</span>
                </a>
            </div>
        </div>
        <div class="domains-hero__icon">🌐</div>
    </div>

    <!-- Status Tabs -->
    <?php
        $active = array_filter($domains, fn ($d) => $d['status'] === 'active');
        $expiring = array_filter($domains, fn ($d) => !empty($d['expiry_date']) && strtotime($d['expiry_date']) < strtotime('+30 days'));
    ?>
    <div class="domains-tabs" id="domain-tabs">
        <button class="domains-tab active" onclick="filterDomains('all')" data-filter="all">
            All (<?= count($domains) ?>)
        </button>
        <button class="domains-tab" onclick="filterDomains('active')" data-filter="active">
            Active (<?= count($active) ?>)
        </button>
        <?php if (count($expiring) > 0): ?>
            <button class="domains-tab" onclick="filterDomains('expiring')" data-filter="expiring" style="color: #dc2626;">
                ⚠️ Expiring Soon (<?= count($expiring) ?>)
            </button>
        <?php endif; ?>
    </div>

    <!-- Search Toolbar -->
    <div class="domains-toolbar">
        <div style="flex: 1; min-width: 200px;">
            <?= $view->partial('partials.table-search', ['target' => '#domains-list', 'placeholder' => 'Search domains...']) ?>
        </div>
    </div>

    <!-- Domains Grid or Empty State -->
    <?php if ($domains === []): ?>
        <div class="empty-state-domains">
            <div class="empty-state-domains__icon">🏜️</div>
            <h2 class="empty-state-domains__title">No Domains Yet</h2>
            <p class="empty-state-domains__text">You haven't registered any domains yet. Get started by registering your first domain.</p>
        </div>
    <?php else: ?>
        <div class="domains-grid" id="domains-list">
            <?php foreach ($domains as $domain): ?>
                <?php
                    $isExpiring = !empty($domain['expiry_date']) && strtotime($domain['expiry_date']) < strtotime('+30 days');
                    $expiryClass = $isExpiring ? 'expiring' : 'active';
                ?>
                <div class="domain-card domain-card-<?= $expiryClass ?>" style="<?= $isExpiring ? 'border-left: 4px solid #dc2626;' : '' ?>">
                    <div class="domain-card__header">
                        <h3 class="domain-card__name"><?= e($domain['domain_name']) ?></h3>
                        <div class="domain-card__status">
                            <?php if ($domain['status'] === 'active'): ?>
                                <span class="cv-badge" style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 6px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; text-transform: uppercase;">Active</span>
                            <?php else: ?>
                                <span class="cv-badge" style="background: linear-gradient(135deg, #6b7280, #4b5563); color: white; padding: 6px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; text-transform: uppercase;"><?= e($domain['status']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="domain-card__body">
                        <div class="domain-card__info-row">
                            <span class="domain-card__info-label">Expiry Date</span>
                            <span class="domain-card__info-value">
                                <?php if (!empty($domain['expiry_date'])): ?>
                                    <?= e($domain['expiry_date']) ?>
                                    <?php if ($isExpiring): ?>
                                        <span class="domain-card__expiry-badge">Renew Soon!</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="domain-card__info-row">
                            <span class="domain-card__info-label">Registrar Lock</span>
                            <span class="domain-card__info-value"><?= $domain['registrar_lock_enabled'] ? '🔒 Locked' : '🔓 Unlocked' ?></span>
                        </div>
                        <div class="domain-card__info-row">
                            <span class="domain-card__info-label">ID Protection</span>
                            <span class="domain-card__info-value"><?= $domain['id_protection_enabled'] ? '✓ Enabled' : '✗ Disabled' ?></span>
                        </div>
                    </div>

                    <div class="domain-card__footer">
                        <a class="domain-card__cta" href="/client/domains/<?= (int) $domain['id'] ?>">Manage Domain →</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <script>
            function filterDomains(filter) {
                const cards = document.querySelectorAll('[class*="domain-card-"]');
                const tabs = document.querySelectorAll('.domains-tab');

                tabs.forEach(t => t.classList.remove('active'));
                event.target.classList.add('active');

                if (filter === 'all') {
                    cards.forEach(c => c.style.display = '');
                } else {
                    cards.forEach(c => c.style.display = c.classList.contains(`domain-card-${filter}`) ? '' : 'none');
                }
            }
        </script>
    <?php endif; ?>
</div>
