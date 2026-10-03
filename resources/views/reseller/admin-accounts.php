<?php
/**
 * Every store's running account: what we owe, and what could be taken today.
 *
 * @var array<int, array<string, mixed>> $accounts
 * @var float $totalBalance
 * @var float $totalWithdrawable
 * @var int $claimable
 * @var int $holdingDays
 * @var array<int, array<string, mixed>> $minimums
 * @var float $minimumAmount the threshold, in $minimumCurrency
 * @var string $minimumCurrency the currency the threshold is set in
 * @var string $baseCode
 * @var string|null $notice
 * @var string|null $error
 */

$money = static fn (float $amount, string $code): string => number_format($amount, 2) . ($code === '' ? '' : ' ' . $code);
// Rates are shown to six places without trailing zeros: 1490, 0.92, 0.000671.
$rateText = static fn (float $rate): string => rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.');
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
        one number and calling it “available” would overstate every account by a month of sales. The payout
        threshold is set once (<?= e($money((float) $minimumAmount, $minimumCurrency)) ?>) and converted into each reseller's
        currency, so “above the minimum” is a separate count.
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
                    <span style="color:var(--cv-text-secondary);">The smallest withdrawable balance a reseller can ask to
                        be paid. Set it once, in one currency; every other currency's minimum is derived from it using
                        the conversion rate below.</span></td>
                <td>
                    <div style="display:flex;gap:var(--cv-space-2);align-items:center;flex-wrap:wrap;">
                        <input class="cv-input" type="number" name="payout_minimum_amount" min="0" step="0.01" required
                               style="max-width:10rem;" data-payout-minimum-amount
                               value="<?= e(number_format((float) $minimumAmount, 2, '.', '')) ?>">
                        <select class="cv-input" name="payout_minimum_currency" style="max-width:8rem;" data-payout-minimum-anchor>
                            <?php foreach ($minimums as $minimumRow): ?>
                                <?php $optionCode = (string) ($minimumRow['currency_code'] ?? ''); ?>
                                <option value="<?= e($optionCode) ?>" <?= $optionCode === $minimumCurrency ? 'selected' : '' ?>><?= e($optionCode) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <strong>Conversion rate for other currencies</strong><br>
                    <span style="color:var(--cv-text-secondary);">How many units of each currency one
                        <?= e($minimumCurrency) ?> is worth when working out its minimum. Leave a rate blank to follow the
                        live exchange rate from <a href="/admin/currencies">Currencies</a>; enter a figure to fix the rate
                        used for payouts in that currency. The balance is still compared in base units at today's rate.</span>
                    <div style="overflow-x:auto;margin-top:var(--cv-space-2);">
                    <table class="cv-table">
                        <thead><tr>
                            <th>Currency</th>
                            <th>Live rate (1 <?= e($minimumCurrency) ?> =)</th>
                            <th>Conversion rate used for payouts</th>
                            <th>Minimum payout</th>
                            <th>Base equivalent</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($minimums as $minimumRow): ?>
                            <?php
                            $minimumCode = (string) ($minimumRow['currency_code'] ?? '');
                            $isAnchor = ($minimumRow['anchor'] ?? false) === true;
                            $isCustom = ($minimumRow['custom'] ?? false) === true;
                            $liveRate = (float) ($minimumRow['live_rate'] ?? 1.0);
                            ?>
                            <tr data-payout-rate-row data-live-rate="<?= e($rateText($liveRate)) ?>" data-anchor="<?= $isAnchor ? '1' : '0' ?>">
                                <td><strong><?= e($minimumCode) ?></strong>
                                    <?php if ($isAnchor): ?>
                                        <br><span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">minimum is set in this currency</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($rateText($liveRate)) ?></td>
                                <td>
                                    <?php if ($isAnchor): ?>
                                        1
                                    <?php else: ?>
                                        <input class="cv-input" type="number" name="payout_minimum_rates[<?= e($minimumCode) ?>]"
                                               min="0" step="any" style="max-width:10rem;" data-payout-rate-input
                                               placeholder="<?= e($rateText($liveRate)) ?> (live)"
                                               value="<?= $isCustom ? e($rateText((float) $minimumRow['rate'])) : '' ?>">
                                        <br><span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
                                            <?= $isCustom ? 'fixed rate' : 'following the live rate' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><strong data-payout-rate-minimum data-code="<?= e($minimumCode) ?>"><?= e($money((float) $minimumRow['minimum'], $minimumCode)) ?></strong></td>
                                <td><?= e($money((float) $minimumRow['minimum_base'], $baseCode)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($minimums === []): ?>
                            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No currencies are configured.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </td>
            </tr>
            </tbody>
        </table>
        <button class="cv-btn" type="submit">Save payout settings</button>
    </form>
    <script nonce="<?= csp_nonce() ?>">
    (function () {
        // Live preview of each currency's minimum while the admin types. The
        // server recomputes everything on save; this only saves a round trip.
        var amountInput = document.querySelector('[data-payout-minimum-amount]');
        var rows = document.querySelectorAll('[data-payout-rate-row]');
        if (!amountInput || !rows.length) { return; }
        function refresh() {
            var amount = parseFloat(amountInput.value);
            if (!isFinite(amount) || amount < 0) { amount = 0; }
            rows.forEach(function (row) {
                var out = row.querySelector('[data-payout-rate-minimum]');
                if (!out) { return; }
                var rate = 1;
                if (row.getAttribute('data-anchor') !== '1') {
                    var input = row.querySelector('[data-payout-rate-input]');
                    var typed = input ? parseFloat(input.value) : NaN;
                    rate = isFinite(typed) && typed > 0 ? typed : parseFloat(row.getAttribute('data-live-rate')) || 1;
                }
                var code = out.getAttribute('data-code') || '';
                var value = (Math.round(amount * rate * 100) / 100).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                out.textContent = value + (code ? ' ' + code : '');
            });
        }
        amountInput.addEventListener('input', refresh);
        document.querySelectorAll('[data-payout-rate-input]').forEach(function (el) { el.addEventListener('input', refresh); });
    })();
    </script>
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
                <td>
                    <a class="cv-btn" href="/admin/resellers/<?= $clientId ?>/account">Ledger</a>
                    <a class="cv-btn" href="/admin/resellers/<?= $clientId ?>/statement">Statement</a>
                </td>
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
