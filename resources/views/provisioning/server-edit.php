<?php
/** @var array<string, mixed> $server */
/** @var array<int, array<string, mixed>> $groups */
/** @var array<int, string> $moduleSlugs */
/** @var CodeVault\View $view */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Edit Server</h1>
    <p><a href="/admin/servers">&larr; Back to servers</a></p>
</div>

<div class="cv-card">
    <?= $view->partial('provisioning.server-form', [
        'server' => $server,
        'groups' => $groups,
        'moduleSlugs' => $moduleSlugs,
        'action' => '/admin/servers/' . (int) $server['id'],
        'submitLabel' => 'Save Changes',
    ]) ?>

    <div style="margin-top:var(--cv-space-4);padding-top:var(--cv-space-4);border-top:1px solid var(--cv-border-default);display:flex;align-items:center;gap:var(--cv-space-3);">
        <button type="button" class="cv-btn cv-btn--secondary" data-test-server="<?= (int) $server['id'] ?>" data-token="<?= e(csrf_token()) ?>">Test Connection</button>
        <span class="server-test-result" style="font-size:var(--cv-text-xs);"></span>
    </div>
</div>
