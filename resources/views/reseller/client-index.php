<?php
/** @var string $state */
/** @var array<string, mixed>|null $credential */
/** @var array<string, mixed>|null $issued */
/** @var string|null $error */
/** @var string|null $notice */
/** @var array{service: float, domain: float} $discounts */
/** @var array<int, array<string, mixed>> $services */
/** @var array<int, array<string, mixed>> $domains */
/** @var array<string, mixed> $currency */
/** @var string $docsUrl */

$servicePct = \CodeVault\Reseller\ResellerSettings::formatPercent($discounts['service']);
$domainPct = \CodeVault\Reseller\ResellerSettings::formatPercent($discounts['domain']);
?>
<div class="cv-card" style="max-width:56rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Reseller Area</h1>
    <p><a href="/client/dashboard">&larr; Back to dashboard</a></p>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== null && $notice !== ''): ?>
        <div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div>
    <?php endif; ?>

    <p>Resell our services and domain names. Your reseller discounts are currently
        <strong><?= e($servicePct) ?>%</strong> on services and
        <strong><?= e($domainPct) ?>%</strong> on domains — the reseller prices below already include them.</p>
    <p><a href="<?= e($docsUrl) ?>">API documentation &rarr;</a> &middot;
        <a href="/client/reseller/store">Your store &amp; branding &rarr;</a> &middot;
        <a href="/client/reseller/account">Your account &amp; earnings &rarr;</a> &middot;
        <a href="/client/reseller/tickets">Your support queue &rarr;</a> &middot;
        <a href="/client/reseller/mail">Your support address &rarr;</a></p>
</div>

<?php if (is_array($issued)): ?>
    <div class="cv-card" style="max-width:56rem;margin:0 auto;margin-bottom:var(--cv-space-4);border:2px solid var(--cv-color-success-500);">
        <h2 class="cv-card__title"><?= $issued['rotated'] ? 'Your new API credentials' : 'Your API credentials' ?></h2>
        <p><strong>Copy the secret now.</strong> It is shown once and stored only as a hash — we cannot show it
            again. If you lose it, rotate the key to issue a new one.</p>
        <p><strong>API key:</strong><br><code><?= e((string) $issued['key']) ?></code></p>
        <p><strong>API secret:</strong><br><code><?= e((string) $issued['secret']) ?></code></p>
        <p>Send both on every request as:</p>
        <p><code>Authorization: Bearer <?= e((string) $issued['key']) ?>.<?= e((string) $issued['secret']) ?></code></p>
        <p style="color:var(--cv-text-secondary);"><?= $state === 'active'
            ? 'This key is active.'
            : 'This key is <strong>not active yet</strong> — submit your reselling domain below.' ?></p>
    </div>
<?php endif; ?>

<div class="cv-card" style="max-width:56rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Your API access</h2>

    <?php if ($state === 'none'): ?>
        <p>You do not have a reseller API key yet. Request one to get started — it is created
            <strong>disabled</strong>, and switches on once you tell us the domain you will resell from.</p>
        <form method="post" action="/client/reseller/key"><?= csrf_field() ?>
            <p>
                <label for="label">Label (optional)</label><br>
                <input class="cv-input" type="text" id="label" name="label" maxlength="120"
                       placeholder="My storefront" value="">
            </p>
            <button class="cv-btn" type="submit">Request API key</button>
        </form>
    <?php else: ?>
        <table class="cv-table">
            <tbody>
            <tr>
                <th>API key</th>
                <td><code><?= e((string) ($credential['api_key'] ?? '')) ?></code></td>
            </tr>
            <tr>
                <th>Status</th>
                <td>
                    <?php if ($state === 'active'): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">Disabled — awaiting your domain</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Reselling domain</th>
                <td><?= ($credential['reseller_domain'] ?? null) !== null
                    ? e((string) $credential['reseller_domain'])
                    : '<em>not submitted yet</em>' ?></td>
            </tr>
            <tr>
                <th>Scopes</th>
                <td>
                    <?php
                    // The column is JSON; decoding here matches the API-credentials
                    // admin view so the two render the same value identically.
                    $scopes = json_decode((string) ($credential['scopes'] ?? ''), true);
                    $scopes = is_array($scopes) ? $scopes : [];
                    ?>
                    <?php if (in_array('*', $scopes, true)): ?>
                        <span class="cv-badge cv-badge--neutral">All scopes</span>
                    <?php else: ?>
                        <code><?= e(implode(', ', $scopes)) ?></code>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if (($credential['activated_at'] ?? null) !== null): ?>
                <tr>
                    <th>Activated</th>
                    <td><?= e((string) $credential['activated_at']) ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <h3><?= $state === 'active' ? 'Change the reselling domain' : 'Activate your key' ?></h3>
        <p><?= $state === 'active'
            ? 'Submit a different domain to move this key to another storefront.'
            : 'Your key stays disabled until you submit the domain name you will resell our services and domains from.' ?></p>
        <form method="post" action="/client/reseller/activate"><?= csrf_field() ?>
            <p>
                <label for="domain">Domain name</label><br>
                <input class="cv-input" type="text" id="domain" name="domain" maxlength="253"
                       placeholder="reseller.example.com" required
                       value="<?= e((string) ($credential['reseller_domain'] ?? '')) ?>">
            </p>
            <button class="cv-btn" type="submit"><?= $state === 'active' ? 'Update domain' : 'Activate API key' ?></button>
        </form>

        <h3>Lost the secret?</h3>
        <p>Rotating issues a new key and secret immediately. <strong>The old secret stops working at once</strong>,
            and the new key starts out disabled until you re-submit your domain.</p>
        <form method="post" action="/client/reseller/rotate"
              onsubmit="return confirm('Rotate the key? Your existing secret will stop working immediately.');">
            <?= csrf_field() ?>
            <input type="hidden" name="label" value="<?= e((string) ($credential['label'] ?? 'Reseller key')) ?>">
            <button class="cv-btn" type="submit">Rotate key &amp; secret</button>
        </form>
    <?php endif; ?>
</div>

<div class="cv-card" style="max-width:56rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Service reseller prices</h2>
    <p style="color:var(--cv-text-secondary);">Prices in <?= e((string) $currency['code']) ?>. Setup fees are discounted at the same rate.</p>
    <table class="cv-table">
        <thead>
        <tr><th>Service</th><th>Billing cycle</th><th>List price</th><th>Your discount</th><th>You pay</th></tr>
        </thead>
        <tbody>
        <?php foreach ($services as $product): ?>
            <?php foreach ($product['cycles'] as $cycle): ?>
                <tr>
                    <td><?= e((string) $product['name']) ?></td>
                    <td><?= e((string) $cycle['label']) ?></td>
                    <td><?= e((string) $cycle['price']['list']) ?></td>
                    <td><?= e((string) $cycle['price']['discount_label']) ?> (save <?= e((string) $cycle['price']['saving']) ?>)</td>
                    <td><strong><?= e((string) $cycle['price']['reseller']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if ($services === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No services are available to resell yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="cv-card" style="max-width:56rem;margin:0 auto;">
    <h2 class="cv-card__title">Domain reseller prices</h2>
    <p style="color:var(--cv-text-secondary);">Prices in <?= e((string) $currency['code']) ?>.</p>
    <table class="cv-table">
        <thead>
        <tr><th>TLD</th><th>Register</th><th>Transfer</th><th>Renew</th><th>Discount</th></tr>
        </thead>
        <tbody>
        <?php foreach ($domains as $row): ?>
            <tr>
                <td><?= e((string) $row['tld']) ?></td>
                <td><strong><?= e((string) $row['register']['reseller']) ?></strong><br>
                    <span style="color:var(--cv-text-secondary);">was <?= e((string) $row['register']['list']) ?></span></td>
                <td><strong><?= e((string) $row['transfer']['reseller']) ?></strong></td>
                <td><strong><?= e((string) $row['renew']['reseller']) ?></strong></td>
                <td><?= e((string) $row['register']['discount_label']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($domains === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No TLD pricing is configured yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <p style="color:var(--cv-text-secondary);">These are quote prices — the API reports what you pay, not what your
        customer is billed. You invoice your own customers.</p>
</div>
