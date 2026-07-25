<?php
/** @var float $creditBalance */
/** @var array<string, mixed> $currency */
/** @var float $minDeposit */
/** @var float $maxDeposit */
/** @var string|null $error */
// The wallet balance is a STORED amount, so it is held in the base currency
// (see CurrencyService) and has to be converted up for display.
$rate = (float) ($currency['exchange_rate'] ?? 1.0) > 0 ? (float) $currency['exchange_rate'] : 1.0;
$money = static fn (float $amount): string => $currency['symbol'] . number_format(round($amount * $rate, 2), 2);

// The deposit limits are different: they are compared against the figure the
// client types into the form, which is already in the currency being shown, so
// converting them would print bounds that disagree with the input's own
// min/max and with what the server actually enforces.
$typedMoney = static fn (float $amount): string => $currency['symbol'] . number_format($amount, 2);
?>
<div class="cv-card" style="max-width:32rem;margin:0 auto;padding:var(--cv-space-6);border-radius:12px;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1);">
    <h1 class="cv-card__title" style="font-family:'Hanken Grotesk',sans-serif;font-weight:800;font-size:1.5rem;margin-bottom:var(--cv-space-2);display:flex;align-items:center;gap:8px;">
        <span>💳</span> Add Funds to Wallet
    </h1>
    <p style="color:var(--cv-text-secondary);margin-bottom:var(--cv-space-4);">Deposit funds in advance to automatically pay for future invoices or quickly apply them during checkout.</p>

    <div style="background:var(--cv-bg-surface-sunken);border:1px solid var(--cv-border-default);padding:var(--cv-space-4);border-radius:8px;margin-bottom:var(--cv-space-4);display:flex;justify-content:space-between;align-items:center;">
        <div>
            <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);display:block;text-transform:uppercase;font-weight:600;">Current Balance</span>
            <span style="font-size:1.5rem;font-weight:800;color:var(--cv-color-brand-500);"><?= $money($creditBalance) ?></span>
        </div>
        <div style="font-size:2rem;">💼</div>
    </div>

    <?php if ($error !== null): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-4);"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/client/wallet/add-funds"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:var(--cv-space-4);">
            <label class="cv-label">Amount to Deposit (<?= e($currency['code']) ?>)</label>
            <div style="position:relative;">
                <span style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);color:var(--cv-text-secondary);font-weight:600;"><?= e($currency['symbol']) ?></span>
                <input class="cv-input" type="number" step="0.01" min="<?= e(number_format($minDeposit, 2, '.', '')) ?>"<?= $maxDeposit > 0 ? ' max="' . e(number_format($maxDeposit, 2, '.', '')) . '"' : '' ?> name="amount" placeholder="<?= e(number_format($minDeposit, 2, '.', '')) ?>" style="padding-left:2rem;width:100%;font-size:var(--cv-text-md);" required>
            </div>
            <p style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:var(--cv-space-1);">
                <?php if ($maxDeposit > 0): ?>
                    Minimum deposit is <?= $typedMoney($minDeposit) ?>, maximum is <?= $typedMoney($maxDeposit) ?>.
                <?php else: ?>
                    Minimum deposit is <?= $typedMoney($minDeposit) ?>.
                <?php endif; ?>
            </p>
        </div>

        <button class="cv-btn" type="submit" style="width:100%;padding:var(--cv-space-3);font-size:var(--cv-text-md);font-weight:700;">Generate Deposit Invoice</button>
    </form>
</div>
