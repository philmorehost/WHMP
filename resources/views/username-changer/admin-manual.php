<?php
/**
 * @var array<string, mixed>|null $service
 * @var array<int, array<string, mixed>> $history
 * @var array{ok: bool, message: string}|null $result
 * @var string $search
 * @var array<int, array<string, mixed>> $matches
 * @var array{min: int, max: int, reserved: array<int, string>} $rules
 * @var string $input
 * @var int $pending
 */
use CodeVault\UsernameChanger\UsernameChangeNotifier;
?>
<div class="uca">
    <div class="uca-head">
        <div>
            <h1>Manual username change</h1>
            <p>Rename any cPanel account. Skips confirmation, approval, limits and payment — never the safety checks.</p>
        </div>
    </div>
    <?= $view->render('username-changer.admin-tabs', ['pending' => $pending, 'notice' => $notice ?? null, 'error' => $error ?? null]) ?>

    <?php if ($result !== null): ?>
        <div class="cv-alert cv-alert--<?= $result['ok'] ? 'success' : 'error' ?>"><?= e($result['message']) ?></div>
    <?php endif; ?>

    <div class="uca-card">
        <h2>1. Find the service</h2>
        <form class="uca-filter" method="get" action="/admin/username-changer/manual">
            <input class="cv-input" name="service_id" type="number" min="1" placeholder="Service ID" value="<?= $service ? (int) $service['id'] : '' ?>">
            <span>or</span>
            <input class="cv-input" name="q" type="search" placeholder="Username, domain or client email" value="<?= e($search) ?>">
            <button class="cv-btn cv-btn--secondary" type="submit">Find</button>
        </form>
        <?php if ($matches !== []): ?>
            <table class="uca-table">
                <?php foreach ($matches as $m): ?>
                    <tr><td>#<?= (int) $m['id'] ?></td><td class="uca-mono"><?= e((string) $m['username']) ?></td><td><?= e((string) $m['domain']) ?></td><td><?= e(trim($m['first_name'] . ' ' . $m['last_name'])) ?></td><td><?= e((string) $m['status']) ?></td><td><a class="cv-btn uca-btn-sm" href="/admin/username-changer/manual?service_id=<?= (int) $m['id'] ?>">Select</a></td></tr>
                <?php endforeach; ?>
            </table>
        <?php elseif ($search !== ''): ?>
            <p style="color:var(--cv-text-secondary)">No cPanel services match.</p>
        <?php endif; ?>
    </div>

    <?php if ($service !== null): ?>
        <div class="uca-card">
            <h2>2. New username for #<?= (int) $service['id'] ?> — <?= e((string) $service['domain']) ?></h2>
            <p>Client: <?= e(trim($service['first_name'] . ' ' . $service['last_name'])) ?> · server <?= e((string) ($service['server_name'] ?? '—')) ?> · current <strong class="uca-mono"><?= e((string) $service['username']) ?></strong></p>
            <?php if (($service['module_slug'] ?? '') !== 'cpanel'): ?>
                <div class="cv-alert cv-alert--error">This service is not on a cPanel server.</div>
            <?php else: ?>
                <form method="post" action="/admin/username-changer/manual" id="uca-manual" data-service="<?= (int) $service['id'] ?>" data-min="<?= (int) $rules['min'] ?>" data-max="<?= (int) $rules['max'] ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>">
                    <div class="uca-field" style="max-width:360px">
                        <label for="uca-new">New username</label>
                        <input class="cv-input uca-mono" id="uca-new" name="new_username" value="<?= e($input) ?>" autocomplete="off" maxlength="<?= (int) $rules['max'] ?>" required>
                        <div class="uca-verdict" id="uca-verdict"></div>
                    </div>
                    <label class="uca-checks" style="margin-bottom:12px"><input type="checkbox" name="rename_db" value="1"> Also rename databases and database users to the new prefix</label>
                    <div class="uca-inline">
                        <button class="cv-btn cv-btn--secondary" type="submit" name="mode" value="preflight">Preflight (no changes)</button>
                        <button class="cv-btn" type="submit" name="mode" value="rename" data-confirm="Rename this cPanel account now? Logins and the home folder change immediately.">Rename now</button>
                    </div>
                </form>
                <script src="/assets/js/username-changer-admin.js" defer></script>
            <?php endif; ?>
        </div>
        <?php if ($history !== []): ?>
            <div class="uca-card">
                <h2>History</h2>
                <table class="uca-table">
                    <?php foreach ($history as $h): ?>
                        <tr><td><a href="/admin/username-changer/requests/<?= (int) $h['id'] ?>">#<?= (int) $h['id'] ?></a></td><td class="uca-mono"><?= e($h['old_username']) ?> → <?= e($h['new_username']) ?></td><td><span class="uca-pill uca-pill--<?= e($h['status']) ?>"><?= e(UsernameChangeNotifier::statusLabel((string) $h['status'])) ?></span></td><td><small><?= e((string) $h['created_at']) ?></small></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
