<?php
/** @var array<int, array<string, mixed>> $clients */
/** @var array<int, array<string, mixed>> $registrars */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Add Domain</h1>
    <p><a href="/admin/domains">&larr; Back to domains</a></p>
</div>

<div class="cv-card">
    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/admin/domains"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Domain Name</label>
            <input class="cv-input" name="domain_name" placeholder="example.com" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Client</label>
            <select class="cv-select" name="client_id" required>
                <option value="">Select a client</option>
                <?php foreach ($clients as $client): ?>
                    <option value="<?= (int) $client['id'] ?>"><?= e($client['first_name'] . ' ' . $client['last_name']) ?> (<?= e($client['email']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field">
            <label class="cv-label">Registrar</label>
            <select class="cv-select" name="registrar_slug" required>
                <?php foreach ($registrars as $registrar): ?>
                    <option value="<?= e($registrar['slug']) ?>"><?= e($registrar['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--cv-space-3);">
            <div class="cv-field">
                <label class="cv-label">Registration Years</label>
                <input class="cv-input" type="number" name="years" value="1" min="1" max="10">
            </div>
            <div class="cv-field">
                <label class="cv-label">Renewal Amount</label>
                <input class="cv-input" type="number" step="0.01" name="amount" value="0.00">
            </div>
        </div>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">This immediately attempts registration with the selected registrar.</p>
        <button class="cv-btn" type="submit">Register Domain</button>
    </form>
</div>
