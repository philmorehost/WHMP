<?php
/** @var string $question */
/** @var string|null $answer */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Ask AI</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
    <p style="color:var(--cv-text-secondary);">A general-purpose assistant for quick questions — it doesn't have access to your data, just general knowledge.</p>
</div>

<div class="cv-card">
    <form method="post" action="/admin/ask-ai"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Question</label>
            <textarea class="cv-input" name="question" rows="4" required><?= e($question) ?></textarea>
        </div>
        <button class="cv-btn" type="submit">Ask</button>
    </form>

    <?php if ($error !== null): ?>
        <div class="cv-field-error" style="margin-top:var(--cv-space-3);"><?= e($error) ?></div>
    <?php elseif ($answer !== null): ?>
        <div class="cv-field" style="margin-top:var(--cv-space-3);">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:var(--cv-space-2);">
                <label class="cv-label" style="margin:0;">Answer</label>
                <?php // Handler lives in app.js — inline scripts are blocked by the CSP. ?>
                <button type="button" class="cv-btn cv-btn--secondary" data-copy-target="#ai-answer"
                        style="padding:4px 12px;font-size:.8rem;">Copy</button>
            </div>
            <div id="ai-answer" style="white-space:pre-wrap;padding:var(--cv-space-3);background:var(--cv-color-brand-50);border-radius:var(--cv-radius-sm);"><?= e($answer) ?></div>
        </div>
    <?php endif; ?>
</div>
