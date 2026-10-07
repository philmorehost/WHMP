<?php
/**
 * @var array<int, array<string, mixed>> $events
 * @var string $q
 * @var int $pending
 */
?>
<div class="uca">
    <div class="uca-head">
        <div>
            <h1>Audit log</h1>
            <p>Every step of every request, with who did it and from where.</p>
        </div>
    </div>
    <?= $view->render('username-changer.admin-tabs', ['pending' => $pending, 'notice' => $notice ?? null, 'error' => $error ?? null]) ?>
    <div class="uca-card">
        <form class="uca-filter" method="get" action="/admin/username-changer/audit">
            <input class="cv-input" type="search" name="q" value="<?= e($q) ?>" placeholder="Username, event, IP or service ID">
            <button class="cv-btn cv-btn--secondary" type="submit">Search</button>
        </form>
        <?php if ($events === []): ?>
            <p style="color:var(--cv-text-secondary)">No events<?= $q !== '' ? ' match your search' : ' yet' ?>.</p>
        <?php else: ?>
            <div style="overflow-x:auto">
            <table class="uca-table">
                <thead><tr><th>When</th><th>Request</th><th>Event</th><th>Actor</th><th>IP</th><th>Detail</th></tr></thead>
                <tbody>
                <?php foreach ($events as $ev): ?>
                    <tr>
                        <td><small><?= e((string) $ev['created_at']) ?></small></td>
                        <td><a href="/admin/username-changer/requests/<?= (int) $ev['request_id'] ?>">#<?= (int) $ev['request_id'] ?></a> <span class="uca-mono"><?= e((string) $ev['old_username']) ?> → <?= e((string) $ev['new_username']) ?></span></td>
                        <td><?= e(str_replace('_', ' ', (string) $ev['event'])) ?></td>
                        <td><?= e((string) $ev['actor_type']) ?><?= $ev['actor_id'] ? ' #' . (int) $ev['actor_id'] : '' ?></td>
                        <td><small><?= e((string) ($ev['ip'] ?? '')) ?></small></td>
                        <td><small><?= e((string) ($ev['detail'] ?? '')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>
