<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Database;

/**
 * The editable email templates the Username Changer sends. One list, used by
 * migration 0214 to seed them and by the cron job to re-create any an admin
 * deleted. Existing rows are NEVER overwritten (INSERT IGNORE), so an admin's
 * edits survive both.
 *
 * {{company_name}} is the brand the client knows: the store's name for a store
 * customer (EmailDispatcher brands store mail automatically), the platform's
 * otherwise. No template names the platform when it is sent for a store.
 */
final class UsernameChangeTemplates
{
    public const CONFIRM = 'username_change.confirm';
    public const SUBMITTED = 'username_change.submitted';
    public const APPROVED = 'username_change.approved';
    public const DECLINED = 'username_change.declined';
    public const PAYMENT_DUE = 'username_change.payment_due';
    public const COMPLETED = 'username_change.completed';
    public const FAILED = 'username_change.failed';
    public const STAFF_NEW = 'username_change.staff_new';
    public const STAFF_FAILED = 'username_change.staff_failed';
    public const STAFF_MISMATCH = 'username_change.staff_mismatch';
    public const STORE_APPROVAL = 'username_change.store_approval';
    public const PIN_CODE = 'username_change.pin_code';
    public const PIN_CHANGED = 'username_change.pin_changed';

    /** @return array<string, array{name: string, subject: string, body: string}> */
    public static function all(): array
    {
        $sign = '<p>Thanks,<br>{{company_name}}</p>';

        return [
            self::CONFIRM => [
                'name' => 'Username Change: Confirm Request',
                'subject' => 'Confirm your cPanel username change to {{new_username}}',
                'body' => '<p>Hello {{client_name}},</p><p>We received a request to change the cPanel username of <strong>{{domain}}</strong> from <strong>{{old_username}}</strong> to <strong>{{new_username}}</strong>.</p><p><a href="{{confirm_url}}">Confirm this change</a></p><p>The link works once and expires on {{expires_at}}. If you did not ask for this, ignore this email and nothing will change.</p>' . $sign,
            ],
            self::SUBMITTED => [
                'name' => 'Username Change: Submitted',
                'subject' => 'Your username change request #{{request_id}} has been received',
                'body' => '<p>Hello {{client_name}},</p><p>Your request to change the cPanel username of <strong>{{domain}}</strong> to <strong>{{new_username}}</strong> is confirmed and is now <strong>{{status_label}}</strong>.</p><p>We will email you again when it is done. You can follow it at <a href="{{service_url}}">your service page</a>.</p>' . $sign,
            ],
            self::APPROVED => [
                'name' => 'Username Change: Approved',
                'subject' => 'Username change #{{request_id}} approved',
                'body' => '<p>Hello {{client_name}},</p><p>Your request to change the cPanel username of <strong>{{domain}}</strong> to <strong>{{new_username}}</strong> has been approved and will be carried out shortly.</p>' . $sign,
            ],
            self::DECLINED => [
                'name' => 'Username Change: Declined',
                'subject' => 'Username change #{{request_id}} declined',
                'body' => '<p>Hello {{client_name}},</p><p>Your request to change the cPanel username of <strong>{{domain}}</strong> to <strong>{{new_username}}</strong> was declined.</p><p><strong>Reason:</strong> {{decline_reason}}</p><p>Your username is still <strong>{{old_username}}</strong>.</p>' . $sign,
            ],
            self::PAYMENT_DUE => [
                'name' => 'Username Change: Payment Due',
                'subject' => 'Invoice #{{invoice_id}} for your username change',
                'body' => '<p>Hello {{client_name}},</p><p>Your username change for <strong>{{domain}}</strong> ({{old_username}} → <strong>{{new_username}}</strong>) is ready. A fee of <strong>{{fee}}</strong> applies.</p><p><a href="{{invoice_url}}">View and pay invoice #{{invoice_id}}</a></p><p>The change is carried out automatically as soon as the invoice is paid.</p>' . $sign,
            ],
            self::COMPLETED => [
                'name' => 'Username Change: Completed',
                'subject' => 'Your cPanel username is now {{new_username}}',
                'body' => '<p>Hello {{client_name}},</p><p>The cPanel username of <strong>{{domain}}</strong> has been changed from <strong>{{old_username}}</strong> to <strong>{{new_username}}</strong>.</p><p><strong>Please update:</strong></p><ul><li>Your cPanel, FTP and SSH logins now use <strong>{{new_username}}</strong> (your password is unchanged).</li><li>Your home folder is now <code>/home/{{new_username}}</code> — update cron jobs and scripts that use the old path.</li><li>{{database_note}}</li></ul>' . $sign,
            ],
            self::FAILED => [
                'name' => 'Username Change: Failed',
                'subject' => 'We could not change your username (request #{{request_id}})',
                'body' => '<p>Hello {{client_name}},</p><p>We were unable to change the cPanel username of <strong>{{domain}}</strong> to <strong>{{new_username}}</strong>. Your account is unchanged and still uses <strong>{{old_username}}</strong>.</p><p>Our team has been notified. You can open a support ticket if you need help.</p>' . $sign,
            ],
            self::STAFF_NEW => [
                'name' => 'Username Change: Staff — New Request',
                'subject' => 'Username change #{{request_id}}: {{old_username}} → {{new_username}} ({{status_label}})',
                'body' => '<p>A username change request is <strong>{{status_label}}</strong>.</p><ul><li><strong>Client:</strong> {{client_name}}</li><li><strong>Service:</strong> {{domain}} (#{{service_id}})</li><li><strong>Change:</strong> {{old_username}} → {{new_username}}</li><li><strong>Reason:</strong> {{reason}}</li></ul><p><a href="{{admin_url}}">Open the request</a></p>',
            ],
            self::STAFF_FAILED => [
                'name' => 'Username Change: Staff — Failed',
                'subject' => 'Username change #{{request_id}} FAILED',
                'body' => '<p>A username change failed after {{attempts}} attempt(s).</p><ul><li><strong>Service:</strong> {{domain}} (#{{service_id}})</li><li><strong>Change:</strong> {{old_username}} → {{new_username}}</li><li><strong>Error:</strong> {{error}}</li></ul><p><a href="{{admin_url}}">Open the request</a> to retry.</p>',
            ],
            self::STAFF_MISMATCH => [
                'name' => 'Username Change: Staff — Sync Problem',
                'subject' => 'Username change #{{request_id}}: server renamed but billing record not updated',
                'body' => '<p>The account was renamed on the server to <strong>{{new_username}}</strong>, but the service record (#{{service_id}}) could not be updated and may still show <strong>{{old_username}}</strong>.</p><p><a href="{{admin_url}}">Open the request</a> and use “Sync from server”.</p>',
            ],
            self::STORE_APPROVAL => [
                'name' => 'Username Change: Store Owner — Approval Needed',
                'subject' => 'Approval needed: username change for {{domain}}',
                'body' => '<p>Hello,</p><p>Your customer {{client_name}} asked to change the cPanel username of <strong>{{domain}}</strong> from {{old_username}} to <strong>{{new_username}}</strong>.</p><p><strong>Reason:</strong> {{reason}}</p><p><a href="{{panel_url}}">Approve or decline it in your reseller panel</a>.</p>',
            ],
            self::PIN_CODE => [
                'name' => 'Security PIN: Reset Code',
                'subject' => 'Your Security PIN reset code: {{code}}',
                'body' => '<p>Hello {{client_name}},</p><p>Use this code to set a new Security PIN on your account:</p><p style="font-size:24px;letter-spacing:6px;font-weight:700;">{{code}}</p><p>It expires in {{minutes}} minutes. If you did not ask for this, ignore this email — your PIN has not changed.</p>' . $sign,
            ],
            self::PIN_CHANGED => [
                'name' => 'Security PIN: Changed',
                'subject' => 'Your Security PIN was changed',
                'body' => '<p>Hello {{client_name}},</p><p>The Security PIN on your account was changed on {{changed_at}}.</p><p>If this was not you, sign in and change your password and PIN straight away from <a href="{{account_url}}">your account</a>, and contact support.</p>' . $sign,
            ],
        ];
    }

    /**
     * Inserts any template that is missing. Never overwrites an existing one.
     *
     * @param array<int, string>|null $only limit to these keys
     */
    public static function ensure(Database $db, ?array $only = null): void
    {
        foreach (self::all() as $key => $template) {
            if ($only !== null && !in_array($key, $only, true)) {
                continue;
            }

            $db->insert(
                'INSERT IGNORE INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
                [$key, $template['name'], $template['subject'], $template['body']]
            );
        }
    }
}
