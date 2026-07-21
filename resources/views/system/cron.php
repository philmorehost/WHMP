<?php
/** @var string $cronPath */
/** @var string $logPath */
/** @var string $everyMinute */
/** @var string $everyFiveMinutes */
/** @var array<int, array{name: string, desc: string}> $jobs */
?>
<style>
/* Code/path boxes render as a consistent dark "terminal" with explicit
   colours, so the text never collides with the light/dark page theme. */
.cron-code {
    display: block; background: #0f1729; color: #e7edf6;
    border: 1px solid rgba(148, 163, 184, .28); border-radius: 8px;
    font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
    font-size: var(--cv-text-sm); overflow-x: auto;
}
.cron-code--block { padding: .85rem 1rem; white-space: pre; }
.cron-code--inline { padding: .45rem .7rem; white-space: nowrap; }
.cron-code__label { font-size: var(--cv-text-xs); color: var(--cv-text-secondary); text-transform: uppercase; letter-spacing: .05em; margin-bottom: .25rem; }
.cron-row { display: flex; gap: var(--cv-space-2); align-items: center; flex-wrap: wrap; }
.cron-row .cron-code { flex: 1; min-width: 14rem; }
</style>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Cron &amp; Automation</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-bottom:0;">
        WHMP's automated tasks — recurring billing, auto-charging saved cards, dunning, domain renewals, ticket escalation, backups and more — all run from <strong>one</strong> cron entry. Add the command below to your hosting control panel once, and everything runs on schedule.
    </p>
</div>

<!-- The command -->
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">1. Your cron command</h2>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Run every minute (recommended — each task decides for itself when it's actually due):</p>

    <div class="cron-row">
        <code id="cron-cmd" class="cron-code cron-code--block"><?= e($everyMinute) ?></code>
        <button type="button" class="cv-btn" data-copy-text="<?= e($everyMinute) ?>" style="white-space:nowrap;">Copy command</button>
    </div>

    <details style="margin-top:var(--cv-space-3);">
        <summary style="cursor:pointer;color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Host limits cron to every 5 minutes? Use this instead</summary>
        <div class="cron-row" style="margin-top:var(--cv-space-2);">
            <code class="cron-code cron-code--block"><?= e($everyFiveMinutes) ?></code>
            <button type="button" class="cv-btn cv-btn--secondary" data-copy-text="<?= e($everyFiveMinutes) ?>" style="white-space:nowrap;">Copy</button>
        </div>
    </details>

    <div style="margin-top:var(--cv-space-3);display:grid;gap:var(--cv-space-3);">
        <div>
            <div class="cron-code__label">Script path</div>
            <div class="cron-row">
                <code class="cron-code cron-code--inline"><?= e($cronPath) ?></code>
                <button type="button" class="cv-btn cv-btn--secondary" data-copy-text="<?= e($cronPath) ?>">Copy path</button>
            </div>
        </div>
        <div>
            <div class="cron-code__label">Log file (created automatically)</div>
            <div class="cron-row">
                <code class="cron-code cron-code--inline"><?= e($logPath) ?></code>
                <button type="button" class="cv-btn cv-btn--secondary" data-copy-text="<?= e($logPath) ?>">Copy path</button>
            </div>
        </div>
    </div>
</div>

<!-- cPanel instructions -->
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">2. Add it in cPanel</h2>
    <ol style="padding-left:1.2rem;line-height:1.7;color:var(--cv-text-primary);">
        <li>Log into <strong>cPanel</strong> and open <strong>Advanced &rarr; Cron Jobs</strong>.</li>
        <li>Under <strong>Add New Cron Job</strong>, set <strong>Common Settings</strong> to <em>&ldquo;Once Per Minute (* * * * *)&rdquo;</em> — or fill the five time fields with <code>*</code> each.</li>
        <li>Paste the copied command into the <strong>Command</strong> box.</li>
        <li>Click <strong>Add New Cron Job</strong>. That's it — automation is now live.</li>
    </ol>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-bottom:0;">
        <strong>Tip:</strong> if the job doesn't run, your host may require the full path to PHP instead of <code>php</code>. Find it in cPanel under <strong>Select PHP Version</strong> (or run <code>which php</code> over SSH) — it's usually something like <code>/usr/local/bin/php</code> or <code>/opt/cpanel/ea-php82/root/usr/bin/php</code> — and replace <code>php</code> at the start of the command with that path.
    </p>
</div>

<!-- Other panels -->
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Other control panels &amp; plain servers</h2>
    <ul style="padding-left:1.2rem;line-height:1.7;color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
        <li><strong>Plesk:</strong> Websites &amp; Domains &rarr; Scheduled Tasks &rarr; Add Task &rarr; type &ldquo;Run a command&rdquo;, set interval to every minute, paste the command.</li>
        <li><strong>DirectAdmin:</strong> Advanced Features &rarr; Cron Jobs, set all fields to <code>*</code>, paste the command.</li>
        <li><strong>SSH / plain Linux:</strong> run <code>crontab -e</code> and paste the command as a new line.</li>
    </ul>
</div>

<!-- What runs -->
<div class="cv-card">
    <h2 class="cv-card__title">What this runs (<?= count($jobs) ?> tasks)</h2>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">All of these run from that single cron entry, each on its own schedule:</p>
    <table class="cv-table">
        <thead><tr><th>Task</th><th>What it does</th></tr></thead>
        <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td><strong><?= e($job['name']) ?></strong></td>
                    <td style="color:var(--cv-text-secondary);"><?= e($job['desc']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
