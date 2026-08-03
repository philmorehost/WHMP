<?php
/** @var array<int, array<string, mixed>> $results */
/** @var array{total: int, invalid: int, lastScanAt: ?string} $summary */
/** @var string|null $scanned */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Email Validation</h1>
    <p><a href="/admin/clients">&larr; Back to clients</a></p>
</div>

<?php if ($scanned !== null): ?>
    <?php [$invalidCount, $totalCount] = array_map('intval', explode('-', $scanned) + [0, 0]); ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-3);">
        Scanned <?= $totalCount ?> client email(s) — found <?= $invalidCount ?> that look invalid.
    </div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-top:0;">
        Checks every active client's email for two things: whether the domain has anywhere to receive mail at all
        (a DNS lookup only — nothing is emailed, so scanning never generates a bounce itself), and whether that exact
        address has actually bounced recently according to your own email history. Run this before a marketing send
        to catch dead addresses ahead of time, instead of finding out from a mail-daemon bounce that turns into a
        support ticket.
    </p>
    <div style="display:flex;align-items:center;gap:var(--cv-space-4);flex-wrap:wrap;">
        <form method="post" action="/admin/email-validation/scan" data-confirm="Scan every active client's email now? This runs a DNS lookup per client and may take a moment for a large client base.">
            <?= csrf_field() ?>
            <button class="cv-btn" type="submit">🔍 Scan All Client Emails</button>
        </form>
        <?php if ($summary['lastScanAt'] !== null): ?>
            <span style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);">
                Last scan: <?= e((string) $summary['lastScanAt']) ?> &middot;
                <?= (int) $summary['invalid'] ?> of <?= (int) $summary['total'] ?> flagged
            </span>
        <?php endif; ?>
    </div>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Client</th><th>Email</th><th>Status</th><th>Reason</th><th>Checked</th></tr></thead>
        <tbody>
        <?php foreach ($results as $row): ?>
            <tr>
                <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                <td><?= e($row['email']) ?></td>
                <td>
                    <?php if ((int) $row['is_valid'] === 1): ?>
                        <span class="cv-badge cv-badge--success">OK</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">Invalid</span>
                    <?php endif; ?>
                </td>
                <td><?= e((string) ($row['reason'] ?? '—')) ?></td>
                <td style="white-space:nowrap;"><?= e((string) $row['checked_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($results === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No scan has been run yet — click "Scan All Client Emails" above.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
