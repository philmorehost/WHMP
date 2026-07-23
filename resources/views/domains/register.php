<?php
/** @var string $query */
/** @var array<int, array{tld: string, domain: string, available: ?bool, price: ?float, checked: bool, message: string}> $results */
/** @var string|null $error */
/** @var array<int, string> $defaultNameservers */
/** @var array<string, array<int, array<string, mixed>>> $categories */
/** @var array<int, array<string, mixed>> $featured */
$categoryNames = array_keys($categories);
?>
<style>
.dr-wrap { max-width: 60rem; margin: 0 auto; }
.dr-hero {
    background: var(--cv-gradient-accent, linear-gradient(135deg, #ffb347, #ff8f28));
    border-radius: var(--cv-radius-lg, 12px);
    padding: clamp(1.5rem, 5vw, 3rem);
    position: relative; overflow: hidden; margin-bottom: var(--cv-space-4);
}
.dr-hero__search { display: flex; gap: .5rem; max-width: 34rem; flex-wrap: wrap; }
.dr-hero__search input { flex: 1; min-width: 150px; border: none; border-radius: 8px; padding: .85rem 1rem; font-size: 1rem; }
@media (max-width: 480px) {
    .dr-hero__search { flex-direction: column; width: 100%; }
    .dr-hero__search input { width: 100%; box-sizing: border-box; }
    .dr-hero__search button { width: 100%; }
}
.dr-featured { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--cv-space-3); margin-bottom: var(--cv-space-4); }
.dr-featured__card { background: var(--cv-bg-surface); border: 1px solid var(--cv-border-default); border-radius: var(--cv-radius-md, 10px); overflow: hidden; text-align: center; }
.dr-featured__tld { font-size: clamp(1.8rem, 6vw, 2.6rem); font-weight: 800; padding: 1.25rem; letter-spacing: -.02em; }
.dr-featured__price { background: var(--cv-color-brand-500, #ff8f28); color: #fff; padding: .6rem; font-weight: 700; }
.dr-tabs { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: var(--cv-space-3); }
.dr-tab { border: none; cursor: pointer; padding: .4rem .85rem; border-radius: 999px; font-size: var(--cv-text-sm); font-weight: 600;
    background: var(--cv-border-default); color: var(--cv-text-secondary); }
.dr-tab.is-active { background: var(--cv-color-brand-500, #ff8f28); color: #fff; }
.dr-table-scroll { overflow-x: auto; }
.dr-table { width: 100%; border-collapse: collapse; min-width: 32rem; }
.dr-table th { text-align: center; padding: .75rem; font-size: var(--cv-text-xs); color: var(--cv-text-secondary); text-transform: uppercase; letter-spacing: .04em; border-bottom: 2px solid var(--cv-border-default); }
.dr-table th:first-child { text-align: left; }
.dr-table td { padding: .85rem .75rem; text-align: center; border-bottom: 1px solid var(--cv-border-default); }
.dr-table td:first-child { text-align: left; font-weight: 700; color: var(--cv-color-brand-500, #ff8f28); }
.dr-table .dr-cycle { display: block; font-size: var(--cv-text-xs); color: var(--cv-text-secondary); font-weight: 400; }
.dr-promos { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: var(--cv-space-3); margin-top: var(--cv-space-4); }
.dr-panel[hidden] { display: none; }
</style>

<div class="dr-wrap">
    <h1 class="cv-card__title" style="margin-bottom:.25rem;">Register a Domain</h1>
    <p style="color:var(--cv-text-secondary);margin-top:0;">Find your new domain name. Enter your name or keywords below to check availability.</p>

    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- Search hero -->
    <div class="dr-hero">
        <form method="get" action="/domains/register" class="dr-hero__search">
            <input name="domain" id="domain-search-input" value="<?= e($query) ?>" placeholder="Find your new domain name" required>
            <button class="cv-btn" type="submit">Search</button>
        </form>
    </div>

    <!-- Featured TLDs -->
    <?php if ($featured !== []): ?>
        <div class="dr-featured">
            <?php foreach ($featured as $f): ?>
                <div class="dr-featured__card">
                    <div class="dr-featured__tld"><?= e($f['tld']) ?></div>
                    <div class="dr-featured__price">$<?= number_format((float) $f['register_price'], 2) ?>/yr</div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Live search results -->
    <?php if ($query !== ''): ?>
        <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
            <h2 class="cv-card__title">Results for &ldquo;<?= e($query) ?>&rdquo;</h2>
            <?php if ($results === []): ?>
                <p style="color:var(--cv-text-secondary);">No TLDs are configured to search against yet.</p>
            <?php endif; ?>
            <?php foreach ($results as $result): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.85rem 0;border-bottom:1px solid var(--cv-border-default);">
                    <div>
                        <strong style="font-size:1.15rem;color:var(--cv-text-primary);"><?= e($result['domain']) ?></strong>
                        <?php if (!$result['checked']): ?>
                            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:0.25rem;"><?= e($result['message'] !== '' ? $result['message'] : 'Could not check availability.') ?></div>
                        <?php elseif ($result['available']): ?>
                            <div style="font-size:var(--cv-text-sm);color:#10b981;font-weight:700;margin-top:0.3rem;display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                                <span style="font-weight:800;font-size:0.85rem;padding:0.35rem 0.75rem;text-transform:uppercase;letter-spacing:0.05em;background:#10b981;color:#ffffff;border-radius:6px;box-shadow:0 2px 4px rgba(16,185,129,0.3);">✔ AVAILABLE</span>
                                <span style="font-size:1rem;color:var(--cv-text-primary);">$<?= number_format((float) $result['price'], 2) ?>/yr</span>
                            </div>
                        <?php else: ?>
                            <div style="font-size:var(--cv-text-sm);color:#ef4444;font-weight:700;margin-top:0.3rem;">
                                <span style="font-weight:800;font-size:0.85rem;padding:0.35rem 0.75rem;text-transform:uppercase;letter-spacing:0.05em;background:#ef4444;color:#ffffff;border-radius:6px;box-shadow:0 2px 4px rgba(239,68,68,0.3);display:inline-block;">✖ ALREADY TAKEN</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($result['checked'] && $result['available']): ?>
                        <button type="button" class="cv-btn" data-domain-register-trigger="<?= e($result['domain']) ?>">Add to Cart</button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Domain spinner -->
    <?php if ($query !== ''): ?>
    <div style="margin-bottom:var(--cv-space-4);">
        <h3 class="cv-card__title" style="margin-bottom:var(--cv-space-1);">Suggested Alternatives</h3>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-top:0;">Available alternatives across popular extensions.</p>
        <button type="button" class="cv-btn cv-btn--secondary" data-domain-spin-trigger style="display:none;">Suggest Names</button>
        <div id="domain-spin-results" style="margin-top:var(--cv-space-3);">
            <div style="padding:var(--cv-space-3);text-align:center;color:var(--cv-text-secondary);">Loading suggestions...</div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var trigger = document.querySelector('[data-domain-spin-trigger]');
                if (trigger) trigger.click();
            });
        </script>
    </div>
    <?php endif; ?>

    <!-- Browse extensions by category (tabbed) -->
    <?php if ($categories !== []): ?>
        <div class="cv-card">
            <h2 class="cv-card__title">Browse extensions by category</h2>

            <div class="dr-tabs" role="tablist">
                <?php foreach ($categoryNames as $i => $name): ?>
                    <button type="button" class="dr-tab <?= $i === 0 ? 'is-active' : '' ?>" data-tld-tab="<?= e($name) ?>">
                        <?= e($name) ?> (<?= count($categories[$name]) ?>)
                    </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($categoryNames as $i => $name): ?>
                <div class="dr-panel dr-table-scroll" data-tld-panel="<?= e($name) ?>" <?= $i === 0 ? '' : 'hidden' ?>>
                    <table class="dr-table">
                        <thead>
                            <tr><th>Domain</th><th>New Price</th><th>Transfer</th><th>Renewal</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories[$name] as $row): ?>
                                <tr>
                                    <td><?= e($row['tld']) ?></td>
                                    <td>$<?= number_format((float) $row['register_price'], 2) ?><span class="dr-cycle">1 Year</span></td>
                                    <td>$<?= number_format((float) $row['transfer_price'], 2) ?><span class="dr-cycle">1 Year</span></td>
                                    <td>$<?= number_format((float) $row['renew_price'], 2) ?><span class="dr-cycle">1 Year</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Bottom promos -->
    <div class="dr-promos">
        <div class="cv-card" style="display:flex;justify-content:space-between;align-items:center;gap:var(--cv-space-3);">
            <div>
                <h3 style="margin:0 0 .25rem;">Add Web Hosting</h3>
                <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin:0 0 .75rem;">Packages designed to fit every budget.</p>
                <a class="cv-btn" href="/store">Explore packages</a>
            </div>
        </div>
        <div class="cv-card" style="display:flex;justify-content:space-between;align-items:center;gap:var(--cv-space-3);">
            <div>
                <h3 style="margin:0 0 .25rem;">Transfer your domain to us</h3>
                <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin:0 0 .75rem;">Transfer now to extend your registration by 1 year.*</p>
                <a class="cv-btn cv-btn--secondary" href="/domains/transfer">Transfer a domain</a>
            </div>
        </div>
    </div>

    <!-- Add-to-cart form (revealed by the "Add to Cart" buttons above) -->
    <div id="register-form-wrapper" class="cv-card" style="display:none;margin-top:var(--cv-space-4);">
        <h3>Nameservers for <span id="register-form-domain"></span></h3>
        <form method="post" action="/domains/register/add-to-cart">
            <?= csrf_field() ?>
            <input type="hidden" name="domain" id="register-form-domain-input">

            <div style="display:flex;flex-direction:column;gap:var(--cv-space-2);margin-bottom:var(--cv-space-3);">
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;">
                    <input type="radio" name="nameserver_choice" value="default" checked>
                    Use default nameservers
                    <?php if ($defaultNameservers !== []): ?>
                        <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">(<?= e(implode(', ', $defaultNameservers)) ?>)</span>
                    <?php endif; ?>
                </label>
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;">
                    <input type="radio" name="nameserver_choice" value="custom">
                    Use custom nameservers
                </label>
            </div>

            <div id="custom-ns-fields" style="display:none;grid-template-columns:1fr 1fr;gap:var(--cv-space-2);margin-bottom:var(--cv-space-3);">
                <?php for ($i = 1; $i <= 6; $i++): ?>
                    <input class="cv-input" name="ns<?= $i ?>" placeholder="ns<?= $i ?>.yournameservers.com<?= $i > 2 ? ' (optional)' : '' ?>">
                <?php endfor; ?>
            </div>

            <button class="cv-btn" type="submit">Add to Cart</button>
        </form>
    </div>
</div>
