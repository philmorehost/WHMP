<?php
/** @var array<int, array<string, mixed>> $requests */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Pending Cancellation Requests</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead>
            <tr>
                <th>Service ID</th>
                <th>Client</th>
                <th>Product</th>
                <th>Domain/Hostname</th>
                <th>Next Due Date</th>
                <th>Request Date</th>
                <th>Reason</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($requests as $request): ?>
                <tr>
                    <td>
                        <a href="/admin/services/<?= (int) $request['service_id'] ?>" style="font-weight:bold;">
                            #<?= (int) $request['service_id'] ?>
                        </a>
                    </td>
                    <td>
                        <?= e($request['first_name'] . ' ' . $request['last_name']) ?><br>
                        <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);"><?= e($request['email']) ?></span>
                    </td>
                    <td><?= e($request['product_name']) ?></td>
                    <td><?= e($request['domain'] ?: $request['hostname'] ?: '-') ?></td>
                    <td><?= e($request['next_due_date']) ?></td>
                    <td><?= e($request['created_at']) ?></td>
                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= e((string) $request['reason']) ?>">
                        <?= e($request['reason'] ?: '-') ?>
                    </td>
                    <td>
                        <form method="post" action="/admin/cancellations/<?= (int) $request['id'] ?>/process" data-confirm="Are you sure you want to terminate this service immediately? This action is irreversible.">
                            <?= csrf_field() ?>
                            <button type="submit" class="cv-btn cv-btn--danger" style="font-size:var(--cv-text-xs);padding:var(--cv-space-1) var(--cv-space-2);">
                                Terminate Now
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($requests === []): ?>
                <tr>
                    <td colspan="8" style="color:var(--cv-text-secondary);text-align:center;">
                        No pending cancellation requests found.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
