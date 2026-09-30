<?php
/**
 * ONE ISSUED STATEMENT — rendered entirely from what was frozen at issue time.
 *
 * This view never reads the ledger, and that is not an oversight. Every figure and
 * every line here comes from the row written when the statement was issued, which
 * is what makes two copies of "statement 12" identical no matter when they are
 * opened. If you are tempted to "fix" a total by joining the ledger, the answer is
 * that the document is not wrong — it is a record of what was said, and the
 * correction belongs in a superseding statement, not in a rewrite of this one.
 *
 * The frozen identity is printed rather than today's for the same reason: "issued
 * by" has to describe the issuer at the time, or a later change of VAT number
 * would silently restate every document already sent.
 *
 * @var int $clientId
 * @var array<string, mixed> $document
 * @var string $baseCode
 * @var string|null $notice
 * @var string|null $error
 */

$money = static fn (float $amount, string $code): string => number_format($amount, 2) . ($code === '' ? '' : ' ' . $code);

$identity = is_array($document['identity'] ?? null) ? $document['identity'] : [];
$lines = is_array($document['lines'] ?? null) ? $document['lines'] : [];
$theirCode = (string) ($document['currency_code'] ?? '');
$inArrears = (float) $document['closing_base'] < 0;

$fromDate = substr((string) $document['period_from'], 0, 10);
$toDate = substr((string) $document['period_to'], 0, 10);

$kindLabels = [
    'store_receipt' => 'Retail collected',
    'cost_invoice' => 'Cost billed',
    'payout' => 'Paid out',
    'adjustment' => 'Adjustment',
];

$addressLines = array_filter(array_map('trim', explode("\n", (string) ($identity['address'] ?? ''))));
?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Statement <?= e((string) $document['number']) ?></h1>
    <p><a href="/admin/resellers/<?= $clientId ?>/statement">&larr; Back to the account</a> &middot;
        <a href="/admin/resellers/<?= $clientId ?>/account">Full ledger</a> &middot;
        <a href="/admin/resellers/<?= $clientId ?>/account/export">Export CSV</a></p>
    <p style="color:var(--cv-text-secondary);">
        Issued <?= e((string) $document['issued_at']) ?> &middot; covering
        <strong><?= e($fromDate) ?></strong> to <strong><?= e($toDate) ?></strong> &middot;
        <?= (int) $document['entry_count'] ?> entr<?= (int) $document['entry_count'] === 1 ? 'y' : 'ies' ?>
    </p>
</div>

<?php if ($error !== null && $error !== ''): ?>
    <div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <table class="cv-table">
        <thead>
        <tr><th>Issued by</th><th>Issued to</th></tr>
        </thead>
        <tbody>
        <tr>
            <td>
                <strong><?= e((string) ($identity['legal_name'] ?? '') !== '' ? (string) $identity['legal_name'] : 'not set') ?></strong>
                <?php foreach ($addressLines as $line): ?>
                    <br><?= e((string) $line) ?>
                <?php endforeach; ?>
                <?php if (($identity['tax_number'] ?? '') !== ''): ?>
                    <br>Tax / VAT: <?= e((string) $identity['tax_number']) ?>
                <?php endif; ?>
                <?php if (($identity['registration_number'] ?? '') !== ''): ?>
                    <br>Registered: <?= e((string) $identity['registration_number']) ?>
                <?php endif; ?>
                <?php if (($identity['email'] ?? '') !== ''): ?>
                    <br><?= e((string) $identity['email']) ?>
                <?php endif; ?>
                <?php if (($identity['phone'] ?? '') !== ''): ?>
                    <br><?= e((string) $identity['phone']) ?>
                <?php endif; ?>
            </td>
            <td>
                <strong><?= e((string) ($document['store']['brand_name'] ?? $document['store']['slug'] ?? 'store')) ?></strong>
                <br><code><?= e((string) ($document['store']['slug'] ?? '')) ?></code>
                <br><a href="/admin/clients/<?= $clientId ?>">Client #<?= $clientId ?></a>
            </td>
        </tr>
        </tbody>
    </table>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
        The issuer details above are the ones recorded when this statement was issued, not the current settings —
        so a later change of name, address or VAT number cannot restate a document that has already gone out.
    </p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Summary</h2>
    <table class="cv-table">
        <thead>
        <tr><th>Opening balance</th><th>Credits</th><th>Debits</th><th>Closing balance</th><th>Withdrawable as at <?= e($toDate) ?></th></tr>
        </thead>
        <tbody>
        <tr>
            <td><?= e($money((float) $document['opening_base'], $baseCode)) ?></td>
            <td>+<?= e($money((float) $document['credits_base'], $baseCode)) ?></td>
            <td>&minus;<?= e($money(abs((float) $document['debits_base']), $baseCode)) ?></td>
            <td>
                <strong><?= e($money((float) $document['closing_base'], $baseCode)) ?></strong>
                <?php if ($inArrears): ?>
                    <br><span class="cv-badge cv-badge--error">in arrears</span>
                <?php endif; ?>
            </td>
            <td><?= e($money((float) $document['withdrawable_base'], $baseCode)) ?></td>
        </tr>
        </tbody>
    </table>
    <?php if ($theirCode !== '' && $theirCode !== $baseCode): ?>
        <p style="color:var(--cv-text-secondary);">
            In the reseller's currency, at the rate locked when this statement was issued
            (<?= e(number_format((float) $document['currency_rate'], 6)) ?>):
            closing <strong><?= e($money((float) $document['closing_converted'], $theirCode)) ?></strong>,
            withdrawable <?= e($money((float) $document['withdrawable_converted'], $theirCode)) ?>.
            The itemised amounts stay in <?= e($baseCode) ?> because there is no single per-line rate at which every
            line was settled — the reseller carries the exchange movement.
        </p>
    <?php endif; ?>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
        Every figure on this page is frozen. A later entry dated inside this period appears on the
        <a href="/admin/resellers/<?= $clientId ?>/statement">live account view</a> but does not alter this document.
    </p>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Entries as issued</h2>
    <table class="cv-table">
        <thead>
        <tr><th>When</th><th>Kind</th><th>Amount</th><th>Balance after</th><th>Withdrawable from</th><th>Reference</th><th>Description</th></tr>
        </thead>
        <tbody>
        <tr>
            <td colspan="3" style="color:var(--cv-text-secondary);">Opening balance carried in</td>
            <td><strong><?= e($money((float) $document['opening_base'], $baseCode)) ?></strong></td>
            <td colspan="3"></td>
        </tr>
        <?php foreach ($lines as $line): ?>
            <?php
            $amount = (float) ($line['amount'] ?? 0);
            $kind = (string) ($line['kind'] ?? '');
            ?>
            <tr>
                <td><?= e((string) ($line['created_at'] ?? '')) ?></td>
                <td><?= e($kindLabels[$kind] ?? $kind) ?></td>
                <td style="<?= $amount < 0 ? 'color:var(--cv-text-secondary);' : '' ?>">
                    <?= $amount < 0 ? '&minus;' : '+' ?><?= e($money(abs($amount), $baseCode)) ?>
                </td>
                <td><?= e($money((float) ($line['balance_after'] ?? 0), $baseCode)) ?></td>
                <td>
                    <?php if (($line['withdrawable_at'] ?? null) === null): ?>
                        <span style="color:var(--cv-text-secondary);"><?= $amount < 0 ? 'n/a' : 'immediately' ?></span>
                    <?php else: ?>
                        <?= e((string) $line['withdrawable_at']) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (($line['order_id'] ?? null) !== null): ?>
                        <a href="/admin/orders/<?= (int) $line['order_id'] ?>">Order #<?= (int) $line['order_id'] ?></a>
                    <?php endif; ?>
                    <?php if (($line['invoice_id'] ?? null) !== null): ?>
                        <a href="/admin/invoices/<?= (int) $line['invoice_id'] ?>">Invoice #<?= (int) $line['invoice_id'] ?></a>
                    <?php endif; ?>
                    <?php if (($line['payout_id'] ?? null) !== null): ?>
                        Payout #<?= (int) $line['payout_id'] ?>
                    <?php endif; ?>
                    <?php if (($line['order_id'] ?? null) === null && ($line['invoice_id'] ?? null) === null && ($line['payout_id'] ?? null) === null): ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td><?= e((string) ($line['description'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td colspan="3" style="color:var(--cv-text-secondary);">Closing balance carried out</td>
            <td><strong><?= e($money((float) $document['closing_base'], $baseCode)) ?></strong></td>
            <td colspan="3"></td>
        </tr>
        <?php if ($lines === []): ?>
            <tr><td colspan="7" style="color:var(--cv-text-secondary);">
                No entries fell in this period, as issued, so the closing balance is the opening one.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
