<?php
/** @var array<int, array<string, mixed>> $requests */
/** @var string $status */
/** @var array{pending: int, approved: int, rejected: int, completed: int} $counts */
/** @var string $notice */

$serviceBadge = static function (string $s): string {
    return match ($s) {
        'active' => '<span class="cv-badge cv-badge--success">' . htmlspecialchars($s) . '</span>',
        'cancelled', 'terminated' => '<span class="cv-badge cv-badge--danger">' . htmlspecialchars($s) . '</span>',
        'suspended' => '<span class="cv-badge" style="background:rgba(245,158,11,.16);color:#d97706;">suspended</span>',
        default => '<span class="cv-badge cv-badge--neutral">' . htmlspecialchars($s !== '' ? $s : 'unknown') . '</span>',
    };
};
$requestBadge = static function (string $s): string {
    return match ($s) {
        'pending' => '<span class="cv-badge" style="background:rgba(59,130,246,.16);color:#3b82f6;">Pending</span>',
        'approved' => '<span class="cv-badge" style="background:rgba(245,158,11,.16);color:#d97706;">Approved</span>',
        'rejected' => '<span class="cv-badge cv-badge--danger">Rejected</span>',
        'completed' => '<span class="cv-badge cv-badge--success">Completed</span>',
        default => '<span class="cv-badge cv-badge--neutral">' . htmlspecialchars($s) . '</span>',
    };
};
$tabs = ['pending' => '⏳ Pending', 'approved' => '✓ Approved', 'rejected' => '✕ Rejected', 'completed' => '✔ Completed'];
?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Cancellation Requests</h1>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin:0;">
        Review and process client cancellation requests. Approving an immediate cancellation cancels the service right away;
        approving a due-date request schedules it. If the service is already cancelled, approval marks the request completed automatically.
    </p>
</div>

<?php if ($notice !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e($notice) ?></div>
<?php endif; ?>

<div style="display:flex;gap:8px;margin-bottom:var(--cv-space-4);flex-wrap:wrap;">
    <?php foreach ($tabs as $key => $label): ?>
        <?php
        $active = $status === $key;
        $tint = $key === 'pending' ? '#3b82f6' : ($key === 'approved' ? '#d97706' : ($key === 'rejected' ? '#ef4444' : '#22c55e'));
        ?>
        <a href="?status=<?= $key ?>" style="padding:10px 16px;background:<?= $active ? $tint : 'var(--cv-bg-surface)' ?>;color:<?= $active ? '#fff' : 'var(--cv-text-primary)' ?>;border-radius:8px;text-decoration:none;font-weight:600;border:1px solid <?= $active ? $tint : 'var(--cv-border-default)' ?>;font-size:.9rem;">
            <?= $label ?> <span style="opacity:.8;">(<?= (int) ($counts[$key] ?? 0) ?>)</span>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($requests === []): ?>
    <div class="cv-card" style="padding:40px;text-align:center;">
        <p style="color:var(--cv-text-secondary);margin:0;">No <?= $status ?> cancellation requests.</p>
    </div>
<?php else: ?>
    <div class="cv-card" style="padding:0;overflow:hidden;">
        <div style="overflow-x:auto;">
            <table class="cv-table" style="min-width:900px;">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Service</th>
                        <th>Cancellation</th>
                        <th>Reason</th>
                        <th>Requested</th>
                        <th>Status</th>
                        <th style="min-width:150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $req): ?>
                    <tr>
                        <td>
                            <strong><?= e(trim((string) ($req['first_name'] ?? '') . ' ' . (string) ($req['last_name'] ?? ''))) ?></strong>
                            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);"><?= e((string) ($req['email'] ?? '')) ?></div>
                        </td>
                        <td>
                            <a href="/admin/services/<?= (int) $req['service_id'] ?>" style="font-weight:600;"><?= e((string) ($req['product_name'] ?? 'Service #' . (int) $req['service_id'])) ?></a>
                            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);">#<?= (int) $req['service_id'] ?> <?= $serviceBadge((string) ($req['service_status'] ?? '')) ?></div>
                        </td>
                        <td>
                            <?php if (($req['cancellation_type'] ?? 'immediate') === 'immediate'): ?>
                                <span class="cv-badge cv-badge--danger">⚡ Immediate</span>
                            <?php else: ?>
                                <span class="cv-badge" style="background:rgba(245,158,11,.16);color:#d97706;">📅 Due date</span>
                                <?php if (!empty($req['cancel_date'])): ?>
                                    <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:4px;">on <?= e((string) $req['cancel_date']) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td style="max-width:220px;">
                            <span title="<?= e((string) ($req['reason'] ?? '')) ?>" style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);"><?= e(mb_strimwidth((string) ($req['reason'] ?? '-'), 0, 60, '…')) ?></span>
                            <?php if (!empty($req['admin_notes'])): ?>
                                <div style="font-size:var(--cv-text-xs);color:var(--cv-text-tertiary);margin-top:4px;">Admin: <?= e((string) $req['admin_notes']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);">
                            <?= e((string) ($req['created_at'] ?? '')) ?>
                            <?php if (!empty($req['reviewed_at'])): ?>
                                <div style="font-size:var(--cv-text-xs);">
                                    Reviewed <?= e((string) ($req['reviewed_by_name'] ?? 'by admin')) ?> · <?= e((string) $req['reviewed_at']) ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($req['completed_at'])): ?>
                                <div style="font-size:var(--cv-text-xs);">Completed <?= e((string) $req['completed_at']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= $requestBadge((string) ($req['status'] ?? '')) ?></td>
                        <td>
                            <?php if (($req['status'] ?? '') === 'pending'): ?>
                                <form method="post" action="/admin/cancellations/<?= (int) $req['id'] ?>/approve" style="display:inline;margin-right:6px;">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="cv-btn" style="background:#22c55e;color:#fff;border:none;padding:6px 12px;font-size:.75rem;">✓ Approve</button>
                                </form>

                                <details style="display:inline-block;vertical-align:middle;">
                                    <summary style="display:inline-block;cursor:pointer;padding:6px 12px;background:#ef4444;color:#fff;border-radius:6px;font-weight:600;font-size:.75rem;border:none;list-style:none;">✕ Reject</summary>
                                    <form method="post" action="/admin/cancellations/<?= (int) $req['id'] ?>/reject" style="margin-top:8px;padding:12px;border:1px solid var(--cv-border-default);border-radius:8px;background:var(--cv-bg-surface-sunken);max-width:280px;">
                                        <?= csrf_field() ?>
                                        <textarea name="notes" required placeholder="Reason for rejection…" class="cv-input" style="width:100%;min-height:60px;box-sizing:border-box;"></textarea>
                                        <button type="submit" class="cv-btn" style="background:#ef4444;color:#fff;border:none;padding:6px 12px;font-size:.75rem;margin-top:6px;">Reject</button>
                                    </form>
                                </details>

                            <?php elseif (($req['status'] ?? '') === 'approved' && in_array((string) ($req['service_status'] ?? ''), ['cancelled', 'terminated'], true)): ?>
                                <form method="post" action="/admin/cancellations/<?= (int) $req['id'] ?>/complete" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="cv-btn" style="background:#8b5cf6;color:#fff;border:none;padding:6px 12px;font-size:.75rem;" data-confirm="Mark this cancellation request as completed?">✔ Mark Completed</button>
                                </form>
                            <?php else: ?>
                                <span style="color:var(--cv-text-tertiary);font-size:var(--cv-text-xs);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
