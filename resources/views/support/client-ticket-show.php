<?php
/** @var array<string, mixed> $ticket */
/** @var array<int, array<string, mixed>> $replies */
/** @var array<int, array<int, array<string, mixed>>> $attachments grouped by reply_id (0 = opening message) */
$id = (int) $ticket['id'];
?>
<div class="cv-card" style="max-width:40rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">#<?= $id ?> &mdash; <?= e($ticket['subject']) ?></h1>
    <p><a href="/client/tickets">&larr; Back to tickets</a></p>
    <p><strong>Department:</strong> <?= e($ticket['department_name']) ?> &middot; <strong>Status:</strong> <?= e($ticket['status']) ?></p>
</div>

<div class="cv-card" style="max-width:40rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <?php foreach ($replies as $i => $reply):
        $replyAttachments = $attachments[(int) $reply['id']] ?? [];
        if ($i === 0) { $replyAttachments = array_merge($attachments[0] ?? [], $replyAttachments); }
    ?>
        <div style="border-bottom:1px solid var(--cv-border);padding:var(--cv-space-3) 0;">
            <p>
                <strong><?= e($reply['author_name']) ?></strong>
                <span style="color:var(--cv-text-secondary);"> &middot; <?= e($reply['created_at']) ?></span>
            </p>
            <p style="white-space:pre-wrap;word-break:break-word;"><?= e($reply['message']) ?></p>
            <?= $view->partial('partials.ticket-attachments', ['items' => $replyAttachments, 'baseUrl' => "/client/tickets/{$id}/attachments"]) ?>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($ticket['status'] !== 'closed'): ?>
<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h2 class="cv-card__title">Reply</h2>
    <form method="post" action="/client/tickets/<?= $id ?>/reply" enctype="multipart/form-data"><?= csrf_field() ?>
        <div class="cv-field">
            <textarea class="cv-input" name="message" rows="6" placeholder="Type your reply… 🙂"></textarea>
        </div>
        <div class="cv-field">
            <label class="cv-label">Attachments <span style="color:var(--cv-text-secondary);font-weight:400;">(images &amp; documents, up to 10 MB each)</span></label>
            <input class="cv-input" type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf,.odt,.zip">
        </div>
        <button class="cv-btn" type="submit">Send Reply</button>
    </form>
</div>
<?php elseif ($ticket['satisfaction_rating'] === null): ?>
<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h2 class="cv-card__title">How did we do?</h2>
    <form method="post" action="/client/tickets/<?= $id ?>/rate" style="display:flex;gap:var(--cv-space-2);"><?= csrf_field() ?>
        <?php for ($star = 1; $star <= 5; $star++): ?>
            <button class="cv-btn cv-btn--secondary" type="submit" name="rating" value="<?= $star ?>"><?= $star ?> &#9733;</button>
        <?php endfor; ?>
    </form>
</div>
<?php else: ?>
<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <p>You rated this ticket <?= (int) $ticket['satisfaction_rating'] ?> / 5. Thank you!</p>
</div>
<?php endif; ?>
