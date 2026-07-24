<?php
/** @var array<int, array<string, mixed>> $requests */
/** @var string $status */
?>
<div style="background:linear-gradient(135deg,#1e293b,#0f172a);padding:48px 40px;margin-bottom:32px;border-radius:16px;position:relative;">
    <a href="/admin" style="color:#3b82f6;text-decoration:none;font-weight:600;font-size:.9rem;">← Back to Dashboard</a>
    <h1 style="font-size:2rem;font-weight:900;color:#fff;margin:12px 0 0;font-family:'Hanken Grotesk';">Cancellation Requests</h1>
</div>

<div style="display:flex;gap:8px;margin-bottom:24px;">
    <a href="?status=pending" style="padding:10px 16px;background:<?= $status==='pending'?'#3b82f6':'var(--cv-bg-surface)' ?>;color:<?= $status==='pending'?'white':'var(--cv-text-primary)' ?>;border-radius:6px;text-decoration:none;font-weight:600;border:1px solid <?= $status==='pending'?'#3b82f6':'var(--cv-border-default)' ?>;">⏳ Pending</a>
    <a href="?status=approved" style="padding:10px 16px;background:<?= $status==='approved'?'#10b981':'var(--cv-bg-surface)' ?>;color:<?= $status==='approved'?'white':'var(--cv-text-primary)' ?>;border-radius:6px;text-decoration:none;font-weight:600;border:1px solid <?= $status==='approved'?'#10b981':'var(--cv-border-default)' ?>;">✓ Approved</a>
    <a href="?status=rejected" style="padding:10px 16px;background:<?= $status==='rejected'?'#ef4444':'var(--cv-bg-surface)' ?>;color:<?= $status==='rejected'?'white':'var(--cv-text-primary)' ?>;border-radius:6px;text-decoration:none;font-weight:600;border:1px solid <?= $status==='rejected'?'#ef4444':'var(--cv-border-default)' ?>;">✕ Rejected</a>
    <a href="?status=completed" style="padding:10px 16px;background:<?= $status==='completed'?'#8b5cf6':'var(--cv-bg-surface)' ?>;color:<?= $status==='completed'?'white':'var(--cv-text-primary)' ?>;border-radius:6px;text-decoration:none;font-weight:600;border:1px solid <?= $status==='completed'?'#8b5cf6':'var(--cv-border-default)' ?>;">✔ Completed</a>
</div>

<?php if (empty($requests)): ?>
    <div style="background:var(--cv-bg-surface);padding:40px;border-radius:12px;text-align:center;border:1px solid var(--cv-border-default);">
        <p style="color:var(--cv-text-secondary);">No <?= $status ?> cancellation requests</p>
    </div>
<?php else: ?>
    <div style="background:var(--cv-bg-surface);border:1px solid var(--cv-border-default);border-radius:12px;overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
            <thead style="background:linear-gradient(135deg,var(--cv-bg-surface-sunken),var(--cv-bg-surface));border-bottom:2px solid var(--cv-border-default);">
                <tr>
                    <th style="padding:16px;text-align:left;font-weight:700;color:var(--cv-text-secondary);font-size:.8rem;text-transform:uppercase;">Client</th>
                    <th style="padding:16px;text-align:left;font-weight:700;color:var(--cv-text-secondary);font-size:.8rem;text-transform:uppercase;">Service</th>
                    <th style="padding:16px;text-align:left;font-weight:700;color:var(--cv-text-secondary);font-size:.8rem;text-transform:uppercase;">Type</th>
                    <th style="padding:16px;text-align:left;font-weight:700;color:var(--cv-text-secondary);font-size:.8rem;text-transform:uppercase;">Reason</th>
                    <th style="padding:16px;text-align:left;font-weight:700;color:var(--cv-text-secondary);font-size:.8rem;text-transform:uppercase;">Requested</th>
                    <th style="padding:16px;text-align:left;font-weight:700;color:var(--cv-text-secondary);font-size:.8rem;text-transform:uppercase;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($requests as $req): ?>
                <tr style="border-bottom:1px solid var(--cv-border-default);">
                    <td style="padding:16px;"><strong><?= e($req['first_name']??'') . ' ' . e($req['last_name']??'') ?></strong><br><small style="color:var(--cv-text-secondary);"><?= e($req['email']??'') ?></small></td>
                    <td style="padding:16px;"><?= e($req['product_name']??'') ?></td>
                    <td style="padding:16px;"><?= $req['cancellation_type']==='immediate'?'⚡ Immediate':'📅 Due Date' ?></td>
                    <td style="padding:16px;color:var(--cv-text-secondary);"><?= e(substr($req['reason']??'', 0, 50)) ?></td>
                    <td style="padding:16px;font-size:.85rem;color:var(--cv-text-secondary);"><?= e($req['created_at']??'') ?></td>
                    <td style="padding:16px;">
                        <?php if ($status === 'pending'): ?>
                            <form method="post" action="/admin/cancellations/<?= (int)$req['id'] ?>/approve" style="display:inline;margin-right:8px;">
                                <button type="submit" style="padding:6px 12px;background:#10b981;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:600;font-size:.75rem;">✓ Approve</button>
                            </form>
                            <form method="post" action="/admin/cancellations/<?= (int)$req['id'] ?>/reject" style="display:inline;">
                                <input type="hidden" name="notes" value="Rejected by admin">
                                <button type="submit" style="padding:6px 12px;background:#ef4444;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:600;font-size:.75rem;">✕ Reject</button>
                            </form>
                        <?php else: ?>
                            <small style="color:var(--cv-text-secondary);">—</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
