<?php
/** @var array<int, array<string, mixed>> $tickets */
/** @var array<int, array<string, mixed>> $departments */
/** @var string $statusFilter */
/** @var mixed $departmentFilter */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Support Tickets</h1>
    <p><a href="/admin">&larr; Back to dashboard</a> &middot; <a href="/admin/departments">Departments</a> &middot; <a href="/admin/canned-replies">Canned Replies</a></p>
    <div style="margin-top:var(--cv-space-2);">
        <a class="cv-btn <?= $statusFilter === '' ? '' : 'cv-btn--secondary' ?>" href="/admin/tickets">All</a>
        <a class="cv-btn <?= $statusFilter === 'open' ? '' : 'cv-btn--secondary' ?>" href="/admin/tickets?status=open">Open</a>
        <a class="cv-btn <?= $statusFilter === 'customer-reply' ? '' : 'cv-btn--secondary' ?>" href="/admin/tickets?status=customer-reply">Customer Reply</a>
        <a class="cv-btn <?= $statusFilter === 'answered' ? '' : 'cv-btn--secondary' ?>" href="/admin/tickets?status=answered">Answered</a>
        <a class="cv-btn <?= $statusFilter === 'closed' ? '' : 'cv-btn--secondary' ?>" href="/admin/tickets?status=closed">Closed</a>
    </div>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#tickets-table', 'placeholder' => 'Search tickets...']) ?>
    </div>
    <table class="cv-table" id="tickets-table">
        <thead><tr><th>#</th><th>Subject</th><th>Department</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Last Reply</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tickets as $ticket): ?>
            <tr>
                <td>#<?= (int) $ticket['id'] ?></td>
                <td><?= e($ticket['subject']) ?></td>
                <td><?= e($ticket['department_name']) ?></td>
                <td>
                    <?php if ($ticket['priority'] === 'high'): ?>
                        <span class="cv-badge cv-badge--danger">High</span>
                    <?php elseif ($ticket['priority'] === 'low'): ?>
                        <span class="cv-badge cv-badge--neutral">Low</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Medium</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($ticket['status'] === 'closed'): ?>
                        <span class="cv-badge cv-badge--neutral">Closed</span>
                    <?php elseif ($ticket['status'] === 'customer-reply'): ?>
                        <span class="cv-badge cv-badge--danger">Customer Reply</span>
                    <?php elseif ($ticket['status'] === 'answered'): ?>
                        <span class="cv-badge cv-badge--success">Answered</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--success">Open</span>
                    <?php endif; ?>
                </td>
                <td><?= e((string) ($ticket['assigned_admin_name'] ?? 'Unassigned')) ?></td>
                <td><?= e((string) ($ticket['last_reply_at'] ?? '-')) ?></td>
                <td><a class="cv-btn cv-btn--secondary" href="/admin/tickets/<?= (int) $ticket['id'] ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($tickets === []): ?>
            <tr><td colspan="8" style="color:var(--cv-text-secondary);">No tickets found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
