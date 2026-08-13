<?php
/** @var array<int, array{client_id: int, email: string, first_name: string, last_name: string, items: array<int, array{kind: string, name: string, domain: string, due_date: string, amount: string}>}> $accounts */
/** @var int $clientCount */
/** @var int $serviceCount */
/** @var int $domainCount */
/** @var string $message */
/** @var string|null $error */
?>
<style>
.er-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 32px 28px;
    margin-bottom: 24px;
    border-radius: 14px;
}
.er-hero h1 { margin: 0 0 6px 0; font-size: 1.5rem; color: #fff; }
.er-hero p { margin: 0; color: rgba(255,255,255,.7); font-size: .9rem; }
.er-stats { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
.er-stat {
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.15);
    border-radius: 10px;
    padding: 10px 16px;
    text-align: center;
    min-width: 90px;
}
.er-stat strong { display: block; font-size: 1.3rem; color: #fff; }
.er-stat span { font-size: .72rem; color: rgba(255,255,255,.6); text-transform: uppercase; letter-spacing: .04em; }
.er-card {
    background: var(--cv-surface, #111827);
    border: 1px solid var(--cv-border-default, #1f2937);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 16px;
}
.er-card h2 { margin: 0 0 14px 0; font-size: 1.05rem; }
.er-alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-size: .88rem;
}
.er-alert--ok { background: rgba(16,185,129,.12); border: 1px solid rgba(16,185,129,.35); color: #10b981; }
.er-alert--err { background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.35); color: #f87171; }
.er-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
.er-table th, .er-table td { text-align: left; padding: 8px 10px; border-bottom: 1px solid rgba(255,255,255,.06); }
.er-table th { color: var(--cv-text-secondary, #94a3b8); font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; }
.er-btn {
    display: inline-block;
    padding: 10px 18px;
    border: none;
    border-radius: 8px;
    font-weight: 700;
    font-size: .85rem;
    cursor: pointer;
    text-decoration: none;
    color: #fff;
}
.er-btn--primary { background: linear-gradient(135deg, #3b82f6, #2563eb); }
.er-btn--ghost { background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.2); color: #fff; }
.er-empty { color: var(--cv-text-secondary, #94a3b8); font-size: .9rem; }
</style>

<div class="er-hero">
    <h1>📧 Expiring Account Reminders</h1>
    <p>Email every client whose services or domains renew within the next 7 days — each message is personalized with their own service/domain names, due dates and amounts, plus a promotional reminder.</p>
    <div class="er-stats">
        <div class="er-stat"><strong><?= (int) $clientCount ?></strong><span>Clients</span></div>
        <div class="er-stat"><strong><?= (int) $serviceCount ?></strong><span>Services</span></div>
        <div class="er-stat"><strong><?= (int) $domainCount ?></strong><span>Domains</span></div>
    </div>
</div>

<?php if (($error ?? null) !== null): ?>
    <div class="er-alert er-alert--err">⚠️ <?= e($error) ?></div>
<?php endif; ?>
<?php if (($_GET['msg'] ?? '') !== ''): ?>
    <div class="er-alert er-alert--ok">✅ <?= e((string) $_GET['msg']) ?></div>
<?php endif; ?>

<?php if ($accounts === []): ?>
    <div class="er-card">
        <h2>No accounts expiring in the next 7 days 🎉</h2>
        <p class="er-empty">There are no active services or auto-renew domains due within the next 7 days. Nothing to email right now.</p>
    </div>
<?php else: ?>
    <div class="er-card">
        <h2>✍️ Email message</h2>
        <form method="post" action="/admin/expiring-reminders/send">
            <?= csrf_field() ?>
            <textarea name="message" rows="5" class="cv-input" style="width:100%;font-size:.9rem;resize:vertical;" placeholder="Write your reminder message, or generate one with AI below."><?= e($message) ?></textarea>
            <p style="font-size:.78rem;color:var(--cv-text-secondary);margin:8px 0 0;">
                Each client's own service/domain names, due dates and amounts are added automatically — you only write the promotional message here.
                Sending runs in the background; you'll get a summary email when it finishes.
            </p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
                <button class="er-btn er-btn--primary" type="submit">📤 Send to all clients</button>
            </div>
        </form>
        <form method="post" action="/admin/expiring-reminders/generate" style="margin-top:10px;">
            <?= csrf_field() ?>
            <button class="er-btn er-btn--ghost" type="submit">✨ Generate with AI</button>
        </form>
    </div>

    <div class="er-card">
        <h2>👥 Affected clients (<?= (int) $clientCount ?>)</h2>
        <?php foreach ($accounts as $account): ?>
            <div style="margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid rgba(255,255,255,.06);">
                <div style="font-weight:700;font-size:.9rem;margin-bottom:6px;">
                    <?= e((string) ($account['first_name'] ?? '')) ?> <?= e((string) ($account['last_name'] ?? '')) ?>
                    <span style="font-weight:400;color:var(--cv-text-secondary);font-size:.8rem;">&lt;<?= e((string) ($account['email'] ?? '')) ?>&gt;</span>
                </div>
                <table class="er-table">
                    <tr><th>Type</th><th>Name</th><th>Domain</th><th>Due date</th><th>Amount</th></tr>
                    <?php foreach ($account['items'] as $item): ?>
                        <tr>
                            <td><?= $item['kind'] === 'domain' ? 'Domain' : 'Service' ?></td>
                            <td><?= e((string) $item['name']) ?></td>
                            <td><?= e((string) $item['domain']) ?></td>
                            <td><?= e((string) $item['due_date']) ?></td>
                            <td><?= e((string) $item['amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
