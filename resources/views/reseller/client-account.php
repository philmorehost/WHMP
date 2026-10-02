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
 * @var array{open: array<string, mixed>|null, history: array<int, array<string, mixed>>} $payout
 * @var array<string, mixed>|null $destination
 * @var string|null $destinationText
 * @var array<string, string> $labels
 * @var string|null $notice
 * @var string|null $error
 */

$money = static fn (float $amount, string $code): string => number_format($amount, 2) . ($code === '' ? '' : ' ' . $code);

$theirCode = (string) ($account['currency_code'] ?? '');
$holdingDays = (int) ($account['holding_days'] ?? 0);
$inArrears = ($account['in_arrears'] ?? false) === true;
$entries = is_array($account['entries'] ?? null) ? $account['entries'] : [];
$totals = is_array($account['totals'] ?? null) ? $account['totals'] : [];

$openRequest = is_array($payout['open'] ?? null) ? $payout['open'] : null;
$payoutHistory = is_array($payout['history'] ?? null) ? $payout['history'] : [];

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
        <a href="/client/reseller/tickets">Your support queue</a> &middot;
        <a href="/client/reseller/mail">Your support address</a> &middot;
        <a href="/client/reseller/prices">Your prices</a></p>
    <p style="color:var(--cv-text-secondary);">
        Every sale at your store credits this account when your customer's invoice is <strong>paid</strong>, and the
        cost of what they bought is debited against it. Nothing is credited at order time — an unpaid order has put
        nothing in your account, and showing it as earnings would be a number you could not spend.
    </p>
</div>

<?php if ($error !== null && $error !== ''): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e((string) $error) ?></div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e((string) $notice) ?></div>
<?php endif; ?>

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
    <h2 class="cv-card__title">Where we pay you</h2>

    <?php if ($destinationText !== null && $destinationText !== ''): ?>
        <p style="margin-top:0;">
            Payouts are sent to <strong><?= e($destinationText) ?></strong>
            <?php if (($destination['verified_at'] ?? null) !== null): ?>
                <span class="cv-badge cv-badge--success">checked</span>
            <?php else: ?>
                <span class="cv-badge">not yet checked</span>
            <?php endif; ?>
        </p>
        <p style="color:var(--cv-text-secondary);">
            A payout request keeps a copy of these details as they were when you asked, so changing them later does not
            alter where an earlier payout was sent.
        </p>
    <?php else: ?>
        <p style="margin-top:0;">
            <strong>No payout destination is on file.</strong> Add the bank account we should pay into; a payout cannot
            be requested until we know where to send it.
        </p>
    <?php endif; ?>

    <form method="post" action="/client/reseller/account/payout-method" style="margin:0;">
        <?= csrf_field() ?>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <label style="display:flex;flex-direction:column;gap:4px;">
                <span style="font-weight:600;">Account name</span>
                <input type="text" name="account_name" required maxlength="191"
                       value="<?= e((string) ($destination['account_name'] ?? '')) ?>" style="padding:8px 10px;border-radius:8px;">
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;">
                <span style="font-weight:600;">Account number</span>
                <input type="text" name="account_number" required maxlength="64"
                       value="<?= e((string) ($destination['account_number'] ?? '')) ?>" style="padding:8px 10px;border-radius:8px;">
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;">
                <span style="font-weight:600;">Bank</span>
                <input type="text" name="bank_name" maxlength="191"
                       value="<?= e((string) ($destination['bank_name'] ?? '')) ?>" style="padding:8px 10px;border-radius:8px;">
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;">
                <span style="font-weight:600;">Bank code (optional)</span>
                <input type="text" name="bank_code" maxlength="32"
                       value="<?= e((string) ($destination['bank_code'] ?? '')) ?>" style="padding:8px 10px;border-radius:8px;">
            </label>
        </div>
        <p style="margin:var(--cv-space-2) 0 0;">
            <button type="submit" class="cv-btn cv-btn--secondary" style="padding:8px 16px;">
                <?= ($destinationText !== null && $destinationText !== '') ? 'Save new details' : 'Save payout destination' ?>
            </button>
        </p>
    </form>

    <?php if ($destinationText !== null && $destinationText !== ''): ?>
        <form method="post" action="/client/reseller/account/payout-method/remove" style="margin-top:var(--cv-space-2);">
            <?= csrf_field() ?>
            <button type="submit" class="cv-btn cv-btn--secondary" style="padding:6px 12px;font-size:var(--cv-text-sm);">Remove destination</button>
        </form>
    <?php endif; ?>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Getting paid</h2>

    <?php if ($openRequest !== null): ?>
        <?php
        $reqAmount = (float) $openRequest['amount'];
        $reqCode = $theirCode !== '' ? $theirCode : $baseCode;
        ?>
        <p style="color:var(--cv-text-secondary);">
            You have a payout request awaiting payment. <strong>The funds have already been set aside</strong>, so
            they are no longer part of your available balance — that is why asking a second time is not possible
            while this one is open.
        </p>
        <table class="cv-table">
            <tbody>
            <tr>
                <td><strong>Request #<?= (int) $openRequest['id'] ?></strong><br>
                    <span style="color:var(--cv-text-secondary);">
                        <?= e($labels[(string) $openRequest['status']] ?? (string) $openRequest['status']) ?>
                        &middot; asked for on <?= e((string) $openRequest['requested_at']) ?>
                    </span></td>
                <td><strong><?= e($money($reqAmount, $reqCode)) ?></strong><br>
                    <span style="color:var(--cv-text-secondary);">
                        <?= e($money((float) $openRequest['amount_base'], $baseCode)) ?> leaves your account
                    </span></td>
                <td>
                    <form method="post" action="/client/reseller/account/payouts/<?= (int) $openRequest['id'] ?>/cancel">
                        <?= csrf_field() ?>
                        <button class="cv-btn" type="submit">Cancel this request</button>
                    </form>
                    <div style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-top:4px;">
                        Cancelling returns the money to your available balance straight away.
                    </div>
                </td>
            </tr>
            </tbody>
        </table>
    <?php else: ?>
        <?php if (($account['can_withdraw'] ?? false) === true): ?>
            <p style="color:var(--cv-text-secondary);">
                <?= e($money((float) $account['withdrawable'], $theirCode)) ?> is ready to be paid out.
                Payouts are sent by bank transfer, so they are reviewed by hand before the money moves.
            </p>
            <form method="post" action="/client/reseller/account/payouts">
                <?= csrf_field() ?>
                <button class="cv-btn" type="submit">Request a payout</button>
                <span style="color:var(--cv-text-secondary);">
                    The whole available balance is requested, not part of it.
                </span>
            </form>
        <?php elseif ((float) $account['withdrawable'] > 0): ?>
            <p style="color:var(--cv-text-secondary);">
                <?= e($money((float) $account['withdrawable'], $theirCode)) ?> is available, which is below the
                <?= e($money((float) $account['minimum'], $theirCode)) ?> minimum for a payout. The money is
                yours — it is just not worth a transfer yet, and it stays here until it is.
            </p>
        <?php else: ?>
            <p style="color:var(--cv-text-secondary);">
                Nothing is available to withdraw yet.
                <?php if ($holdingDays > 0): ?>
                    A sale becomes available <?= $holdingDays ?> days after your customer pays, so the sales below
                    that date cannot be paid out.
                <?php endif; ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($payoutHistory !== []): ?>
        <table class="cv-table" style="margin-top:var(--cv-space-3);">
            <thead>
            <tr><th>#</th><th>Asked for</th><th>Amount sent</th><th>State</th><th>Reference</th><th>Decided</th></tr>
            </thead>
            <tbody>
            <?php foreach ($payoutHistory as $row): ?>
                <?php $status = (string) $row['status']; ?>
                <tr>
                    <td>#<?= (int) $row['id'] ?></td>
                    <td><?= e((string) $row['requested_at']) ?></td>
                    <td><?= e($money((float) $row['amount'], (string) ($row['amount_code'] ?? $baseCode))) ?></td>
                    <td><?= e($labels[$status] ?? $status) ?></td>
                    <td>
                        <?php if (($row['reference'] ?? null) !== null && $row['reference'] !== ''): ?>
                            <code><?= e((string) $row['reference']) ?></code>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($row['decided_at'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
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
    <h2 class="cv-card__title">Statements</h2>
    <p style="color:var(--cv-text-secondary);">
        Frozen, numbered copies of your account for a period. Unlike everything else on this page, an issued
        statement never changes afterwards — so the copy you keep and the copy we hold always agree.
    </p>
    <p><a class="cv-btn" href="/client/reseller/statements">View your statements</a></p>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Recent activity</h2>
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
