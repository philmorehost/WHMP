<?php
/** @var array<int, array<string, mixed>> $tickets */
?>
<style>
/* ====== Tickets Page Styles ====== */
.tickets-hero {
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
.tickets-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(59,130,246,.12) 0%, transparent 70%);
    pointer-events: none;
}
.tickets-hero__content {
    flex: 1;
    position: relative;
    z-index: 1;
}
.tickets-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2.5rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 16px 0;
    line-height: 1.2;
}
.tickets-hero__subtitle {
    font-size: 1.1rem;
    color: rgba(255,255,255,.75);
    margin: 0 0 24px 0;
    line-height: 1.6;
}
.tickets-hero__actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
    margin-top: 20px;
}
.tickets-hero__link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #10b981;
    text-decoration: none;
    font-weight: 600;
    font-size: .95rem;
    transition: all 0.2s;
}
.tickets-hero__link:hover {
    gap: 12px;
    color: #34d399;
}
.tickets-hero__icon {
    width: 120px;
    height: 120px;
    background: linear-gradient(135deg, rgba(59,130,246,.2), rgba(37,99,235,.15));
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    flex-shrink: 0;
    border: 2px solid rgba(59,130,246,.3);
    position: relative;
    z-index: 1;
    box-shadow: 0 20px 40px rgba(59,130,246,.1);
}

/* Tabs */
.tickets-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 32px;
    border-bottom: 2px solid var(--cv-border-default);
    overflow-x: auto;
    padding-bottom: 12px;
}
.tickets-tab {
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
.tickets-tab:hover {
    color: var(--cv-text-primary);
}
.tickets-tab.active {
    color: var(--cv-color-brand-500);
    border-bottom: 3px solid var(--cv-color-brand-500);
    margin-bottom: -12px;
    padding-bottom: 9px;
}

/* Toolbar & Search */
.tickets-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 32px;
    flex-wrap: wrap;
}

/* Tickets Grid */
.tickets-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

/* Ticket Card */
.ticket-card {
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
.ticket-card:hover {
    transform: translateY(-8px);
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 16px 32px rgba(0,0,0,0.12);
}
.ticket-card__header {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    padding: 24px;
    border-bottom: 1px solid var(--cv-border-default);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}
.ticket-card__id {
    font-family: 'Monaco', 'Courier New', monospace;
    font-size: 1rem;
    font-weight: 700;
    color: var(--cv-text-secondary);
    margin: 0;
}
.ticket-card__status {
    flex-shrink: 0;
}
.ticket-card__body {
    padding: 24px;
    flex: 1;
}
.ticket-card__subject {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 16px 0;
    line-height: 1.3;
}
.ticket-card__info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    font-size: .85rem;
}
.ticket-card__info-label {
    color: var(--cv-text-secondary);
    font-weight: 500;
}
.ticket-card__info-value {
    color: var(--cv-text-primary);
    font-weight: 600;
}
.ticket-card__footer {
    padding: 16px 24px;
    background: var(--cv-bg-surface-sunken);
    border-top: 1px solid var(--cv-border-default);
}
.ticket-card__cta {
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
.ticket-card__cta:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateX(2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}

/* Empty State */
.empty-state-tickets {
    text-align: center;
    padding: 80px 40px;
    background: var(--cv-bg-surface);
    border-radius: 16px;
    border: 1px dashed var(--cv-border-default);
}
.empty-state-tickets__icon {
    font-size: 3.5rem;
    margin-bottom: 24px;
}
.empty-state-tickets__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 12px 0;
}
.empty-state-tickets__text {
    color: var(--cv-text-secondary);
    font-size: 1rem;
    margin: 0 0 24px 0;
}
.empty-state-tickets__cta {
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
.empty-state-tickets__cta:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .tickets-hero {
        flex-direction: column;
        padding: 40px 24px;
        gap: 24px;
    }
    .tickets-hero__title {
        font-size: 1.75rem;
    }
    .tickets-grid {
        grid-template-columns: 1fr;
    }
    .tickets-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>

<div style="max-width: 1400px; margin: 0 auto; padding: 0;">
    <!-- Hero Section -->
    <div class="tickets-hero">
        <div class="tickets-hero__content">
            <h1 class="tickets-hero__title">Support Tickets</h1>
            <p class="tickets-hero__subtitle">Get help with your questions and issues</p>
            <div class="tickets-hero__actions">
                <a href="/client/dashboard" class="tickets-hero__link">
                    <span>← Back to dashboard</span>
                </a>
                <a href="/client/tickets/create" class="tickets-hero__link" style="color: #10b981;">
                    <span>➕ Open New Ticket</span>
                </a>
            </div>
        </div>
        <div class="tickets-hero__icon">💬</div>
    </div>

    <!-- Status Tabs -->
    <?php
        $open = array_filter($tickets, fn ($t) => in_array($t['status'], ['open', 'answered']));
        $awaiting_customer = array_filter($tickets, fn ($t) => $t['status'] === 'answered');
        $awaiting_support = array_filter($tickets, fn ($t) => $t['status'] === 'customer-reply');
        $closed = array_filter($tickets, fn ($t) => $t['status'] === 'closed');
    ?>
    <div class="tickets-tabs" id="ticket-tabs">
        <button class="tickets-tab active" onclick="filterTickets('all')" data-filter="all">
            All (<?= count($tickets) ?>)
        </button>
        <button class="tickets-tab" onclick="filterTickets('open')" data-filter="open">
            🟢 Open (<?= count($open) ?>)
        </button>
        <button class="tickets-tab" onclick="filterTickets('awaiting-customer')" data-filter="awaiting-customer">
            🟠 Your Reply Needed (<?= count($awaiting_customer) ?>)
        </button>
        <button class="tickets-tab" onclick="filterTickets('awaiting-support')" data-filter="awaiting-support">
            🔵 Support Reviewing (<?= count($awaiting_support) ?>)
        </button>
        <button class="tickets-tab" onclick="filterTickets('closed')" data-filter="closed">
            ⚫ Resolved (<?= count($closed) ?>)
        </button>
    </div>

    <!-- Search Toolbar -->
    <div class="tickets-toolbar">
        <div style="flex: 1; min-width: 200px;">
            <?= $view->partial('partials.table-search', ['target' => '#tickets-list', 'placeholder' => 'Search by subject or ticket ID...']) ?>
        </div>
    </div>

    <!-- Tickets Grid or Empty State -->
    <?php if ($tickets === []): ?>
        <div class="empty-state-tickets">
            <div class="empty-state-tickets__icon">📭</div>
            <h2 class="empty-state-tickets__title">No Tickets Yet</h2>
            <p class="empty-state-tickets__text">You haven't opened any support tickets yet. If you need help, we're here for you!</p>
            <a href="/client/tickets/create" class="empty-state-tickets__cta">Open First Ticket →</a>
        </div>
    <?php else: ?>
        <div class="tickets-grid" id="tickets-list">
            <?php foreach ($tickets as $ticket): ?>
                <?php
                    $isOpen = in_array($ticket['status'], ['open', 'answered']);
                    $isAwaitingCustomer = $ticket['status'] === 'answered';
                    $isAwaitingSupport = $ticket['status'] === 'customer-reply';
                    $isClosed = $ticket['status'] === 'closed';

                    $filterClass = match ($ticket['status']) {
                        'answered' => 'ticket-filter-awaiting-customer',
                        'customer-reply' => 'ticket-filter-awaiting-support',
                        'closed' => 'ticket-filter-closed',
                        default => 'ticket-filter-open'
                    };
                ?>
                <div class="ticket-card <?= $filterClass ?> ticket-filter-all">
                    <div class="ticket-card__header">
                        <h3 class="ticket-card__id">#<?= (int) $ticket['id'] ?></h3>
                        <div class="ticket-card__status">
                            <?php if ($ticket['status'] === 'closed'): ?>
                                <span class="cv-badge" style="background: linear-gradient(135deg, #6b7280, #4b5563); color: white; padding: 6px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; text-transform: uppercase;">Resolved</span>
                            <?php elseif ($ticket['status'] === 'answered'): ?>
                                <span class="cv-badge" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white; padding: 6px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; text-transform: uppercase;">Your Reply Needed</span>
                            <?php elseif ($ticket['status'] === 'customer-reply'): ?>
                                <span class="cv-badge" style="background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; padding: 6px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; text-transform: uppercase;">Support Reviewing</span>
                            <?php else: ?>
                                <span class="cv-badge" style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 6px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; text-transform: uppercase;">Open</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ticket-card__body">
                        <h3 class="ticket-card__subject"><?= e($ticket['subject']) ?></h3>
                        <div class="ticket-card__info-row">
                            <span class="ticket-card__info-label">Department</span>
                            <span class="ticket-card__info-value"><?= e($ticket['department_name']) ?></span>
                        </div>
                    </div>

                    <div class="ticket-card__footer">
                        <a class="ticket-card__cta" href="/client/tickets/<?= (int) $ticket['id'] ?>">View Ticket →</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <script nonce="<?= csp_nonce() ?>">
            function filterTickets(filter) {
                const cards = document.querySelectorAll('[class*="ticket-filter-"]');
                const tabs = document.querySelectorAll('.tickets-tab');

                tabs.forEach(t => t.classList.remove('active'));
                event.target.classList.add('active');

                if (filter === 'all') {
                    cards.forEach(c => c.style.display = '');
                } else {
                    cards.forEach(c => c.style.display = c.classList.contains(`ticket-filter-${filter}`) ? '' : 'none');
                }
            }
        </script>
    <?php endif; ?>
</div>
