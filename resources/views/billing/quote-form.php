<?php
/** @var array<string, mixed>|null $client */
/** @var string|null $error */
/** @var int $maxRows */
?>
<div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);">
    <h1 class="cv-card__title">Create Quote</h1>
    <p><a href="/admin/quotes">&larr; Back to quotes</a></p>
</div>

<?php if ($error !== null): ?>
    <div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <form method="post" action="/admin/quotes"><?= csrf_field() ?>
        <?php if ($client !== null): ?>
            <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
            <div class="cv-field">
                <label class="cv-label">Client</label>
                <p style="margin:0;"><?= e($client['first_name'] . ' ' . $client['last_name']) ?> (<?= e($client['email']) ?>)</p>
            </div>
        <?php else: ?>
            <div class="cv-field">
                <label class="cv-label">Client Email</label>
                <input class="cv-input" type="email" name="client_email" required autofocus>
            </div>
        <?php endif; ?>

        <div class="cv-field">
            <label class="cv-label">Subject</label>
            <input class="cv-input" type="text" name="subject" required placeholder="e.g. Website hosting + SSL bundle">
        </div>

        <div class="cv-field">
            <label class="cv-label">Valid Until (optional)</label>
            <input class="cv-input" type="date" name="valid_until">
        </div>

        <div class="cv-field">
            <label class="cv-label">Line Items</label>
            <?php for ($i = 0; $i < $maxRows; $i++): ?>
                <div style="display:flex;gap:var(--cv-space-2);margin-bottom:var(--cv-space-2);">
                    <input class="cv-input" type="text" name="item_description[]" placeholder="Description" style="flex:2;">
                    <input class="cv-input" type="number" step="0.01" min="0" name="item_amount[]" placeholder="Amount" style="flex:1;">
                </div>
            <?php endfor; ?>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">Blank rows are ignored. The total is computed from these line items.</p>
        </div>

        <button class="cv-btn" type="submit">Create Draft Quote</button>
    </form>
</div>
