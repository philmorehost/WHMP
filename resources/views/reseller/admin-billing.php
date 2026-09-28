<?php
/**
 * What the stores owe us, and how it is billed.
 *
 * @var array<int, array<string, mixed>> $summaries
 * @var array<int, array<string, mixed>> $arrears
 * @var array<string, array<string, float>> $byCurrency
 * @var bool $auto
 * @var float $minimum
 * @var int $dueDays
 * @var string|null $notice
 * @var string|null $error
 */

$money = static fn (float $amount, string $code): string => number_format($amount, 2) . ' ' . $code;
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Store cost billing</h1>
    <p><a href="/admin/resellers">&larr; Back to resellers</a> &middot;
        <a href="/admin/resellers/accounts">Reseller accounts</a> &middot;
        <a href="/admin/resellers/docs">Reseller API docs</a></p>
    <p style="color:var(--cv-text-secondary);">
        This page is what the stores <strong>owe us</strong>. The mirror of it — what we
        <strong>owe them</strong>, accrued from the same orders and netted against these cost invoices — is the
        <a href="/admin/resellers/accounts">reseller accounts</a> report.</p>
        A customer buying at a reseller's store pays <strong>us</strong> at retail. The order records what it cost
        us — the catalogue price less the reseller discount — and that is what we bill the reseller for, on an
        ordinary invoice against their client account. Cost is never added to the customer's own invoice.
    </p>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== null && $notice !== ''): ?>
        <div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div>
    <?php endif; ?>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">How billing runs</h2>
    <p style="color:var(--cv-text-secondary);">
        One invoice per store per closed calendar month. A month still in progress is never billed, so a figure
        that is still growing is never invoiced. Billing is idempotent by construction: an order carries the id of
        the invoice that billed it, so running twice bills once and a missed month is picked up by the next run.
    </p>
    <form method="post" action="/admin/resellers/billing/settings">
        <?= csrf_field() ?>
        <table class="cv-table">
            <tbody>
            <tr>
                <td><strong>Bill automatically</strong><br>
                    <span style="color:var(--cv-text-secondary);">Monthly, from the daily cron. Turn this off and
                        nothing is invoiced until you press “Bill now”.</span></td>
                <td>
                    <label><input type="checkbox" name="billing_auto" value="1" <?= $auto ? 'checked' : '' ?>> Run monthly</label>
                </td>
            </tr>
            <tr>
                <td><strong>Minimum</strong><br>
                    <span style="color:var(--cv-text-secondary);">Leave at 0 to invoice any amount. Below this, a
                        month is carried into the next invoice instead of being invoiced on its own.</span></td>
                <td><input type="number" name="billing_minimum" min="0" step="0.01"
                           value="<?= e(number_format($minimum, 2, '.', '')) ?>"></td>
            </tr>
            <tr>
                <td><strong>Payment terms</strong><br>
                    <span style="color:var(--cv-text-secondary);">Days from the day the invoice is raised until it is due.</span></td>
                <td><input type="number" name="billing_due_days" min="0" step="1" value="<?= (int) $dueDays ?>"></td>
            </tr>
            </tbody>
        </table>
        <button class="cv-btn" type="submit">Save billing settings</button>
    </form>

    <form method="post" action="/admin/resellers/billing/run" style="margin-top:var(--cv-space-3);">
        <?= csrf_field() ?>
        <button class="cv-btn" type="submit">Bill now</button>
        <span style="color:var(--cv-text-secondary);">Safe to press twice — the second press raises nothing.</span>
    </form>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Accrued cost by currency</h2>
    <p style="color:var(--cv-text-secondary);">
        Each store is billed in <em>its own</em> currency, so these are shown per currency and never added
        together — a total across NGN and USD would be a number nobody could act on.
    </p>
    <table class="cv-table">
        <thead>
        <tr><th>Currency</th><th>Accrued</th><th>Unbilled</th><th>Unpaid cost invoices</th></tr>
        </thead>
        <tbody>
        <?php foreach ($byCurrency as $code => $totals): ?>
            <tr>
                <td><strong><?= e((string) $code) ?></strong></td>
                <td><?= e($money((float) $totals['accrued'], (string) $code)) ?></td>
                <td><?= e($money((float) $totals['unbilled'], (string) $code)) ?></td>
                <td><?= e($money((float) $totals['arrears'], (string) $code)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($byCurrency === []): ?>
            <tr><td colspan="4" style="color:var(--cv-text-secondary);">No store has taken an order yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">By store</h2>
    <table class="cv-table">
        <thead>
        <tr><th>Store</th><th>Owner</th><th>Orders</th><th>Accrued</th><th>Billed</th><th>Unbilled</th><th>Currency</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($summaries as $summary): ?>
            <?php
            $code = (string) $summary['currency_code'];
            $owner = trim((string) ($summary['client']['first_name'] ?? '') . ' ' . (string) ($summary['client']['last_name'] ?? ''));
            ?>
            <tr>
                <td>
                    <a href="/admin/resellers/<?= (int) ($summary['store']['client_id'] ?? 0) ?>/store">
                        <?= e((string) ($summary['store']['brand_name'] ?? $summary['store']['slug'] ?? 'store')) ?>
                    </a>
                    <br><code><?= e((string) ($summary['store']['slug'] ?? '')) ?></code>
                </td>
                <td>
                    <?php if (($summary['client']['id'] ?? null) !== null): ?>
                        <a href="/admin/clients/<?= (int) $summary['client']['id'] ?>"><?= e($owner !== '' ? $owner : 'client #' . (int) $summary['client']['id']) ?></a>
                    <?php else: ?>
                        <em>account removed</em>
                    <?php endif; ?>
                </td>
                <td><?= (int) $summary['order_count'] ?></td>
                <td><?= e($money((float) $summary['accrued'], $code)) ?></td>
                <td><?= e($money((float) $summary['billed'], $code)) ?></td>
                <td>
                    <?php if ((float) $summary['unbilled'] > 0): ?>
                        <span class="cv-badge cv-badge--warning"><?= e($money((float) $summary['unbilled'], $code)) ?></span>
                    <?php else: ?>
                        0.00
                    <?php endif; ?>
                </td>
                <td><?= e($code) ?></td>
                <td>
                    <?php if (($summary['client']['id'] ?? null) !== null): ?>
                        <a class="cv-btn" href="/admin/clients/<?= (int) $summary['client']['id'] ?>">Client</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($summaries === []): ?>
            <tr><td colspan="8" style="color:var(--cv-text-secondary);">No store has taken an order yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Unpaid cost invoices</h2>
    <p style="color:var(--cv-text-secondary);">
        Cost already invoiced and still owed. Nothing here suspends a store: taking a storefront offline also takes
        the reseller's customers offline, so that stays an explicit decision on the store page.
    </p>
    <table class="cv-table">
        <thead>
        <tr><th>Invoice</th><th>Store owner</th><th>Orders</th><th>Amount</th><th>Due</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($arrears as $row): ?>
            <?php $code = (string) ($row['currency']['code'] ?? ''); ?>
            <tr>
                <td>#<?= (int) $row['id'] ?></td>
                <td><a href="/admin/clients/<?= (int) $row['client_id'] ?>">Client #<?= (int) $row['client_id'] ?></a></td>
                <td><?= (int) $row['order_count'] ?></td>
                <td><?= e($money((float) $row['total'], $code)) ?></td>
                <td><?= e(substr((string) $row['due_date'], 0, 10)) ?></td>
                <td><a class="cv-btn" href="/admin/invoices/<?= (int) $row['id'] ?>">View invoice</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($arrears === []): ?>
            <tr><td colspan="6" style="color:var(--cv-text-secondary);">No unpaid cost invoices.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
