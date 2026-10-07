<?php
/** @var int $pending */
$path = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
$tabs = [
    '/admin/username-changer' => 'Dashboard',
    '/admin/username-changer/manual' => 'Manual change',
    '/admin/username-changer/settings' => 'Settings & pricing',
    '/admin/username-changer/audit' => 'Audit log',
];
?>
<link rel="stylesheet" href="/assets/css/username-changer-admin.css">
<nav class="uca-tabs" aria-label="Username Changer">
    <?php foreach ($tabs as $href => $label): ?>
        <?php $on = $href === '/admin/username-changer' ? ($path === $href || str_starts_with($path, $href . '/requests')) : str_starts_with($path, $href); ?>
        <a href="<?= e($href) ?>" class="<?= $on ? 'is-active' : '' ?>"><?= e($label) ?>
            <?php if ($href === '/admin/username-changer' && ($pending ?? 0) > 0): ?><span class="uca-count"><?= (int) $pending ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
    <a href="/admin/addons/cpanel-username-changer">Add-on</a>
</nav>
<?php if (!empty($notice)): ?><div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div><?php endif; ?>
