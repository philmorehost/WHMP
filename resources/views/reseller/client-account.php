<?php
/**
 * The reseller's own account: what they have earned, and what they can take.
 *
 * Leads with their currency (that is the figure they think in) and shows the base
 * amount it was converted from, because the conversion is what makes the number
 * move on its own.
 *
 * @var array<string, mixed> $account
 * @var string $baseCode
 */

$money = static fn (float $amount, string $code): string => number_format($amount, 2) . ($code === '' ? '' : ' ' . $code);

$theirCode = (string) ($account['currency_code'] ?? '');
$holdingDays = (int) ($account['holding_days'] ?? 0);
$inArrears = ($account['in_arrears'] ?? false) === true;
$entries = is_array($account['entries'] ?? null) ? $account['entries'] : [];
$totals = is_array($account['totals'] ?? null) ? $account['totals'] : [];

$kindLabels = [
    'store_receipt' => 'Sale at your store',
    'cost_invoice' => 'What you owe us',
    'payout' => 'Paid out to you',
    'adjustment' => 'Adjustment',
];
?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Your account</h1>
    <p><a href="/client/reseller">&larr; Back to the reseller area</a> &middot;
        <a href="/client/reseller/store">Your store</a> &middot;
        <a href="/client/reseller/prices">Your prices</a></p>
    <p style="color:var(--cv-text-secondary);">
        Every sale at your store credits this account when your customer's invoice is <strong>paid</strong>, and the
        cost of what they bought is debited against it. Nothing is credited at order time — an unpaid order has put
        nothing in your account, and showing it as earnings would be a number you could not spend.
    </p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Balance</h2>
    <table class="cv-table">
        <thead>
        <tr>
            <th>What you have earned</th>
            <th>Available to withdraw</th>
            <th>In <?= e($baseCode !== '' ? $baseCode : 'base currency') ?></th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td>
                <strong><?= e($money((float) $account['balance'], $theirCode)) ?></strong>
                <?php if ($inArrears): ?>
                    <br><span class="cv-badge cv-badge--error">You currently owe us more than we owe you.</span>
                <?php endif; ?>
            </td>
            <td>
                <?= e($money((float) $account['withdrawable'], $theirCode)) ?>
                <?php if (($account['can_withdraw'] ?? false) === true): ?>
                    <br><span class="cv-badge cv-badge--success">above the <?= e($money((float) $account['minimum'], $theirCode)) ?> minimum</span>
                <?php elseif ((float) $account['withdrawable'] > 0): ?>
                    <br><span class="cv-badge">below the <?= e($money((float) $account['minimum'], $theirCode)) ?> minimum for a payout</span>
                <?php endif; ?>
            </td>
            <td>
                <?= e($money((float) $account['balance_base'], $baseCode)) ?>
                <br><span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
                    withdrawable <?= e($money((float) $account['withdrawable_base'], $baseCode)) ?>
                </span>
            </td>
        </tr>
        </tbody>
    </table>
    <p style="color:var(--cv-text-secondary);margin-top:var(--cv-space-2);">
        <strong>Your account is held in <?= e($baseCode !== '' ? $baseCode : 'the base currency') ?></strong>, so the
        figure in your own currency is a conversion at today's rate and it can go down without a single sale or
        refund — that is the exchange rate moving, not a mistake in the arithmetic. The
        <?= e($baseCode !== '' ? $baseCode : 'base') ?> column is the amount itself, and it is the one that is fixed
        when each sale completes.
    </p>
    <?php if ($holdingDays > 0): ?>
        <p style="color:var(--cv-text-secondary);">
            <strong><?= $holdingDays ?>-day holding period.</strong> A sale becomes available to withdraw
            <?= $holdingDays ?> days after your customer pays, because a payment can still be disputed or charged
            back before then. The exact date for each sale is the “available from” column below — that date is
            fixed when the sale completes, so a later change to the holding period does not move it. The
            difference between the two figures above is the sales not yet past that date: they are yours, they are
            just not yet safe to pay out.
        </p>
    <?php else: ?>
        <p style="color:var(--cv-text-secondary);">
            There is no holding period on this account, so a sale is available to withdraw as soon as it is paid.
        </p>
    <?php endif; ?>
    <p style="color:var(--cv-text-secondary);">
        Requesting a payout is coming next — this page currently shows the figures a payout will be able to draw on.
        Nothing on this page moves money.
    </p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Where the balance comes from</h2>
    <table class="cv-table">
        <thead>
        <tr><th>Type</th><th>Entries</th><th>Total</th></tr>
        </thead>
        <tbody>
        <?php foreach ($kindLabels as $kind => $label): ?>
            <?php if (isset($totals[$kind])): ?>
                <tr>
                    <td><?= e($label) ?></td>
                    <td><?= (int) ($totals[$kind]['entry_count'] ?? 0) ?></td>
                    <td><?= e($money((float) ($totals[$kind]['total'] ?? 0), $baseCode)) ?></td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($totals === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">
                Nothing yet. Your first sale appears here the moment it is paid.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Statement</h2>
    <p style="color:var(--cv-text-secondary);">
        Newest first. Entries are never edited or removed — a refund, a correction and a payout are all new lines —
        so this list is a record of what happened rather than a summary of where things ended up.
    </p>
    <table class="cv-table">
        <thead>
        <tr><th>When</th><th>Type</th><th>Amount</th><th>Available from</th><th>Details</th></tr>
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
                </td>
                <td>
                    <?php if ($withdrawableAt === null || $withdrawableAt === ''): ?>
                        <span style="color:var(--cv-text-secondary);">
                            <?= $amount < 0 ? '&mdash;' : 'immediately' ?>
                        </span>
                    <?php else: ?>
                        <?= e((string) $withdrawableAt) ?>
                    <?php endif; ?>
                </td>
                <td><?= e((string) $entry['description']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($entries === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">
                No entries yet — a sale at your store appears here once its invoice is paid.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
