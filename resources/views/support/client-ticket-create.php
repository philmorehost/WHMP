<?php
/** @var array<int, array<string, mixed>> $departments */
?>
<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h1 class="cv-card__title">Open New Ticket</h1>
    <p><a href="/client/tickets">&larr; Back to tickets</a></p>

    <form method="post" action="/client/tickets"><?= csrf_field() ?>
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
        <button class="cv-btn" type="submit">Submit Ticket</button>
    </form>
</div>
