<?php
/**
 * One store's account, and the entries that produced it.
 *
 * The page exists to answer one question: "why is this balance what it is".
 * A single number is not an answer a reseller can check, so every figure is
 * broken back down into the entries behind it, with a running balance so a
 * disputed total can be walked to the row that caused it.
 *
 * @var int $clientId
 * @var array<string, mixed> $account
 * @var string $baseCode
 * @var string|null $notice
 * @var string|null $error
 */

$money = static fn (float $amount, string $code): string => number_format($amount, 2) . ($code === '' ? '' : ' ' . $code);

$store = is_array($account['store'] ?? null) ? $account['store'] : [];
$storeName = (string) ($store['brand_name'] ?? $store['slug'] ?? 'store');
$theirCode = (string) ($account['currency_code'] ?? '');
$inArrears = ($account['in_arrears'] ?? false) === true;
$holdingDays = (int) ($account['holding_days'] ?? 0);
$entries = is_array($account['entries'] ?? null) ? $account['entries'] : [];
$totals = is_array($account['totals'] ?? null) ? $account['totals'] : [];

$kindLabels = [
    'store_receipt' => 'Retail collected',
    'cost_invoice' => 'Cost billed',
    'payout' => 'Paid out',
    'adjustment' => 'Adjustment',
    'receipt_reversal' => 'Refund (retail returned)',
    'cost_reversal' => 'Cost refunded',
    'upline_margin' => 'Sub-reseller sale (share)',
    'upline_margin_reversal' => 'Sub-reseller refund',
];

// Entries arrive newest-first. A running balance has to be walked in the order
// the money actually moved, so the list is reversed to accumulate and then
// flipped back for display — computing it top-down would print a balance that
// never existed on any day.
$running = [];
$accumulated = 0.0;

foreach (array_reverse($entries) as $entry) {
    $accumulated += (float) $entry['amount'];
    $running[(int) $entry['id']] = $accumulated;
}
?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Account — <?= e($storeName) ?></h1>
    <p><a href="/admin/resellers/accounts">&larr; All reseller accounts</a> &middot;
        <a href="/admin/resellers/<?= $clientId ?>/store">Store settings</a> &middot;
        <a href="/admin/resellers/billing">Store cost billing</a> &middot;
        <a href="/admin/resellers/<?= $clientId ?>/statement">Statement</a> &middot;
        <a href="/admin/resellers/<?= $clientId ?>/account/export">Export CSV</a></p>
    <p style="color:var(--cv-text-secondary);">
        Every amount below is stored in <?= e($baseCode !== '' ? $baseCode : 'the base currency') ?>, at the rate that
        applied when the entry was posted. The reseller carries the exchange movement between the day we collect a
        customer's payment and the day we pay them out, so the account is kept in one unit and their own currency is
        a conversion of the balance rather than the balance itself.
    </p>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== null && $notice !== ''): ?>
        <div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div>
    <?php endif; ?>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Balance</h2>
    <table class="cv-table">
        <thead>
        <tr><th>Owed to this store</th><th>Withdrawable now</th><th>Minimum for a payout</th><th>In their currency</th></tr>
        </thead>
        <tbody>
        <tr>
            <td>
                <strong><?= e($money((float) $account['balance_base'], $baseCode)) ?></strong>
                <?php if ($inArrears): ?>
                    <br><span class="cv-badge cv-badge--error">in arrears — they owe us more than we owe them</span>
                <?php endif; ?>
            </td>
            <td>
                <?= e($money((float) $account['withdrawable_base'], $baseCode)) ?>
                <?php if (($account['can_withdraw'] ?? false) === true): ?>
                    <br><span class="cv-badge cv-badge--success">above the minimum</span>
                <?php elseif ((float) $account['withdrawable_base'] > 0): ?>
                    <br><span class="cv-badge">below the minimum</span>
                <?php endif; ?>
            </td>
            <td><?= e($money((float) $account['minimum_base'], $baseCode)) ?></td>
            <td>
                <?php if ($theirCode !== ''): ?>
                    <?= e($money((float) $account['balance'], $theirCode)) ?>
                    <br><span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
                        withdrawable <?= e($money((float) $account['withdrawable'], $theirCode)) ?> today
                    </span>
                <?php else: ?>
                    <em>&mdash;</em>
                <?php endif; ?>
            </td>
        </tr>
        </tbody>
    </table>
    <p style="color:var(--cv-text-secondary);margin-top:var(--cv-space-2);">
        The two <?= e($baseCode !== '' ? $baseCode : 'base') ?> figures differ by the receipts still inside the
        <?= $holdingDays ?>-day holding period — money a customer could still dispute, so it is owed but not yet
        safe to hand over.
    </p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">What the balance is made of</h2>
    <table class="cv-table">
        <thead>
        <tr><th>Kind</th><th>Entries</th><th>Total</th></tr>
        </thead>
        <tbody>
        <?php foreach ($kindLabels as $kind => $label): ?>
            <?php if (isset($totals[$kind])): ?>
                <tr>
                    <td><?= e($label) ?> <code><?= e($kind) ?></code></td>
                    <td><?= (int) ($totals[$kind]['entry_count'] ?? 0) ?></td>
                    <td><?= e($money((float) ($totals[$kind]['total'] ?? 0), $baseCode)) ?></td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($totals === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">Nothing has been posted yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Ledger</h2>
    <p style="color:var(--cv-text-secondary);">
        Append-only, newest first. Nothing here is ever edited or deleted: a refund, a correction and a payout are
        all new rows, because the only question this table has to answer is where a balance came from. The running
        balance is walked from the oldest entry, so each row shows the balance as it stood on that day.
    </p>
    <table class="cv-table">
        <thead>
        <tr><th>When</th><th>Kind</th><th>Amount</th><th>Balance after</th><th>Withdrawable from</th><th>Reference</th><th>Description</th></tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
            <?php
            $amount = (float) $entry['amount'];
            $kind = (string) $entry['kind'];
            $withdrawableAt = $entry['withdrawable_at'] ?? null;
            ?>
            <tr>
                <td><?= e((string) $entry['created_at']) ?></td>
                <td><?= e($kindLabels[$kind] ?? $kind) ?></td>
                <td style="<?= $amount < 0 ? 'color:var(--cv-text-secondary);' : '' ?>">
                    <?= $amount < 0 ? '&minus;' : '+' ?><?= e($money(abs($amount), $baseCode)) ?>
                    <span style="color:var(--cv-text-secondary);"><?= $amount < 0 ? 'debit' : 'credit' ?></span>
                </td>
                <td><?= e($money((float) ($running[(int) $entry['id']] ?? 0), $baseCode)) ?></td>
                <td>
                    <?php if ($withdrawableAt === null || $withdrawableAt === ''): ?>
                        <span style="color:var(--cv-text-secondary);">
                            <?= $amount < 0 ? 'n/a' : 'immediately' ?>
                        </span>
                    <?php else: ?>
                        <?= e((string) $withdrawableAt) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (($entry['order_id'] ?? null) !== null): ?>
                        <a href="/admin/orders/<?= (int) $entry['order_id'] ?>">Order #<?= (int) $entry['order_id'] ?></a>
                    <?php endif; ?>
                    <?php if (($entry['invoice_id'] ?? null) !== null): ?>
                        <a href="/admin/invoices/<?= (int) $entry['invoice_id'] ?>">Invoice #<?= (int) $entry['invoice_id'] ?></a>
                    <?php endif; ?>
                    <?php if (($entry['payout_id'] ?? null) !== null): ?>
                        <?php // Phase B builds the payout pages; until then a payout row can
                              // only exist if one was inserted by hand, so print the id
                              // rather than link to a route that does not exist yet. ?>
                        Payout #<?= (int) $entry['payout_id'] ?>
                    <?php endif; ?>
                    <?php if (($entry['order_id'] ?? null) === null && ($entry['invoice_id'] ?? null) === null && ($entry['payout_id'] ?? null) === null): ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td><?= e((string) $entry['description']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($entries === []): ?>
            <tr><td colspan="7" style="color:var(--cv-text-secondary);">
                No entries. A credit appears here the moment one of this store's customer invoices is paid.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
