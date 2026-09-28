<?php
/**
 * Every store's running account: what we owe, and what could be taken today.
 *
 * @var array<int, array<string, mixed>> $accounts
 * @var float $totalBalance
 * @var float $totalWithdrawable
 * @var int $claimable
 * @var int $holdingDays
 * @var float $minimum
 * @var string $baseCode
 * @var string|null $notice
 * @var string|null $error
 */

$money = static fn (float $amount, string $code): string => number_format($amount, 2) . ($code === '' ? '' : ' ' . $code);
?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Reseller accounts</h1>
    <p><a href="/admin/resellers">&larr; Back to resellers</a> &middot;
        <a href="/admin/resellers/billing">Store cost billing</a> &middot;
        <a href="/admin/resellers/docs">Reseller API docs</a></p>
    <p style="color:var(--cv-text-secondary);">
        What each store has earned us so far. A customer buying at a reseller's store pays <strong>us</strong> at
        retail; that retail is credited here the moment their invoice is <em>paid</em>, and the cost we bill the
        reseller is debited against it. Crediting on payment rather than on order placement is what keeps the
        balance honest — an unpaid order has put nothing in anyone's account.
    </p>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== null && $notice !== ''): ?>
        <div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div>
    <?php endif; ?>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Totals</h2>
    <p style="color:var(--cv-text-secondary);">
        Unlike the cost report, a single total is meaningful here — and it is the whole payoff of keeping the
        account in one unit. Every entry is stored in the base currency at the rate that applied when it was
        posted, so these figures are already comparable and cannot be re-priced by a later rate change.
    </p>
    <table class="cv-table">
        <thead>
        <tr><th>Balance owed</th><th>Withdrawable now</th><th>Above the minimum</th><th>Accounts</th></tr>
        </thead>
        <tbody>
        <tr>
            <td><strong><?= e($money($totalBalance, $baseCode)) ?></strong></td>
            <td><?= e($money($totalWithdrawable, $baseCode)) ?></td>
            <td><?= (int) $claimable ?></td>
            <td><?= count($accounts) ?></td>
        </tr>
        </tbody>
    </table>
    <p style="color:var(--cv-text-secondary);margin-top:var(--cv-space-2);">
        <strong>Balance owed</strong> is everything we owe, including money collected minutes ago.
        <strong>Withdrawable now</strong> is smaller by exactly the receipts still inside the
        <?= (int) $holdingDays ?>-day holding period, and it is the only figure a payout may draw on. Showing
        one number and calling it “available” would overstate every account by a month of sales. A payout also
        needs <?= e($money($minimum, $baseCode)) ?>, which is why “above the minimum” is a separate count.
    </p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Payout settings</h2>
    <p style="color:var(--cv-text-secondary);">
        The holding period exists because a customer can dispute or charge back a payment for weeks after it
        clears, so paying out immediately would let a reseller take money we later have to give back.
        <strong>Each receipt records the date it becomes withdrawable at the moment it is posted</strong>, so a
        change here governs receipts collected from now on and does not rewrite the terms of money already in hand.
        Shortening it therefore does <em>not</em> release everything already waiting; lengthening it does not
        re-hold anything already matured. Both directions fail safe — an entry is only ever held longer than a
        later policy would choose, never paid out earlier.
    </p>
    <form method="post" action="/admin/resellers/payouts/settings">
        <?= csrf_field() ?>
        <table class="cv-table">
            <tbody>
            <tr>
                <td><strong>Holding period</strong><br>
                    <span style="color:var(--cv-text-secondary);">Days a receipt must age before it can be paid out.
                        0 means withdrawable the moment it is posted.</span></td>
                <td><input type="number" name="payout_holding_days" min="0" step="1" value="<?= (int) $holdingDays ?>"></td>
            </tr>
            <tr>
                <td><strong>Minimum payout</strong><br>
                    <span style="color:var(--cv-text-secondary);">In base currency (<?= e($baseCode !== '' ? $baseCode : 'the base unit') ?>),
                        one value for every reseller regardless of the currency they see. Below it an account can be
                        in credit and still not be paid out — the money is theirs, it is just not worth a transfer yet.</span></td>
                <td><input type="number" name="payout_minimum" min="0" step="0.01"
                           value="<?= e(number_format($minimum, 2, '.', '')) ?>"></td>
            </tr>
            </tbody>
        </table>
        <button class="cv-btn" type="submit">Save payout settings</button>
    </form>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">By store</h2>
    <table class="cv-table">
        <thead>
        <tr>
            <th>Store</th><th>Owner</th><th>Balance</th><th>Withdrawable</th>
            <th>In their currency</th><th>Entries</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($accounts as $account): ?>
            <?php
            $clientId = (int) ($account['store']['client_id'] ?? 0);
            $own = trim((string) ($account['client']['first_name'] ?? '') . ' ' . (string) ($account['client']['last_name'] ?? ''));
            $theirCode = (string) ($account['currency_code'] ?? '');
            $inArrears = ($account['in_arrears'] ?? false) === true;
            ?>
            <tr>
                <td>
                    <a href="/admin/resellers/<?= $clientId ?>/account">
                        <?= e((string) ($account['store']['brand_name'] ?? $account['store']['slug'] ?? 'store')) ?>
                    </a>
                    <br><code><?= e((string) ($account['store']['slug'] ?? '')) ?></code>
                </td>
                <td>
                    <?php if (($account['client']['id'] ?? null) !== null): ?>
                        <a href="/admin/clients/<?= (int) $account['client']['id'] ?>"><?= e($own !== '' ? $own : 'client #' . (int) $account['client']['id']) ?></a>
                    <?php else: ?>
                        <em>account removed</em>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?= e($money((float) $account['balance_base'], $baseCode)) ?></strong>
                    <?php if ($inArrears): ?>
                        <br><span class="cv-badge cv-badge--error">in arrears</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((float) $account['withdrawable_base'] > 0): ?>
                        <?= e($money((float) $account['withdrawable_base'], $baseCode)) ?>
                        <?php if (($account['can_withdraw'] ?? false) === true): ?>
                            <br><span class="cv-badge cv-badge--success">can be paid out</span>
                        <?php else: ?>
                            <br><span class="cv-badge">below minimum</span>
                        <?php endif; ?>
                    <?php else: ?>
                        0.00
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($theirCode !== ''): ?>
                        <?= e($money((float) $account['balance'], $theirCode)) ?>
                        <br><span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
                            at today's rate &mdash; the amount itself is held in <?= e($baseCode !== '' ? $baseCode : 'base') ?>,
                            so this figure moves with the rate
                        </span>
                    <?php else: ?>
                        <em>no currency on the account</em>
                    <?php endif; ?>
                </td>
                <td><?= (int) ($account['entry_count'] ?? 0) ?></td>
                <td><a class="cv-btn" href="/admin/resellers/<?= $clientId ?>/account">Ledger</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($accounts === []): ?>
            <tr><td colspan="7" style="color:var(--cv-text-secondary);">
                No store has earned anything yet. An account appears here as soon as one of their customers pays.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
