<?php
/** @var array<string, mixed> $service */
/** @var string|null $error */
?>
<div style="background:linear-gradient(135deg,#1e293b,#0f172a);padding:32px;margin-bottom:24px;border-radius:12px;color:white;">
    <h2 style="font-size:1.5rem;font-weight:900;margin:0;font-family:'Hanken Grotesk';">Cancel Service</h2>
    <p style="margin:8px 0 0;opacity:.8;">Service: <?= e($service['product_name']??'') ?></p>
</div>

<?php if ($error): ?>
    <div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:8px;padding:12px 16px;margin-bottom:24px;color:#ef4444;">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<form method="post" action="/client/services/<?= (int)$service['id'] ?>/cancel-request" style="display:grid;gap:24px;">
    <?= csrf_field() ?>
    
    <div>
        <label style="display:block;font-weight:700;font-size:.85rem;color:var(--cv-text-secondary);text-transform:uppercase;margin-bottom:12px;">Cancellation Type</label>
        <div style="display:flex;gap:16px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="radio" name="type" value="immediate" checked required>
                <span>⚡ Cancel Immediately</span>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="radio" name="type" value="due_date" required>
                <span>📅 Cancel on Due Date</span>
            </label>
        </div>
    </div>

    <div id="cancel-date-field" style="display:none;">
        <label style="display:block;font-weight:700;font-size:.85rem;color:var(--cv-text-secondary);text-transform:uppercase;margin-bottom:6px;">Cancellation Date</label>
        <input type="date" name="cancel_date" style="width:100%;padding:8px 12px;border:1px solid var(--cv-border-default);border-radius:6px;background:var(--cv-bg-surface);color:var(--cv-text-primary);">
    </div>

    <div>
        <label style="display:block;font-weight:700;font-size:.85rem;color:var(--cv-text-secondary);text-transform:uppercase;margin-bottom:6px;">Reason for Cancellation</label>
        <textarea name="reason" required style="width:100%;padding:12px;border:1px solid var(--cv-border-default);border-radius:6px;background:var(--cv-bg-surface);color:var(--cv-text-primary);font-family:inherit;min-height:100px;box-sizing:border-box;"></textarea>
    </div>

    <button type="submit" style="padding:12px 24px;background:linear-gradient(135deg,#3b82f6,#2563eb);color:white;border:none;border-radius:8px;font-weight:700;cursor:pointer;transition:all .2s;align-self:flex-start;">🚀 Submit Cancellation Request</button>
</form>

<script>
document.querySelectorAll('input[name="type"]').forEach(radio => {
    radio.addEventListener('change', () => {
        document.getElementById('cancel-date-field').style.display = 
            radio.value === 'due_date' ? 'block' : 'none';
    });
});
</script>
