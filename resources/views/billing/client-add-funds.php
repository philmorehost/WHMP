<?php
/** @var float $creditBalance */
/** @var array<string, mixed> $currency */
/** @var string|null $error */
$money = static fn (float $amount): string => $currency['symbol'] . number_format($amount, 2);
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
                <input class="cv-input" type="number" step="0.01" min="10.00" max="10000.00" name="amount" placeholder="100.00" style="padding-left:2rem;width:100%;font-size:var(--cv-text-md);" required>
            </div>
            <p style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:var(--cv-space-1);">Minimum deposit is <?= $money(10.00) ?>, maximum is <?= $money(10000.00) ?>.</p>
        </div>

        <button class="cv-btn" type="submit" style="width:100%;padding:var(--cv-space-3);font-size:var(--cv-text-md);font-weight:700;">Generate Deposit Invoice</button>
    </form>
</div>
