<?php
/** @var array<int, array<string, mixed>> $pending */
/** @var array<int, array<string, mixed>> $reviewed */
/** @var string|null $notice */
/** @var string|null $error */
/** @var CodeVault\View $view */
// The review queue for manual USDT (TRC-20) payments. Approving records the
// invoice's remaining balance through the ordinary payment pipeline (the
// invoice is marked paid; a deposit invoice becomes wallet credit); a
// rejection returns the submission to the client with the reason and leaves
// the invoice unpaid.
?>
<div class="cv-card" style="padding:var(--cv-space-6);border-radius:12px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:var(--cv-space-4);flex-wrap:wrap;margin-bottom:var(--cv-space-4);">
        <div>
            <h1 class="cv-card__title" style="font-family:'Hanken Grotesk',sans-serif;font-weight:800;font-size:1.5rem;margin:0;">🪙 Crypto Payments — Manual Review</h1>
            <p style="color:var(--cv-text-secondary);margin:4px 0 0;font-size:var(--cv-text-sm);">
                USDT (TRC-20) payments clients paid to your wallet and proved with an upload. Approving marks the invoice paid; rejecting returns it to the client with your reason.
            </p>
        </div>
        <a class="cv-btn" href="/admin/gateways" style="text-decoration:none;padding:8px 14px;font-size:var(--cv-text-sm);font-weight:700;">Wallet &amp; toggles</a>
    </div>

    <?php if ($notice !== null): ?>
        <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-4);"><?= e($error) ?></div>
    <?php endif; ?>

    <h2 style="font-size:var(--cv-text-md);font-weight:800;margin:0 0 var(--cv-space-2);">Awaiting review (<?= count($pending) ?>)</h2>
    <?php if ($pending === []): ?>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin:0 0 var(--cv-space-6);">Nothing waiting — every submitted proof has been reviewed.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="cv-table" style="width:100%;">
                <thead>
                    <tr>
                        <th style="text-align:left;">Submitted</th>
                        <th style="text-align:left;">Client</th>
                        <th style="text-align:left;">Invoice</th>
                        <th style="text-align:left;">To expect</th>
                        <th style="text-align:left;">TXID</th>
                        <th style="text-align:left;">Client note</th>
                        <th style="text-align:left;">Proof</th>
                        <th style="text-align:left;">Decision</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending as $row): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?= e(substr((string) $row['created_at'], 0, 16)) ?></td>
                            <td>
                                <?= e(trim((string) $row['first_name'] . ' ' . (string) $row['last_name'])) ?><br>
                                <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);"><?= e((string) $row['client_email']) ?></span>
                            </td>
                            <td><a href="/admin/invoices/<?= (int) $row['invoice_id'] ?>">#<?= (int) $row['invoice_id'] ?></a></td>
                            <td>
                                <strong><?= e((string) $row['usd_display']) ?></strong> USDT<br>
                                <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);">to <?= e(substr((string) $row['wallet_address'], 0, 10)) ?>…<?= e(substr((string) $row['wallet_address'], -6)) ?></span>
                            </td>
                            <td style="max-width:18ch;overflow:hidden;text-overflow:ellipsis;font-family:monospace;font-size:var(--cv-text-xs);" title="<?= e((string) ($row['txid'] ?? '')) ?>"><?= $row['txid'] !== null ? e((string) $row['txid']) : '—' ?></td>
                            <td style="max-width:22ch;font-size:var(--cv-text-xs);"><?= $row['note'] !== null ? e((string) $row['note']) : '—' ?></td>
                            <td>
                                <a href="/admin/crypto-payments/<?= (int) $row['id'] ?>/proof" target="_blank" rel="noopener">View proof ↗</a>
                            </td>
                            <td>
                                <div style="display:flex;flex-direction:column;gap:6px;min-width:13rem;">
                                    <form method="post" action="/admin/crypto-payments/<?= (int) $row['id'] ?>/approve" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input name="admin_note" placeholder="Internal note (optional)" style="width:100%;padding:5px 8px;font-size:var(--cv-text-xs);border:1px solid var(--cv-border-default);border-radius:6px;margin-bottom:6px;">
                                        <button class="cv-btn" type="submit" data-confirm="Approve this payment and mark invoice #<?= (int) $row['invoice_id'] ?> paid?" style="width:100%;padding:6px;font-size:var(--cv-text-xs);font-weight:700;background:#15803d;color:#fff;">✓ Approve — mark paid</button>
                                    </form>
                                    <form method="post" action="/admin/crypto-payments/<?= (int) $row['id'] ?>/reject" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input name="reason" placeholder="Reason (sent to the client)" required style="width:100%;padding:5px 8px;font-size:var(--cv-text-xs);border:1px solid var(--cv-border-default);border-radius:6px;margin-bottom:6px;">
                                        <button class="cv-btn" type="submit" data-confirm="Reject this payment? The client will see your reason." style="width:100%;padding:6px;font-size:var(--cv-text-xs);font-weight:700;background:#b91c1c;color:#fff;">✕ Reject</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 style="font-size:var(--cv-text-md);font-weight:800;margin:var(--cv-space-6) 0 var(--cv-space-2);">Recent decisions</h2>
    <?php if ($reviewed === []): ?>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin:0;">No reviewed payments yet.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="cv-table" style="width:100%;">
                <thead>
                    <tr>
                        <th style="text-align:left;">Reviewed</th>
                        <th style="text-align:left;">Client</th>
                        <th style="text-align:left;">Invoice</th>
                        <th style="text-align:left;">Amount</th>
                        <th style="text-align:left;">Invoice state</th>
                        <th style="text-align:left;">Outcome</th>
                        <th style="text-align:left;">Admin note / reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reviewed as $row): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?= e(substr((string) ($row['reviewed_at'] ?? ''), 0, 16)) ?></td>
                            <td><?= e(trim((string) $row['first_name'] . ' ' . (string) $row['last_name'])) ?></td>
                            <td><a href="/admin/invoices/<?= (int) $row['invoice_id'] ?>">#<?= (int) $row['invoice_id'] ?></a></td>
                            <td><?= e((string) $row['usd_display']) ?></td>
                            <td><?= e((string) $row['invoice_status']) ?></td>
                            <td>
                                <?php $status = (string) $row['status']; ?>
                                <span style="font-weight:700;color:<?= $status === 'approved' ? '#15803d' : '#b91c1c' ?>;">
                                    <?= $status === 'approved' ? '✓ Approved' : '✕ Rejected' ?>
                                </span>
                            </td>
                            <td style="max-width:30ch;font-size:var(--cv-text-xs);"><?= $row['admin_note'] !== null ? e((string) $row['admin_note']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
