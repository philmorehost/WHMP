<?php
/**
 * Reusable domain register/transfer lookup widget for the client area —
 * used on the pre-login landing (client-auth/login.php) and the logged-in
 * dashboard (client-auth/dashboard.php).
 *
 * Self-contained: resolves its own TLD list, featured TLD prices and the
 * effective display currency through the container (same pattern as the
 * header), so any page can include it with no extra wiring. A caller may
 * still pass $domainTlds / $domainFeatured / $currency / $currencyService
 * to override. Interactivity lives in app.js (#dbd-domain-widget):
 * register mode is "name + TLD dropdown", transfer mode is a full domain,
 * both with a debounced live availability/price check that posts straight
 * through to the full /domains/register or /domains/transfer page.
 *
 * @var array<int, string>|null $domainTlds
 * @var array<int, array<string, mixed>>|null $domainFeatured
 * @var array<string, mixed>|null $currency
 * @var \CodeVault\Billing\CurrencyService|null $currencyService
 */
$domainTlds ??= null;
$domainFeatured ??= null;
$currency ??= null;
$currencyService ??= null;

try {
    $container = \CodeVault\Support\App::container();

    if ($domainTlds === null || $domainFeatured === null) {
        $domainPricing = $container->make(\CodeVault\Domains\DomainPricingRepository::class);
        $domainRows = $domainPricing->all();
        $domainTlds ??= array_map(static fn (array $r): string => (string) $r['tld'], $domainRows);
        $domainFeatured ??= array_slice($domainRows, 0, 4);
    }

    if ($currency === null || $currencyService === null) {
        $currencyService ??= $container->make(\CodeVault\Billing\CurrencyService::class);
        $currencySelection = $container->make(\CodeVault\Billing\CurrencySelection::class);
        $client = $container->make(\CodeVault\Clients\ClientAuthGuard::class)->currentClient();
        $currency ??= $currencyService->resolveEffective($client, $currencySelection->get());
    }
} catch (\Throwable) {
    // No container/DB yet (installer, CLI bootstrap) — a graceful fallback
    // so the widget never breaks the page it sits on.
    $domainTlds = $domainTlds ?? ['.com'];
    $domainFeatured = $domainFeatured ?? [];
    $currency = $currency ?? ['code' => 'USD', 'symbol' => '$'];
}
?>
<div class="dbd-domain" id="dbd-domain-widget">
    <div class="dbd-domain__head">
        <h2>Register or Transfer a Domain</h2>
        <p>Find the perfect domain for your next project — or bring one you already own.</p>
    </div>

    <div class="dbd-domain__tabs" role="tablist" aria-label="Domain action">
        <button type="button" class="dbd-domain__tab is-active" data-dbd-domain-mode="register" role="tab" aria-selected="true">🌐 Register</button>
        <button type="button" class="dbd-domain__tab" data-dbd-domain-mode="transfer" role="tab" aria-selected="false">⇄ Transfer</button>
    </div>

    <form class="dbd-domain__form" data-dbd-domain-form>
        <div class="dbd-domain__control">
            <span class="dbd-domain__prefix">www.</span>
            <input class="dbd-domain__input" type="text" name="domain" id="dbd-domain-name"
                   placeholder="yourbusiness" autocomplete="off" spellcheck="false" data-dbd-domain-name>
            <span class="dbd-domain__divider" data-dbd-tld-divider aria-hidden="true"></span>
            <select class="dbd-domain__tld" name="tld" aria-label="Domain extension" data-dbd-domain-tld>
                <?php foreach (($domainTlds ?? ['.com']) as $tld): ?>
                    <option value="<?= e($tld) ?>"><?= e($tld) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="dbd-domain__submit" type="submit" data-dbd-domain-submit>Search</button>
        <div class="dbd-domain__result" data-dbd-domain-result></div>
    </form>

    <?php if (!empty($domainFeatured)): ?>
        <div class="dbd-domain__featured">
            <?php foreach ($domainFeatured as $f): ?>
                <button type="button" class="dbd-domain__featured-chip" data-dbd-featured-tld="<?= e((string) $f['tld']) ?>">
                    <?= e((string) $f['tld']) ?> &mdash; from <?= e($currencyService->format((float) $f['register_price'], $currency)) ?>/yr
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
/* ====== Domain Register/Transfer Widget (partial) ====== */
.dbd-domain__head h2 { color: #fff; font-family: 'Hanken Grotesk', sans-serif; font-size: 1.5rem; font-weight: 800; margin: 0 0 6px 0; text-align: center; }
.dbd-domain__head p { color: rgba(255,255,255,.65); font-size: .9rem; margin: 0 0 18px 0; text-align: center; }
.dbd-domain__tabs { display: flex; gap: 6px; justify-content: center; margin-bottom: 18px; }
.dbd-domain__tab {
    border: 1px solid rgba(255,255,255,.25); background: rgba(255,255,255,.08); color: rgba(255,255,255,.85);
    border-radius: 999px; padding: 8px 20px; font-size: .85rem; font-weight: 700; cursor: pointer;
    font-family: inherit; transition: background .18s, color .18s;
}
.dbd-domain__tab:hover { background: rgba(255,255,255,.16); }
.dbd-domain__tab.is-active { background: #f59e0b; color: #1a1a2e; border-color: #f59e0b; }
.dbd-domain__form { display: flex; gap: 10px; max-width: 760px; margin: 0 auto; flex-wrap: wrap; }
.dbd-domain__control {
    flex: 1; min-width: 260px; display: flex; align-items: stretch;
    background: rgba(255,255,255,.97); border-radius: 10px; overflow: hidden;
    border: 2px solid transparent; transition: border-color .18s, box-shadow .18s;
}
.dbd-domain__control:focus-within { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.35); }
.dbd-domain__prefix { display: flex; align-items: center; padding: 0 4px 0 16px; color: #6b7280; font-weight: 500; user-select: none; }
.dbd-domain__input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; padding: 14px 8px; font-size: 1rem; font-weight: 500; color: #111; }
.dbd-domain__divider { width: 1px; background: #e5e7eb; margin: 10px 0; flex-shrink: 0; }
.dbd-domain__tld {
    -webkit-appearance: none; appearance: none; border: 0; outline: 0; margin: 0; background: transparent;
    padding: 0 34px 0 14px; font-size: .95rem; font-weight: 700; color: #d97706; cursor: pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 12px center; background-size: 14px;
}
.dbd-domain__submit {
    background: linear-gradient(135deg, #f59e0b, #d97706); color: #1a1a2e; border: none; border-radius: 10px;
    padding: 0 26px; font-weight: 800; font-size: .95rem; cursor: pointer; white-space: nowrap;
    font-family: inherit; transition: transform .18s, box-shadow .18s;
}
.dbd-domain__submit:hover { transform: translateY(-1px); box-shadow: 0 8px 18px rgba(245,158,11,.35); }
.dbd-domain__result {
    flex-basis: 100%; text-align: center; font-size: .9rem; font-weight: 600;
    color: rgba(255,255,255,.9); min-height: 22px; margin-top: 2px;
}
.dbd-domain__result.is-ok { color: #6ee7b7; }
.dbd-domain__result.is-error { color: #fca5a5; }
.dbd-domain__result.is-checking { color: rgba(255,255,255,.65); }
.dbd-domain__featured { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin-top: 16px; }
.dbd-domain__featured-chip {
    background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.2); color: rgba(255,255,255,.9);
    border-radius: 999px; padding: 5px 14px; font-size: .78rem; font-weight: 600; cursor: pointer;
    font-family: inherit; transition: background .18s;
}
.dbd-domain__featured-chip:hover { background: rgba(245,158,11,.3); }
@media (max-width: 640px) {
    .dbd-domain__form { flex-direction: column; }
    .dbd-domain__control { width: 100%; }
    .dbd-domain__submit { padding: 13px 0; width: 100%; }
}
</style>
