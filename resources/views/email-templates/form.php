<?php
/** @var array<string, mixed> $template */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Edit Template: <?= e($template['name']) ?></h1>
    <p><a href="/admin/email-templates">&larr; Back to templates</a></p>
</div>

<div class="cv-card">
    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Key: <code><?= e($template['key']) ?></code> (fixed — engines fire by this identifier). Use <code>{{variable}}</code> placeholders in the subject and body.</p>

    <form method="post" action="/admin/email-templates/<?= (int) $template['id'] ?>"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" value="<?= e($template['name']) ?>" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Subject</label>
            <input class="cv-input" name="subject" value="<?= e($template['subject']) ?>" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Body (HTML)</label>
            <textarea class="cv-textarea" name="body_html" rows="10" required><?= e($template['body_html']) ?></textarea>
        </div>
        <button class="cv-btn" type="submit">Save Changes</button>
    </form>
</div>
