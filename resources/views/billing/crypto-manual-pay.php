<?php
/** @var array<string, mixed> $invoice */
/** @var array<string, mixed> $currency */
/** @var float $remaining */
/** @var float $usdAmount */
/** @var string $wallet */
/** @var bool $walletValid */
/** @var string $instructions */
/** @var array<string, mixed>|null $pending */
/** @var array<int, array<string, mixed>> $history */
/** @var string $companyName */
/** @var string $companyEmail */
/** @var bool $submitted */
/** @var string|null $error */
// The USD figure is the QUOTE for the crypto transfer, computed fresh on
// each visit by the controller (remaining balance crossed to USD). It locks
// the moment the client submits — the stored row, not this page, is then
// the figure the admin reviews against.
$statusLabels = [
    'pending' => 'Awaiting review',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
];
?>
<div class="cv-card" style="max-width:44rem;margin:0 auto;padding:var(--cv-space-6);border-radius:12px;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1);">
    <h1 class="cv-card__title" style="font-family:'Hanken Grotesk',sans-serif;font-weight:800;font-size:1.5rem;margin-bottom:var(--cv-space-2);display:flex;align-items:center;gap:8px;">
        <span>🪙</span> Pay with Cryptocurrency — USDT (TRC-20)
    </h1>
    <p style="color:var(--cv-text-secondary);margin-bottom:var(--cv-space-4);">
        Send the USDT amount below on the <strong>TRON (TRC-20)</strong> network to our wallet, upload your payment proof, and an admin will verify it — usually within a few hours. Invoice <strong>#<?= (int) $invoice['id'] ?></strong> is marked paid on approval.
    </p>

    <?php if ($error !== null): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-4);"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($submitted && $pending !== null): ?>
        <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);">
            Payment proof received — it is now <strong>awaiting review</strong>. You will be notified once an admin approves it.
        </div>
    <?php endif; ?>

    <div style="background:var(--cv-bg-surface-sunken);border:1px solid var(--cv-border-default);padding:var(--cv-space-4);border-radius:8px;margin-bottom:var(--cv-space-4);">
        <div style="display:flex;justify-content:space-between;gap:var(--cv-space-4);flex-wrap:wrap;align-items:center;">
            <div>
                <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);display:block;text-transform:uppercase;font-weight:600;">Amount to send</span>
                <span style="font-size:1.6rem;font-weight:800;color:var(--cv-color-brand-500);">$<?= number_format($usdAmount, 2) ?> <span style="font-size:var(--cv-text-sm);font-weight:600;color:var(--cv-text-secondary);">USDT</span></span>
            </div>
            <div style="text-align:right;">
                <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);display:block;text-transform:uppercase;font-weight:600;">Invoice balance</span>
                <span style="font-size:var(--cv-text-md);font-weight:700;"><?= e((string) ($currency['symbol'] ?? '')) ?><?= number_format($remaining, 2) ?> <?= e((string) ($currency['code'] ?? '')) ?></span>
            </div>
        </div>
        <p style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin:var(--cv-space-2) 0 0;">
            Quoted in USD — the same way crypto payments are always counted. The figure is locked the moment you submit your proof below.
        </p>
    </div>

    <?php if (!$walletValid): ?>
        <div class="cv-alert cv-alert--warning" style="margin-bottom:var(--cv-space-4);">
            Cryptocurrency payments are being configured. Please use another payment method or contact
            <a href="mailto:<?= e($companyEmail) ?>"><?= e($companyEmail) ?></a>.
        </div>
    <?php else: ?>
        <div style="background:var(--cv-bg-surface-sunken);border:1px solid var(--cv-border-default);padding:var(--cv-space-4);border-radius:8px;margin-bottom:var(--cv-space-4);">
            <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);display:block;text-transform:uppercase;font-weight:600;margin-bottom:4px;">Our USDT (TRC-20) wallet</span>
            <div style="display:flex;gap:var(--cv-space-2);align-items:center;flex-wrap:wrap;">
                <code style="font-size:var(--cv-text-md);font-weight:700;word-break:break-all;"><?= e($wallet) ?></code>
            </div>
            <p style="font-size:var(--cv-text-xs);color:#b91c1c;margin:var(--cv-space-2) 0 0;">
                ⚠️ TRC-20 only — sending on the wrong network loses the funds. Use <strong>Invoice #<?= (int) $invoice['id'] ?></strong> as your transfer reference where your wallet allows a memo.
            </p>
        </div>

        <?php if ($instructions !== ''): ?>
            <div style="border:1px solid var(--cv-border-default);padding:var(--cv-space-4);border-radius:8px;margin-bottom:var(--cv-space-4);font-size:var(--cv-text-sm);color:var(--cv-text-secondary);">
                <?= nl2br(e($instructions)) ?>
            </div>
        <?php endif; ?>

        <?php if ($pending !== null): ?>
            <div class="cv-alert cv-alert--warning" style="margin-bottom:var(--cv-space-4);">
                <strong>A payment of $<?= number_format((float) $pending['amount_usd'], 2) ?> is awaiting review</strong> for this invoice
                (submitted <?= e(substr((string) $pending['created_at'], 0, 16)) ?>). You will be notified when it is approved. A new proof can be submitted only after this one is reviewed.
            </div>
        <?php else: ?>
            <form method="post" action="/client/invoices/<?= (int) $invoice['id'] ?>/crypto" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="cv-field" style="margin-bottom:var(--cv-space-4);">
                    <label class="cv-label">Payment proof (screenshot or PDF) <span style="color:#b91c1c;">*</span></label>
                    <input class="cv-input" type="file" name="proof" accept="image/*,.pdf" required style="width:100%;padding:8px 12px;">
                    <p style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:var(--cv-space-1);">
                        Show the amount, the recipient wallet and the transaction — up to 10 MB.
                    </p>
                </div>

                <div class="cv-field" style="margin-bottom:var(--cv-space-4);">
                    <label class="cv-label">Transaction hash / TXID (optional)</label>
                    <input class="cv-input" type="text" name="txid" placeholder="e.g. the 64-character hash from TRONScan" style="width:100%;padding:8px 12px;font-family:monospace;">
                    <p style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:var(--cv-space-1);">Helps the admin find your transfer on-chain faster.</p>
                </div>

                <div class="cv-field" style="margin-bottom:var(--cv-space-4);">
                    <label class="cv-label">Note to the reviewer (optional)</label>
                    <textarea class="cv-input" name="note" rows="2" style="width:100%;padding:8px 12px;" placeholder="Anything the reviewer should know"></textarea>
                </div>

                <button class="cv-btn" type="submit" style="width:100%;padding:var(--cv-space-3);font-size:var(--cv-text-md);font-weight:700;">
                    Submit payment for review
                </button>
                <p style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:var(--cv-space-2);">
                    Submitting locks the $<?= number_format($usdAmount, 2) ?> figure for this attempt. Invoice #<?= (int) $invoice['id'] ?> stays unpaid until an admin approves.
                </p>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($history !== []): ?>
        <h2 style="font-size:var(--cv-text-md);font-weight:800;margin:var(--cv-space-6) 0 var(--cv-space-2);">Previous submissions</h2>
        <table class="cv-table" style="width:100%;">
            <thead>
                <tr><th style="text-align:left;">Sent</th><th style="text-align:left;">Amount</th><th style="text-align:left;">TXID</th><th style="text-align:left;">Status</th><?php if (in_array('rejected', array_column($history, 'status'), true)): ?><th style="text-align:left;">Reviewer note</th><?php endif; ?></tr>
            </thead>
            <tbody>
                <?php foreach ($history as $row): ?>
                    <tr>
                        <td><?= e(substr((string) $row['created_at'], 0, 16)) ?></td>
                        <td>$<?= number_format((float) $row['amount_usd'], 2) ?></td>
                        <td style="max-width:14ch;overflow:hidden;text-overflow:ellipsis;"> <?= e((string) ($row['txid'] ?? '—')) ?></td>
                        <td>
                            <?php $status = (string) $row['status']; ?>
                            <span style="font-weight:700;color:<?= $status === 'approved' ? '#15803d' : ($status === 'rejected' ? '#b91c1c' : '#b45309') ?>;">
                                <?= e($statusLabels[$status] ?? $status) ?>
                            </span>
                        </td>
                        <?php if (in_array('rejected', array_column($history, 'status'), true)): ?>
                            <td style="max-width:24ch;"><?= $status === 'rejected' ? e((string) ($row['admin_note'] ?? '')) : '' ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
