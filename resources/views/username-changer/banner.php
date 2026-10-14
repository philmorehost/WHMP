<?php
/**
 * cPanel Username Changer — compact banner + modal (a bottom sheet on phones).
 *
 * Self-contained and theme-independent: its own ucn-* styles read the site's
 * colour tokens when they exist and fall back to neutral values when they do
 * not, so it looks right on the classic client area, the premium main site and
 * every reseller store.
 *
 * The rules (length, reserved words) are embedded so the modal judges every
 * keystroke locally with no network at all; only the "is it free?" question
 * goes to the server, debounced, cached and cancellable (assets/js/username-changer.js).
 *
 * @var array<string, mixed> $ucn ClientUsernameController::bannerFor()
 */
$sid = (int) $ucn['service_id'];
$open = $ucn['open'] ?? null;
$accent = (string) ($ucn['accent'] ?? '');
$pay = $ucn['pay'] ?? null;
$pin = $ucn['pin'] ?? null;
$hasPin = !empty($ucn['has_pin']);
$fee = (string) ($ucn['fee'] ?? '');
$paymentReturn = (string) ($_GET['payment'] ?? '');
$json = json_encode(array_diff_key($ucn, ['pay' => true, 'history' => true]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>
<link rel="stylesheet" href="/assets/css/username-changer.css">
<div class="ucn" id="ucn-<?= $sid ?>" data-ucn-root="<?= $sid ?>"<?php if ($accent !== ''): ?> style="--ucn-accent: <?= e($accent) ?>;"<?php endif; ?>>
    <script type="application/json" data-ucn-data><?= $json ?></script>

    <div class="ucn-banner">
        <div class="ucn-banner__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4"/><path d="M17.5 14.5l3 3-4.5 4.5H13v-3z"/></svg>
        </div>
        <div class="ucn-banner__text">
            <span class="ucn-banner__label">cPanel username</span>
            <strong class="ucn-banner__name"><?= e((string) $ucn['current']) ?></strong>
            <?php if (is_array($open)): ?>
                <span class="ucn-pill ucn-pill--<?= e((string) $open['status']) ?>">→ <?= e((string) $open['new']) ?> · <?= e((string) $open['label']) ?></span>
            <?php elseif (!$ucn['can_request'] && !empty($ucn['blocked_reason'])): ?>
                <span class="ucn-banner__hint"><?= e((string) $ucn['blocked_reason']) ?></span>
            <?php elseif ($ucn['remaining'] !== null): ?>
                <span class="ucn-banner__hint"><?= (int) $ucn['remaining'] ?> change<?= (int) $ucn['remaining'] === 1 ? '' : 's' ?> left<?= !empty($ucn['fee']) ? ' · ' . e((string) $ucn['fee']) . ' per change' : '' ?></span>
            <?php elseif (!empty($ucn['fee'])): ?>
                <span class="ucn-banner__hint"><?= e((string) $ucn['fee']) ?> per change</span>
            <?php endif; ?>
        </div>
        <button type="button" class="ucn-btn ucn-btn--primary" data-ucn-open="<?= $sid ?>">
            <?= is_array($open) ? 'View request' : 'Change' ?>
        </button>
    </div>

    <div class="ucn-modal" data-ucn-modal hidden>
        <div class="ucn-modal__backdrop" data-ucn-close></div>
        <div class="ucn-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ucn-title-<?= $sid ?>">
            <header class="ucn-modal__head">
                <div>
                    <h2 class="ucn-modal__title" id="ucn-title-<?= $sid ?>"><?= e((string) $ucn['heading']) ?></h2>
                    <p class="ucn-modal__sub"><?= e((string) $ucn['domain']) ?> · currently <strong><?= e((string) $ucn['current']) ?></strong></p>
                </div>
                <button type="button" class="ucn-icon-btn" data-ucn-close aria-label="Close">✕</button>
            </header>

            <?php if ($paymentReturn === 'success'): ?>
                <div class="ucn-flash ucn-flash--ok" data-ucn-flash>Payment received — thank you. Your username change is on its way.</div>
            <?php elseif ($paymentReturn === 'failed' || $paymentReturn === 'error'): ?>
                <div class="ucn-flash ucn-flash--bad" data-ucn-flash>The payment was not completed. You can try again below.</div>
            <?php else: ?>
                <div class="ucn-flash" data-ucn-flash hidden></div>
            <?php endif; ?>

            <?php if (is_array($open)): ?>
                <section class="ucn-open">
                    <p>Your request to change to <strong><?= e((string) $open['new']) ?></strong> is <strong><?= e((string) $open['label']) ?></strong>.</p>
                    <?php if (is_array($pay)): ?>
                        <?= $view->render('username-changer.pay-panel', ['pay' => $pay]) ?>
                    <?php elseif (!empty($open['invoice_id']) && $open['status'] === 'awaiting_payment'): ?>
                        <p><a class="ucn-btn ucn-btn--primary" href="/client/invoices/<?= (int) $open['invoice_id'] ?>">Pay invoice #<?= (int) $open['invoice_id'] ?></a></p>
                    <?php endif; ?>
                    <div class="ucn-row">
                        <?php if (!empty($open['can_resend'])): ?>
                            <form method="post" action="/client/services/<?= $sid ?>/username/<?= (int) $open['id'] ?>/resend" data-ucn-ajax>
                                <?= csrf_field() ?>
                                <button class="ucn-btn" type="submit">Resend confirmation email</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!empty($open['can_cancel'])): ?>
                            <form method="post" action="/client/services/<?= $sid ?>/username/<?= (int) $open['id'] ?>/cancel" data-ucn-ajax data-ucn-confirm="Cancel this username change request?">
                                <?= csrf_field() ?>
                                <button class="ucn-btn ucn-btn--ghost" type="submit">Cancel request</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>
            <?php elseif (!$ucn['can_request']): ?>
                <section class="ucn-open"><p><?= e((string) ($ucn['blocked_reason'] ?? 'Username changes are not available right now.')) ?></p></section>
            <?php else: ?>
                <form class="ucn-form" method="post" action="/client/services/<?= $sid ?>/username" data-ucn-form novalidate>
                    <?= csrf_field() ?>
                    <ol class="ucn-steps" aria-hidden="true">
                        <li class="is-active" data-ucn-step-dot="1">New name</li>
                        <li data-ucn-step-dot="2">What changes</li>
                        <li data-ucn-step-dot="3">Confirm</li>
                        <?php if ($fee !== ''): ?><li data-ucn-step-dot="4">Pay</li><?php endif; ?>
                    </ol>

                    <section class="ucn-step" data-ucn-step="1">
                        <label class="ucn-label" for="ucn-name-<?= $sid ?>">New username</label>
                        <div class="ucn-field">
                            <input class="ucn-input" id="ucn-name-<?= $sid ?>" name="new_username" autocomplete="off" autocapitalize="none" spellcheck="false"
                                   maxlength="<?= (int) $ucn['rules']['max'] ?>" placeholder="e.g. <?= e((string) (($ucn['suggestions'][0] ?? '') ?: 'acmehost')) ?>" data-ucn-input>
                            <span class="ucn-status" data-ucn-status aria-hidden="true"></span>
                        </div>
                        <p class="ucn-verdict" data-ucn-verdict aria-live="polite">
                            <?= (int) $ucn['rules']['min'] ?>–<?= (int) $ucn['rules']['max'] ?> characters, lowercase letters and digits, starting with a letter.
                        </p>
                        <div class="ucn-chips" data-ucn-chips>
                            <?php foreach (($ucn['suggestions'] ?? []) as $s): ?>
                                <button type="button" class="ucn-chip" data-ucn-chip="<?= e((string) $s) ?>"><?= e((string) $s) ?></button>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($ucn['fee'])): ?>
                            <p class="ucn-fee">Fee: <strong><?= e((string) $ucn['fee']) ?></strong> — invoiced after you confirm<?= !empty($ucn['approval']) ? ' and the request is approved' : '' ?>. The change runs as soon as it is paid.</p>
                        <?php endif; ?>
                        <div class="ucn-actions">
                            <button type="button" class="ucn-btn ucn-btn--primary" data-ucn-next disabled>Continue</button>
                        </div>
                    </section>

                    <section class="ucn-step" data-ucn-step="2" hidden>
                        <p class="ucn-lead">Changing <strong><?= e((string) $ucn['current']) ?></strong> to <strong data-ucn-new-label>…</strong> affects:</p>
                        <label class="ucn-check"><input type="checkbox" name="ack_login" value="1" data-ucn-ack> Your cPanel login name changes (your password stays the same).</label>
                        <label class="ucn-check"><input type="checkbox" name="ack_home" value="1" data-ucn-ack> Your home folder moves from <code>/home/<?= e((string) $ucn['current']) ?></code> to <code>/home/<span data-ucn-new-label>new</span></code> — scripts and cron jobs with the old path need updating.</label>
                        <label class="ucn-check"><input type="checkbox" name="ack_ftp" value="1" data-ucn-ack> FTP and SSH logins that use the account name change too.</label>
                        <?php if (!empty($ucn['allow_db_rename'])): ?>
                            <label class="ucn-check ucn-check--opt"><input type="checkbox" name="rename_db" value="1"> Also rename my databases and database users to the new prefix <span class="ucn-muted">(you must then update wp-config.php and similar files)</span></label>
                        <?php else: ?>
                            <p class="ucn-muted">Databases keep their current names, so your websites keep working.</p>
                        <?php endif; ?>
                        <label class="ucn-label" for="ucn-reason-<?= $sid ?>">Reason <?= !empty($ucn['require_reason']) ? '' : '<span class="ucn-muted">(optional)</span>' ?></label>
                        <textarea class="ucn-input ucn-textarea" id="ucn-reason-<?= $sid ?>" name="reason" maxlength="500" rows="2"<?= !empty($ucn['require_reason']) ? ' required' : '' ?>></textarea>
                        <div class="ucn-actions">
                            <button type="button" class="ucn-btn ucn-btn--ghost" data-ucn-back>Back</button>
                            <button type="button" class="ucn-btn ucn-btn--primary" data-ucn-next disabled data-ucn-ack-next>Continue</button>
                        </div>
                    </section>

                    <section class="ucn-step" data-ucn-step="3" hidden>
                        <p class="ucn-lead">How do you want to confirm?</p>
                        <div class="ucn-methods">
                            <?php foreach ($ucn['methods'] as $i => $method): ?>
                                <label class="ucn-method">
                                    <input type="radio" name="method" value="<?= e((string) $method) ?>" <?= $i === 0 ? 'checked' : '' ?> data-ucn-method>
                                    <span><?= $method === 'pin' ? '<strong>Security PIN</strong> — instant' : '<strong>Email link</strong> — we email you a one-time link' ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div data-ucn-pin hidden>
                            <?php if ($hasPin || !is_array($pin)): ?>
                                <div class="ucn-labelrow">
                                    <label class="ucn-label" for="ucn-pin-<?= $sid ?>">Security PIN</label>
                                    <?php if (is_array($pin)): ?><button type="button" class="ucn-link" data-ucn-pin-open="forgot">Forgot PIN?</button><?php endif; ?>
                                </div>
                                <input class="ucn-input" id="ucn-pin-<?= $sid ?>" name="pin" type="password" inputmode="numeric" autocomplete="off" maxlength="<?= (int) ($pin['max'] ?? 12) ?>" data-ucn-pin-input>
                            <?php else: ?>
                                <div class="ucn-pin-missing" data-ucn-pin-missing>
                                    <p>You haven't set a Security PIN yet. Set one now — it takes a moment and you'll stay right here.</p>
                                    <button type="button" class="ucn-btn" data-ucn-pin-open="missing">Set a Security PIN</button>
                                </div>
                                <input class="ucn-input" id="ucn-pin-<?= $sid ?>" name="pin" type="password" inputmode="numeric" autocomplete="off" maxlength="<?= (int) $pin['max'] ?>" data-ucn-pin-input hidden>
                            <?php endif; ?>
                        </div>
                        <?php if ($fee !== ''): ?>
                            <p class="ucn-fee"><?= !empty($ucn['approval'])
                                ? 'Fee: <strong>' . e($fee) . '</strong> — you can pay from your wallet or online once the request is approved.'
                                : 'Next: pay <strong>' . e($fee) . '</strong> from your wallet or online, and the change runs straight away.' ?></p>
                        <?php endif; ?>
                        <div class="ucn-actions">
                            <button type="button" class="ucn-btn ucn-btn--ghost" data-ucn-back>Back</button>
                            <button type="submit" class="ucn-btn ucn-btn--primary" data-ucn-submit>Request change</button>
                        </div>
                    </section>
                </form>
                <?php // Outside the form: the Pay step holds forms of its own. ?>
                <section class="ucn-step ucn-step--pay" data-ucn-step="4" data-ucn-paystep hidden aria-live="polite"></section>
            <?php endif; ?>

            <?php if (!empty($ucn['history'])): ?>
                <details class="ucn-history">
                    <summary>History</summary>
                    <ul>
                        <?php foreach ($ucn['history'] as $h): ?>
                            <li>
                                <span><?= e($h['old']) ?> → <strong><?= e($h['new']) ?></strong></span>
                                <span class="ucn-pill ucn-pill--<?= e($h['status']) ?>"><?= e($h['label']) ?></span>
                                <span class="ucn-muted"><?= e($h['completed'] ?? $h['created']) ?></span>
                                <?php if (!empty($h['decline_reason'])): ?><span class="ucn-muted">— <?= e($h['decline_reason']) ?></span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>

        <?php if (is_array($pin)): ?>
            <div class="ucn-pinm" data-ucn-pinm hidden>
                <div class="ucn-pinm__backdrop" data-ucn-pinm-close></div>
                <div class="ucn-pinm__dialog" role="dialog" aria-modal="true" aria-labelledby="ucn-pinm-title-<?= $sid ?>">
                    <header class="ucn-modal__head">
                        <div>
                            <h3 class="ucn-modal__title" id="ucn-pinm-title-<?= $sid ?>" data-ucn-pinm-title><?= $hasPin ? 'Reset your Security PIN' : 'Set a Security PIN' ?></h3>
                            <p class="ucn-modal__sub" data-ucn-pinm-reason>Your username change is kept — close this when you're done to carry on.</p>
                        </div>
                        <button type="button" class="ucn-icon-btn" data-ucn-pinm-close aria-label="Close">✕</button>
                    </header>
                    <div class="ucn-flash" data-ucn-pinm-flash hidden></div>
                    <form class="ucn-pinm__form" method="post" action="/client/services/<?= $sid ?>/username/pin" data-ucn-pinm-form novalidate>
                        <?= csrf_field() ?>
                        <p class="ucn-label">First, confirm it's you</p>
                        <div class="ucn-seg" role="radiogroup">
                            <?php foreach ($pin['methods'] as $i => $m): ?>
                                <label class="ucn-seg__opt">
                                    <input type="radio" name="via" value="<?= e($m) ?>" <?= $i === 0 ? 'checked' : '' ?> data-ucn-pinm-via>
                                    <span><?= $m === 'code' ? 'Email me a code' : 'Use my password' ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div data-ucn-pinm-section="code">
                            <div class="ucn-row ucn-row--tight">
                                <input class="ucn-input ucn-input--code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6-digit code" aria-label="Code from email" data-ucn-pinm-code>
                                <button type="button" class="ucn-btn" data-ucn-pinm-send>Send code</button>
                            </div>
                            <p class="ucn-muted">We'll email it to <?= e((string) $pin['email']) ?>.</p>
                        </div>
                        <?php if (in_array('password', $pin['methods'], true)): ?>
                            <div data-ucn-pinm-section="password" hidden>
                                <input class="ucn-input" name="current_password" type="password" autocomplete="current-password" placeholder="Your account password" aria-label="Account password">
                            </div>
                        <?php endif; ?>
                        <label class="ucn-label" for="ucn-newpin-<?= $sid ?>">New Security PIN</label>
                        <input class="ucn-input" id="ucn-newpin-<?= $sid ?>" name="new_pin" type="password" inputmode="numeric" autocomplete="new-password" minlength="<?= (int) $pin['min'] ?>" maxlength="<?= (int) $pin['max'] ?>" placeholder="<?= (int) $pin['min'] ?>–<?= (int) $pin['max'] ?> characters" data-ucn-pinm-new>
                        <input class="ucn-input" name="new_pin_confirm" type="password" inputmode="numeric" autocomplete="new-password" maxlength="<?= (int) $pin['max'] ?>" placeholder="Repeat the new PIN" aria-label="Repeat the new PIN" style="margin-top:8px;">
                        <div class="ucn-actions">
                            <button type="button" class="ucn-btn ucn-btn--ghost" data-ucn-pinm-close>Close</button>
                            <button type="submit" class="ucn-btn ucn-btn--primary" data-ucn-pinm-save>Save PIN</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<script src="/assets/js/username-changer.js" defer></script>
