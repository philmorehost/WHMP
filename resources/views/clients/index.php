<?php
/** @var array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int} $results */
/** @var string $search */
$totalPages = max(1, (int) ceil($results['total'] / $results['perPage']));
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Clients</h1>
    <p><a href="/admin">&larr; Back to dashboard</a> &middot; <a href="/admin/client-groups">Manage Client Groups</a></p>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <form method="get" action="/admin/clients" style="display:flex;gap:var(--cv-space-2);">
            <input class="cv-input" type="text" name="q" value="<?= e($search) ?>" placeholder="Search name, email, or company" aria-label="Search clients">
            <button class="cv-btn cv-btn--secondary" type="submit">Search</button>
        </form>
        <a class="cv-btn cv-btn--secondary" href="/admin/clients/export?q=<?= urlencode($search) ?>">Export CSV</a>
        <a class="cv-btn" href="/admin/clients/create">Add Client</a>
    </div>

    <table class="cv-table">
        <thead><tr><th>Name</th><th>Email</th><th>Company</th><th>Group</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($results['data'] as $client): ?>
            <tr>
                <td><a href="/admin/clients/<?= (int) $client['id'] ?>"><?= e($client['first_name'] . ' ' . $client['last_name']) ?></a></td>
                <td><?= e($client['email']) ?></td>
                <td><?= e((string) ($client['company_name'] ?? '')) ?></td>
                <td><?= e((string) ($client['group_name'] ?? 'None')) ?></td>
                <td>
                    <?php if ($client['status'] === 'active'): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php elseif ($client['status'] === 'closed'): ?>
                        <span class="cv-badge cv-badge--danger">Closed</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Inactive</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($results['data'] === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No clients found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="cv-datatable__pagination">
        <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Page <?= $results['page'] ?> of <?= $totalPages ?> (<?= $results['total'] ?> total)</span>
        <?php if ($results['page'] > 1): ?>
            <a class="cv-btn cv-btn--secondary" href="/admin/clients?q=<?= urlencode($search) ?>&page=<?= $results['page'] - 1 ?>">Previous</a>
        <?php endif; ?>
        <?php if ($results['page'] < $totalPages): ?>
            <a class="cv-btn cv-btn--secondary" href="/admin/clients?q=<?= urlencode($search) ?>&page=<?= $results['page'] + 1 ?>">Next</a>
        <?php endif; ?>
    </div>
</div>
