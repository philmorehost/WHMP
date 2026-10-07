<?php
/**
 * @var array<string, mixed> $r findDetailed()
 * @var array<string, mixed>|null $store
 * @var array<int, array<string, mixed>> $events
 * @var int $pending
 */
use CodeVault\UsernameChanger\UsernameChangeNotifier;

$id = (int) $r['id'];
$status = (string) $r['status'];
$act = static fn (string $action, string $label, string $class = 'cv-btn', string $extra = ''): string =>
    '<form method="post" action="/admin/username-changer/requests/' . $id . '/' . $action . '">' . csrf_field() . $extra
    . '<button class="' . $class . ' uca-btn-sm" type="submit">' . e($label) . '</button></form>';
?>
<div class="uca">
    <div class="uca-head">
        <div>
            <h1>Request #<?= $id ?> <span class="uca-pill uca-pill--<?= e($status) ?>"><?= e(UsernameChangeNotifier::statusLabel($status)) ?></span>
                <?php if ($r['sync_state'] === 'mismatch'): ?><span class="uca-pill uca-pill--mismatch">sync problem</span><?php endif; ?></h1>
            <p class="uca-mono"><?= e((string) $r['old_username']) ?> → <strong><?= e((string) $r['new_username']) ?></strong></p>
        </div>
        <div class="uca-inline">
            <?php if ($status === 'pending_approval'): ?>
                <?= $act('approve', 'Approve') ?>
                <?= $act('decline', 'Decline', 'cv-btn cv-btn--secondary', '<input class="cv-input uca-btn-sm" name="reason" placeholder="Reason (client sees it)" required style="width:200px">') ?>
            <?php endif; ?>
            <?php if ($status === 'failed'): ?><?= $act('retry', 'Retry now') ?><?php endif; ?>
            <?php if ($status === 'awaiting_payment'): ?><?= $act('waive', 'Waive payment & run', 'cv-btn cv-btn--secondary') ?><?php endif; ?>
            <?php if ($r['sync_state'] === 'mismatch'): ?><?= $act('sync', 'Sync from server') ?><?php endif; ?>
            <?php if (in_array($status, ['awaiting_confirmation', 'pending_approval', 'awaiting_payment', 'queued', 'failed'], true)): ?><?= $act('cancel', 'Cancel', 'cv-btn cv-btn--secondary') ?><?php endif; ?>
        </div>
    </div>
    <?= $view->render('username-changer.admin-tabs', ['pending' => $pending, 'notice' => $notice ?? null, 'error' => $error ?? null]) ?>

    <div class="uca-split">
        <div>
            <div class="uca-card">
                <h2>Details</h2>
                <dl class="uca-dl">
                    <?php $clientUrl = $store === null ? '/admin/clients/' . (int) $r['client_id'] : '/admin/resellers/' . (int) $store['client_id'] . '/customers/' . (int) $r['client_id']; ?>
                    <dt>Client</dt><dd><a href="<?= e($clientUrl) ?>"><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></a> · <?= e((string) $r['client_email']) ?></dd>
                    <dt>Store</dt><dd><?php if ($store === null): ?>Platform customer<?php else: ?><a href="/admin/resellers/<?= (int) $store['client_id'] ?>/store"><?= e((string) ($store['brand_name'] ?: $store['slug'])) ?></a> (reseller ID <?= (int) $store['id'] ?>)<?php endif; ?></dd>
                    <dt>Service</dt><dd><a href="/admin/services/<?= (int) $r['service_id'] ?>">#<?= (int) $r['service_id'] ?> — <?= e((string) ($r['domain'] ?? '')) ?></a> (<?= e((string) $r['service_status']) ?>)</dd>
                    <dt>Product</dt><dd><?= e((string) ($r['product_name'] ?? '—')) ?></dd>
                    <dt>Server</dt><dd><?= e((string) ($r['server_name'] ?? '—')) ?> <small>(<?= e((string) ($r['server_hostname'] ?? '')) ?>)</small></dd>
                    <dt>Current username</dt><dd class="uca-mono"><?= e((string) $r['current_username']) ?></dd>
                    <dt>Rename databases</dt><dd><?= (int) $r['rename_db_objects'] === 1 ? 'Yes' : 'No' ?></dd>
                    <dt>Reason</dt><dd><?= e((string) ($r['reason'] ?: '—')) ?></dd>
                    <dt>Requested by</dt><dd><?= e((string) $r['requested_by_type']) ?> #<?= (int) $r['requested_by_id'] ?> from <?= e((string) ($r['ip'] ?? '—')) ?></dd>
                    <dt>Confirmation</dt><dd><?= e((string) ($r['confirm_method'] ?? '—')) ?><?= $r['confirmed_at'] ? ' · ' . e((string) $r['confirmed_at']) : '' ?></dd>
                    <?php if ($r['decided_at']): ?><dt>Decision</dt><dd><?= e((string) $r['decided_by_type']) ?> #<?= (int) $r['decided_by_id'] ?> · <?= e((string) $r['decided_at']) ?><?= $r['decline_reason'] ? ' — ' . e((string) $r['decline_reason']) : '' ?></dd><?php endif; ?>
                    <?php if ($r['invoice_id'] !== null): ?>
                        <dt>Fee</dt><dd><a href="/admin/invoices/<?= (int) $r['invoice_id'] ?>">Invoice #<?= (int) $r['invoice_id'] ?></a> · <?= e(number_format((float) $r['fee_amount'], 2)) ?><?= $r['paid_at'] ? ' · paid ' . e((string) $r['paid_at']) : '' ?></dd>
                        <?php if ($r['fee_cost'] !== null): ?>
                            <dt>Split (catalog)</dt><dd>Store price <?= e(number_format((float) $r['fee_retail'], 2)) ?> · store cost <?= e(number_format((float) $r['fee_cost'], 2)) ?><?= $r['fee_upline_cost'] !== null ? ' · upline cost ' . e(number_format((float) $r['fee_upline_cost'], 2)) : '' ?><?= $r['fee_credited_at'] ? ' · credited ' . e((string) $r['fee_credited_at']) : '' ?><?= $r['fee_reversed_at'] ? ' · reversed ' . e((string) $r['fee_reversed_at']) : '' ?></dd>
                        <?php endif; ?>
                    <?php endif; ?>
                    <dt>Attempts</dt><dd><?= (int) $r['attempts'] ?><?= $r['next_attempt_at'] ? ' · next ' . e((string) $r['next_attempt_at']) : '' ?></dd>
                    <dt>Created / completed</dt><dd><?= e((string) $r['created_at']) ?> / <?= e((string) ($r['completed_at'] ?? '—')) ?></dd>
                </dl>
            </div>
            <?php if (!empty($r['last_error']) || !empty($r['server_response'])): ?>
                <div class="uca-card">
                    <h2>Server error</h2>
                    <?php if (!empty($r['last_error'])): ?><p style="color:#b91c1c"><?= e((string) $r['last_error']) ?></p><?php endif; ?>
                    <?php if (!empty($r['server_response'])): ?><div class="uca-pre"><?= e((string) $r['server_response']) ?></div><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="uca-card">
            <h2>Timeline</h2>
            <ul class="uca-timeline">
                <?php foreach ($events as $ev): ?>
                    <li><strong><?= e(str_replace('_', ' ', (string) $ev['event'])) ?></strong> <small>· <?= e((string) $ev['actor_type']) ?><?= $ev['actor_id'] ? ' #' . (int) $ev['actor_id'] : '' ?><?= $ev['ip'] ? ' · ' . e((string) $ev['ip']) : '' ?> · <?= e((string) $ev['created_at']) ?></small>
                        <?php if (!empty($ev['detail'])): ?><br><small><?= e((string) $ev['detail']) ?></small><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
