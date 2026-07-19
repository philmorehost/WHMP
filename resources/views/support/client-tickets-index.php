<?php
/** @var array<int, array<string, mixed>> $tickets */
?>
<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h1 class="cv-card__title">Support Tickets</h1>
    <p><a href="/client/dashboard">&larr; Back to dashboard</a> &middot; <a href="/client/tickets/create">Open New Ticket</a></p>

    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#my-tickets-table', 'placeholder' => 'Search tickets...']) ?>
    </div>
    <table class="cv-table" id="my-tickets-table">
        <thead><tr><th>#</th><th>Subject</th><th>Department</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tickets as $ticket): ?>
            <tr>
                <td>#<?= (int) $ticket['id'] ?></td>
                <td><?= e($ticket['subject']) ?></td>
                <td><?= e($ticket['department_name']) ?></td>
                <td>
                    <?php if ($ticket['status'] === 'closed'): ?>
                        <span class="cv-badge cv-badge--neutral">Closed</span>
                    <?php elseif ($ticket['status'] === 'customer-reply'): ?>
                        <span class="cv-badge cv-badge--success">Awaiting Support</span>
                    <?php elseif ($ticket['status'] === 'answered'): ?>
                        <span class="cv-badge cv-badge--danger">Answered — Reply Needed</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--success">Open</span>
                    <?php endif; ?>
                </td>
                <td><a class="cv-btn cv-btn--secondary" href="/client/tickets/<?= (int) $ticket['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($tickets === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No tickets yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
