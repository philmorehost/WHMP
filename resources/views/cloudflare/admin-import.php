<?php
/**
 * @var bool $connected
 * @var string $accountName
 * @var array<int, array{zone: array<string, mixed>, linked: array<string, mixed>|null, name_owner: array<string, mixed>|null, free_plan: bool, candidates: array<int, array<string, mixed>>}> $remoteZones
 * @var string $q
 * @var int $pageNo
 * @var int $totalPages
 * @var int $totalCount
 * @var string|null $loadError
 */
?>
<div class="cfa">
    <div class="cfa-head">
        <div>
            <h1><span class="cfa-logo" aria-hidden="true">CF</span> Import existing zones</h1>
            <p>Browse zones in <?= $connected ? '<strong>' . e($accountName) . '</strong>' : 'your connected Cloudflare account' ?> and link an opted-in customer service.</p>
        </div>
        <a class="cv-btn cv-btn--secondary" href="/admin/cloudflare">&larr; All zones</a>
    </div>
    <?= $view->render('cloudflare.admin-tabs', ['active' => 'import', 'notice' => $notice ?? null, 'error' => $error ?? null]) ?>

    <div class="cfa-card">
        <h2>Safe import</h2>
        <p class="cfa-muted" style="margin-bottom:0">Import only links a Free-plan zone to an active service whose client selected Cloudflare at checkout. WHMP reads the zone and DNS records; the service dashboard then displays current records live from Cloudflare. Import does not edit DNS, Cloudflare settings, DNSSEC, or registrar nameservers. No nameserver switch happens automatically.</p>
    </div>

    <?php if ($loadError !== null): ?>
        <div class="cv-alert cv-alert--<?= $connected ? 'error' : 'warning' ?>" role="<?= $connected ? 'alert' : 'status' ?>"><?= e($loadError) ?></div>
    <?php endif; ?>

    <?php if ($connected): ?>
        <div class="cfa-card">
            <form class="cfa-filter" method="get" action="/admin/cloudflare/import">
                <input class="cv-input" type="search" name="q" value="<?= e($q) ?>" maxlength="253" placeholder="Exact domain, e.g. example.com" aria-label="Exact Cloudflare zone domain">
                <button class="cv-btn cv-btn--secondary" type="submit">Find zone</button>
                <?php if ($q !== ''): ?><a class="cv-btn cv-btn--secondary" href="/admin/cloudflare/import">Clear</a><?php endif; ?>
            </form>
            <p class="cfa-muted">Showing <?= count($remoteZones) ?> of <?= (int) $totalCount ?> Cloudflare zone(s)<?= $totalPages > 1 ? ' · page ' . (int) $pageNo . ' of ' . (int) $totalPages : '' ?>.</p>

            <div style="display:grid;gap:12px">
                <?php foreach ($remoteZones as $item): ?>
                    <?php
                    $remote = $item['zone'];
                    $zoneName = (string) ($remote['name'] ?? '');
                    $zoneId = (string) ($remote['id'] ?? '');
                    $plan = is_array($remote['plan'] ?? null) ? $remote['plan'] : [];
                    $planName = (string) ($plan['name'] ?? $plan['id'] ?? 'Unknown');
                    $status = (string) ($remote['status'] ?? 'unknown');
                    $nameservers = array_values(array_filter((array) ($remote['name_servers'] ?? []), 'is_string'));
                    ?>
                    <article class="cfa-card" style="padding:16px;margin:0">
                        <div class="cfa-head" style="align-items:center">
                            <div>
                                <h2 style="margin:0 0 4px"><?= e($zoneName) ?></h2>
                                <span class="cfa-muted cfa-mono">Zone ID: <?= e($zoneId) ?></span>
                            </div>
                            <div class="cfa-actions">
                                <span class="cfa-pill<?= $status === 'active' ? ' cfa-pill--active' : ' cfa-pill--pending' ?>"><?= e(ucfirst($status)) ?></span>
                                <span class="cfa-pill<?= $item['free_plan'] ? '' : ' cfa-pill--deleting' ?>"><?= e($planName) ?></span>
                            </div>
                        </div>
                        <?php if ($nameservers !== []): ?><p class="cfa-muted" style="margin:8px 0">Cloudflare nameservers: <span class="cfa-mono"><?= e(implode(', ', $nameservers)) ?></span></p><?php endif; ?>

                        <?php if ($item['linked'] !== null): ?>
                            <p class="cfa-muted" style="margin-bottom:0">Already linked to WHMP zone <a href="/admin/cloudflare/zones/<?= (int) $item['linked']['id'] ?>">#<?= (int) $item['linked']['id'] ?></a>.</p>
                        <?php elseif ($item['name_owner'] !== null): ?>
                            <p class="cfa-muted" style="margin-bottom:0">This domain is already linked to another WHMP Cloudflare zone. It cannot be imported again.</p>
                        <?php elseif (!$item['free_plan']): ?>
                            <p class="cfa-muted" style="margin-bottom:0">Import is disabled: the Cloudflare Free plan is required.</p>
                        <?php elseif (count($item['candidates']) > 1): ?>
                            <p class="cfa-muted" style="margin-bottom:0">Import is paused: several active, opted-in services use this domain. Resolve the duplicate service assignment first so the zone cannot be linked to the wrong customer or reseller.</p>
                        <?php elseif ($item['candidates'] === []): ?>
                            <p class="cfa-muted" style="margin-bottom:0">No active WHMP service matches this domain with a recorded Free Cloudflare opt-in at checkout.</p>
                        <?php else: ?>
                            <form method="post" action="/admin/cloudflare/import" class="cfa-actions" style="margin-top:12px">
                                <?= csrf_field() ?>
                                <input type="hidden" name="cf_zone_id" value="<?= e($zoneId) ?>">
                                <label class="cfa-muted" for="cf-service-<?= e($zoneId) ?>">Link to opted-in service</label>
                                <select class="cv-input" id="cf-service-<?= e($zoneId) ?>" name="service_id" required>
                                    <option value="">Choose service…</option>
                                    <?php foreach ($item['candidates'] as $candidate): ?>
                                        <?php
                                        $clientName = trim((string) ($candidate['first_name'] ?? '') . ' ' . (string) ($candidate['last_name'] ?? ''));
                                        if ($clientName === '') {
                                            $clientName = 'Client #' . (int) $candidate['client_id'];
                                        }
                                        $serviceLabel = 'Service #' . (int) $candidate['service_id'] . ' · ' . (string) ($candidate['product_name'] ?? 'Hosting') . ' · ' . $clientName;
                                        if (!empty($candidate['client_email'])) {
                                            $serviceLabel .= ' (' . (string) $candidate['client_email'] . ')';
                                        }
                                        if (($candidate['client_reseller_id'] ?? null) !== null) {
                                            $storeName = (string) (($candidate['store_name'] ?? '') ?: ($candidate['store_slug'] ?? ('Reseller #' . (int) $candidate['client_reseller_id'])));
                                            $serviceLabel .= ' · Store: ' . $storeName;
                                        }
                                        ?>
                                        <option value="<?= (int) $candidate['service_id'] ?>"><?= e($serviceLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="cv-btn" type="submit">Import and link</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if ($remoteZones === []): ?>
                    <div class="cfa-muted" style="text-align:center;padding:24px">No zones found in the selected Cloudflare account<?= $q !== '' ? ' for that exact domain' : '' ?>.</div>
                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="cfa-actions" style="margin-top:16px">
                    <?php if ($pageNo > 1): ?><a class="cv-btn cv-btn--secondary" href="?<?= e(http_build_query(['q' => $q, 'page' => $pageNo - 1])) ?>">&larr; Previous</a><?php endif; ?>
                    <?php if ($pageNo < $totalPages): ?><a class="cv-btn cv-btn--secondary" href="?<?= e(http_build_query(['q' => $q, 'page' => $pageNo + 1])) ?>">Next &rarr;</a><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
