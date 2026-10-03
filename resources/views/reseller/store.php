<?php
/** @var array<string, mixed>|null $store */
/** @var string $platformHost */
/** @var string|null $platformUrl */
/** @var string|null $recordName */
/** @var string|null $error */
/** @var string|null $notice */
/** @var array<string, mixed>|null $verification */
/** @var string $docsUrl */
/** @var array<string, mixed>|null $cost */
/** @var array<int, array<string, mixed>> $arrears */
/** @var array<int, array<string, mixed>> $goLive */

$customDomain = $store === null ? null : ($store['custom_domain'] ?? null);
$verified = $store !== null && ($store['domain_verified_at'] ?? null) !== null;
?>
<div class="cv-card" style="max-width:56rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <header class="rs-head">
        <h1 class="rs-head__title">Your store</h1>
    </header>
    <?= $view->render('partials.reseller-nav') ?>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== null && $notice !== ''): ?>
        <div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div>
    <?php endif; ?>

    <?php if ($cost !== null): ?>
        <h2 class="cv-card__title" style="margin-top:var(--cv-space-4);">What your store has cost you</h2>
        <p style="color:var(--cv-text-secondary);">
            Your customers pay you directly — we never charge them. This is the wholesale cost of the orders your
            store has taken, at our catalogue prices, and we invoice it to your client account after the configured
            billing period closes. Periods are calendar months or ISO weeks; below-minimum amounts can carry forward,
            and an open period is never invoiced.
        </p>
        <table class="cv-table">
            <tbody>
            <?php $code = (string) $cost['currency_code']; ?>
            <tr><td>Orders taken</td><td><?= (int) $cost['order_count'] ?></td></tr>
            <tr><td>Accrued in total</td>
                <td><?= e(number_format((float) $cost['accrued'], 2)) ?> <?= e($code) ?></td></tr>
            <tr><td>Already invoiced</td>
                <td><?= e(number_format((float) $cost['billed'], 2)) ?> <?= e($code) ?></td></tr>
            <tr><td>Not yet invoiced</td>
                <td><?= e(number_format((float) $cost['unbilled'], 2)) ?> <?= e($code) ?></td></tr>
            </tbody>
        </table>

        <h3>Cost invoices</h3>
        <?php if ($arrears === []): ?>
            <p style="color:var(--cv-text-secondary);">Nothing unpaid. Invoices appear here as they are raised.</p>
        <?php else: ?>
            <table class="cv-table">
                <thead><tr><th>Invoice</th><th>Amount</th><th>Due</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($arrears as $row): ?>
                    <tr>
                        <td>#<?= (int) $row['id'] ?></td>
                        <td><?= e(number_format((float) $row['total'], 2)) ?>
                            <?= e((string) ($row['currency']['code'] ?? '')) ?></td>
                        <td><?= e(substr((string) $row['due_date'], 0, 10)) ?></td>
                        <td><a class="cv-btn" href="/client/invoices/<?= (int) $row['id'] ?>">View invoice</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
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
                <label for="slug">Your web address</label><br>
                <input class="cv-input" type="text" id="slug" name="slug" maxlength="63"
                       placeholder="acme" value="<?= e((string) ($slugSuggestion ?? '')) ?>">
                <br><span style="color:var(--cv-text-secondary);">
                    <strong>This becomes a link you give to customers.</strong> Your store's front page will be
                    <code>https://<?= e((string) (($slugSuggestion ?? '') !== '' ? $slugSuggestion : 'yourname')) ?>.<?= e($platformHost) ?></code>
                    — so keep it short and easy to say out loud. Letters, numbers and hyphens only; spaces become
                    hyphens. You can change it later.
                </span>
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
                <label for="slug">Your web address</label><br>
                <input class="cv-input" type="text" id="slug" name="slug" maxlength="63"
                       value="<?= e((string) $store['slug']) ?>">
                <br><span style="color:var(--cv-text-secondary);">This is the link you give to customers:
                    <code>https://<?= e((string) $store['slug']) ?>.<?= e($platformHost) ?></code>.
                    Keep it short and easy to say out loud. Changing it stops every link you have already
                    handed out from working, so only change it if you have to.</span>
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
        <h2 class="cv-card__title">Your support chat</h2>
        <p>This is the chat button on your storefront. Our own chat never appears there — your customers talk to
            <strong>you</strong>, and reach your prices and your team.</p>

        <form method="post" action="/client/reseller/store/chat"><?= csrf_field() ?>
            <p>
                <label for="support_whatsapp">WhatsApp number</label><br>
                <input class="cv-input" type="text" id="support_whatsapp" name="support_whatsapp" maxlength="32"
                       placeholder="+234 801 234 5678"
                       value="<?= e($chat['support_whatsapp'] !== '' ? $chat['support_whatsapp'] : $phoneHint) ?>">
                <br><span style="color:var(--cv-text-secondary);">Include the country code. This becomes your default
                    chat button — it works straight away, and there is nothing to sign up for. Leave both fields blank
                    to show no chat at all.</span>
            </p>
            <p>
                <label for="tawk_property_id">Tawk.To property id <em>(optional)</em></label><br>
                <input class="cv-input" type="text" id="tawk_property_id" name="tawk_property_id" maxlength="64"
                       value="<?= e($chat['tawk_property_id']) ?>">
                <br><span style="color:var(--cv-text-secondary);">If you use Tawk.To, this <strong>replaces</strong> the
                    WhatsApp button with your own live chat. Paste only the <strong>id</strong> from the address Tawk
                    gives you — <code>embed.tawk.to/&lt;id&gt;/&lt;widget&gt;</code> — not the whole code block; we add
                    the code ourselves.</span>
            </p>
            <p>
                <label for="tawk_widget_id">Tawk.To widget id <em>(optional)</em></label><br>
                <input class="cv-input" type="text" id="tawk_widget_id" name="tawk_widget_id" maxlength="64"
                       value="<?= e($chat['tawk_widget_id']) ?>">
                <br><span style="color:var(--cv-text-secondary);">Leave blank unless your Tawk.To property has more than
                    one widget.</span>
            </p>
            <button class="cv-btn" type="submit">Save chat settings</button>
        </form>
    </div>

    <?php
    // What the SERVER has, which is not the same question as what the store is
    // allowed to use. The reseller cannot infer this from the field below, and it
    // is the state that decides whether their old address is still answering.
    $panelHost = trim((string) ($store['domain_provisioned_host'] ?? ''));
    $panelMatches = $panelHost !== '' && $panelHost === trim((string) ($customDomain ?? ''));
    ?>

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

            <?php if ($customDomain !== null): ?>
                <p class="cv-alert cv-alert--warning">
                    <strong>Saving a different name replaces this one.</strong>
                    <?= e((string) $customDomain) ?> stops being served immediately and is taken off our server, and
                    the new name has to be proved and approved before your store opens on it.
                    <?php if ($panelMatches): ?>
                        Right now <strong><?= e($panelHost) ?></strong> is set up on our server.
                    <?php endif; ?>
                    Save the same name again and nothing changes.
                </p>
            <?php endif; ?>

            <button class="cv-btn" type="submit"><?= $customDomain === null ? 'Claim this domain' : 'Update domain' ?></button>
        </form>

        <?php if ($panelHost !== '' && !$panelMatches): ?>
            <p style="margin-top:var(--cv-space-3);color:#b91c1c;">
                <strong><?= e($panelHost) ?></strong> is still set up on our server from a previous name and has to
                come off. Ask support if it is still there after you save.
            </p>
        <?php endif; ?>

        <?php
        // The admin's decision, shown to the reseller. A refusal without the
        // reason on screen is a dead end, and the reseller cannot resubmit
        // anything sensible without knowing what to change.
        $reviewStatus = (string) ($store['domain_status'] ?? 'none');
        $reviewLabels = [
            'none' => 'Not requested',
            'pending' => 'Waiting for review',
            'approved' => 'Approved',
            'rejected' => 'Refused',
        ];
        ?>
        <?php if ($customDomain !== null): ?>
            <p style="margin-top:var(--cv-space-3);">
                Review status: <strong><?= e($reviewLabels[$reviewStatus] ?? $reviewStatus) ?></strong>
                <?php if ($reviewStatus === 'pending'): ?>
                    — an administrator has to approve the domain before it can be set up on our server.
                <?php elseif ($reviewStatus === 'rejected'): ?>
                    <br><span style="color:#b91c1c;">Reason: <?= e((string) ($store['domain_review_note'] ?? 'no reason given')) ?></span>
                    <br><span style="color:var(--cv-text-secondary);">Change the domain above and save to submit it
                        again — a new submission is reviewed from scratch.</span>
                <?php endif; ?>
            </p>
        <?php endif; ?>

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

        <?php if ($goLive !== []): ?>
            <h3 style="margin-top:var(--cv-space-4);">Getting live on your own domain</h3>
            <table class="cv-table">
                <tbody>
                <?php foreach ($goLive as $step): ?>
                    <tr>
                        <td><?= e((string) $step['label']) ?></td>
                        <td>
                            <?php if ($step['manual']): ?>
                                <span class="cv-badge">Ask support</span>
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
            <p style="color:var(--cv-text-secondary);">The last two steps are server configuration, not something you or
                this page can change. Verifying the domain only proves it is yours. For your domain to actually open your
                store, two more things have to happen on the server: the domain is <strong>added to the server</strong>
                (so the server answers for that address) <em>and</em> its DNS points here. Ask support to finish those and
                your store will answer on it.</p>
            <p style="color:var(--cv-text-secondary);">Until then your store keeps working at
                <?php if ($platformUrl !== null): ?><code><?= e((string) $platformUrl) ?></code><?php else: ?>its platform address<?php endif; ?>
                — you do not have to wait to start selling.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>
