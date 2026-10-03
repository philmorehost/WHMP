<?php
/**
 * One of the store's customers, and everything the reseller can do for them
 * (ResellerClientManager). Every list on this page was read through the store
 * (ResellerClientDirectory), so it only ever shows this store's customer's rows.
 *
 * @var array<string, mixed> $store
 * @var array<string, mixed> $client
 * @var array<int, array<string, mixed>> $services
 * @var array<int, array<string, mixed>> $domains
 * @var array<int, array<string, mixed>> $invoices
 * @var array<int, array<string, mixed>> $tickets
 * @var callable(float): string $serviceMoney
 * @var callable(array<string, mixed>): string $invoiceMoney
 * @var string|null $storeError
 * @var string|null $notice
 * @var string|null $error
 * @var array<string, mixed>|null $actor   who is acting (the reseller, or ResellerClientManager::adminActor())
 * @var string|null $mode    'reseller' (default) or 'admin' (the reseller's page in the admin panel)
 * @var string|null $baseUrl the customer list this page belongs to
 * @var array<int, array<string, mixed>>|null $orders
 * @var array<string, mixed>|null $owner   admin mode: the reseller's own user account
 * @var string|null $storeNote admin mode: a note about the store's state
 */

use CodeVault\Reseller\ResellerClientManager;

$id = (int) $client['id'];
$storeId = (int) $store['id'];
$name = trim($client['first_name'] . ' ' . $client['last_name']);
$canAct = $storeError === null;
$closed = (string) $client['status'] === 'closed';
$isAdmin = ($mode ?? 'reseller') === 'admin';
$actor = is_array($actor ?? null) ? $actor : [];
$listUrl = (string) ($baseUrl ?? '/client/reseller/clients');
$base = $listUrl . '/' . $id;
$orders = $orders ?? [];

$serviceStatus = [
    'active' => ['Active', 'cv-badge--success'],
    'pending' => ['Pending', 'cv-badge--neutral'],
    'suspended' => ['Suspended', 'cv-badge--warning'],
    'cancelled' => ['Cancelled', 'cv-badge--neutral'],
    'terminated' => ['Terminated', 'cv-badge--danger'],
];
$domainStatus = [
    'active' => ['Active', 'cv-badge--success'],
    'pending' => ['Pending', 'cv-badge--neutral'],
    'grace' => ['Grace period', 'cv-badge--warning'],
    'redemption' => ['Redemption', 'cv-badge--danger'],
    'expired' => ['Expired', 'cv-badge--danger'],
    'cancelled' => ['Cancelled', 'cv-badge--neutral'],
    'transferred_away' => ['Transferred away', 'cv-badge--neutral'],
];
$invoiceStatus = [
    'unpaid' => ['Unpaid', 'cv-badge--danger'],
    'paid' => ['Paid', 'cv-badge--success'],
    'cancelled' => ['Cancelled', 'cv-badge--neutral'],
    'refunded' => ['Refunded', 'cv-badge--neutral'],
];
$ticketStatus = [
    'open' => 'Open',
    'customer-reply' => 'Customer replied',
    'answered' => 'Answered',
    'closed' => 'Closed',
];
$cycles = [
    'one_time' => 'one-time',
    'monthly' => 'monthly',
    'quarterly' => 'quarterly',
    'semi_annually' => 'every 6 months',
    'annually' => 'yearly',
    'biennially' => 'every 2 years',
    'triennially' => 'every 3 years',
];
$badge = static fn (array $map, string $key): string => '<span class="cv-badge ' . ($map[$key][1] ?? 'cv-badge--neutral') . '">'
    . e($map[$key][0] ?? ucfirst(str_replace('_', ' ', $key))) . '</span>';
$v = static fn (string $field): string => e((string) ($client[$field] ?? ''));
?>
<?php if ($isAdmin): ?>
    <?= $view->render('partials.reseller-admin-head', ['store' => $store, 'owner' => $owner ?? null, 'current' => 'customers']) ?>
<?php endif; ?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <header class="rs-head">
        <h1 class="rs-head__title"><?= e($name) ?> <span class="rs-id-badge rs-id-badge--user" title="This customer's unique user ID">User ID <?= $id ?></span></h1>
    </header>
    <?php if (!$isAdmin): ?><?= $view->render('partials.reseller-nav') ?><?php endif; ?>
    <div class="rs-cust-hero">
        <div>
            <p class="rs-cust-back"><a href="<?= e($listUrl) ?>">&larr; All <?= $isAdmin ? 'of this reseller\'s ' : '' ?>customers</a></p>
            <p class="rs-cust-sub">
                <?= e((string) $client['email']) ?>
                <?php if (trim((string) ($client['company_name'] ?? '')) !== ''): ?> · <?= e((string) $client['company_name']) ?><?php endif; ?>
                · customer since <?= e(substr((string) $client['created_at'], 0, 10)) ?>
                <?php if ($closed): ?> · <span class="cv-badge cv-badge--neutral">Account closed</span><?php endif; ?>
            </p>
        </div>
        <?php if ($canAct && !$closed): ?>
            <div class="rs-cust-hero__actions">
                <form method="post" action="<?= $base ?>/login" target="_blank">
                    <?= csrf_field() ?>
                    <button class="cv-btn" type="submit">Log in as customer &#8599;</button>
                </form>
                <form method="post" action="<?= $base ?>/password-reset" data-confirm="Email <?= e((string) $client['email']) ?> a link to choose a new password?">
                    <?= csrf_field() ?>
                    <button class="cv-btn cv-btn--secondary" type="submit">Send password reset</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($canAct && !$closed && $isAdmin): ?>
        <p class="rs-cust-note">
            This customer belongs to <strong>Reseller ID <?= $storeId ?></strong>. <strong>Log in as customer</strong> opens the
            reseller's website in a new tab, signed in to this account. Changes you make here are recorded as yours in the activity log.
            To move this customer to the main site or another reseller, use
            <a href="/admin/resellers/migrations/review?client=<?= $id ?>">Move provider</a> (super admin).
        </p>
    <?php elseif ($canAct && !$closed): ?>
        <p class="rs-cust-note">
            <strong>Log in as customer</strong> opens your store's website in a new tab, signed in to this account, so you
            can order, pay, open tickets or manage services exactly as they would. Their password, security settings and
            saved cards stay theirs to change.
        </p>
    <?php endif; ?>
</div>

<?php if ($storeError !== null): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e($storeError) ?></div>
<?php endif; ?>
<?php if (!empty($storeNote)): ?>
    <div class="cv-alert cv-alert--warning" style="margin-bottom:var(--cv-space-4);"><?= e((string) $storeNote) ?></div>
<?php endif; ?>
<?php if ($error !== null && $error !== ''): ?>
    <div class="cv-alert cv-alert--error" role="alert" style="margin-bottom:var(--cv-space-4);"><?= e((string) $error) ?></div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success" role="status" style="margin-bottom:var(--cv-space-4);"><?= e((string) $notice) ?></div>
<?php endif; ?>

<!-- Services -->
<section class="cv-card" style="margin-bottom:var(--cv-space-4);" aria-labelledby="rs-services">
    <h2 class="cv-card__title" id="rs-services">Services <span class="rs-cust-count"><?= count($services) ?></span></h2>
    <?php if ($services === []): ?>
        <p class="rs-cust-sub">No services yet.</p>
    <?php else: ?>
        <div class="rs-cust-table-wrap">
            <table class="cv-table rs-cust-table">
                <thead>
                    <tr><th scope="col">Service</th><th scope="col">Status</th><th scope="col">Price</th><th scope="col">Next due</th><th scope="col"><span class="rs-visually-hidden">Actions</span></th></tr>
                </thead>
                <tbody>
                <?php foreach ($services as $service): ?>
                    <?php
                    $sid = (int) $service['id'];
                    $status = (string) $service['status'];
                    $where = (string) ($service['domain'] ?? '') !== '' ? (string) $service['domain'] : (string) ($service['hostname'] ?? '');
                    $canLift = ResellerClientManager::canLiftAs($service, $storeId, $actor);
                    $byStore = ResellerClientManager::canLift($service, $storeId);
                    ?>
                    <tr>
                        <td data-label="Service">
                            <?php if ($isAdmin): ?><a href="/admin/services/<?= $sid ?>"><strong><?= e((string) $service['product_name']) ?></strong></a> <span class="rs-cust-sub">#<?= $sid ?></span><?php else: ?><strong><?= e((string) $service['product_name']) ?></strong><?php endif; ?>
                            <?php if ($where !== ''): ?><div class="rs-cust-sub"><?= e($where) ?></div><?php endif; ?>
                            <?php if (!empty($service['username'])): ?><div class="rs-cust-sub">Username: <?= e((string) $service['username']) ?></div><?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <?= $badge($serviceStatus, $status) ?>
                            <?php if ($status === 'suspended'): ?>
                                <div class="rs-cust-sub"><?= $isAdmin ? ($byStore ? 'Suspended by the reseller' : 'Suspended by ' . e(brand_name())) : ($canLift ? 'Suspended by you' : 'Suspended by ' . e(brand_name())) ?><?php if (trim((string) ($service['suspension_reason'] ?? '')) !== ''): ?>: <?= e((string) $service['suspension_reason']) ?><?php endif; ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Price"><?= e($serviceMoney((float) $service['amount'])) ?> <span class="rs-cust-sub"><?= e($cycles[(string) $service['billing_cycle']] ?? (string) $service['billing_cycle']) ?></span></td>
                        <td data-label="Next due"><?= e((string) $service['next_due_date']) ?></td>
                        <td class="rs-cust-actions">
                            <?php if ($canAct): ?>
                                <?php if (ResellerClientManager::canSuspend($service)): ?>
                                    <details class="rs-cust-pop">
                                        <summary class="cv-btn cv-btn--secondary rs-btn-sm">Suspend</summary>
                                        <form method="post" action="<?= $base ?>/services/<?= $sid ?>/suspend" class="rs-cust-pop__body">
                                            <?= csrf_field() ?>
                                            <label class="cv-label" for="reason-<?= $sid ?>">Reason <span class="rs-cust-sub">(the customer sees this)</span></label>
                                            <input class="cv-input" id="reason-<?= $sid ?>" type="text" name="reason" maxlength="200" placeholder="e.g. Outstanding balance">
                                            <button class="cv-btn rs-btn-sm" type="submit">Suspend service</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                                <?php if ($canLift): ?>
                                    <form method="post" action="<?= $base ?>/services/<?= $sid ?>/unsuspend">
                                        <?= csrf_field() ?>
                                        <button class="cv-btn rs-btn-sm" type="submit">Unsuspend</button>
                                    </form>
                                <?php elseif ($status === 'suspended'): ?>
                                    <span class="rs-cust-sub" title="Only our team can lift a suspension we made">Contact support to lift</span>
                                <?php endif; ?>
                                <?php if (ResellerClientManager::canTerminate($service)): ?>
                                    <details class="rs-cust-pop">
                                        <summary class="cv-btn cv-btn--danger rs-btn-sm">Terminate</summary>
                                        <form method="post" action="<?= $base ?>/services/<?= $sid ?>/terminate" class="rs-cust-pop__body">
                                            <?= csrf_field() ?>
                                            <p class="rs-cust-sub" style="margin:0;">Deletes the account and all its files on the server. This cannot be undone.</p>
                                            <label class="rs-cust-check"><input type="checkbox" name="confirm" value="1" required> I understand, terminate it</label>
                                            <button class="cv-btn cv-btn--danger rs-btn-sm" type="submit">Terminate service</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- Domains -->
<section class="cv-card" style="margin-bottom:var(--cv-space-4);" aria-labelledby="rs-domains">
    <h2 class="cv-card__title" id="rs-domains">Domains <span class="rs-cust-count"><?= count($domains) ?></span></h2>
    <?php if ($domains === []): ?>
        <p class="rs-cust-sub">No domains yet.</p>
    <?php else: ?>
        <div class="rs-cust-table-wrap">
            <table class="cv-table rs-cust-table">
                <thead>
                    <tr><th scope="col">Domain</th><th scope="col">Status</th><th scope="col">Expires</th><th scope="col">Auto-renew</th><th scope="col"><span class="rs-visually-hidden">Actions</span></th></tr>
                </thead>
                <tbody>
                <?php foreach ($domains as $domain): ?>
                    <?php
                    $did = (int) $domain['id'];
                    $dStatus = (string) $domain['status'];
                    $ns = json_decode((string) ($domain['nameservers'] ?? ''), true);
                    $ns = is_array($ns) ? array_values(array_map('strval', $ns)) : [];
                    $autoRenew = !empty($domain['auto_renew']);
                    $locked = !empty($domain['registrar_lock_enabled']);
                    ?>
                    <tr>
                        <td data-label="Domain">
                            <?php if ($isAdmin): ?><a href="/admin/domains/<?= $did ?>"><strong><?= e((string) $domain['domain_name']) ?></strong></a><?php else: ?><strong><?= e((string) $domain['domain_name']) ?></strong><?php endif; ?>
                            <?php if ($ns !== []): ?><div class="rs-cust-sub"><?= e(implode(', ', $ns)) ?></div><?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <?= $badge($domainStatus, $dStatus) ?>
                            <?php if ($dStatus === 'active'): ?><div class="rs-cust-sub"><?= $locked ? '🔒 Transfer locked' : 'Transfer unlocked' ?></div><?php endif; ?>
                        </td>
                        <td data-label="Expires"><?= e((string) ($domain['expiry_date'] ?? '—')) ?></td>
                        <td data-label="Auto-renew">
                            <?php if ($canAct && in_array($dStatus, ['active', 'pending', 'grace'], true)): ?>
                                <form method="post" action="<?= $base ?>/domains/<?= $did ?>/auto-renew">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="auto_renew" value="<?= $autoRenew ? '0' : '1' ?>">
                                    <button class="cv-btn cv-btn--secondary rs-btn-sm" type="submit" aria-label="Turn auto-renew <?= $autoRenew ? 'off' : 'on' ?> for <?= e((string) $domain['domain_name']) ?>">
                                        <?= $autoRenew ? 'On — turn off' : 'Off — turn on' ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <?= $autoRenew ? 'On' : 'Off' ?>
                            <?php endif; ?>
                        </td>
                        <td class="rs-cust-actions">
                            <?php if ($canAct && $dStatus === 'active'): ?>
                                <form method="post" action="<?= $base ?>/domains/<?= $did ?>/lock">
                                    <?= csrf_field() ?>
                                    <button class="cv-btn cv-btn--secondary rs-btn-sm" type="submit"><?= $locked ? 'Unlock' : 'Lock' ?></button>
                                </form>
                                <details class="rs-cust-pop">
                                    <summary class="cv-btn cv-btn--secondary rs-btn-sm">Nameservers</summary>
                                    <form method="post" action="<?= $base ?>/domains/<?= $did ?>/nameservers" class="rs-cust-pop__body">
                                        <?= csrf_field() ?>
                                        <?php for ($i = 1; $i <= 6; $i++): ?>
                                            <label class="rs-visually-hidden" for="ns<?= $i ?>-<?= $did ?>">Nameserver <?= $i ?></label>
                                            <input class="cv-input" id="ns<?= $i ?>-<?= $did ?>" type="text" name="ns<?= $i ?>" value="<?= e($ns[$i - 1] ?? '') ?>" placeholder="ns<?= $i ?>.example.com<?= $i > 2 ? ' (optional)' : '' ?>" <?= $i <= 2 ? 'required' : '' ?>>
                                        <?php endfor; ?>
                                        <button class="cv-btn rs-btn-sm" type="submit">Save nameservers</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div class="rs-cust-grid">
    <!-- Profile -->
    <section class="cv-card" aria-labelledby="rs-profile">
        <h2 class="cv-card__title" id="rs-profile">Customer details</h2>
        <form method="post" action="<?= $base ?>/profile" class="rs-cust-form">
            <?= csrf_field() ?>
            <fieldset <?= $canAct ? '' : 'disabled' ?> style="border:0;padding:0;margin:0;">
                <div class="rs-cust-form__row">
                    <div class="cv-field"><label class="cv-label" for="p-first">First name</label><input class="cv-input" id="p-first" name="first_name" required maxlength="100" value="<?= $v('first_name') ?>"></div>
                    <div class="cv-field"><label class="cv-label" for="p-last">Last name</label><input class="cv-input" id="p-last" name="last_name" required maxlength="100" value="<?= $v('last_name') ?>"></div>
                </div>
                <div class="rs-cust-form__row">
                    <div class="cv-field"><label class="cv-label" for="p-company">Company</label><input class="cv-input" id="p-company" name="company_name" maxlength="191" value="<?= $v('company_name') ?>"></div>
                    <div class="cv-field"><label class="cv-label" for="p-phone">Phone</label><input class="cv-input" id="p-phone" name="phone" type="tel" maxlength="40" value="<?= $v('phone') ?>"></div>
                </div>
                <div class="cv-field"><label class="cv-label" for="p-a1">Address</label><input class="cv-input" id="p-a1" name="address1" maxlength="191" value="<?= $v('address1') ?>"></div>
                <div class="cv-field"><label class="rs-visually-hidden" for="p-a2">Address line 2</label><input class="cv-input" id="p-a2" name="address2" maxlength="191" value="<?= $v('address2') ?>" placeholder="Address line 2 (optional)"></div>
                <div class="rs-cust-form__row">
                    <div class="cv-field"><label class="cv-label" for="p-city">City</label><input class="cv-input" id="p-city" name="city" maxlength="100" value="<?= $v('city') ?>"></div>
                    <div class="cv-field"><label class="cv-label" for="p-state">State</label><input class="cv-input" id="p-state" name="state" maxlength="100" value="<?= $v('state') ?>"></div>
                </div>
                <div class="rs-cust-form__row">
                    <div class="cv-field"><label class="cv-label" for="p-post">Postcode</label><input class="cv-input" id="p-post" name="postcode" maxlength="20" value="<?= $v('postcode') ?>"></div>
                    <div class="cv-field"><label class="cv-label" for="p-country">Country code</label><input class="cv-input" id="p-country" name="country" maxlength="2" value="<?= $v('country') ?>" placeholder="NG"></div>
                </div>
                <p class="rs-cust-sub">The login email (<?= e((string) $client['email']) ?>) can only be changed by the customer.</p>
                <button class="cv-btn" type="submit">Save details</button>
            </fieldset>
        </form>
    </section>

    <div>
        <!-- Invoices -->
        <section class="cv-card" style="margin-bottom:var(--cv-space-4);" aria-labelledby="rs-invoices">
            <h2 class="cv-card__title" id="rs-invoices">Recent invoices</h2>
            <?php if ($invoices === []): ?>
                <p class="rs-cust-sub">No invoices yet.</p>
            <?php else: ?>
                <table class="cv-table rs-cust-table rs-cust-table--compact">
                    <thead><tr><th scope="col">Invoice</th><th scope="col">Due</th><th scope="col">Total</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td data-label="Invoice"><?php if ($isAdmin): ?><a href="/admin/invoices/<?= (int) $invoice['id'] ?>">#<?= (int) $invoice['id'] ?></a><?php else: ?>#<?= (int) $invoice['id'] ?><?php endif; ?></td>
                            <td data-label="Due"><?= e((string) $invoice['due_date']) ?></td>
                            <td data-label="Total"><?= e($invoiceMoney($invoice)) ?></td>
                            <td data-label="Status"><?= $badge($invoiceStatus, (string) $invoice['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <?php if (!$isAdmin): ?>
            <p class="rs-cust-sub" style="margin-top:var(--cv-space-2);">
                Payments are collected through <?= e(brand_name()) ?>, so invoices can't be changed here. To pay one for the
                customer, log in as them.
            </p>
            <?php endif; ?>
        </section>

        <!-- Tickets -->
        <section class="cv-card" aria-labelledby="rs-tickets">
            <h2 class="cv-card__title" id="rs-tickets">Support tickets</h2>
            <?php if ($tickets === []): ?>
                <p class="rs-cust-sub">No tickets from this customer.</p>
            <?php else: ?>
                <ul class="rs-cust-list">
                    <?php foreach ($tickets as $ticket): ?>
                        <li>
                            <?php if (!$isAdmin): ?>
                                <a href="/client/reseller/tickets/<?= (int) $ticket['id'] ?>">#<?= (int) $ticket['id'] ?> <?= e((string) $ticket['subject']) ?></a>
                            <?php elseif (!empty($ticket['escalated_at'])): ?>
                                <a href="/admin/tickets/<?= (int) $ticket['id'] ?>">#<?= (int) $ticket['id'] ?> <?= e((string) $ticket['subject']) ?></a> <span class="cv-badge cv-badge--warning">Escalated</span>
                            <?php else: ?>
                                #<?= (int) $ticket['id'] ?> <?= e((string) $ticket['subject']) ?> <span class="rs-cust-sub">(on the reseller's desk)</span>
                            <?php endif; ?>
                            <span class="rs-cust-sub"><?= e($ticketStatus[(string) $ticket['status']] ?? (string) $ticket['status']) ?> · <?= e(substr((string) $ticket['updated_at'], 0, 16)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php if ($isAdmin): ?>
    <!-- Orders (the platform fulfils them, so pending ones are accepted from the order page) -->
    <section class="cv-card" style="margin-top:var(--cv-space-4);" aria-labelledby="rs-orders">
        <h2 class="cv-card__title" id="rs-orders">Orders <span class="rs-cust-count"><?= count($orders) ?></span></h2>
        <?php if ($orders === []): ?>
            <p class="rs-cust-sub">No orders yet.</p>
        <?php else: ?>
            <ul class="rs-cust-list">
                <?php foreach ($orders as $order): ?>
                    <li>
                        <a href="/admin/orders/<?= (int) $order['id'] ?>">Order #<?= (int) $order['id'] ?></a>
                        <span class="cv-badge cv-badge--<?= (string) $order['status'] === 'pending' ? 'warning' : ((string) $order['status'] === 'active' ? 'success' : 'neutral') ?>"><?= e(ucfirst((string) $order['status'])) ?></span>
                        <span class="rs-cust-sub"><?= e(substr((string) $order['created_at'], 0, 10)) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>
