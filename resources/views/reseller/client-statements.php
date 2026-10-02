<?php
/**
 * The statements this reseller has been issued.
 *
 * A list, not a document: it exists so a reseller can find "the one for August"
 * without knowing its number, and so the numbers themselves are visible in one
 * place. Seeing the sequence is most of the point of a sequence — a gap is
 * something the reader can notice, which is exactly what makes it evidence.
 *
 * @var array<int, array<string, mixed>> $issued
 * @var string $baseCode
 * @var string|null $notice
 * @var string|null $error
 */

$money = static fn (float $amount, string $code): string => number_format($amount, 2) . ($code === '' ? '' : ' ' . $code);
$issued = is_array($issued ?? null) ? $issued : [];
?>

<div class="cv-card">
    <header class="rs-head">
        <h1 class="rs-head__title">Your statements</h1>
    </header>
    <?= $view->render('partials.reseller-nav') ?>

    <p style="color:var(--cv-text-secondary);">
        A statement is a frozen copy of your account for a period. Unlike your account page, which always shows the
        current position, an issued statement never changes afterwards — so the copy you keep and the copy we hold
        always agree. If something is later added to a period you already have a statement for, it appears in your
        account and in the next statement, not in the one you already hold.
    </p>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== null && $notice !== ''): ?>
        <div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div>
    <?php endif; ?>

    <table class="cv-table">
        <thead>
        <tr><th>Statement</th><th>Period</th><th>Closing balance</th><th>Issued</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($issued as $doc): ?>
            <tr>
                <td>
                    <a href="/client/reseller/statements/<?= (int) $doc['id'] ?>">
                        <strong><?= e((string) $doc['number']) ?></strong>
                    </a>
                </td>
                <td>
                    <?= e(substr((string) $doc['period_from'], 0, 10)) ?>
                    &ndash;
                    <?= e(substr((string) $doc['period_to'], 0, 10)) ?>
                </td>
                <td><?= e($money((float) $doc['closing_base'], $baseCode)) ?></td>
                <td><?= e(substr((string) $doc['issued_at'], 0, 10)) ?></td>
                <td><a class="cv-btn" href="/client/reseller/statements/<?= (int) $doc['id'] ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($issued === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">
                You have not been issued a statement yet. One appears here once it has been issued for a period —
                there is nothing you need to do to request it.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
