<?php
/** @var array<int, array<string, mixed>> $requests */
/** @var string|null $error */
$hasPending = static function (string $type) use ($requests): bool {
    foreach ($requests as $r) {
        if ($r['type'] === $type && $r['status'] === 'pending') {
            return true;
        }
    }

    return false;
};
$badgeClass = static fn (string $status): string => match ($status) {
    'completed' => 'cv-badge--success',
    'rejected' => 'cv-badge--danger',
    default => 'cv-badge--neutral',
};
?>
<div class="cv-card" style="max-width:40rem;margin:var(--cv-space-6) auto;">
    <h1 class="cv-card__title">Privacy &amp; Your Data</h1>
    <p><a href="/client/account">&larr; Back to my account</a></p>
</div>

<?php if ($error !== null): ?>
    <div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Export My Data</h2>
    <p style="color:var(--cv-text-secondary);">Request a copy of everything we hold on you — profile, services, domains, invoices, tickets, and recent account activity.</p>
    <?php if ($hasPending('export')): ?>
        <p><span class="cv-badge cv-badge--neutral">Pending review</span></p>
    <?php else: ?>
        <form method="post" action="/client/account/privacy/export"><?= csrf_field() ?>
            <button class="cv-btn" type="submit">Request My Data</button>
        </form>
    <?php endif; ?>
</div>

<div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Delete My Account</h2>
    <p style="color:var(--cv-text-secondary);">Request that your personal details be erased. Financial records (invoices, transactions) are kept for legal/accounting reasons but will no longer identify you. This is reviewed by our team before it's processed, and your account will stop working once it is.</p>
    <?php if ($hasPending('erasure')): ?>
        <p><span class="cv-badge cv-badge--neutral">Pending review</span></p>
    <?php else: ?>
        <form method="post" action="/client/account/privacy/erasure" data-confirm="Request permanent deletion of your account and personal data? This cannot be undone once processed."><?= csrf_field() ?>
            <button class="cv-btn cv-btn--danger" type="submit">Request Account Deletion</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($requests !== []): ?>
    <div class="cv-card" style="max-width:40rem;margin:0 auto;">
        <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Your Requests</h2>
        <table class="cv-table">
            <thead>
                <tr><th>Type</th><th>Status</th><th>Requested</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td><?= e(ucfirst((string) $r['type'])) ?></td>
                        <td><span class="cv-badge <?= $badgeClass((string) $r['status']) ?>"><?= e(ucfirst((string) $r['status'])) ?></span></td>
                        <td><?= e((string) $r['created_at']) ?></td>
                        <td>
                            <?php if ($r['type'] === 'export' && $r['status'] === 'completed'): ?>
                                <a href="/client/account/privacy/export/<?= (int) $r['id'] ?>/download">Download</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
