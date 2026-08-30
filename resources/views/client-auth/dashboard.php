<?php
/** @var array<string, mixed> $client */
/** @var int $servicesCount */
/** @var int $domainsCount */
/** @var int $invoicesCount */
/** @var int $ticketsCount */
/** @var array<int, array<string, mixed>> $unpaidInvoices */
/** @var array<int, array<string, mixed>> $activeServices */
/** @var array<int, array<string, mixed>> $recentTickets */
/** @var array<string, mixed> $currency */
/** @var \CodeVault\Billing\CurrencyService $currencyService */
/** @var float $creditBalance */
/** @var bool $aiCopilotEnabled */
$sym = e($currency['symbol'] ?? 'N');
$recentTickets = $recentTickets ?? [];
$clientName = e((string)($client['first_name'] ?? $client['name'] ?? 'there'));
?>
<style>
/* ====== Dashboard Layout Reset ====== */
.cv-shell__main { padding: 0 !important; }

/* ====== Hero Banner ====== */
.dbd-hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 45%, #0f3460 100%);
    padding: 36px 40px;
    display: flex;
    align-items: center;
    gap: 28px;
    position: relative;
    overflow: hidden;
    margin-bottom: 0;
}
.dbd-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(245,158,11,.15) 0%, transparent 65%);
    pointer-events: none;
}
.dbd-hero__icon {
    width: 84px;
    height: 84px;
    background: linear-gradient(135deg, rgba(245,158,11,.25), rgba(249,115,22,.2));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.6rem;
    flex-shrink: 0;
    border: 2px solid rgba(245,158,11,.35);
    position: relative;
    z-index: 1;
}
.dbd-hero__body {
    flex: 1;
    min-width: 0;
    position: relative;
    z-index: 1;
}
.dbd-hero__label {
    color: rgba(255,255,255,.6);
    font-size: .8rem;
    font-weight: 600;
    letter-spacing: .05em;
    text-transform: uppercase;
    margin-bottom: 4px;
}
.dbd-hero__title {
    color: #fff;
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.75rem;
    font-weight: 800;
    margin: 0 0 18px 0;
    line-height: 1.15;
}
.dbd-hero__search-row {
    display: flex;
    gap: 10px;
    margin-bottom: 14px;
}
.dbd-hero__search-row input {
    flex: 1;
    background: rgba(255,255,255,.92);
    border: none;
    border-radius: 8px;
    padding: 12px 16px;
    font-size: .9rem;
    color: #111;
    outline: none;
    min-width: 0;
}
.dbd-hero__search-row input:focus { box-shadow: 0 0 0 3px rgba(245,158,11,.5); }
.dbd-hero__search-row button {
    background: #f59e0b;
    color: #1a1a2e;
    border: none;
    border-radius: 8px;
    padding: 12px 22px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    white-space: nowrap;
    transition: background .2s;
}
.dbd-hero__search-row button:hover { background: #d97706; }
.dbd-hero__chips { display: flex; flex-wrap: wrap; gap: 8px; }
.dbd-hero__chip {
    background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.2);
    color: rgba(255,255,255,.85);
    border-radius: 20px;
    padding: 5px 14px;
    font-size: .8rem;
    cursor: pointer;
    white-space: nowrap;
    text-decoration: none;
    font-family: inherit;
    transition: background .2s;
}
.dbd-hero__chip:hover { background: rgba(245,158,11,.3); color: #fff; }
.dbd-answer { display:none; background:rgba(255,255,255,.08); color:#fff; border:1px solid rgba(255,255,255,.18); border-radius:10px; padding:14px 16px; margin-top:14px; font-size:.875rem; white-space:pre-wrap; }

/* Chat history inside hero */
.dbd-chat {
    display: none;
    flex-direction: column;
    gap: 10px;
    margin-top: 14px;
    max-height: 280px;
    overflow-y: auto;
    padding-right: 4px;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.2) transparent;
}
.dbd-chat::-webkit-scrollbar { width: 4px; }
.dbd-chat::-webkit-scrollbar-track { background: transparent; }
.dbd-chat::-webkit-scrollbar-thumb { background: rgba(255,255,255,.2); border-radius: 4px; }
.dbd-chat.has-messages { display: flex; }
.dbd-msg {
    padding: 10px 14px;
    border-radius: 10px;
    font-size: .875rem;
    line-height: 1.55;
    max-width: 90%;
    white-space: pre-wrap;
    word-break: break-word;
    animation: dbdFadeIn .25s ease;
}
.dbd-msg--user {
    background: rgba(245,158,11,.25);
    color: #fff;
    align-self: flex-end;
    border-bottom-right-radius: 3px;
    font-weight: 600;
}
.dbd-msg--ai {
    background: rgba(255,255,255,.1);
    color: rgba(255,255,255,.92);
    align-self: flex-start;
    border-bottom-left-radius: 3px;
    border: 1px solid rgba(255,255,255,.12);
}
.dbd-msg--thinking {
    color: rgba(255,255,255,.5);
    font-style: italic;
    background: transparent;
    border: none;
    padding: 4px 0;
}
@keyframes dbdFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ====== Page Body ====== */
.dbd-body { padding: 24px 24px 40px; max-width: 1280px; margin: 0 auto; }

/* ====== Stats Row ====== */
.dbd-stats {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
    margin-bottom: 24px;
}
.dbd-stat {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    padding: 16px 18px;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 6px;
    transition: transform .18s, box-shadow .18s, border-color .18s;
}
.dbd-stat:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,.1); border-color: var(--cv-color-brand-500); }
.dbd-stat__label { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--cv-text-secondary); }
.dbd-stat__value { font-family: 'Hanken Grotesk', sans-serif; font-size: 2.25rem; font-weight: 800; line-height: 1; color: var(--cv-color-brand-500); }
.dbd-stat__value--red   { color: #ef4444; }
.dbd-stat__value--green { color: #10b981; font-size: 1.6rem; }
.dbd-stat__sub { font-size: .75rem; color: var(--cv-text-secondary); }
.dbd-stat__sub a { color: var(--cv-color-brand-500); font-weight: 600; text-decoration: none; }
.dbd-btn-addfunds {
    display: inline-block;
    background: rgba(16, 185, 129, 0.12);
    color: #10b981 !important;
    border: 1px solid rgba(16, 185, 129, 0.3);
    border-radius: 20px;
    padding: 3px 12px;
    font-size: .75rem;
    font-weight: 700 !important;
    text-decoration: none !important;
    transition: all .15s ease;
    margin-top: 2px;
}
.dbd-btn-addfunds:hover {
    background: #10b981 !important;
    color: #ffffff !important;
    border-color: #10b981 !important;
    transform: translateY(-1px);
}

/* ====== Domain Search Banner ====== */
.dbd-domain {
    background: linear-gradient(135deg, #1e3a5f 0%, #2d1b69 100%);
    border-radius: 14px;
    padding: 32px 40px;
    margin-bottom: 24px;
}
.dbd-domain h2 { color: #fff; font-family: 'Hanken Grotesk', sans-serif; font-size: 1.5rem; font-weight: 800; margin: 0 0 20px 0; text-align: center; }
.dbd-domain form { display: flex; gap: 10px; max-width: 680px; margin: 0 auto; }
.dbd-domain input { flex: 1; background: rgba(255,255,255,.95); border: none; border-radius: 8px; padding: 14px 18px; font-size: .95rem; color: #111; outline: none; min-width: 0; }
.dbd-domain button { background: #f59e0b; color: #1a1a2e; border: none; border-radius: 8px; padding: 14px 28px; font-weight: 700; font-size: .95rem; cursor: pointer; white-space: nowrap; transition: background .2s; }
.dbd-domain button:hover { background: #d97706; }

/* ====== 2-Column Main Grid ====== */
.dbd-grid {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 20px;
    align-items: start;
}

/* ====== Cards ====== */
.dbd-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 14px;
    padding: 20px 24px;
    margin-bottom: 20px;
}
.dbd-card:last-child { margin-bottom: 0; }
.dbd-card__head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    gap: 8px;
}
.dbd-card__title { font-family: 'Hanken Grotesk', sans-serif; font-size: 1rem; font-weight: 700; color: var(--cv-text-primary); margin: 0; }
.dbd-card__viewall { font-size: .8rem; font-weight: 700; color: var(--cv-color-brand-500); text-decoration: none; white-space: nowrap; }
.dbd-card__viewall:hover { text-decoration: underline; }

/* ====== Tables ====== */
.dbd-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
.dbd-table thead th { text-align: left; padding: 8px 10px; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--cv-text-secondary); border-bottom: 2px solid var(--cv-border-default); white-space: nowrap; }
.dbd-table tbody tr { border-bottom: 1px solid var(--cv-border-default); transition: background .15s; }
.dbd-table tbody tr:last-child { border-bottom: none; }
.dbd-table tbody tr:hover { background: var(--cv-bg-surface-sunken, rgba(0,0,0,.03)); }
.dbd-table tbody td { padding: 12px 10px; color: var(--cv-text-primary); vertical-align: middle; }
.dbd-table .t-sub { font-size: .75rem; color: var(--cv-text-secondary); margin-top: 2px; }
.dbd-table .t-amt { font-weight: 700; font-size: .95rem; }

/* Pay button */
.dbd-pay {
    display: inline-block;
    background: var(--cv-color-brand-500);
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 6px 14px;
    font-size: .78rem;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition: background .18s, transform .15s;
    white-space: nowrap;
}
.dbd-pay:hover { background: #d97706; transform: scale(1.04); }

/* Badges */
.dbd-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: .72rem; font-weight: 700; white-space: nowrap; }
.dbd-badge--open    { background: rgba(16,185,129,.12); color: #059669; border: 1px solid rgba(16,185,129,.3); }
.dbd-badge--pending { background: rgba(245,158,11,.12); color: #d97706; border: 1px solid rgba(245,158,11,.3); }
.dbd-badge--closed  { background: rgba(107,114,128,.12); color: #6b7280; border: 1px solid rgba(107,114,128,.3); }
.dbd-badge--cycle   { background: var(--cv-bg-surface-sunken, rgba(0,0,0,.05)); color: var(--cv-text-secondary); border: 1px solid var(--cv-border-default); font-size: .72rem; }

.dbd-empty { text-align: center; padding: 28px; color: var(--cv-text-secondary); font-size: .875rem; }
.dbd-empty a { color: var(--cv-color-brand-500); font-weight: 700; text-decoration: none; }

/* ====== Quick Actions ====== */
.dbd-actions { display: flex; flex-direction: column; gap: 6px; }
.dbd-action {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 14px;
    border-radius: 10px;
    border: 1px solid var(--cv-border-default);
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .9rem;
    font-weight: 500;
    font-family: inherit;
    text-decoration: none;
    cursor: pointer;
    width: 100%;
    text-align: left;
    transition: background .15s, border-color .15s, transform .15s;
}
.dbd-action:hover { background: var(--cv-bg-surface-sunken, rgba(0,0,0,.04)); border-color: var(--cv-color-brand-500); transform: translateX(3px); }
.dbd-action--danger { color: #ef4444; }
.dbd-action--danger:hover { background: rgba(239,68,68,.06); border-color: rgba(239,68,68,.4); transform: translateX(3px); }
.dbd-action__icon { width: 28px; height: 28px; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }

/* Support Options */
.dbd-support { display: flex; gap: 10px; }
.dbd-support-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 11px 10px;
    border-radius: 10px;
    border: 1px solid var(--cv-border-default);
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    text-decoration: none;
    font-size: .825rem;
    font-weight: 600;
    transition: background .15s, border-color .15s;
}
.dbd-support-btn:hover { background: var(--cv-bg-surface-sunken, rgba(0,0,0,.04)); border-color: var(--cv-color-brand-500); }

/* ====== Responsive ====== */
@media (max-width: 1024px) {
    .dbd-grid { grid-template-columns: 1fr; }
    .dbd-stats { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 640px) {
    .dbd-hero { flex-direction: column; align-items: flex-start; padding: 24px 20px; gap: 18px; }
    .dbd-hero__icon { width: 64px; height: 64px; font-size: 2rem; }
    .dbd-hero__title { font-size: 1.3rem; }
    .dbd-hero__search-row { flex-direction: column; }
    .dbd-hero__search-row button { width: 100%; }
    .dbd-body { padding: 16px 16px 32px; }
    .dbd-stats { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .dbd-stat__value { font-size: 1.75rem; }
    .dbd-domain { padding: 24px 20px; }
    .dbd-domain h2 { font-size: 1.15rem; }
    .dbd-domain form { flex-direction: column; }
    .dbd-domain button { width: 100%; }
    .dbd-card { padding: 16px; }
    .dbd-support { flex-direction: column; }
}
@media (max-width: 400px) {
    .dbd-stats { grid-template-columns: 1fr 1fr; }
}
</style>

<!-- HERO -->
<div class="dbd-hero">
    <div class="dbd-hero__icon">🚀</div>
    <div class="dbd-hero__body">
        <div class="dbd-hero__label">Getting Started</div>
        <h1 class="dbd-hero__title">How can we help you today?</h1>
        <div id="dbd-copilot" data-token="<?= e(csrf_token()) ?>" data-enabled="<?= !empty($aiCopilotEnabled) ? '1' : '0' ?>">
            <div class="dbd-hero__chips">
                <button type="button" class="dbd-hero__chip" data-q="How do I get online after paying?">How do I get online after paying?</button>
                <button type="button" class="dbd-hero__chip" data-q="How do I log into cPanel?">How do I log into cPanel?</button>
                <button type="button" class="dbd-hero__chip" data-q="How do I point my domain?">Point my domain</button>
            </div>
            <div class="dbd-hero__search-row" style="margin-top:12px;">
                <input type="text" id="dbd-q" placeholder="Ask a question…" maxlength="1000">
                <button type="button" id="dbd-ask">Ask</button>
            </div>
            <div class="dbd-chat" id="dbd-chat"></div>
        </div>
    </div>
</div>

<!-- BODY WRAPPER -->
<div class="dbd-body">

<!-- STATS -->
<div class="dbd-stats">
    <a href="/client/services" class="dbd-stat">
        <div class="dbd-stat__label">SERVICES</div>
        <div class="dbd-stat__value"><?= (int)$servicesCount ?></div>
        <div class="dbd-stat__sub">Active Services</div>
    </a>
    <a href="/client/domains" class="dbd-stat">
        <div class="dbd-stat__label">DOMAINS</div>
        <div class="dbd-stat__value"><?= (int)$domainsCount ?></div>
        <div class="dbd-stat__sub">Active Domains</div>
    </a>
    <a href="/client/invoices" class="dbd-stat">
        <div class="dbd-stat__label">INVOICES</div>
        <div class="dbd-stat__value <?= $invoicesCount > 0 ? 'dbd-stat__value--red' : '' ?>"><?= (int)$invoicesCount ?></div>
        <div class="dbd-stat__sub"><?= $invoicesCount > 0 ? 'Unpaid Invoices' : 'All Invoices Paid' ?></div>
    </a>
    <a href="/client/tickets" class="dbd-stat">
        <div class="dbd-stat__label">TICKETS</div>
        <div class="dbd-stat__value <?= $ticketsCount > 0 ? 'dbd-stat__value--red' : '' ?>"><?= (int)$ticketsCount ?></div>
        <div class="dbd-stat__sub">Open Tickets</div>
    </a>
    <a href="/client/orders" class="dbd-stat">
        <div class="dbd-stat__label">ORDERS</div>
        <div class="dbd-stat__value <?= $ordersCount > 0 ? 'dbd-stat__value--red' : '' ?>"><?= (int)$ordersCount ?></div>
        <div class="dbd-stat__sub"><?= $ordersCount > 0 ? 'Pending Orders' : 'All Orders' ?></div>
    </a>
    <a href="/client/wallet/add-funds" class="dbd-stat">
        <div class="dbd-stat__label">WALLET</div>
        <div class="dbd-stat__value dbd-stat__value--green"><?= $sym ?><?= number_format((float)$creditBalance, 2) ?></div>
        <div class="dbd-stat__sub"><span class="dbd-btn-addfunds">Add Funds →</span></div>
    </a>
</div>

<!-- DOMAIN REGISTER / TRANSFER (shared widget — also on the pre-login page) -->
<?= $view->partial('partials.domain-lookup') ?>

<!-- MAIN 2-COL GRID -->
<div class="dbd-grid">

    <!-- LEFT COLUMN -->
    <div>

        <!-- Unpaid Invoices -->
        <div class="dbd-card">
            <div class="dbd-card__head">
                <h2 class="dbd-card__title">Unpaid Invoices</h2>
                <a href="/client/invoices" class="dbd-card__viewall">View All Invoices →</a>
            </div>
            <?php if (!empty($unpaidInvoices)): ?>
            <div style="overflow-x:auto;">
                <table class="dbd-table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Due Date</th>
                            <th>Total</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unpaidInvoices as $inv): ?>
                        <tr style="cursor:pointer;" data-open-url="/client/invoices/<?= (int)$inv['id'] ?>">
                            <td><a href="/client/invoices/<?= (int)$inv['id'] ?>" style="color:inherit;text-decoration:none;"><strong>INV-<?= (int)$inv['id'] ?></strong></a></td>
                            <td><?= e((string)($inv['due_date'] ?? '')) ?></td>
                            <?php // Unlocked invoices fall back to this client's currency, not the system default. ?>
                            <td class="t-amt"><?= e($currencyService->formatDocument((float)$inv['total'], $inv['currency_id'] !== null ? (int)$inv['currency_id'] : null, (float)($inv['currency_rate'] ?? 1.0), $currency)) ?></td>
                            <td><a class="dbd-pay" href="/client/invoices/<?= (int)$inv['id'] ?>">Pay Now</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="dbd-empty">You do not have any unpaid invoices. Thank you!</div>
            <?php endif; ?>
        </div>

        <!-- Active Products & Services -->
        <div class="dbd-card">
            <div class="dbd-card__head">
                <h2 class="dbd-card__title">Your Active Products &amp; Services</h2>
                <a href="/client/services" class="dbd-card__viewall">View All Services →</a>
            </div>
            <?php if (!empty($activeServices)): ?>
            <div style="overflow-x:auto;">
                <table class="dbd-table">
                    <thead>
                        <tr>
                            <th>Product/Domain</th>
                            <th>Billing Cycle</th>
                            <th>Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeServices as $svc): ?>
                        <?php $svcUrl = '/client/services/' . (int) $svc['id']; ?>
                        <tr style="cursor:pointer;" data-open-url="<?= e($svcUrl) ?>">
                            <td>
                                <strong><a href="<?= e($svcUrl) ?>" style="color:inherit;text-decoration:none;"><?= e((string)$svc['product_name']) ?></a></strong>
                                <?php $dom = $svc['domain'] ?? $svc['hostname'] ?? ''; if ($dom !== ''): ?>
                                <div class="t-sub"><?= e((string)$dom) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="dbd-badge dbd-badge--cycle"><?= e((string)$svc['billing_cycle']) ?></span></td>
                            <?php
                            // services.amount is already denominated in the
                            // client's currency and must NOT be converted again.
                            //
                            // format() multiplies by the currency's exchange
                            // rate, which made this the only screen in the app
                            // that re-converted it: the same service rendered
                            // here as N62,095,750.00 and on its own invoice as
                            // N41,397.17 — a factor of the NGN rate apart.
                            //
                            // The invoice is authoritative. RecurringBillingService
                            // raises renewals with subtotal = services.amount and
                            // denominateFor()'s rate of 1.0, i.e. "already in this
                            // currency"; the admin service list, the admin service
                            // page, the client service page and the client detail
                            // page all render it raw with a symbol. This screen
                            // was the sole outlier.
                            ?>
                            <td class="t-amt"><?= $sym ?><?= number_format((float) $svc['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="dbd-empty">No active services yet. <a href="/store">Browse Store →</a></div>
            <?php endif; ?>
        </div>

        <!-- Recent Support Tickets -->
        <div class="dbd-card" style="margin-bottom:0;">
            <div class="dbd-card__head">
                <h2 class="dbd-card__title">Recent Support Tickets</h2>
                <a href="/client/tickets" class="dbd-card__viewall">View All →</a>
            </div>
            <?php if (!empty($recentTickets)): ?>
            <div style="overflow-x:auto;">
                <table class="dbd-table">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTickets as $t):
                            $st = strtolower((string)($t['status'] ?? 'open'));
                            $bc = ($st === 'open' || $st === 'customer-reply') ? 'dbd-badge--open'
                                : (($st === 'pending' || $st === 'on-hold') ? 'dbd-badge--pending' : 'dbd-badge--closed');
                            $bl = match($st) {
                                'open' => 'Open', 'customer-reply' => 'Replied',
                                'pending' => 'Pending', 'on-hold' => 'On Hold',
                                'closed' => 'Closed', default => ucfirst($st),
                            };
                            $ua = $t['updated_at'] ?? $t['created_at'] ?? '';
                            $ul = '';
                            if ($ua) {
                                $ts2 = strtotime((string)$ua);
                                if ($ts2 >= strtotime('today'))          $ul = 'Today, ' . date('g:i A', $ts2);
                                elseif ($ts2 >= strtotime('yesterday'))  $ul = 'Yesterday, ' . date('g:i A', $ts2);
                                else                                     $ul = date('M j, Y', $ts2);
                            }
                        ?>
                        <tr style="cursor:pointer;" data-open-url="/client/tickets/<?= (int)$t['id'] ?>">
                            <td><a href="/client/tickets/<?= (int)$t['id'] ?>" style="color:inherit;text-decoration:none;"><?= e((string)($t['subject'] ?? 'Ticket')) ?></a></td>
                            <td><span class="dbd-badge <?= $bc ?>"><?= $bl ?></span></td>
                            <td style="color:var(--cv-text-secondary);font-size:.825rem;white-space:nowrap;"><?= $ul ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="dbd-empty">No tickets yet. <a href="/client/tickets/new">Open a Ticket →</a></div>
            <?php endif; ?>
        </div>

    </div><!-- /left -->

    <!-- RIGHT COLUMN -->
    <div>

        <!-- Quick Actions -->
        <div class="dbd-card">
            <h2 class="dbd-card__title" style="margin-bottom:14px;">Quick Actions</h2>
            <div class="dbd-actions">
                <a href="/store" class="dbd-action">
                    <div class="dbd-action__icon" style="background:rgba(249,115,22,.12);">🛍️</div>Order New Services
                </a>
                <a href="/domains/register" class="dbd-action">
                    <div class="dbd-action__icon" style="background:rgba(59,130,246,.12);">🌐</div>Register New Domains
                </a>
                <a href="/client/tickets/new" class="dbd-action">
                    <div class="dbd-action__icon" style="background:rgba(107,114,128,.12);">✉️</div>Submit Support Ticket
                </a>
                <a href="/client/account" class="dbd-action">
                    <div class="dbd-action__icon" style="background:rgba(59,130,246,.12);">👤</div>Edit Profile Details
                </a>
                <a href="/client/affiliate" class="dbd-action">
                    <div class="dbd-action__icon" style="background:rgba(245,158,11,.12);">🤝</div>Affiliate Program
                </a>
                <form method="post" action="/client/logout" style="margin:0;"><?= csrf_field() ?>
                    <button type="submit" class="dbd-action dbd-action--danger">
                        <div class="dbd-action__icon" style="background:rgba(239,68,68,.1);">🚪</div>Log Out
                    </button>
                </form>
            </div>
        </div>

        <!-- Support Options -->
        <div class="dbd-card" style="margin-bottom:0;">
            <h2 class="dbd-card__title" style="margin-bottom:6px;">Support Options</h2>
            <p style="color:var(--cv-text-secondary);font-size:.85rem;margin:0 0 14px 0;">Find answers instantly in our knowledgebase or download tools and resources.</p>
            <div class="dbd-support">
                <a href="/kb" class="dbd-support-btn">📚 KB articles</a>
                <a href="/downloads" class="dbd-support-btn">💾 Downloads</a>
            </div>
        </div>

    </div><!-- /right -->

</div><!-- /grid -->
</div><!-- /body -->