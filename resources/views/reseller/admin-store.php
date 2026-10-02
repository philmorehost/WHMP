<?php
/**
 * @var int $clientId
 * @var array<string, mixed>|null $store
 * @var array<string, mixed>|null $client
 * @var string $platformHost
 * @var string|null $platformUrl
 * @var string|null $recordName
 * @var string|null $error
 * @var string|null $notice
 * @var array<string, mixed>|null $verification
 * @var float $markup
 * @var int $overrideCount
 * @var array<int, array<string, mixed>> $goLive
 */

$clientName = $client === null
    ? 'client #' . $clientId
    : trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));

$customDomain = $store === null ? null : ($store['custom_domain'] ?? null);
$verified = $store !== null && ($store['domain_verified_at'] ?? null) !== null;
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Reseller store — <?= e($clientName) ?></h1>
    <p><a href="/admin/resellers">&larr; Back to resellers</a> &middot;
        <a href="/admin/clients/<?= (int) $clientId ?>">Client account</a></p>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== null && $notice !== ''): ?>
        <div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div>
    <?php endif; ?>
</div>

<?php if (is_array($verification)): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <div class="cv-alert cv-alert--warning">
            <h3 style="margin-top:0;">Verification failed for <?= e((string) $verification['domain']) ?></h3>
            <?php if (($verification['error'] ?? null) !== null): ?>
                <p><?= e((string) $verification['error']) ?></p>
            <?php endif; ?>
            <p>Expected a TXT record at <code><?= e((string) $verification['record_name']) ?></code> containing:</p>
            <pre><code><?= e((string) $verification['expected']) ?></code></pre>
            <?php if (($verification['found'] ?? []) !== []): ?>
                <p>What DNS returned:</p>
                <ul>
                    <?php foreach ((array) $verification['found'] as $found): ?>
                        <li><code><?= e((string) $found) ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>DNS returned nothing at that name — the record may be missing, or still propagating.</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($store === null): ?>
    <div class="cv-card">
        <h2 class="cv-card__title">No store yet</h2>
        <p>This client has no storefront. Open one on their behalf if they have asked support to set it up — they
            can then brand it and point their domain at it themselves.</p>
        <form method="post" action="/admin/resellers/<?= (int) $clientId ?>/store"><?= csrf_field() ?>
            <p>
                <label for="store_name">Store name</label><br>
                <input class="cv-input" type="text" id="store_name" name="store_name" maxlength="191" required>
            </p>
            <p>
                <label for="slug">Store address (optional)</label><br>
                <input class="cv-input" type="text" id="slug" name="slug" maxlength="63"
                       placeholder="acme">
            </p>
            <button class="cv-btn" type="submit">Open store</button>
        </form>
    </div>
<?php else: ?>

    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Store</h2>
        <table class="cv-table">
            <tbody>
            <tr><th>Store name</th><td><?= e((string) ($store['brand_name'] ?? '')) ?></td></tr>
            <tr><th>Slug</th><td><code><?= e((string) $store['slug']) ?></code></td></tr>
            <tr>
                <th>Platform address</th>
                <td><a href="<?= e((string) $platformUrl) ?>"><?= e((string) $platformUrl) ?></a></td>
            </tr>
            <tr>
                <th>Custom domain</th>
                <td>
                    <?php if ($customDomain === null): ?>
                        <em>not set</em>
                    <?php elseif ($verified): ?>
                        <span class="cv-badge cv-badge--success">Verified
                            <?= e((string) $store['domain_verified_at']) ?></span>
                        <code><?= e((string) $customDomain) ?></code>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">Not verified</span>
                        <code><?= e((string) $customDomain) ?></code>
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
            <tr><th>Opened</th><td><?= e((string) ($store['created_at'] ?? '')) ?></td></tr>
            </tbody>
        </table>

        <form method="post" action="/admin/resellers/<?= (int) $clientId ?>/store/status" style="margin-top:var(--cv-space-3);">
            <?= csrf_field() ?>
            <input type="hidden" name="status" value="<?= ($store['status'] ?? 'active') === 'active' ? 'suspended' : 'active' ?>">
            <button class="cv-btn" type="submit">
                <?= ($store['status'] ?? 'active') === 'active' ? 'Suspend store' : 'Reactivate store' ?>
            </button>
            <?php if (($store['status'] ?? 'active') === 'active'): ?>
                <span style="color:var(--cv-text-secondary);">Its domain will return 503 rather than showing our shop.</span>
            <?php endif; ?>
        </form>
    </div>

    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Prices the reseller charges</h2>
        <p>Retail prices are the reseller's own — they set them on their own store page, not here. Shown so you can
            see what their customers will be charged once the store goes live.</p>
        <table class="cv-table">
            <tbody>
            <tr>
                <th>Store-wide markup</th>
                <td><?= e(\CodeVault\Reseller\ResellerSettings::formatPercent($markup)) ?>% over list</td>
            </tr>
            <tr>
                <th>Prices set by hand</th>
                <td><?= (int) $overrideCount === 0
                    ? '<em>none — the markup covers everything</em>'
                    : (int) $overrideCount . ' override(s)' ?></td>
            </tr>
            <tr>
                <th>Your discount to them</th>
                <td><?= e(\CodeVault\Reseller\ResellerSettings::formatPercent($discounts['service'])) ?>% services /
                    <?= e(\CodeVault\Reseller\ResellerSettings::formatPercent($discounts['domain'])) ?>% domains
                    &middot; <a href="/admin/resellers">change</a></td>
            </tr>
            </tbody>
        </table>
    </div>

    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Branding &amp; address</h2>
        <form method="post" action="/admin/resellers/<?= (int) $clientId ?>/store/brand"><?= csrf_field() ?>
            <p>
                <label for="brand_name">Store name</label><br>
                <input class="cv-input" type="text" id="brand_name" name="brand_name" maxlength="191"
                       value="<?= e((string) ($store['brand_name'] ?? '')) ?>">
            </p>
            <p>
                <label for="slug">Store address</label><br>
                <input class="cv-input" type="text" id="slug" name="slug" maxlength="63"
                       value="<?= e((string) $store['slug']) ?>">
            </p>
            <p>
                <label for="logo_url">Logo URL</label><br>
                <input class="cv-input" type="text" id="logo_url" name="logo_url" maxlength="255"
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
                       value="<?= e((string) ($store['primary_color'] ?? '')) ?>">
            </p>
            <button class="cv-btn" type="submit">Save</button>
        </form>
    </div>

    <div class="cv-card">
        <h2 class="cv-card__title">Support chat</h2>
        <p style="color:var(--cv-text-secondary);">
            What this store's customers see. The platform's own chatbox is never shown on a store's domain — a store
            either has its own chat here or none at all. The reseller can change this themselves from their portal.
        </p>
        <form method="post" action="/admin/resellers/<?= (int) $clientId ?>/store/chat"><?= csrf_field() ?>
            <p>
                <label for="support_whatsapp">WhatsApp number</label><br>
                <input class="cv-input" type="text" id="support_whatsapp" name="support_whatsapp" maxlength="32"
                       placeholder="+234 801 234 5678" value="<?= e($chat['support_whatsapp']) ?>">
                <br><span style="color:var(--cv-text-secondary);">Include the country code. The default chat button;
                    no account needed.</span>
            </p>
            <p>
                <label for="tawk_property_id">Tawk.To property id <em>(optional)</em></label><br>
                <input class="cv-input" type="text" id="tawk_property_id" name="tawk_property_id" maxlength="64"
                       value="<?= e($chat['tawk_property_id']) ?>">
                <br><span style="color:var(--cv-text-secondary);">Replaces the WhatsApp button with the store's own live
                    chat. The <strong>id only</strong> — <code>embed.tawk.to/&lt;id&gt;/&lt;widget&gt;</code>; a pasted
                    code block is refused rather than stored, because we build the script ourselves.</span>
            </p>
            <p>
                <label for="tawk_widget_id">Tawk.To widget id <em>(optional)</em></label><br>
                <input class="cv-input" type="text" id="tawk_widget_id" name="tawk_widget_id" maxlength="64"
                       value="<?= e($chat['tawk_widget_id']) ?>">
            </p>
            <button class="cv-btn" type="submit">Save chat settings</button>
        </form>
    </div>

    <div class="cv-card">
        <h2 class="cv-card__title">Custom domain</h2>
        <form method="post" action="/admin/resellers/<?= (int) $clientId ?>/store/domain"><?= csrf_field() ?>
            <p>
                <label for="custom_domain">Domain</label><br>
                <input class="cv-input" type="text" id="custom_domain" name="custom_domain" maxlength="253"
                       placeholder="shop.example.com" value="<?= e((string) ($customDomain ?? '')) ?>">
                <br><span style="color:var(--cv-text-secondary);">Blank and save releases the domain.</span>
            </p>
            <button class="cv-btn" type="submit">Save domain</button>
        </form>

        <?php if ($customDomain !== null): ?>
            <p style="margin-top:var(--cv-space-3);">The reseller creates this record, or you can create it for them:</p>
            <table class="cv-table">
                <tbody>
                <tr><th>Type</th><td><code>TXT</code></td></tr>
                <tr><th>Name</th><td><code><?= e((string) $recordName) ?></code></td></tr>
                <tr><th>Value</th><td><code>codevault-store-verify=<?= e((string) $store['domain_verification_token']) ?></code></td></tr>
                </tbody>
            </table>
            <form method="post" action="/admin/resellers/<?= (int) $clientId ?>/store/verify">
                <?= csrf_field() ?>
                <button class="cv-btn" type="submit">Verify domain now</button>
            </form>
            <p style="color:var(--cv-text-secondary);">Verification means DNS proves they control the domain. It does
                NOT make the store reachable at it: the domain still has to be <strong>added to the web server</strong>
                (cPanel: Domains → Create A New Domain, same document root) and given a TLS certificate. Until then the
                server answers on that hostname as its default site.</p>
        <?php endif; ?>
    </div>

    <div class="cv-card">
        <h2 class="cv-card__title">Going live on the custom domain</h2>
        <p style="color:var(--cv-text-secondary);">Five things have to hold before customers reach this store on its
            own domain. The last two happen on the server that runs this application — no amount of clicking here can
            do them, so they are listed rather than tracked.</p>
        <table class="cv-table">
            <thead><tr><th>Step</th><th>State</th><th>What it means</th></tr></thead>
            <tbody>
            <?php foreach ($goLive as $step): ?>
                <tr>
                    <td><?= e((string) $step['label']) ?></td>
                    <td>
                        <?php if ($step['manual']): ?>
                            <span class="cv-badge">On the server</span>
                        <?php elseif ($step['done']): ?>
                            <span class="cv-badge cv-badge--success">Done</span>
                        <?php else: ?>
                            <span class="cv-badge cv-badge--warning">Outstanding</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--cv-text-secondary);"><?= e((string) $step['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($customDomain !== null): ?>
            <form method="post" action="/admin/resellers/<?= (int) $clientId ?>/store/domain/override"
                  style="margin-top:var(--cv-space-3);">
                <?= csrf_field() ?>
                <input type="hidden" name="verified" value="<?= $verified ? '0' : '1' ?>">
                <button class="cv-btn" type="submit"><?= $verified
                    ? 'Remove verification'
                    : 'Mark verified without DNS' ?></button>
            </form>
            <p style="color:var(--cv-text-secondary);">Only for a provider that cannot serve the TXT record. An override
                is recorded as <strong>manual</strong> on the store and in the activity log — it is never presented as a
                DNS proof, and a later real DNS proof replaces it.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>
