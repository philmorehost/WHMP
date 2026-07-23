<?php
/** @var CodeVault\View $view */
/** @var array<string, mixed> $admin */
/** @var int $clientCount */
/** @var int $newClientsThisMonth */
/** @var float $incomeThisMonth */
/** @var int $pendingOrders */
/** @var int $overdueInvoiceCount */
/** @var float $overdueInvoiceTotal */
/** @var int $openTickets */
/** @var int $renewingServices */
/** @var int $renewingDomains */
/** @var string $revenueChart raw SVG markup */
/** @var array<int, array<string, mixed>> $recentActivity */
/** @var int $hookPointCount */
/** @var array<int, string> $widgetOutputs raw HTML per active dashboard widget */
?>
<style>
/* Admin Dashboard Styles */
.admin-dashboard-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 56px 40px;
    margin-bottom: 48px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 40px;
    flex-wrap: wrap;
}
.admin-dashboard-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-dashboard-hero__greeting {
    flex: 1;
    position: relative;
    z-index: 1;
    min-width: 250px;
}
.admin-dashboard-hero__greeting-title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 8px 0;
    line-height: 1.2;
}
.admin-dashboard-hero__greeting-subtitle {
    color: rgba(255,255,255,.7);
    font-size: .95rem;
    margin: 0;
}
.admin-dashboard-hero__actions {
    display: flex;
    gap: 12px;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
    align-items: center;
}
.admin-dashboard-search {
    display: flex;
    gap: 8px;
    flex: 1;
    min-width: 280px;
    max-width: 400px;
}
.admin-dashboard-search input {
    flex: 1;
    padding: 12px 16px;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 8px;
    background: rgba(255,255,255,.1);
    color: white;
    font-size: .95rem;
}
.admin-dashboard-search input::placeholder {
    color: rgba(255,255,255,.5);
}
.admin-dashboard-search input:focus {
    outline: none;
    background: rgba(255,255,255,.15);
    border-color: rgba(255,255,255,.4);
}
.admin-dashboard-search button {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 12px 24px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    transition: all 0.2s;
}
.admin-dashboard-search button:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}
.admin-dashboard-logout {
    background: rgba(255,255,255,.1);
    color: white;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 8px;
    padding: 12px 24px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    transition: all 0.2s;
}
.admin-dashboard-logout:hover {
    background: rgba(255,255,255,.2);
    border-color: rgba(255,255,255,.4);
}

/* Enhanced Stat Grid */
.admin-stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 48px;
}
.admin-stat-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
}
.admin-stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.1);
    border-color: var(--cv-color-brand-500);
}
.admin-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
    color: white;
}
.admin-stat-label {
    color: var(--cv-text-secondary);
    font-size: .85rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 8px;
}
.admin-stat-value {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: var(--cv-text-primary);
    margin-bottom: 12px;
    line-height: 1;
}
.admin-stat-meta {
    font-size: .85rem;
    color: var(--cv-text-secondary);
    line-height: 1.5;
}
.admin-stat-meta a {
    color: var(--cv-color-brand-500);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}
.admin-stat-meta a:hover {
    color: var(--cv-color-brand-600);
    text-decoration: underline;
}

/* Enhanced Card */
.admin-dashboard-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.admin-dashboard-card__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0;
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
}

/* Chart Container */
.admin-dashboard-chart {
    padding: 24px;
}

/* Recent Activity Table */
.admin-activity-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-activity-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-activity-table th {
    padding: 16px 24px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-activity-table tbody tr {
    border-bottom: 1px solid var(--cv-border-default);
    transition: all 0.2s;
}
.admin-activity-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-activity-table td {
    padding: 16px 24px;
    color: var(--cv-text-primary);
}
.admin-activity-table .admin-activity-time {
    font-size: .85rem;
    color: var(--cv-text-secondary);
    font-family: 'Monaco', 'Courier New', monospace;
}

/* Grid Layout */
.admin-dashboard-grid-2-1 {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
    margin-bottom: 32px;
}
@media (max-width: 1024px) {
    .admin-dashboard-grid-2-1 {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .admin-dashboard-hero {
        flex-direction: column;
        padding: 40px 24px;
        gap: 24px;
    }
    .admin-dashboard-hero__greeting-title {
        font-size: 1.5rem;
    }
    .admin-dashboard-search {
        max-width: 100%;
    }
    .admin-stat-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Admin Dashboard Hero -->
<div class="admin-dashboard-hero">
    <div class="admin-dashboard-hero__greeting">
        <h1 class="admin-dashboard-hero__greeting-title">Welcome back, <?= e($admin['display_name']) ?></h1>
        <p class="admin-dashboard-hero__greeting-subtitle">@<?= e($admin['username']) ?> — Dashboard</p>
    </div>
    <div class="admin-dashboard-hero__actions">
        <form method="get" action="/admin/clients" class="admin-dashboard-search">
            <input type="search" name="q" placeholder="Search clients by name, email…">
            <button type="submit">🔍 Search</button>
        </form>
        <form method="post" action="/logout" style="margin:0;"><?= csrf_field() ?>
            <button class="admin-dashboard-logout" type="submit">Log Out</button>
        </form>
    </div>
</div>

<div class="admin-stat-grid">
    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background:linear-gradient(135deg, #f59e0b, #d97706);">💰</div>
        <span class="admin-stat-label">Income This Month</span>
        <span class="admin-stat-value">$<?= e(number_format($incomeThisMonth, 0)) ?></span>
        <span class="admin-stat-meta"><a href="/admin/reports">View income report →</a></span>
    </div>

    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background:linear-gradient(135deg, #06b6d4, #3b82f6);">👥</div>
        <span class="admin-stat-label">Total Clients</span>
        <span class="admin-stat-value"><?= (int) $clientCount ?></span>
        <span class="admin-stat-meta"><?= (int) $newClientsThisMonth ?> new this month · <a href="/admin/clients">View all →</a></span>
    </div>

    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background:linear-gradient(135deg, #f59e0b, #ef4444);">🛡️</div>
        <span class="admin-stat-label">Pending Orders</span>
        <span class="admin-stat-value"><?= (int) $pendingOrders ?></span>
        <span class="admin-stat-meta"><a href="/admin/orders">Review queue →</a></span>
    </div>

    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background:linear-gradient(135deg, #ef4444, #8b5cf6);">⏰</div>
        <span class="admin-stat-label">Overdue Invoices</span>
        <span class="admin-stat-value"><?= (int) $overdueInvoiceCount ?></span>
        <span class="admin-stat-meta">$<?= e(number_format($overdueInvoiceTotal, 0)) ?> outstanding · <a href="/admin/invoices">View →</a></span>
    </div>

    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background:linear-gradient(135deg, #8b5cf6, #3b82f6);">💬</div>
        <span class="admin-stat-label">Open Tickets</span>
        <span class="admin-stat-value"><?= (int) $openTickets ?></span>
        <span class="admin-stat-meta"><a href="/admin/tickets">Go to queue →</a></span>
    </div>

    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background:linear-gradient(135deg, #3b82f6, #06b6d4);">🔄</div>
        <span class="admin-stat-label">Renewing Soon (7d)</span>
        <span class="admin-stat-value"><?= (int) ($renewingServices + $renewingDomains) ?></span>
        <span class="admin-stat-meta"><?= (int) $renewingServices ?> services · <?= (int) $renewingDomains ?> domains</span>
    </div>
</div>

<div class="admin-dashboard-grid-2-1">
    <div class="admin-dashboard-card">
        <h2 class="admin-dashboard-card__title">📊 Revenue — Last 6 Months</h2>
        <div class="admin-dashboard-chart">
            <?= $revenueChart ?>
        </div>
    </div>

    <div class="admin-dashboard-card">
        <h2 class="admin-dashboard-card__title">⚡ Quick Links</h2>
        <div style="display:flex;flex-direction:column;gap:12px;padding:24px;">
            <a href="/admin/orders" style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:linear-gradient(135deg, rgba(245,158,11,.1), rgba(217,119,6,.05));border:1px solid rgba(245,158,11,.2);border-radius:8px;text-decoration:none;color:var(--cv-text-primary);font-weight:600;transition:all 0.2s;">
                <span>📋</span>
                <span>Pending Orders</span>
                <span style="margin-left:auto;color:var(--cv-text-secondary);">→</span>
            </a>
            <a href="/admin/ask-ai" style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:linear-gradient(135deg, rgba(59,130,246,.1), rgba(37,99,235,.05));border:1px solid rgba(59,130,246,.2);border-radius:8px;text-decoration:none;color:var(--cv-text-primary);font-weight:600;transition:all 0.2s;">
                <span>🤖</span>
                <span>Ask AI</span>
                <span style="margin-left:auto;color:var(--cv-text-secondary);">→</span>
            </a>
            <a href="/admin/import/clients" style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:linear-gradient(135deg, rgba(16,185,129,.1), rgba(5,150,105,.05));border:1px solid rgba(16,185,129,.2);border-radius:8px;text-decoration:none;color:var(--cv-text-primary);font-weight:600;transition:all 0.2s;">
                <span>📥</span>
                <span>Import Clients</span>
                <span style="margin-left:auto;color:var(--cv-text-secondary);">→</span>
            </a>
            <a href="/admin/reports" style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:linear-gradient(135deg, rgba(139,92,246,.1), rgba(124,58,202,.05));border:1px solid rgba(139,92,246,.2);border-radius:8px;text-decoration:none;color:var(--cv-text-primary);font-weight:600;transition:all 0.2s;">
                <span>📈</span>
                <span>Reports</span>
                <span style="margin-left:auto;color:var(--cv-text-secondary);">→</span>
            </a>
        </div>
        <p style="margin:0;padding:0 24px 16px 24px;color:var(--cv-text-secondary);font-size:.75rem;"><?= (int) $hookPointCount ?> hook points registered</p>
    </div>
</div>

<?php foreach ($widgetOutputs as $widgetHtml): ?>
    <div style="margin-top:var(--cv-space-5);"><?= $widgetHtml ?></div>
<?php endforeach; ?>

<div class="admin-dashboard-card" style="margin-top:32px;">
    <h2 class="admin-dashboard-card__title">📝 Recent Activity</h2>
    <div style="overflow-x:auto;">
        <table class="admin-activity-table">
            <thead><tr><th>Time</th><th>Action</th><th>Description</th></tr></thead>
            <tbody>
            <?php foreach ($recentActivity as $entry): ?>
                <tr>
                    <td class="admin-activity-time"><?= e($entry['created_at']) ?></td>
                    <td style="font-weight:600;color:var(--cv-color-brand-500);"><?= e($entry['action']) ?></td>
                    <td><?= e($entry['description']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($recentActivity === []): ?>
                <tr><td colspan="3" style="color:var(--cv-text-secondary);padding:40px 24px;text-align:center;">No activity recorded yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
