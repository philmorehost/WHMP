<?php
/** @var array<string, mixed> $service */
/** @var array<int, array<string, mixed>> $products */
/** @var array<string, string> $cycles */
/** @var array<string, string> $modes */
/** @var string|null $error */
$id = (int) $service['id'];
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= e($service['product_name']) ?></h1>
    <p><a href="/admin/services">&larr; Back to services</a></p>
    <p><strong>Client:</strong> <?= e(($service['first_name'] ?? '') . ' ' . ($service['last_name'] ?? '')) ?> (<?= e($service['client_email'] ?? '') ?>)</p>
    <p><strong>Cycle:</strong> <?= e($cycles[$service['billing_cycle']] ?? $service['billing_cycle']) ?> &middot; <strong>Amount:</strong> <?= e($service['currency_symbol'] ?? '$') ?><?= number_format((float) $service['amount'], 2) ?> (<?= e($service['currency_code'] ?? 'USD') ?>) &middot; <strong>Next Due:</strong> <?= e($service['next_due_date']) ?></p>
    <p><strong>Status:</strong> <?= e($service['status']) ?></p>
    <p><strong>Username:</strong> <?= e((string) ($service['username'] ?? '-')) ?></p>

    <?php if (!empty($service['provisioning_error'])): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);">Provisioning error: <?= e($service['provisioning_error']) ?></div>
        <form method="post" action="/admin/services/<?= $id ?>/retry-provisioning" style="margin-bottom:var(--cv-space-3);"><?= csrf_field() ?>
            <button class="cv-btn" type="submit">Retry Provisioning</button>
        </form>
    <?php endif; ?>

    <div style="display:flex;gap:var(--cv-space-2);margin-top:var(--cv-space-3);">
        <?php if ($service['status'] === 'pending'): ?>
            <form method="post" action="/admin/services/<?= $id ?>/retry-provisioning"><?= csrf_field() ?><button class="cv-btn" type="submit">Provision Now</button></form>
        <?php endif; ?>
        <?php if ($service['status'] === 'active'): ?>
            <form method="post" action="/admin/services/<?= $id ?>/suspend"><?= csrf_field() ?><button class="cv-btn cv-btn--danger" type="submit">Suspend</button></form>
        <?php elseif ($service['status'] === 'suspended'): ?>
            <form method="post" action="/admin/services/<?= $id ?>/unsuspend"><?= csrf_field() ?><button class="cv-btn" type="submit">Unsuspend</button></form>
        <?php endif; ?>
        <?php if ($service['status'] !== 'terminated'): ?>
            <form method="post" action="/admin/services/<?= $id ?>/terminate"><?= csrf_field() ?><button class="cv-btn cv-btn--danger" type="submit">Terminate</button></form>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Edit Service Details</h2>
    <form method="post" action="/admin/services/<?= $id ?>/edit"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Username / Service ID (Remote Hostname / Numeric ID)</label>
            <input class="cv-input" name="username" value="<?= e((string) ($service['username'] ?? '')) ?>" placeholder="e.g. cv123 or 5001">
        </div>
        <div class="cv-field">
            <label class="cv-label">Domain</label>
            <input class="cv-input" name="domain" value="<?= e((string) ($service['domain'] ?? '')) ?>" placeholder="example.com">
        </div>
        <div class="cv-field">
            <label class="cv-label">Hostname</label>
            <input class="cv-input" name="hostname" value="<?= e((string) ($service['hostname'] ?? '')) ?>" placeholder="vps.example.com">
        </div>
        <div class="cv-field">
            <label class="cv-label">Assigned Server</label>
            <select class="cv-select" name="server_id">
                <option value="">None — No Server Assigned</option>
                <?php foreach ($servers as $srv): ?>
                    <option value="<?= (int) $srv['id'] ?>" <?= ((int) ($service['server_id'] ?? 0) === (int) $srv['id']) ? 'selected' : '' ?>>
                        <?= e($srv['name']) ?> (<?= e($srv['module_slug']) ?><?= !empty($srv['group_name']) ? ' &bull; ' . e($srv['group_name']) : '' ?><?= !$srv['active'] ? ' &bull; Disabled' : '' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);display:block;margin-top:var(--cv-space-1);">
                Includes all VPS, Dedicated, and Shared Hosting servers configured in WHMP (<a href="/admin/servers" target="_blank" style="color:var(--cv-color-primary, #2563eb);text-decoration:underline;">Manage Servers &amp; Server Groups</a>).
            </span>
        </div>
        <button class="cv-btn" type="submit">Save Details</button>
    </form>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Upgrade / Downgrade</h2>
    <form method="post" action="/admin/services/<?= $id ?>/upgrade"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">New Product</label>
            <select class="cv-select" name="product_id" required>
                <option value="">Select a product</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?= (int) $product['id'] ?>"><?= e($product['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field">
            <label class="cv-label">Proration Mode</label>
            <select class="cv-select" name="proration_mode">
                <?php foreach ($modes as $modeKey => $label): ?>
                    <option value="<?= e($modeKey) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">The new product must have pricing set for this service's current billing cycle (<?= e($cycles[$service['billing_cycle']] ?? $service['billing_cycle']) ?>).</p>
        <button class="cv-btn" type="submit">Upgrade Service</button>
    </form>
</div>
