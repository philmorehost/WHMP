<?php
/** @var string|null $error */
/** @var string $domain */
/** @var array<int, string> $defaultNameservers */
?>
<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h1 class="cv-card__title">Transfer a Domain</h1>
    <p><a href="/client/dashboard">&larr; Back to dashboard</a></p>

    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/domains/transfer/add-to-cart">
        <?= csrf_field() ?>
        
        <div class="cv-field">
            <label class="cv-label" for="domain-transfer-input">Domain Name</label>
            <input class="cv-input" name="domain" id="domain-transfer-input" value="<?= e($domain) ?>" placeholder="example.com" required>
        </div>

        <div class="cv-field">
            <label class="cv-label" for="epp-code-input">EPP Code / Auth Code</label>
            <input class="cv-input" name="epp_code" id="epp-code-input" placeholder="Authorization code from current registrar" required>
        </div>

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
