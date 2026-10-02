<?php
/**
 * The payout queue: what resellers have asked for, and answering it.
 *
 * Pending requests first and oldest at the top, because this is a work list. The
 * decided ones are kept underneath as history rather than mixed in, so an admin
 * cannot mistake a settled payout for one still to make.
 *
 * @var array<int, array<string, mixed>> $pending
 * @var array<int, array<string, mixed>> $decided
 * @var array<string, mixed> $totals
 * @var string $baseCode
 * @var array<string, string> $labels
 * @var string|null $notice
 * @var string|null $error
 */

$money = static fn (float $amount, string $code): string => number_format($amount, 2) . ($code === '' ? '' : ' ' . $code);
?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Reseller payouts</h1>
    <p><a href="/admin/resellers">&larr; Back to resellers</a> &middot;
        <a href="/admin/resellers/accounts">Reseller accounts</a> &middot;
        <a href="/admin/resellers/billing">Store cost billing</a></p>
    <p style="color:var(--cv-text-secondary);">
        Payment is a <strong>manual bank transfer</strong>: make the transfer in your own banking interface, then
        record the reference here. The system never moves money.
    </p>
    <p style="color:var(--cv-text-secondary);">
        <strong>The account is debited when the request is made, not when it is paid.</strong> A request sets the
        money aside immediately, so a reseller cannot ask for the same balance twice while a request is waiting.
        That means recording a payment here must <em>not</em> change the balance — the debit already happened — and
        refusing a request puts the funds back as a new ledger entry rather than by editing anything.
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
    <table class="cv-table">
        <thead>
        <tr><th>State</th><th>Requests</th><th>Total</th></tr>
        </thead>
        <tbody>
        <?php foreach ($labels as $status => $label): ?>
            <tr>
                <td><?= e($label) ?></td>
                <td><?= (int) ($totals[$status]['count'] ?? 0) ?></td>
                <td><?= e($money((float) ($totals[$status]['total_base'] ?? 0), $baseCode)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="color:var(--cv-text-secondary);margin-top:var(--cv-space-2);">
        Totals are in <?= e($baseCode !== '' ? $baseCode : 'the base currency') ?> and are comparable across
        resellers, because every figure here is the base amount the account was actually debited by — not the
        amount sent, which is in each reseller's own currency.
    </p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Awaiting payment</h2>
    <table class="cv-table">
        <thead>
        <tr>
            <th>#</th><th>Store</th><th>Owner</th><th>Requested</th>
            <th>Amount to send</th><th>Leaves the account</th><th>Rate</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($pending as $row): ?>
            <?php
            $clientId = (int) ($row['store']['client_id'] ?? 0);
            $storeName = (string) ($row['store']['brand_name'] ?? $row['store']['slug'] ?? 'store');
            ?>
            <tr>
                <td>#<?= (int) $row['id'] ?></td>
                <td>
                    <?php if ($clientId > 0): ?>
                        <a href="/admin/resellers/<?= $clientId ?>/account"><?= e($storeName) ?></a>
                    <?php else: ?>
                        <em>store removed</em>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($clientId > 0): ?>
                        <?php $owner = (string) ($row['owner'] ?? ''); ?>
                        <a href="/admin/resellers/<?= $clientId ?>/account"><?= e($owner !== '' ? $owner : 'client #' . $clientId) ?></a>
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td><?= e((string) $row['requested_at']) ?></td>
                <td><strong><?= e($money((float) $row['amount'], (string) ($row['amount_code'] ?? ''))) ?></strong></td>
                <td><?= e($money((float) $row['amount_base'], $baseCode)) ?></td>
                <td><?= e(number_format((float) $row['currency_rate'], 4)) ?></td>
            </tr>
            <tr>
                <td colspan="7" style="background:var(--cv-surface-2);">
                    <div style="display:flex;gap:var(--cv-space-4);flex-wrap:wrap;align-items:flex-start;">
                        <!-- Where the money goes, frozen when the request was made. Shown
                             next to the "record as paid" form so the admin pays the account
                             the reseller named rather than one from memory. -->
                        <div style="min-width:220px;">
                            <span style="font-size:var(--cv-text-sm);">Send to</span><br>
                            <?php if ((string) ($row['destination_snapshot'] ?? '') !== ''): ?>
                                <strong><?= e((string) $row['destination_snapshot']) ?></strong>
                            <?php else: ?>
                                <span class="cv-badge cv-badge--error">No destination on file</span>
                            <?php endif; ?>
                        </div>
                        <form method="post" action="/admin/resellers/payouts/<?= (int) $row['id'] ?>/paid"
                              style="display:flex;gap:var(--cv-space-2);align-items:flex-end;">
                            <?= csrf_field() ?>
                            <label style="display:flex;flex-direction:column;gap:4px;">
                                <span style="font-size:var(--cv-text-sm);">Bank / transfer reference
                                    <em>(required)</em></span>
                                <input class="cv-input" name="reference" required maxlength="191"
                                       placeholder="e.g. TRF-99120-ACME">
                            </label>
                            <button class="cv-btn" type="submit">Record as paid</button>
                        </form>

                        <form method="post" action="/admin/resellers/payouts/<?= (int) $row['id'] ?>/reject"
                              style="display:flex;gap:var(--cv-space-2);align-items:flex-end;">
                            <?= csrf_field() ?>
                            <label style="display:flex;flex-direction:column;gap:4px;">
                                <span style="font-size:var(--cv-text-sm);">Why it is being refused
                                    <em>(optional, worth writing)</em></span>
                                <input class="cv-input" name="note" maxlength="255"
                                       placeholder="Bank details could not be verified">
                            </label>
                            <button class="cv-btn" type="submit">Reject &amp; return funds</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($pending === []): ?>
            <tr><td colspan="7" style="color:var(--cv-text-secondary);">
                Nothing is waiting. A request appears here as soon as a reseller asks for their balance.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <p style="color:var(--cv-text-secondary);">
        The two figures differ on purpose: <strong>Amount to send</strong> is what the reseller's bank should
        receive, in their currency, at the rate locked when they asked. <strong>Leaves the account</strong> is the
        base-currency figure the ledger was debited by. Recording a payment with a reference is what ties the two
        to a real bank line.
    </p>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Decided</h2>
    <table class="cv-table">
        <thead>
        <tr><th>#</th><th>Store</th><th>Amount sent</th><th>Leaves the account</th><th>State</th><th>Reference / note</th><th>Decided</th></tr>
        </thead>
        <tbody>
        <?php foreach ($decided as $row): ?>
            <?php
            $clientId = (int) ($row['store']['client_id'] ?? 0);
            $storeName = (string) ($row['store']['brand_name'] ?? $row['store']['slug'] ?? 'store');
            $status = (string) $row['status'];
            ?>
            <tr>
                <td>#<?= (int) $row['id'] ?></td>
                <td>
                    <?php if ($clientId > 0): ?>
                        <a href="/admin/resellers/<?= $clientId ?>/account"><?= e($storeName) ?></a>
                    <?php else: ?>
                        <em>store removed</em>
                    <?php endif; ?>
                </td>
                <td><?= e($money((float) $row['amount'], (string) ($row['amount_code'] ?? ''))) ?></td>
                <td><?= e($money((float) $row['amount_base'], $baseCode)) ?></td>
                <td>
                    <span class="cv-badge <?= $status === 'paid' ? 'cv-badge--success' : '' ?>">
                        <?= e($labels[$status] ?? $status) ?>
                    </span>
                </td>
                <td>
                    <?php if (($row['reference'] ?? null) !== null && $row['reference'] !== ''): ?>
                        <code><?= e((string) $row['reference']) ?></code>
                    <?php endif; ?>
                    <?php if (($row['note'] ?? null) !== null && $row['note'] !== ''): ?>
                        <div style="color:var(--cv-text-secondary);"><?= e((string) $row['note']) ?></div>
                    <?php endif; ?>
                    <?php if (($row['reference'] ?? null) === null && ($row['note'] ?? null) === null): ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td><?= e((string) ($row['decided_at'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($decided === []): ?>
            <tr><td colspan="7" style="color:var(--cv-text-secondary);">No payout has been decided yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
