<div id="cancel-invoice-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--cv-bg-surface);border-radius:12px;padding:32px;max-width:500px;width:90%;border:1px solid var(--cv-border-default);">
        <h2 style="font-size:1.25rem;font-weight:800;margin:0 0 16px;color:var(--cv-text-primary);">Cancel Invoice</h2>
        <p style="color:var(--cv-text-secondary);margin:0 0 24px;">Cancelling this invoice will prevent automated billing attempts. Paid invoices cannot be cancelled.</p>
        
        <form method="post" action="/client/invoices/<?= (int)$invoice['id'] ?>/cancel">
            <?= csrf_field() ?>
            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:700;font-size:.85rem;color:var(--cv-text-secondary);text-transform:uppercase;margin-bottom:6px;">Reason for Cancellation</label>
                <textarea name="reason" required style="width:100%;padding:12px;border:1px solid var(--cv-border-default);border-radius:6px;background:var(--cv-bg-surface);color:var(--cv-text-primary);font-family:inherit;min-height:80px;box-sizing:border-box;"></textarea>
            </div>
            
            <div style="display:flex;gap:12px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('cancel-invoice-modal').style.display='none'" style="padding:10px 20px;background:var(--cv-bg-surface-sunken);color:var(--cv-text-primary);border:1px solid var(--cv-border-default);border-radius:6px;cursor:pointer;font-weight:600;">Cancel</button>
                <button type="submit" style="padding:10px 20px;background:#ef4444;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:600;">Cancel Invoice</button>
            </div>
        </form>
    </div>
</div>

<button onclick="document.getElementById('cancel-invoice-modal').style.display='flex'" style="padding:10px 16px;background:rgba(239,68,68,.2);color:#ef4444;border:1px solid rgba(239,68,68,.3);border-radius:6px;cursor:pointer;font-weight:600;font-size:.9rem;">❌ Cancel Invoice</button>
