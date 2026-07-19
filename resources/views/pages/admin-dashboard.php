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
<div class="cv-card" style="margin-bottom:var(--cv-space-5);display:flex;align-items:center;justify-content:space-between;gap:var(--cv-space-4);flex-wrap:wrap;">
    <div>
        <h1 style="margin:0 0 0.25rem;font-size:var(--cv-text-xl);letter-spacing:-0.01em;">Welcome back, <?= e($admin['display_name']) ?></h1>
        <p style="margin:0;color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">@<?= e($admin['username']) ?></p>
    </div>
    <div style="display:flex;gap:var(--cv-space-3);align-items:center;">
        <form method="get" action="/admin/clients" style="display:flex;gap:var(--cv-space-2);margin:0;">
            <input class="cv-input" type="search" name="q" placeholder="Search clients&hellip;" style="width:14rem;">
            <button class="cv-btn cv-btn--secondary" type="submit">Search</button>
        </form>
        <form method="post" action="/logout" style="margin:0;"><?= csrf_field() ?>
            <button class="cv-btn cv-btn--secondary" type="submit">Log Out</button>
        </form>
    </div>
</div>

<div class="cv-stat-grid" style="margin-bottom:var(--cv-space-5);">
    <div class="cv-card cv-stat-tile">
        <div class="cv-stat-tile__icon" style="background:var(--cv-gradient-accent);">
            <svg width="20" height="20" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><rect x="1.5" y="3.5" width="13" height="9" rx="1.5"/><path d="M1.5 6.5h13"/></svg>
        </div>
        <span class="cv-stat-tile__label">Income This Month</span>
        <span class="cv-stat-tile__value"><?= e(number_format($incomeThisMonth, 2)) ?></span>
        <span class="cv-stat-tile__meta"><a href="/admin/reports">View income report &rarr;</a></span>
    </div>

    <div class="cv-card cv-stat-tile">
        <div class="cv-stat-tile__icon" style="background:linear-gradient(135deg, var(--cv-color-teal-500), var(--cv-color-brand-500));">
            <svg width="20" height="20" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="5.5" r="2.5"/><path d="M1.5 14c0-2.5 2-4 4.5-4s4.5 1.5 4.5 4"/></svg>
        </div>
        <span class="cv-stat-tile__label">Total Clients</span>
        <span class="cv-stat-tile__value"><?= (int) $clientCount ?></span>
        <span class="cv-stat-tile__meta"><?= (int) $newClientsThisMonth ?> new this month &middot; <a href="/admin/clients">View all &rarr;</a></span>
    </div>

    <div class="cv-card cv-stat-tile">
        <div class="cv-stat-tile__icon" style="background:linear-gradient(135deg, var(--cv-color-amber-500), var(--cv-color-danger-500));">
            <svg width="20" height="20" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M8 1.5 13.5 3.5v4c0 4-2.3 6.5-5.5 7.5-3.2-1-5.5-3.5-5.5-7.5v-4L8 1.5z"/><path d="M8 5.5v3.2M8 11h.01"/></svg>
        </div>
        <span class="cv-stat-tile__label">Pending Orders</span>
        <span class="cv-stat-tile__value"><?= (int) $pendingOrders ?></span>
        <span class="cv-stat-tile__meta"><a href="/admin/orders">Review queue &rarr;</a></span>
    </div>

    <div class="cv-card cv-stat-tile">
        <div class="cv-stat-tile__icon" style="background:linear-gradient(135deg, var(--cv-color-danger-500), var(--cv-color-violet-600));">
            <svg width="20" height="20" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6.25"/><path d="M8 4.5v4l2.5 1.5"/></svg>
        </div>
        <span class="cv-stat-tile__label">Overdue Invoices</span>
        <span class="cv-stat-tile__value"><?= (int) $overdueInvoiceCount ?></span>
        <span class="cv-stat-tile__meta"><?= e(number_format($overdueInvoiceTotal, 2)) ?> outstanding &middot; <a href="/admin/invoices">View &rarr;</a></span>
    </div>

    <div class="cv-card cv-stat-tile">
        <div class="cv-stat-tile__icon" style="background:linear-gradient(135deg, var(--cv-color-violet-500), var(--cv-color-brand-600));">
            <svg width="20" height="20" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6.25"/><circle cx="8" cy="8" r="2.5"/></svg>
        </div>
        <span class="cv-stat-tile__label">Open Tickets</span>
        <span class="cv-stat-tile__value"><?= (int) $openTickets ?></span>
        <span class="cv-stat-tile__meta"><a href="/admin/tickets">Go to queue &rarr;</a></span>
    </div>

    <div class="cv-card cv-stat-tile">
        <div class="cv-stat-tile__icon" style="background:linear-gradient(135deg, var(--cv-color-brand-500), var(--cv-color-teal-400));">
            <svg width="20" height="20" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="12" height="4.5" rx="1"/><rect x="2" y="9" width="12" height="4.5" rx="1"/></svg>
        </div>
        <span class="cv-stat-tile__label">Renewing Soon (7d)</span>
        <span class="cv-stat-tile__value"><?= (int) ($renewingServices + $renewingDomains) ?></span>
        <span class="cv-stat-tile__meta"><?= (int) $renewingServices ?> services &middot; <?= (int) $renewingDomains ?> domains</span>
    </div>
</div>

<div class="cv-dashboard-grid--2-1">
    <div class="cv-card">
        <h2 class="cv-card__title">Revenue — Last 6 Months</h2>
        <?= $revenueChart ?>
    </div>

    <div class="cv-card">
        <h2 class="cv-card__title">Quick Links</h2>
        <div style="display:flex;flex-direction:column;gap:var(--cv-space-2);">
            <a class="cv-btn cv-btn--secondary" href="/admin/orders" style="justify-content:flex-start;">Pending Orders</a>
            <a class="cv-btn cv-btn--secondary" href="/admin/ask-ai" style="justify-content:flex-start;">Ask AI</a>
            <a class="cv-btn cv-btn--secondary" href="/admin/import/clients" style="justify-content:flex-start;">Import Clients</a>
            <a class="cv-btn cv-btn--secondary" href="/admin/reports" style="justify-content:flex-start;">Reports</a>
        </div>
        <p style="margin:var(--cv-space-4) 0 0;color:var(--cv-text-tertiary);font-size:var(--cv-text-xs);"><?= (int) $hookPointCount ?> hook points registered (core kernel catalog, blueprint §7)</p>
    </div>
</div>

<?php foreach ($widgetOutputs as $widgetHtml): ?>
    <div style="margin-top:var(--cv-space-5);"><?= $widgetHtml ?></div>
<?php endforeach; ?>

<div class="cv-card" style="margin-top:var(--cv-space-5);">
    <h1 class="cv-card__title">Recent Activity</h1>
    <table class="cv-table">
        <thead><tr><th>Time</th><th>Action</th><th>Description</th></tr></thead>
        <tbody>
        <?php foreach ($recentActivity as $entry): ?>
            <tr>
                <td><?= e($entry['created_at']) ?></td>
                <td><?= e($entry['action']) ?></td>
                <td><?= e($entry['description']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($recentActivity === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">No activity recorded yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
