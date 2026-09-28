<?php
/** @var array<string, mixed>|null $credential */
/** @var string $backUrl */
/** @var string $backLabel */
/** @var string $baseUrl */
/** @var string $authHeader */
/** @var array<int, array<string, string>> $endpoints */
/** @var array<int, array{scope: string, description: string, reseller: bool}> $scopes */
/** @var array<int, array{code: int, message: string, meaning: string}> $errorCodes */
?>
<div class="cv-card" style="max-width:64rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">API Documentation</h1>
    <p><a href="<?= e($backUrl) ?>">&larr; <?= e($backLabel) ?></a></p>

    <?php if (is_array($credential)): ?>
        <?php $isActive = (int) ($credential['active'] ?? 0) === 1; ?>
        <div class="cv-alert <?= $isActive ? 'cv-alert--success' : 'cv-alert--warning' ?>">
            <strong>Your key:</strong> <code><?= e((string) ($credential['api_key'] ?? '')) ?></code>
            — <?= $isActive ? 'active' : 'disabled (submit your reselling domain to activate it)' ?>
            <?php if (($credential['reseller_domain'] ?? null) !== null): ?>
                for <strong><?= e((string) $credential['reseller_domain']) ?></strong>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p>Everything below is the same API this platform uses for its own integrations. Requests are
        authenticated with a key/secret pair, and every response is JSON.</p>
</div>

<div class="cv-card" style="max-width:64rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Authentication</h2>
    <p>Send your key and secret on every request, joined by a dot:</p>
    <pre><code><?= e($authHeader) ?></code></pre>
    <p>Your key identifies the credential; the secret proves it is yours. The secret is shown once when the
        key is created (or rotated) and is stored only as a hash — if it is lost, rotate the key to get a new one.</p>
    <p>A key is <strong>disabled until you submit the domain you resell from</strong>. Requests made with a
        disabled key are rejected with <code>401</code>, exactly as if the credentials were wrong.</p>
    <p><strong>Base URL:</strong> <code><?= e($baseUrl) ?></code> — all paths below are relative to it.</p>
</div>

<div class="cv-card" style="max-width:64rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Response format</h2>
    <p>Success:</p>
    <pre><code>{
  "status": "success",
  "data": { ... }
}</code></pre>
    <p>Error:</p>
    <pre><code>{
  "status": "error",
  "message": "Credential is not permitted to perform this action.",
  "code": "UNAUTHORIZED"
}</code></pre>
    <p>Use the HTTP status code as the outcome and <code>code</code> for programmatic handling;
        <code>message</code> is for a human reading a log.</p>
</div>

<div class="cv-card" style="max-width:64rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Endpoints</h2>
    <?php foreach ($endpoints as $endpoint): ?>
        <div style="border-top:1px solid var(--cv-border-subtle,#e5e7eb);padding:var(--cv-space-3) 0;">
            <p style="margin:0 0 var(--cv-space-2);">
                <span class="cv-badge cv-badge--neutral"><?= e($endpoint['method']) ?></span>
                <code><?= e($endpoint['path']) ?></code>
                &nbsp;scope: <code><?= e($endpoint['scope']) ?></code>
            </p>
            <p style="margin:0 0 var(--cv-space-2);"><?= e($endpoint['summary']) ?></p>
            <pre><code><?= e($endpoint['example']) ?></code></pre>
            <p style="margin:0;color:var(--cv-text-secondary);"><strong>Data:</strong>
                <code><?= e($endpoint['returns']) ?></code></p>
        </div>
    <?php endforeach; ?>
</div>

<div class="cv-card" style="max-width:64rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Scopes</h2>
    <p>A credential can only call an endpoint whose scope it holds. A reseller key holds
        <strong>reseller.read and nothing else</strong> — the other scopes return data across the whole
        installation, so they are never granted to a client-owned key.</p>
    <table class="cv-table">
        <thead><tr><th>Scope</th><th>What it allows</th><th>Available to reseller keys</th></tr></thead>
        <tbody>
        <?php foreach ($scopes as $scope): ?>
            <tr>
                <td><code><?= e($scope['scope']) ?></code></td>
                <td><?= e($scope['description']) ?></td>
                <td>
                    <?php if ($scope['reseller']): ?>
                        <span class="cv-badge cv-badge--success">Yes</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">No</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="cv-card" style="max-width:64rem;margin:0 auto;">
    <h2 class="cv-card__title">Errors</h2>
    <table class="cv-table">
        <thead><tr><th>Status</th><th>Message</th><th>Meaning</th></tr></thead>
        <tbody>
        <?php foreach ($errorCodes as $error): ?>
            <tr>
                <td><code><?= e((string) $error['code']) ?></code></td>
                <td><?= e($error['message']) ?></td>
                <td><?= e($error['meaning']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="color:var(--cv-text-secondary);">Prices returned by <code>/api/reseller/pricing</code> are catalogue
        figures in the currency named in the response — never assume a currency from the number alone.</p>
</div>
