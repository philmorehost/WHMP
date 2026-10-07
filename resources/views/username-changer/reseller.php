<?php
/**
 * Reseller panel → Username requests (the store's own customers only).
 *
 * @var array<int, array<string, mixed>> $rows
 * @var array<string, int> $counts
 * @var string $status
 * @var string $q
 * @var array<string, mixed> $policy the store's own rules (may be empty)
 * @var array{max_changes: int, cooldown_days: int, approval: string, store_approval_allowed: bool} $global
 * @var array{enabled: bool, resale: bool, cost: float, costLabel: string, price: ?float, priceLabel: ?string, currencyCode: string, catalogCode: string} $fee
 * @var string|null $notice
 * @var string|null $error
 */
use CodeVault\UsernameChanger\UsernameChangeNotifier;

$storeDecides = $global['approval'] === 'none' && ($policy['approval'] ?? null) === 'reseller';
$open = \CodeVault\UsernameChanger\UsernameChangeRepository::OPEN;
$openCount = 0;
foreach ($open as $s) {
    $openCount += (int) ($counts[$s] ?? 0);
}
$tabs = [
    'open' => ['Open', $openCount],
    'pending_approval' => ['Needs your approval', (int) ($counts['pending_approval'] ?? 0)],
    'completed' => ['Completed', (int) ($counts['completed'] ?? 0)],
    'all' => ['All', array_sum($counts)],
];
if (!$storeDecides) {
    unset($tabs['pending_approval']);
}
$catalog = $fee['catalogCode'] !== '' ? $fee['catalogCode'] : 'catalog currency';
$margin = $fee['price'] === null ? 0.0 : max(0.0, $fee['price'] - $fee['cost']);
?>
<link rel="stylesheet" href="/assets/css/username-changer-admin.css">
<div class="cv-card" style="max-width:64rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <header class="rs-head">
        <h1 class="rs-head__title">Username requests</h1>
    </header>
    <?= $view->render('partials.reseller-nav') ?>

    <?php if ($error !== null && $error !== ''): ?><div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div><?php endif; ?>
    <?php if ($notice !== null && $notice !== ''): ?><div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div><?php endif; ?>

    <p style="color:var(--cv-text-secondary);">Your customers can rename the cPanel account of their hosting from their service page. All emails and pages carry only your brand.</p>

    <div class="uca-stats">
        <div class="uca-stat"><b><?= $openCount ?></b><span>Open</span></div>
        <div class="uca-stat"><b><?= (int) ($counts['completed'] ?? 0) ?></b><span>Completed</span></div>
        <?php if ($fee['enabled']): ?>
            <div class="uca-stat"><b><?= e($fee['costLabel']) ?></b><span>Your cost per change</span></div>
            <?php if ($fee['resale']): ?><div class="uca-stat"><b><?= e($fee['priceLabel'] ?? $fee['costLabel']) ?></b><span>Your customers pay</span></div><?php endif; ?>
        <?php else: ?>
            <div class="uca-stat"><b>Free</b><span>Price to customers</span></div>
        <?php endif; ?>
    </div>
</div>

<div class="cv-card uca" style="max-width:64rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <nav class="uca-tabs uca-tabs--muted" aria-label="Filter">
        <?php foreach ($tabs as $key => [$label, $n]): ?>
            <a href="/client/reseller/username-requests?status=<?= e($key) ?>" class="<?= $status === $key ? 'is-active' : '' ?>"><?= e($label) ?> <span class="uca-count"><?= (int) $n ?></span></a>
        <?php endforeach; ?>
    </nav>
    <form class="uca-filter" method="get" action="/client/reseller/username-requests">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <input class="cv-input" type="search" name="q" value="<?= e($q) ?>" placeholder="Username, domain or customer email">
        <button class="cv-btn cv-btn--secondary" type="submit">Search</button>
    </form>

    <?php if ($rows === []): ?>
        <p style="color:var(--cv-text-secondary);">No requests here yet.</p>
    <?php else: ?>
        <div style="overflow-x:auto">
        <table class="uca-table">
            <thead><tr><th>Customer</th><th>Change</th><th>Status</th><th>Requested</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $st = (string) $r['status']; ?>
                <tr>
                    <td><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?><br><small><?= e((string) $r['client_email']) ?> · <?= e((string) $r['domain']) ?></small></td>
                    <td class="uca-mono"><?= e((string) $r['old_username']) ?> → <strong><?= e((string) $r['new_username']) ?></strong>
                        <?php if (!empty($r['reason'])): ?><br><small style="font-family:inherit">“<?= e((string) $r['reason']) ?>”</small><?php endif; ?></td>
                    <td><span class="uca-pill uca-pill--<?= e($st) ?>"><?= e(UsernameChangeNotifier::statusLabel($st)) ?></span>
                        <?php if ($st === 'declined' && !empty($r['decline_reason'])): ?><br><small><?= e((string) $r['decline_reason']) ?></small><?php endif; ?></td>
                    <td><small><?= e((string) $r['created_at']) ?></small></td>
                    <td>
                        <?php if ($st === 'pending_approval' && $storeDecides): ?>
                            <div class="uca-inline">
                                <form method="post" action="/client/reseller/username-requests/<?= (int) $r['id'] ?>/approve"><?= csrf_field() ?><button class="cv-btn uca-btn-sm" type="submit">Approve</button></form>
                                <form method="post" action="/client/reseller/username-requests/<?= (int) $r['id'] ?>/decline"><?= csrf_field() ?><input class="cv-input uca-btn-sm" name="reason" placeholder="Reason" required style="width:130px"><button class="cv-btn cv-btn--secondary uca-btn-sm" type="submit">Decline</button></form>
                            </div>
                        <?php elseif ($st === 'pending_approval'): ?>
                            <small>Waiting for the platform</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<form method="post" action="/client/reseller/username-requests/policy">
    <?= csrf_field() ?>
    <?php if ($fee['enabled'] && $fee['resale']): ?>
    <div class="cv-card uca" style="max-width:64rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">💰 Your price</h2>
        <p>The platform charges you <strong><?= e(number_format($fee['cost'], 2)) ?> <?= e($catalog) ?></strong> for each username change your customers make. Set what your customers pay — the difference is credited to your reseller balance as soon as they pay the invoice.</p>
        <div class="uca-grid2">
            <div class="uca-field">
                <label for="ucn-price">Your price (<?= e($catalog) ?>)</label>
                <input class="cv-input" id="ucn-price" name="fee" type="number" step="0.01" min="<?= e(number_format($fee['cost'], 2, '.', '')) ?>" value="<?= $fee['price'] === null ? '' : e(number_format($fee['price'], 2, '.', '')) ?>" placeholder="<?= e(number_format($fee['cost'], 2, '.', '')) ?>" data-ucn-cost="<?= e(number_format($fee['cost'], 2, '.', '')) ?>">
                <small>Blank = charge exactly your cost (no margin). Customers see it in their own currency.</small>
            </div>
            <div class="uca-field">
                <label>Your margin per change</label>
                <div class="uca-margin" id="ucn-margin"><?= e(number_format($margin, 2)) ?> <?= e($catalog) ?></div>
                <small>Refunded invoices reverse the credit.</small>
            </div>
        </div>
    </div>
    <?php elseif ($fee['enabled']): ?>
    <div class="cv-card" style="max-width:64rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
        <p style="margin:0">Each change costs your customers <strong><?= e($fee['costLabel']) ?></strong>, set by the platform.</p>
    </div>
    <?php endif; ?>

    <div class="cv-card uca" style="max-width:64rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Rules for your customers</h2>
        <p style="color:var(--cv-text-secondary);">You can make the platform's rules stricter, never looser. Leave a field blank to use the platform's setting.</p>
        <label class="uca-switch"><input type="checkbox" name="enabled" value="0"<?= isset($policy['enabled']) && (int) $policy['enabled'] === 0 ? ' checked' : '' ?>> <strong>Turn username changes off for my customers</strong></label>
        <div class="uca-grid2">
            <div class="uca-field"><label>Changes per service</label><input class="cv-input" type="number" min="0" name="max_changes" value="<?= e((string) ($policy['max_changes'] ?? '')) ?>" placeholder="Platform: <?= $global['max_changes'] === 0 ? 'unlimited' : (int) $global['max_changes'] ?>"></div>
            <div class="uca-field"><label>Days between changes</label><input class="cv-input" type="number" min="0" name="cooldown_days" value="<?= e((string) ($policy['cooldown_days'] ?? '')) ?>" placeholder="Platform: <?= (int) $global['cooldown_days'] ?>"></div>
        </div>
        <?php if ($global['approval'] === 'none' && $global['store_approval_allowed']): ?>
            <label class="uca-switch"><input type="checkbox" name="approval" value="reseller"<?= ($policy['approval'] ?? null) === 'reseller' ? ' checked' : '' ?>> <strong>I want to approve each request myself</strong></label>
        <?php elseif ($global['approval'] === 'admin'): ?>
            <p><small>The platform reviews every request before it runs.</small></p>
        <?php endif; ?>
        <label class="uca-switch"><input type="checkbox" name="allow_db_rename" value="0"<?= isset($policy['allow_db_rename']) && (int) $policy['allow_db_rename'] === 0 ? ' checked' : '' ?>> <strong>Never offer database renaming to my customers</strong></label>
        <p><button class="cv-btn" type="submit">Save</button></p>
    </div>
</form>
<script src="/assets/js/username-changer-admin.js" defer></script>
