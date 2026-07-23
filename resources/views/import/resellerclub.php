<?php
/** @var string|null $success */
/** @var string|null $error */
?>
<div class="cv-card" style="max-width:32rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">ResellerClub Email Hosting Importer</h1>
    <p><a href="/admin/import/clients">&larr; Back to Importers</a></p>

    <?php if (!empty($success)): ?>
        <div style="background:rgba(16, 185, 129, 0.1);border-left:4px solid #10b981;color:#059669;padding:var(--cv-space-3);border-radius:4px;margin-bottom:var(--cv-space-3);"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);line-height:1.5;">
        This tool connects to the ResellerClub API integration configured under your Registrar settings and imports professional email services (Business Email, Enterprise Email, Titan Email, and Google Workspace) as catalog products with custom markups.
    </p>

    <hr style="border:0;border-top:1px solid var(--cv-border-color, #e5e7eb);margin:var(--cv-space-4) 0;">

    <form method="post" action="/admin/import-resellerclub"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Markup Type</label>
            <select class="cv-select" name="markup_type" required style="width:100%;">
                <option value="fixed">Fixed Amount (Add $X.XX USD to Cost)</option>
                <option value="percentage">Percentage (Add X% to Cost)</option>
            </select>
        </div>

        <div class="cv-field">
            <label class="cv-label">Markup Value</label>
            <input class="cv-input" type="number" step="0.01" name="markup_value" value="2.00" required style="width:100%;">
        </div>

        <div style="margin-top:var(--cv-space-4);">
            <button class="cv-btn" type="submit" style="width:100%;">Import Package Plans</button>
        </div>
    </form>
</div>
