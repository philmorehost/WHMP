<?php
/** @var array<int, array<string, mixed>> $servers */
/** @var array<string, array> $grouped */
/** @var array<string, array> $duplicates */
/** @var array $recommendations */
?>
<style>
.audit-hero {background:linear-gradient(135deg,#1e293b,#0f172a);padding:48px 40px;margin-bottom:32px;border-radius:16px;position:relative;}
.audit-hero h1 {font-family:'Hanken Grotesk';font-size:2rem;font-weight:900;color:#fff;margin:0;}
.module-section {background:var(--cv-bg-surface);border:1px solid var(--cv-border-default);border-radius:12px;margin-bottom:24px;padding:24px;}
.server-item {display:flex;justify-content:space-between;align-items:center;padding:12px;background:var(--cv-bg-surface-sunken);border-radius:8px;margin-bottom:8px;}
.status-badge {display:inline-block;padding:4px 12px;border-radius:6px;font-size:.75rem;font-weight:700;}
.status-active {background:linear-gradient(135deg,rgba(16,185,129,.2),rgba(5,150,105,.15));color:#10b981;border:1px solid rgba(16,185,129,.3);}
.status-inactive {background:linear-gradient(135deg,rgba(107,114,128,.2),rgba(75,85,99,.15));color:#6b7280;border:1px solid rgba(107,114,128,.3);}
.duplicate-warning {background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:8px;padding:16px;margin-bottom:16px;color:#ef4444;}
.delete-list {background:rgba(239,68,68,.05);border-left:4px solid #ef4444;padding:16px;border-radius:6px;margin-top:16px;}
.delete-list li {margin:8px 0;font-family:monospace;}
</style>

<div class="audit-hero">
    <a href="/admin" style="color:#3b82f6;text-decoration:none;font-weight:600;font-size:.9rem;">← Back to Dashboard</a>
    <h1>🔍 Server Audit</h1>
    <p style="color:rgba(255,255,255,.7);margin:12px 0 0;">Identifying duplicate servers and non-functional configurations</p>
</div>

<?php if (!$recommendations['isClean']): ?>
    <div class="duplicate-warning">
        <strong>⚠️ Issues Found:</strong> <?= count($recommendations['issues']) ?> duplicate/inactive server(s) detected
    </div>
<?php else: ?>
    <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);border-radius:8px;padding:16px;margin-bottom:24px;color:#10b981;font-weight:600;">
        ✅ All servers are properly configured - no duplicates!
    </div>
<?php endif; ?>

<?php foreach ($grouped as $module => $moduleServers): ?>
    <div class="module-section">
        <h2 style="margin:0 0 16px;display:flex;justify-content:space-between;align-items:center;">
            <span>🔷 <?= e($module) ?></span>
            <span style="font-size:.85rem;color:var(--cv-text-secondary);"><?= count($moduleServers) ?> server<?= count($moduleServers) !== 1 ? 's' : '' ?></span>
        </h2>

        <?php if (isset($duplicates[$module])): ?>
            <div class="duplicate-warning" style="margin-bottom:16px;">
                ⚠️ Multiple servers for this module - one will be kept, others removed
            </div>
        <?php endif; ?>

        <?php foreach ($moduleServers as $srv): ?>
            <div class="server-item">
                <div>
                    <strong style="display:block;margin-bottom:4px;">ID: <?= (int)$srv['id'] ?> — <?= e($srv['name']) ?></strong>
                    <small style="color:var(--cv-text-secondary);">Hostname: <?= e($srv['hostname'] ?? 'N/A') ?></small>
                </div>
                <span class="status-badge <?= $srv['is_active'] ? 'status-active' : 'status-inactive' ?>">
                    <?= $srv['is_active'] ? '✅ ACTIVE' : '❌ INACTIVE' ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<?php if (!empty($recommendations['toDelete'])): ?>
    <div class="module-section" style="background:rgba(239,68,68,.05);border-left:4px solid #ef4444;">
        <h2 style="color:#ef4444;margin-top:0;">🗑️ Servers to Delete</h2>
        <p style="color:var(--cv-text-secondary);">Delete these in Admin > Servers to remove duplicates:</p>
        <ul class="delete-list">
        <?php foreach ($recommendations['toDelete'] as $id => $reason): ?>
            <li>
                <strong>Server ID <?= (int)$id ?></strong> — <?= e($reason) ?>
                <br><small>Go to: <a href="/admin/servers" style="color:#3b82f6;">Admin > Servers</a> → Find this server → Click Delete</small>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="module-section">
    <h2 style="margin-top:0;">📋 Summary</h2>
    <table style="width:100%;border-collapse:collapse;">
        <tr style="border-bottom:1px solid var(--cv-border-default);">
            <td style="padding:12px;"><strong>Total Servers:</strong></td>
            <td style="padding:12px;text-align:right;"><?= count($servers) ?></td>
        </tr>
        <tr style="border-bottom:1px solid var(--cv-border-default);">
            <td style="padding:12px;"><strong>Active Servers:</strong></td>
            <td style="padding:12px;text-align:right;color:#10b981;"><?= count(array_filter($servers, fn($s) => $s['is_active'])) ?></td>
        </tr>
        <tr style="border-bottom:1px solid var(--cv-border-default);">
            <td style="padding:12px;"><strong>Modules with Duplicates:</strong></td>
            <td style="padding:12px;text-align:right;color:#ef4444;"><?= count($duplicates) ?></td>
        </tr>
        <tr>
            <td style="padding:12px;"><strong>Servers to Delete:</strong></td>
            <td style="padding:12px;text-align:right;color:#ef4444;font-weight:700;"><?= count($recommendations['toDelete']) ?></td>
        </tr>
    </table>
</div>
