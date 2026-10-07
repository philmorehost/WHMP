<?php
/**
 * @var array<string, int> $counts
 * @var array{today: int, week: int, completedWeek: int, mismatch: int} $stats
 * @var array<int, array<string, mixed>> $rows
 * @var string $status
 * @var string $q
 * @var int $page
 * @var bool $feeEnabled
 * @var int $pending
 */
use CodeVault\UsernameChanger\UsernameChangeNotifier;

$filters = ['open' => 'Open', 'pending_approval' => 'Awaiting approval', 'awaiting_payment' => 'Awaiting payment', 'queued' => 'Queued', 'failed' => 'Failed', 'mismatch' => 'Sync problems', 'completed' => 'Completed', 'all' => 'All'];
$back = '/admin/username-changer?' . http_build_query(['status' => $status, 'q' => $q]);
?>
<div class="uca">
    <div class="uca-head">
        <div>
            <h1>cPanel Username Changer</h1>
            <p>Every request from your customers and every reseller store's customers. Payment is <strong><?= $feeEnabled ? 'ON' : 'OFF' ?></strong>.</p>
        </div>
        <a class="cv-btn" href="/admin/username-changer/manual">Manual change</a>
    </div>
    <?= $view->render('username-changer.admin-tabs', ['pending' => $pending, 'notice' => $notice ?? null, 'error' => $error ?? null]) ?>

    <div class="uca-stats">
        <div class="uca-stat" style="--uca-c:#d97706"><b><?= (int) ($counts['pending_approval'] ?? 0) ?></b><span>Awaiting approval</span></div>
        <div class="uca-stat" style="--uca-c:#7c3aed"><b><?= (int) ($counts['awaiting_payment'] ?? 0) ?></b><span>Awaiting payment</span></div>
        <div class="uca-stat" style="--uca-c:#2563eb"><b><?= (int) (($counts['queued'] ?? 0) + ($counts['processing'] ?? 0)) ?></b><span>Queued / running</span></div>
        <div class="uca-stat" style="--uca-c:#dc2626"><b><?= (int) ($counts['failed'] ?? 0) + (int) $stats['mismatch'] ?></b><span>Failed / sync problems</span></div>
        <div class="uca-stat" style="--uca-c:#16a34a"><b><?= (int) $stats['completedWeek'] ?></b><span>Completed (7 days)</span></div>
        <div class="uca-stat"><b><?= (int) $stats['today'] ?> / <?= (int) $stats['week'] ?></b><span>New today / 7 days</span></div>
    </div>

    <div class="uca-card">
        <form class="uca-filter" method="get" action="/admin/username-changer">
            <select class="cv-input" name="status">
                <?php foreach ($filters as $k => $label): ?>
                    <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <input class="cv-input" type="search" name="q" value="<?= e($q) ?>" placeholder="Username, domain, email, #id">
            <button class="cv-btn cv-btn--secondary" type="submit">Filter</button>
        </form>

        <div style="overflow-x:auto">
        <table class="uca-table">
            <thead><tr><th>#</th><th>Client</th><th>Service</th><th>Change</th><th>Status</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--cv-text-secondary);padding:24px">No requests match.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a href="/admin/username-changer/requests/<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
                    <td><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?><br><small style="color:var(--cv-text-secondary)"><?= e((string) $r['client_email']) ?><?= $r['reseller_id'] !== null ? ' · store #' . (int) $r['reseller_id'] : '' ?></small></td>
                    <td><a href="/admin/services/<?= (int) $r['service_id'] ?>"><?= e((string) ($r['domain'] ?: '#' . $r['service_id'])) ?></a></td>
                    <td class="uca-mono"><?= e((string) $r['old_username']) ?> → <strong><?= e((string) $r['new_username']) ?></strong></td>
                    <td>
                        <span class="uca-pill uca-pill--<?= e((string) $r['status']) ?>"><?= e(UsernameChangeNotifier::statusLabel((string) $r['status'])) ?></span>
                        <?php if ($r['sync_state'] === 'mismatch'): ?><span class="uca-pill uca-pill--mismatch">sync problem</span><?php endif; ?>
                    </td>
                    <td><small><?= e(substr((string) $r['created_at'], 0, 16)) ?></small></td>
                    <td>
                        <div class="uca-inline">
                            <?php if ($r['status'] === 'pending_approval'): ?>
                                <form method="post" action="/admin/username-changer/requests/<?= (int) $r['id'] ?>/approve"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><button class="cv-btn uca-btn-sm" type="submit">Approve</button></form>
                                <form method="post" action="/admin/username-changer/requests/<?= (int) $r['id'] ?>/decline"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><input class="cv-input uca-btn-sm" name="reason" placeholder="Reason" required style="width:130px"><button class="cv-btn cv-btn--secondary uca-btn-sm" type="submit">Decline</button></form>
                            <?php elseif ($r['status'] === 'failed'): ?>
                                <form method="post" action="/admin/username-changer/requests/<?= (int) $r['id'] ?>/retry"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><button class="cv-btn uca-btn-sm" type="submit">Retry</button></form>
                            <?php else: ?>
                                <a class="cv-btn cv-btn--secondary uca-btn-sm" href="/admin/username-changer/requests/<?= (int) $r['id'] ?>">Open</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="uca-inline" style="margin-top:12px">
            <?php if ($page > 1): ?><a class="cv-btn cv-btn--secondary uca-btn-sm" href="?<?= e(http_build_query(['status' => $status, 'q' => $q, 'page' => $page - 1])) ?>">← Newer</a><?php endif; ?>
            <?php if (count($rows) === 50): ?><a class="cv-btn cv-btn--secondary uca-btn-sm" href="?<?= e(http_build_query(['status' => $status, 'q' => $q, 'page' => $page + 1])) ?>">Older →</a><?php endif; ?>
        </div>
    </div>
</div>
