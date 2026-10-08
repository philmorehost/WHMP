<?php

declare(strict_types=1);

namespace CodeVault\Modules\Addons;

use CodeVault\Mail\EmailSuppression;
use CodeVault\Modules\AddonModule;
use Throwable;

/**
 * Invalid Email Blocker — stops every email type from being sent to client
 * addresses the Email Validation scan marked Invalid.
 *
 * This class is the Admin → Addons page: the ON/OFF switch and its settings.
 * The blocking itself happens in EmailDispatcher (the one path all client mail
 * takes) via EmailSuppression, and the list of invalid addresses is the Email
 * Validation page (/admin/email-validation), which also carries the switch.
 */
final class InvalidEmailBlockerAddon implements AddonModule
{
    public function __construct(
        private readonly EmailSuppression $suppression
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Invalid Email Blocker',
            'description' => 'Stop sending any email — invoices, reminders, tickets, campaigns — to client addresses that Email Validation marked invalid. Skipped emails stay in the log and in the client\'s in-app notifications.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [];
    }

    /** @return array{success: bool, message: string} */
    public function activate(): array
    {
        return ['success' => true, 'message' => 'Invalid Email Blocker activated — emails to addresses marked Invalid on the Email Validation page are no longer sent. Open the addon to change its settings.'];
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(): array
    {
        return ['success' => true, 'message' => 'Invalid Email Blocker deactivated — every address receives email again.'];
    }

    public function hooks(): array
    {
        return [];
    }

    public function render(array $params): string
    {
        $saved = false;
        $error = null;

        if (!empty($params['save'])) {
            try {
                $this->suppression->save($params);
                $saved = true;
            } catch (Throwable) {
                $error = 'The settings could not be saved. Please try again.';
            }
        }

        try {
            $s = $this->suppression->settings();
            $stats = $this->suppression->stats();
            $recent = $this->suppression->recentSkipped(10);
        } catch (Throwable) {
            return '<div class="cv-alert cv-alert--error">The Invalid Email Blocker could not read its data. Has the database been migrated?</div>';
        }

        $csrf = (string) ($params['csrf_field'] ?? '');
        $slug = EmailSuppression::SLUG;
        $blocking = $s['active'] && $s['block'];

        $state = !$s['active']
            ? '<span class="cv-badge cv-badge--neutral">Addon not activated — nothing is blocked</span>'
            : ($s['block'] ? '<span class="cv-badge cv-badge--success">Blocking ON</span>' : '<span class="cv-badge cv-badge--neutral">Blocking OFF</span>');

        $blockChecked = $s['block'] ? ' checked' : '';
        $securityChecked = $s['allow_security'] ? ' checked' : '';
        $rescan = (int) $s['rescan_days'];
        $allow = e(implode("\n", $s['allow']));
        $blocked = (int) $stats['blocked'];
        $skipped = (int) $stats['skipped30'];
        $blockedLabel = $blocking ? 'addresses blocked now' : 'addresses marked invalid';

        $rows = '';

        foreach ($recent as $r) {
            $rows .= '<tr><td style="white-space:nowrap;">' . e((string) $r['created_at']) . '</td>'
                . '<td>' . e((string) $r['to_email']) . '</td>'
                . '<td>' . e((string) $r['subject']) . '</td>'
                . '<td>' . e((string) ($r['error'] ?? '')) . '</td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4" style="color:var(--cv-text-secondary);">No emails have been skipped yet.</td></tr>';
        }

        $banner = $saved ? '<div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);">Settings saved.</div>' : '';
        $banner .= $error !== null ? '<div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);">' . e($error) . '</div>' : '';

        return $banner . <<<HTML
        <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
            <h3 class="cv-card__title">Status {$state}</h3>
            <p style="color:var(--cv-text-secondary);margin:0 0 var(--cv-space-3);">
                Addresses are marked invalid by the scan on the <a href="/admin/email-validation">Email Validation</a> page — a
                malformed address, a domain with no mail server, or repeated real delivery failures. While blocking is ON, no
                email of any type is sent to them. Each skipped email is still written to the email log (status
                <em>suppressed</em>) and still appears in the client's in-app notifications.
            </p>
            <p style="margin:0;"><strong>{$blocked}</strong> {$blockedLabel} · <strong>{$skipped}</strong> emails skipped in the last 30 days ·
                <a href="/admin/email-validation">Open Email Validation</a></p>
        </div>

        <form method="post" action="/admin/addons/{$slug}" class="cv-card" style="margin-bottom:var(--cv-space-4);">
            {$csrf}
            <input type="hidden" name="save" value="1">
            <h3 class="cv-card__title">Settings</h3>
            <label style="display:flex;gap:var(--cv-space-2);align-items:flex-start;margin-bottom:var(--cv-space-3);cursor:pointer;">
                <input type="checkbox" name="block" value="1"{$blockChecked} style="margin-top:4px;">
                <span><strong>Stop sending emails to invalid addresses</strong><br>
                <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">The ON/OFF switch. Applies to every email type the billing portal sends to clients.</span></span>
            </label>
            <label style="display:flex;gap:var(--cv-space-2);align-items:flex-start;margin-bottom:var(--cv-space-3);cursor:pointer;">
                <input type="checkbox" name="allow_security" value="1"{$securityChecked} style="margin-top:4px;">
                <span><strong>Still send account-security emails</strong><br>
                <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Password resets, sign-up codes and Security PIN codes go out even to an invalid address, so a wrongly flagged client can never be locked out. Recommended.</span></span>
            </label>
            <div class="cv-field" style="margin-bottom:var(--cv-space-3);">
                <label class="cv-label" for="ieb-rescan">Re-scan all client emails automatically every</label>
                <div style="display:flex;align-items:center;gap:var(--cv-space-2);">
                    <input class="cv-input" id="ieb-rescan" type="number" name="rescan_days" min="0" max="90" value="{$rescan}" style="max-width:110px;">
                    <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">days (0 = only when you click Scan). Fixed addresses are un-blocked by the next scan.</span>
                </div>
            </div>
            <div class="cv-field" style="margin-bottom:var(--cv-space-3);">
                <label class="cv-label" for="ieb-allow">Always send to these addresses</label>
                <textarea class="cv-input" id="ieb-allow" name="allow" rows="4" placeholder="one address per line">{$allow}</textarea>
                <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">Exceptions that always receive email, whatever the scan says. You can also add them with "Always send" on the Email Validation page.</span>
            </div>
            <button class="cv-btn" type="submit">Save settings</button>
        </form>

        <div class="cv-card">
            <h3 class="cv-card__title">Recently skipped emails</h3>
            <div style="overflow-x:auto;">
                <table class="cv-table">
                    <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Why</th></tr></thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>
        </div>
        HTML;
    }
}
