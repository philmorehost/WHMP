<?php
/**
 * One store's account for a PERIOD: opening balance, the entries, closing
 * balance (plan §10.2).
 *
 * WHY THE OPENING BALANCE IS PRINTED, AND NOT DERIVED ON THE PAGE
 *
 * A statement exists to be checked, and the only way to check a running balance
 * is to have somewhere to start. "Opening + credits − debits = closing" is a sum
 * a reader can do in their head, so every figure on this page is independently
 * verifiable rather than being one number the reader has to take on trust.
 *
 * THE AMOUNTS ARE BASE FIGURES, AND ONLY THE CLOSING TOTALS ARE CONVERTED.
 * That is not a missing feature. A receipt collected in the reseller's own
 * currency is stored in base at the rate that applied when it was posted, and
 * there is no single per-line rate at which every line was settled — the reseller
 * carries the exchange movement. Showing a converted figure per line would imply
 * otherwise, so the conversion appears only where it is true: against the closing
 * balance and the withdrawable figure, as a valuation of the total.
 *
 * @var int $clientId
 * @var array<string, mixed> $statement
 * @var array<int, array<string, mixed>> $issued
 * @var array<int, string> $missingIdentity
 * @var bool $canIssueStatement
 * @var string $baseCode
 * @var string|null $notice
 * @var string|null $error
 */

$money = static fn (float $amount, string $code): string => number_format($amount, 2) . ($code === '' ? '' : ' ' . $code);

$store = is_array($statement['store'] ?? null) ? $statement['store'] : [];
$storeName = (string) ($store['brand_name'] ?? $store['slug'] ?? 'store');
$theirCode = (string) ($statement['currency_code'] ?? '');
$entries = is_array($statement['entries'] ?? null) ? $statement['entries'] : [];
$running = is_array($statement['running'] ?? null) ? $statement['running'] : [];
$inArrears = ($statement['in_arrears'] ?? false) === true;
$holdingDays = (int) ($statement['holding_days'] ?? 0);
$missingIdentity = is_array($missingIdentity ?? null) ? $missingIdentity : [];
$issued = is_array($issued ?? null) ? $issued : [];

// The period, as the two plain dates a reader thinks in rather than the
// datetimes the query needs.
$fromDate = substr((string) $statement['from'], 0, 10);
$toDate = substr((string) $statement['to'], 0, 10);

$kindLabels = [
    'store_receipt' => 'Retail collected',
    'cost_invoice' => 'Cost billed',
    'payout' => 'Paid out',
    'adjustment' => 'Adjustment',
];
?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Statement of account — <?= e($storeName) ?></h1>
    <p><a href="/admin/resellers/accounts">&larr; All reseller accounts</a> &middot;
        <a href="/admin/resellers/<?= $clientId ?>/account">Full ledger</a> &middot;
        <a href="/admin/resellers/<?= $clientId ?>/account/export">Export CSV</a> &middot;
        <a href="/admin/resellers/<?= $clientId ?>/store">Store settings</a></p>

    <p style="color:var(--cv-text-secondary);">
        <strong><?= e(brand_name()) ?></strong> &middot; statement of the reseller account for
        <strong><?= e($fromDate) ?></strong> to <strong><?= e($toDate) ?></strong>, in
        <?= e($baseCode !== '' ? $baseCode : 'the base currency') ?>.
    </p>

    <form method="get" action="/admin/resellers/<?= $clientId ?>/statement"
          style="display:flex;gap:var(--cv-space-2);align-items:flex-end;flex-wrap:wrap;">
        <label>From<br><input type="date" name="from" value="<?= e($fromDate) ?>"></label>
        <label>To<br><input type="date" name="to" value="<?= e($toDate) ?>"></label>
        <button class="cv-btn" type="submit">Show period</button>
    </form>

    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-top:var(--cv-space-2);">
        <strong>Not yet a numbered document.</strong> This statement is recomputed from the ledger every time it is
        opened, so it always reflects the entries as they now stand and a mistake in it can be corrected by fixing
        the cause. A statement used for tax or accounting has the opposite property — it must be numbered and
        frozen, so that two copies of the same statement can never disagree. The figures below are the ones such a
        document would carry; the numbering and the frozen copy are a separate piece of work.
    </p>
</div>

<?php if ($error !== null && $error !== ''): ?>
    <div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Summary</h2>
    <table class="cv-table">
        <thead>
        <tr><th>Opening balance</th><th>Credits</th><th>Debits</th><th>Closing balance</th></tr>
        </thead>
        <tbody>
        <tr>
            <td><?= e($money((float) $statement['opening_base'], $baseCode)) ?></td>
            <td>+<?= e($money((float) $statement['credits_base'], $baseCode)) ?></td>
            <td>&minus;<?= e($money(abs((float) $statement['debits_base']), $baseCode)) ?></td>
            <td>
                <strong><?= e($money((float) $statement['closing_base'], $baseCode)) ?></strong>
                <?php if ($inArrears): ?>
                    <br><span class="cv-badge cv-badge--error">in arrears</span>
                <?php endif; ?>
            </td>
        </tr>
        </tbody>
    </table>
    <p style="color:var(--cv-text-secondary);margin-top:var(--cv-space-2);">
        Opening + credits &minus; debits = closing. <?= (int) $statement['entry_count'] ?> entr<?= (int) $statement['entry_count'] === 1 ? 'y' : 'ies' ?>
        fall inside this period.
    </p>

    <table class="cv-table" style="margin-top:var(--cv-space-3);">
        <thead>
        <tr><th>Withdrawable as at <?= e($toDate) ?></th><th>In the reseller's currency</th></tr>
        </thead>
        <tbody>
        <tr>
            <td><?= e($money((float) $statement['withdrawable_base'], $baseCode)) ?></td>
            <td>
                <?php if ($theirCode !== ''): ?>
                    <?= e($money((float) $statement['closing'], $theirCode)) ?> <span style="color:var(--cv-text-secondary);">closing balance</span>
                    <br><span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
                        withdrawable <?= e($money((float) $statement['withdrawable'], $theirCode)) ?>
                    </span>
                <?php else: ?>
                    <em>&mdash;</em>
                <?php endif; ?>
            </td>
        </tr>
        </tbody>
    </table>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
        The reseller's currency figures are a conversion of the closing totals made today, not the figures each entry
        was posted at — the reseller carries the exchange movement, so these move with the rate and with no new
        sales. The withdrawable figure is as at the period END, and is smaller than the closing balance by any
        receipt still inside the <?= $holdingDays ?>-day holding period.
    </p>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Entries in this period</h2>
    <table class="cv-table">
        <thead>
        <tr><th>When</th><th>Kind</th><th>Amount</th><th>Balance after</th><th>Withdrawable from</th><th>Reference</th><th>Description</th></tr>
        </thead>
        <tbody>
        <tr>
            <td colspan="3" style="color:var(--cv-text-secondary);">Opening balance carried in</td>
            <td><strong><?= e($money((float) $statement['opening_base'], $baseCode)) ?></strong></td>
            <td colspan="3"></td>
        </tr>
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
                <td><?= e($money((float) ($running[(int) $entry['id']] ?? 0), $baseCode)) ?></td>
                <td>
                    <?php if ($withdrawableAt === null || $withdrawableAt === ''): ?>
                        <span style="color:var(--cv-text-secondary);"><?= $amount < 0 ? 'n/a' : 'immediately' ?></span>
                    <?php else: ?>
                        <?= e((string) $withdrawableAt) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (($entry['order_id'] ?? null) !== null): ?>
                        <a href="/admin/orders/<?= (int) $entry['order_id'] ?>">Order #<?= (int) $entry['order_id'] ?></a>
                    <?php endif; ?>
                    <?php if (($entry['invoice_id'] ?? null) !== null): ?>
                        <a href="/admin/invoices/<?= (int) $entry['invoice_id'] ?>">Invoice #<?= (int) $entry['invoice_id'] ?></a>
                    <?php endif; ?>
                    <?php if (($entry['payout_id'] ?? null) !== null): ?>
                        Payout #<?= (int) $entry['payout_id'] ?>
                    <?php endif; ?>
                    <?php if (($entry['order_id'] ?? null) === null && ($entry['invoice_id'] ?? null) === null && ($entry['payout_id'] ?? null) === null): ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td><?= e((string) $entry['description']) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td colspan="3" style="color:var(--cv-text-secondary);">Closing balance carried out</td>
            <td><strong><?= e($money((float) $statement['closing_base'], $baseCode)) ?></strong></td>
            <td colspan="3"></td>
        </tr>
        <?php if ($entries === []): ?>
            <tr><td colspan="7" style="color:var(--cv-text-secondary);">
                No entries fall in this period, so the closing balance is the opening one. Activity outside the
                period is on the <a href="/admin/resellers/<?= $clientId ?>/account">full ledger</a>.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="cv-card" style="margin-top:var(--cv-space-4);">
    <h2 class="cv-card__title">Issued statements</h2>
    <p style="color:var(--cv-text-secondary);">
        Issuing turns the figures above into a <strong>numbered document that can no longer change</strong>. This is
        not the same thing as printing the page: a late entry dated inside the same period will appear above the
        moment it is posted, but it will never appear in a statement already issued. That refusal to move is the
        whole value of the document — without it, two copies of the same statement could disagree.
    </p>

    <?php if ($missingIdentity !== []): ?>
        <div class="cv-alert cv-alert--error">
            <strong>Statement identity details:</strong> these fields are still blank, so an issued document
            will show them as missing — <?= e(implode(', ', $missingIdentity)) ?>.
            Set them under <a href="/admin/settings/general">General Settings → Company Information</a>.
            They are saved onto the document as they stand at the moment you issue it, so filling them in afterwards
            will not change a statement that has already gone out.
        </div>
    <?php endif; ?>

    <?php if ($canIssueStatement): ?>
        <form method="post" action="/admin/resellers/<?= $clientId ?>/statement/issue"
              style="display:flex;gap:var(--cv-space-2);align-items:flex-end;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="from" value="<?= e($fromDate) ?>">
            <input type="hidden" name="to" value="<?= e($toDate) ?>">
            <button class="cv-btn" type="submit">Issue for <?= e($fromDate) ?> &ndash; <?= e($toDate) ?></button>
        </form>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-top:var(--cv-space-2);">
            Issuing the same period twice returns the statement that already exists rather than minting a second
            number, so a double click cannot put a gap in the sequence.
        </p>
    <?php else: ?>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
            You can review this live account view. Freezing a numbered statement additionally requires the
            <code>resellers.statements.issue</code> permission.
        </p>
    <?php endif; ?>

    <table class="cv-table" style="margin-top:var(--cv-space-3);">
        <thead>
        <tr><th>Number</th><th>Period</th><th>Closing</th><th>Issued</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($issued as $doc): ?>
            <tr>
                <td>
                    <a href="/admin/resellers/<?= $clientId ?>/statements/<?= (int) $doc['id'] ?>">
                        <strong><?= e((string) $doc['number']) ?></strong>
                    </a>
                </td>
                <td><?= e(substr((string) $doc['period_from'], 0, 10)) ?> &ndash; <?= e(substr((string) $doc['period_to'], 0, 10)) ?></td>
                <td><?= e($money((float) $doc['closing_base'], $baseCode)) ?></td>
                <td><?= e((string) $doc['issued_at']) ?></td>
                <td><a class="cv-btn" href="/admin/resellers/<?= $clientId ?>/statements/<?= (int) $doc['id'] ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($issued === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">
                Nothing has been issued to this store yet.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
