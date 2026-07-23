<?php
/** @var array<string, mixed>|null $server */
/** @var array<int, array<string, mixed>> $groups */
/** @var array<int, string> $moduleSlugs */
/** @var string $action */
/** @var string $submitLabel */
$formId = 'server-form-' . ($server['id'] ?? 'new');
?>
<form id="<?= e($formId) ?>" method="post" action="<?= e($action) ?>" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:var(--cv-space-3);max-width:40rem;"><?= csrf_field() ?>
    <div class="cv-field">
        <label class="cv-label">Name</label>
        <input class="cv-input" name="name" value="<?= e((string) ($server['name'] ?? '')) ?>" required>
    </div>
    <div class="cv-field">
        <label class="cv-label">Hostname</label>
        <input class="cv-input" name="hostname" value="<?= e((string) ($server['hostname'] ?? '')) ?>" placeholder="server1.example.com" required>
        <div id="hostname-hint" style="font-size: var(--cv-text-xs); color: var(--cv-text-secondary); margin-top: var(--cv-space-1); display: none;"></div>
    </div>
    <div class="cv-field">
        <label class="cv-label">Module</label>
        <select class="cv-select" name="module_slug" data-server-module-select>
            <?php foreach ($moduleSlugs as $slug): ?>
                <?php 
                    $label = match($slug) {
                        'cpanel' => 'cPanel / WHM',
                        'cyberpanel' => 'CyberPanel',
                        'interserver-vps', 'interserver_vps' => 'InterServer VPS',
                        'interserver-dedicated', 'interserver_dedicated' => 'InterServer Dedicated',
                        'nocix-dedicated', 'nocix_dedicated', 'nocix' => 'Nocix Dedicated Server',
                        'resellerclub-email', 'resellerclub_email' => 'ResellerClub Email',
                        'local' => 'Local / Custom',
                        default => ucfirst($slug),
                    };
                ?>
                <option value="<?= e($slug) ?>" <?= ($server['module_slug'] ?? 'cpanel') === $slug ? 'selected' : '' ?>><?= e($label) ?> (<?= e($slug) ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="cv-field">
        <label class="cv-label">Server Group</label>
        <select class="cv-select" name="server_group_id">
            <option value="">None</option>
            <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>" <?= ($server['server_group_id'] ?? null) == $group['id'] ? 'selected' : '' ?>><?= e($group['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="cv-field" data-server-username-field>
        <label class="cv-label">API Username</label>
        <input class="cv-input" name="api_username" value="<?= e((string) ($server['api_username'] ?? '')) ?>">
    </div>
    <div class="cv-field">
        <label class="cv-label" data-server-token-label>API Token / Password</label>
        <input class="cv-input" type="password" name="api_token" placeholder="<?= !empty($server['api_token']) ? '••••••••  (leave blank to keep)' : '' ?>">
    </div>
    <div class="cv-field">
        <label class="cv-label">API Port (blank = module default)</label>
        <input class="cv-input" type="number" name="api_port" value="<?= e((string) ($server['api_port'] ?? '')) ?>">
    </div>
    <div class="cv-field">
        <label class="cv-label" style="font-weight:normal;"><input type="checkbox" name="use_ssl" value="1" <?= ($server === null || $server['use_ssl']) ? 'checked' : '' ?>> Use SSL</label>
        <label class="cv-label" style="font-weight:normal;"><input type="checkbox" name="active" value="1" <?= ($server === null || $server['active']) ? 'checked' : '' ?>> Active</label>
    </div>
    <button class="cv-btn" type="submit" style="grid-column: 1 / -1;"><?= e($submitLabel) ?></button>
</form>
