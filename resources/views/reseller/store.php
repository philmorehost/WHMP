<?php
/** @var array<string, mixed>|null $store */
/** @var string $platformHost */
/** @var string|null $platformUrl */
/** @var string|null $recordName */
/** @var string|null $error */
/** @var string|null $notice */
/** @var array<string, mixed>|null $verification */
/** @var string $docsUrl */

$customDomain = $store === null ? null : ($store['custom_domain'] ?? null);
$verified = $store !== null && ($store['domain_verified_at'] ?? null) !== null;
?>
<div class="cv-card" style="max-width:56rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Your Store</h1>
    <p><a href="/client/reseller">&larr; Back to the reseller area</a> &middot;
        <a href="/client/reseller/prices">Your prices &rarr;</a> &middot;
        <a href="<?= e($docsUrl) ?>">API documentation</a></p>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== null && $notice !== ''): ?>
        <div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div>
    <?php endif; ?>

    <?php if (is_array($verification)): ?>
        <div class="cv-alert cv-alert--warning">
            <h3 style="margin-top:0;">We could not verify <?= e((string) $verification['domain']) ?> yet</h3>
            <?php if (($verification['error'] ?? null) !== null): ?>
                <p><?= e((string) $verification['error']) ?></p>
            <?php endif; ?>
            <p>We looked for a <strong>TXT</strong> record at
                <code><?= e((string) $verification['record_name']) ?></code> containing:</p>
            <pre><code><?= e((string) $verification['expected']) ?></code></pre>
            <?php if (($verification['found'] ?? []) !== []): ?>
                <p>What DNS returned instead:</p>
                <ul>
                    <?php foreach ((array) $verification['found'] as $found): ?>
                        <li><code><?= e((string) $found) ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>DNS returned no TXT record at that name — it may simply not exist yet, or DNS changes
                    may still be propagating. Records usually appear within minutes but can take a few hours.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($store === null): ?>
    <div class="cv-card" style="max-width:56rem;margin:0 auto;">
        <h2 class="cv-card__title">Open your store</h2>
        <p>Your store sells our services and domains under your own name, on your own domain. You set the prices
            your customers pay; you pay us the reseller price for what they buy.</p>
        <form method="post" action="/client/reseller/store"><?= csrf_field() ?>
            <p>
                <label for="store_name">Store name</label><br>
                <input class="cv-input" type="text" id="store_name" name="store_name" maxlength="191"
                       placeholder="Acme Hosting" required>
            </p>
            <p>
                <label for="slug">Store address (optional)</label><br>
                <input class="cv-input" type="text" id="slug" name="slug" maxlength="63"
                       placeholder="acme">
                <br><span style="color:var(--cv-text-secondary);">Letters, numbers and hyphens, 3-63 characters.
                    Leave blank to use your store name. It becomes
                    <code>&lt;address&gt;.<?= e($platformHost) ?></code>.</span>
            </p>
            <button class="cv-btn" type="submit">Open my store</button>
        </form>
    </div>
<?php else: ?>

    <div class="cv-card" style="max-width:56rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Where your store lives</h2>
        <table class="cv-table">
            <tbody>
            <tr>
                <th>Store name</th>
                <td><?= e((string) ($store['brand_name'] ?? '')) ?></td>
            </tr>
            <tr>
                <th>Platform address</th>
                <td><a href="<?= e((string) $platformUrl) ?>"><?= e((string) $platformUrl) ?></a>
                    <br><span style="color:var(--cv-text-secondary);">Always available.</span></td>
            </tr>
            <tr>
                <th>Your own domain</th>
                <td>
                    <?php if ($customDomain === null): ?>
                        <em>not set</em>
                    <?php elseif ($verified): ?>
                        <span class="cv-badge cv-badge--success">Verified</span>
                        <code><?= e((string) $customDomain) ?></code>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">Not verified</span>
                        <code><?= e((string) $customDomain) ?></code>
                        <br><span style="color:var(--cv-text-secondary);">Nothing is served there until you verify it.</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Status</th>
                <td>
                    <?php if (($store['status'] ?? 'active') === 'active'): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">Suspended</span>
                    <?php endif; ?>
                </td>
            </tr>
            </tbody>
        </table>
    </div>

    <div class="cv-card" style="max-width:56rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Branding</h2>
        <form method="post" action="/client/reseller/store/brand"><?= csrf_field() ?>
            <p>
                <label for="brand_name">Store name</label><br>
                <input class="cv-input" type="text" id="brand_name" name="brand_name" maxlength="191"
                       value="<?= e((string) ($store['brand_name'] ?? '')) ?>" required>
            </p>
            <p>
                <label for="slug">Store address</label><br>
                <input class="cv-input" type="text" id="slug" name="slug" maxlength="63"
                       value="<?= e((string) $store['slug']) ?>">
                <br><span style="color:var(--cv-text-secondary);">Changing this changes your store's platform address.
                    Existing links using the old address stop working.</span>
            </p>
            <p>
                <label for="logo_url">Logo URL</label><br>
                <input class="cv-input" type="text" id="logo_url" name="logo_url" maxlength="255"
                       placeholder="https://cdn.example.com/logo.png"
                       value="<?= e((string) ($store['logo_url'] ?? '')) ?>">
            </p>
            <p>
                <label for="favicon_url">Favicon URL</label><br>
                <input class="cv-input" type="text" id="favicon_url" name="favicon_url" maxlength="255"
                       value="<?= e((string) ($store['favicon_url'] ?? '')) ?>">
            </p>
            <p>
                <label for="primary_color">Accent colour</label><br>
                <input class="cv-input" type="text" id="primary_color" name="primary_color" maxlength="7"
                       placeholder="#ff8f28" value="<?= e((string) ($store['primary_color'] ?? '')) ?>">
                <br><span style="color:var(--cv-text-secondary);">A hex colour like #ff8f28. Leave blank to use the
                    platform's colour.</span>
            </p>
            <button class="cv-btn" type="submit">Save branding</button>
        </form>
    </div>

    <div class="cv-card" style="max-width:56rem;margin:0 auto;">
        <h2 class="cv-card__title">Your own domain</h2>
        <p>Point a domain you own at your store and your customers never see us. We check that you control the
            domain before serving it — that is what stops anyone claiming a hostname that isn't theirs.</p>

        <form method="post" action="/client/reseller/store/domain"><?= csrf_field() ?>
            <p>
                <label for="custom_domain">Domain name</label><br>
                <input class="cv-input" type="text" id="custom_domain" name="custom_domain" maxlength="253"
                       placeholder="shop.example.com" value="<?= e((string) ($customDomain ?? '')) ?>">
                <br><span style="color:var(--cv-text-secondary);">Leave blank and save to remove it.</span>
            </p>
            <button class="cv-btn" type="submit"><?= $customDomain === null ? 'Claim this domain' : 'Update domain' ?></button>
        </form>

        <?php if ($customDomain !== null): ?>
            <h3>Prove you control it</h3>
            <p>Create this DNS record, then press Verify. It does not affect your website, email or any other
                record on the domain:</p>
            <table class="cv-table">
                <tbody>
                <tr><th>Type</th><td><code>TXT</code></td></tr>
                <tr><th>Name</th><td><code><?= e((string) $recordName) ?></code></td></tr>
                <tr><th>Value</th><td><code>codevault-store-verify=<?= e((string) $store['domain_verification_token']) ?></code></td></tr>
                </tbody>
            </table>
            <p style="color:var(--cv-text-secondary);">Alternatively, point a <code>CNAME</code> for
                <code><?= e((string) $customDomain) ?></code> at
                <code><?= e($platformHost) ?></code> — that verifies the domain too.</p>

            <form method="post" action="/client/reseller/store/verify"><?= csrf_field() ?>
                <button class="cv-btn" type="submit">Verify domain now</button>
            </form>
        <?php endif; ?>

        <p style="color:var(--cv-text-secondary);margin-top:var(--cv-space-4);">A verified domain also needs a TLS
            certificate before customers can use it over https://. That is set up on the server side — ask support
            once the domain is verified.</p>
    </div>
<?php endif; ?>
