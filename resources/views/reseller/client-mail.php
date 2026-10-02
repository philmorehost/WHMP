<?php
/**
 * The store's support address.
 *
 * The page answers two questions in order, because the second is meaningless without the
 * first: "what address do my customers see?" and "will that address actually be accepted?"
 *
 * The second is the one people do not know to ask. A store with no address is ALREADY
 * white-labelled — its name is on the message and the platform's authenticated address
 * carries it. Setting an address whose domain has not authorised our server replaces
 * authenticated mail with mail that gets filtered, so the page says that plainly rather
 * than presenting the address as a strict upgrade.
 *
 * @var array<string, mixed> $store
 * @var string|null $address
 * @var string $domain
 * @var array{ok: bool, message: string} $mailboxReady
 * @var string|null $warning
 * @var string|null $remediation
 * @var bool $aligned
 * @var string $checkedAt
 * @var array{address: string, password: ?string, adopted: bool, status: string, message: string}|null $created
 * @var string|null $notice
 * @var string|null $error
 * @var string $platformSender
 */

$brand = trim((string) ($store['brand_name'] ?? ''));
$brand = $brand !== '' ? $brand : 'your store';
$status = (string) ($store['support_email_status'] ?? '');
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Your support address</h1>
    <p><a href="/client/reseller">&larr; Back to the reseller area</a> &middot;
        <a href="/client/reseller/store">Your store</a> &middot;
        <a href="/client/reseller/tickets">Your support queue</a></p>
    <p style="color:var(--cv-text-secondary);">
        This is the address your customers see when your store replies to a support ticket.
        <?php if ($address === null): ?>
            Right now it is <strong><?= e($platformSender !== '' ? $platformSender : 'our address') ?></strong>,
            with <strong><?= e($brand) ?></strong> as the name on every message — so your customers
            already see your store, not us.
        <?php else: ?>
            Right now it is <strong><?= e($address) ?></strong>.
        <?php endif; ?>
    </p>
</div>

<?php if ($error !== null && $error !== ''): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e((string) $error) ?></div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e((string) $notice) ?></div>
<?php endif; ?>

<?php if (is_array($created)): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4); border:2px solid var(--cv-color-brand-500);">
        <h2 class="cv-card__title">Your mailbox is ready</h2>
        <p><strong><?= e((string) $created['address']) ?></strong> — <?= e((string) $created['message']) ?></p>

        <?php if (($created['password'] ?? null) !== null): ?>
            <p><strong>Copy this password now.</strong> It is shown once and we do not keep it. You need it
                to sign in to webmail and read mail sent <em>to</em> this address — for example, a customer
                replying to a support email. If you lose it, reset it in cPanel.</p>
            <p><code><?= e((string) $created['password']) ?></code></p>
        <?php else: ?>
            <p style="color:var(--cv-text-secondary);">The mailbox already existed, so no new password was set —
                the password you already have still applies.</p>
        <?php endif; ?>

        <?php if ($created['adopted'] === true): ?>
            <div class="cv-alert cv-alert--success">
                Your domain authorises our server, so this address is now the one your customers see.
            </div>
        <?php else: ?>
            <div class="cv-alert cv-alert--warning">
                <strong>We have not switched your sending address to it yet.</strong>
                Your domain does not currently authorise our server to send as it, so mail from that address
                would be treated as unauthenticated and could land in your customers' spam folders.
                Until that changes, your customers still see <?= e($brand) ?> on messages sent from our
                authenticated address. Add the records below, then press <em>Check again</em>.
                <?php if ($remediation !== null): ?>
                    <div style="margin-top:var(--cv-space-2); white-space:pre-wrap;"><?= e($remediation) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($warning !== null): ?>
    <div class="cv-alert cv-alert--warning" style="margin-bottom:var(--cv-space-4);">
        <?= e($warning) ?>
        <?php if ($remediation !== null): ?>
            <div style="margin-top:var(--cv-space-2); white-space:pre-wrap;"><strong>What to add:</strong> <?= e($remediation) ?></div>
        <?php endif; ?>
    </div>
<?php elseif ($aligned && $checkedAt !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);">
        Checked <?= e($checkedAt) ?> — your domain authorises us to send as this address.
    </div>
<?php endif; ?>

<?php if ($mailboxReady['ok']): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Create a mailbox on your own domain</h2>
        <p style="color:var(--cv-text-secondary);"><?= e($mailboxReady['message']) ?></p>
        <p style="color:var(--cv-text-secondary); font-size:var(--cv-text-sm);">
            A mailbox matters even when we do not send from it yet: replies your customers send back go to
            this address, and with the mailbox in place you can read them in webmail.
        </p>
        <form method="post" action="/client/reseller/mail/create"><?= csrf_field() ?>
            <button type="submit" class="cv-btn">Create the mailbox</button>
        </form>
    </div>
<?php else: ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Create a mailbox on your own domain</h2>
        <p style="color:var(--cv-text-secondary);">
            Not available yet. <?= e($mailboxReady['message']) ?>
        </p>
    </div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Send from your own address</h2>
    <p style="color:var(--cv-text-secondary);">
        Enter an address on a domain you control. We will check whether that domain authorises our server,
        and tell you exactly what to add if it does not.
    </p>
    <form method="post" action="/client/reseller/mail"><?= csrf_field() ?>
        <div class="cv-form-group">
            <label for="support_email">Support address</label>
            <input type="email" id="support_email" name="support_email" class="cv-input"
                   value="<?= e((string) ($address ?? '')) ?>"
                   placeholder="<?= e($domain !== '' ? 'support@' . $domain : 'support@yourdomain.com') ?>" required>
        </div>
        <button type="submit" class="cv-btn">Save and check</button>
    </form>

    <?php if ($address !== null): ?>
        <div style="margin-top:var(--cv-space-4); display:flex; gap:var(--cv-space-3); flex-wrap:wrap; align-items:center;">
            <form method="post" action="/client/reseller/mail/check" style="margin:0;"><?= csrf_field() ?>
                <button type="submit" class="cv-btn cv-btn--secondary">Check again</button>
            </form>
            <form method="post" action="/client/reseller/mail/clear" style="margin:0;"><?= csrf_field() ?>
                <button type="submit" class="cv-btn cv-btn--secondary">Use our address instead</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Why this check exists</h2>
    <p style="color:var(--cv-text-secondary);">
        Mail providers check that the domain an email claims to come from actually authorised the server that
        sent it. If it did not, the message is treated as unauthenticated — it does not bounce, it quietly
        goes to spam, so nobody notices anything is wrong.
    </p>
    <p style="color:var(--cv-text-secondary);">
        Because your store's domain is hosted on our server, we can usually sign mail for it as soon as the
        right DNS records exist. The check above tells you whether they do — and if it cannot reach DNS at
        all, it says that instead of claiming a failure.
    </p>
</div>
