<?php
/** @var array<int, array<string, mixed>> $departments */
?>
<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h1 class="cv-card__title">Open New Ticket</h1>
    <p><a href="/client/tickets">&larr; Back to tickets</a></p>

    <form method="post" action="/client/tickets" enctype="multipart/form-data"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Department</label>
            <select class="cv-input" name="department_id" required>
                <?php foreach ($departments as $department): ?>
                    <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field">
            <label class="cv-label">Subject</label>
            <input class="cv-input" name="subject" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Message</label>
            <textarea class="cv-input" name="message" rows="6" required></textarea>
        </div>
        <div class="cv-field">
            <label class="cv-label">Attachments <span style="color:var(--cv-text-secondary);font-weight:400;">(optional — images &amp; documents, up to 10 MB each)</span></label>
            <input class="cv-input" type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf,.odt,.zip">
        </div>
        <button class="cv-btn" type="submit">Submit Ticket</button>
    </form>
</div>
