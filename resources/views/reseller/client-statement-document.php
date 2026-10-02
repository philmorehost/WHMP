<?php
/**
 * ONE ISSUED STATEMENT, as the reseller received it.
 *
 * Rendered entirely from what was frozen at issue time — no ledger is read here,
 * and that is deliberate. The document has to be identical every time it is
 * opened, which is the only reason a statement is worth more than a screenshot.
 *
 * The issuer details come from the frozen snapshot rather than today's settings,
 * so a later change of our name, address or VAT number cannot restate a document
 * that has already been sent.
 *
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
    <?= $view->render('partials.reseller-nav') ?>
    <p style="color:var(--cv-text-secondary);">
        <strong><?= e((string) ($identity['legal_name'] ?? '') !== '' ? (string) $identity['legal_name'] : brand_name()) ?></strong>
        &middot; issued <?= e(substr((string) $document['issued_at'], 0, 10)) ?> &middot;
        covering <strong><?= e($fromDate) ?></strong> to <strong><?= e($toDate) ?></strong>
    </p>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== null && $notice !== ''): ?>
        <div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div>
    <?php endif; ?>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <table class="cv-table">
        <thead>
        <tr><th>From</th><th>To</th></tr>
        </thead>
        <tbody>
        <tr>
            <td>
                <strong><?= e((string) ($identity['legal_name'] ?? '') !== '' ? (string) $identity['legal_name'] : brand_name()) ?></strong>
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
                <strong><?= e((string) ($document['store']['brand_name'] ?? $document['store']['slug'] ?? 'your store')) ?></strong>
                <br><code><?= e((string) ($document['store']['slug'] ?? '')) ?></code>
            </td>
        </tr>
        </tbody>
    </table>
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
    <p style="color:var(--cv-text-secondary);">
        Your account is held in <?= e($baseCode) ?>, so these are the figures it actually holds. You carry the
        exchange movement between the day a customer pays and the day you are paid out, which is why the amount is
        not held in your own currency.
        <?php if ($theirCode !== '' && $theirCode !== $baseCode): ?>
            At the rate fixed when this statement was issued
            (<?= e(number_format((float) $document['currency_rate'], 6)) ?>) the closing balance is
            <strong><?= e($money((float) $document['closing_converted'], $theirCode)) ?></strong>, with
            <?= e($money((float) $document['withdrawable_converted'], $theirCode)) ?> withdrawable.
        <?php endif; ?>
    </p>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
        This statement is frozen: it will show these figures and these entries every time you open it. Your
        <a href="/client/reseller/account">account page</a> shows the current position instead.
    </p>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Entries as issued</h2>
    <table class="cv-table">
        <thead>
        <tr><th>When</th><th>Kind</th><th>Amount</th><th>Balance after</th><th>Withdrawable from</th><th>Description</th></tr>
        </thead>
        <tbody>
        <tr>
            <td colspan="3" style="color:var(--cv-text-secondary);">Opening balance carried in</td>
            <td><strong><?= e($money((float) $document['opening_base'], $baseCode)) ?></strong></td>
            <td colspan="2"></td>
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
                <td><?= e((string) ($line['description'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td colspan="3" style="color:var(--cv-text-secondary);">Closing balance carried out</td>
            <td><strong><?= e($money((float) $document['closing_base'], $baseCode)) ?></strong></td>
            <td colspan="2"></td>
        </tr>
        <?php if ($lines === []): ?>
            <tr><td colspan="6" style="color:var(--cv-text-secondary);">
                No entries fell in this period, so the closing balance is the opening one.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
