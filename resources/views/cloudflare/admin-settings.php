<?php
/**
 * @var CodeVault\Cloudflare\CloudflareSettings $settings
 * @var bool $hasToken
 * @var array<int, array{id: string, name: string}> $accounts
 * @var array<int, array<string, mixed>> $products
 * @var array<int, int> $attached
 */
use CodeVault\Cloudflare\CloudflareSettings;

$sslLabels = ['off' => 'Off', 'flexible' => 'Flexible', 'full' => 'Full (recommended)', 'strict' => 'Full (strict)'];
$levelLabels = ['essentially_off' => 'Essentially off', 'low' => 'Low', 'medium' => 'Medium (recommended)', 'high' => 'High', 'under_attack' => "I'm under attack"];
?>
<div class="cfa">
    <div class="cfa-head">
        <div>
            <h1><span class="cfa-logo" aria-hidden="true">CF</span> Cloudflare settings</h1>
            <p>Free plan only — nothing here can upgrade a zone or charge anyone. Clients and reseller stores get it free.</p>
        </div>
    </div>
    <?= $view->render('cloudflare.admin-tabs', ['active' => 'settings', 'notice' => $notice ?? null, 'error' => $error ?? null]) ?>

    <div class="cfa-grid">
        <div class="cfa-card">
            <h2>1. Connect your Cloudflare account</h2>
            <p>
                <?php if ($settings->connected()): ?>
                    <span class="cfa-pill cfa-pill--active">Connected</span> <strong><?= e($settings->accountName()) ?></strong> <span class="cfa-muted cfa-mono"><?= e($settings->accountId()) ?></span>
                <?php elseif ($hasToken): ?>
                    <span class="cfa-pill cfa-pill--pending">Token saved — choose an account</span>
                <?php else: ?>
                    <span class="cfa-pill">Not connected</span>
                <?php endif; ?>
            </p>
            <form method="post" action="/admin/cloudflare/connect">
                <?= csrf_field() ?>
                <div class="cfa-field">
                    <label for="cf-token">API token</label>
                    <input class="cv-input" id="cf-token" name="api_token" type="password" autocomplete="off" placeholder="<?= $hasToken ? '•••••••• saved — leave blank to keep' : 'Paste your API token' ?>">
                    <span class="cfa-muted">Stored encrypted. Never shown again.</span>
                </div>
                <?php if (count($accounts) > 1): ?>
                    <div class="cfa-field">
                        <label for="cf-account">Account for new zones</label>
                        <select class="cv-input" id="cf-account" name="account_id">
                            <?php foreach ($accounts as $account): ?>
                                <option value="<?= e($account['id']) ?>"<?= $account['id'] === $settings->accountId() ? ' selected' : '' ?>><?= e($account['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="cfa-actions">
                    <button class="cv-btn" type="submit">Verify &amp; save</button>
                </div>
            </form>
            <?php if ($hasToken): ?>
                <form method="post" action="/admin/cloudflare/disconnect" style="margin-top:10px" data-cf-confirm="Disconnect Cloudflare? Existing zones keep working in Cloudflare.">
                    <?= csrf_field() ?>
                    <button class="cv-btn cv-btn--secondary" type="submit">Disconnect</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="cfa-card">
            <h2>Creating the API token</h2>
            <ol class="cfa-steps">
                <li>In Cloudflare open <strong>My Profile → API Tokens → Create Token → Custom token</strong>.</li>
                <li>Permissions:
                    <ul class="cfa-muted">
                        <li>Account · Account Settings · Read</li>
                        <li>Zone · Zone · Edit</li>
                        <li>Zone · Zone Settings · Edit</li>
                        <li>Zone · DNS · Edit</li>
                        <li>Zone · Cache Purge · Purge</li>
                        <li>Zone · Firewall Services · Edit</li>
                    </ul>
                </li>
                <li>Account resources: <strong>Include → your account</strong>. Zone resources: <strong>Include → All zones from an account → your account</strong>.</li>
                <li>Create the token, paste it here and click <strong>Verify &amp; save</strong>.</li>
            </ol>
            <p class="cfa-muted">A normal Cloudflare account is enough — no partner programme needed. Each customer domain becomes a Free-plan zone in this account.</p>
        </div>
    </div>

    <div class="cfa-card">
        <h2>2. Defaults &amp; behaviour</h2>
        <form method="post" action="/admin/cloudflare/settings">
            <?= csrf_field() ?>
            <div class="cfa-grid">
                <div>
                    <div class="cfa-field">
                        <label for="cf-ssl">SSL/TLS mode for new zones</label>
                        <select class="cv-input" id="cf-ssl" name="default_ssl">
                            <?php foreach (CloudflareSettings::SSL_MODES as $mode): ?>
                                <option value="<?= e($mode) ?>"<?= $settings->defaultSsl() === $mode ? ' selected' : '' ?>><?= e($sslLabels[$mode]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="cfa-field">
                        <label for="cf-level">Security level for new zones</label>
                        <select class="cv-input" id="cf-level" name="default_security_level">
                            <?php foreach (CloudflareSettings::SECURITY_LEVELS as $level): ?>
                                <option value="<?= e($level) ?>"<?= $settings->defaultSecurityLevel() === $level ? ' selected' : '' ?>><?= e($levelLabels[$level]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label class="cfa-check"><input type="checkbox" name="default_always_https" value="1"<?= $settings->defaultAlwaysHttps() ? ' checked' : '' ?>> <span>Turn on <strong>Always use HTTPS</strong> for new zones</span></label>
                </div>
                <div>
                    <div class="cfa-field">
                        <label for="cf-grace">Grace period before deletion (days)</label>
                        <input class="cv-input" id="cf-grace" name="grace_days" type="number" min="1" max="90" value="<?= (int) $settings->graceDays() ?>">
                        <span class="cfa-muted">After a service ends or a client turns Cloudflare off, the zone is deleted after this many days. It can be undone until then.</span>
                    </div>
                    <label class="cfa-check"><input type="checkbox" name="allow_later" value="1"<?= $settings->allowLater() ? ' checked' : '' ?>> <span>Let clients on an eligible product turn Cloudflare on later from their service page (not only at order)</span></label>
            </div>
            <button class="cv-btn" type="submit">Save settings</button>
        </form>
    </div>

    <div class="cfa-card" id="products">
        <h2>3. Products that offer free Cloudflare</h2>
        <p class="cfa-muted">Ticked products show a free option <strong>“Cloudflare CDN &amp; Security (Free)”</strong> at order (No thanks / Yes). Choosing “Yes” sets Cloudflare up as soon as the service is active. It is priced at 0 on every billing cycle, so it never changes an invoice — on your site or any reseller store.</p>
        <form method="post" action="/admin/cloudflare/products">
            <?= csrf_field() ?>
            <div class="cfa-products">
                <?php foreach ($products as $product): ?>
                    <label class="cfa-check">
                        <input type="checkbox" name="products[]" value="<?= (int) $product['id'] ?>"<?= in_array((int) $product['id'], $attached, true) ? ' checked' : '' ?>>
                        <span><?= e((string) $product['name']) ?> <small class="cfa-muted"><?= e((string) $product['type']) ?><?= $product['status'] !== 'active' ? ' · hidden' : '' ?></small></span>
                    </label>
                <?php endforeach; ?>
                <?php if ($products === []): ?><p class="cfa-muted">No products yet.</p><?php endif; ?>
            </div>
            <div class="cfa-actions" style="margin-top:12px"><button class="cv-btn" type="submit">Save products</button></div>
        </form>
    </div>
</div>
<script src="/assets/js/cloudflare.js" defer></script>
